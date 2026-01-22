<?php
defined('ABSPATH') || exit;
use Symfony\Component\HttpClient\HttpClient;
// 1. Route pour les FACTURES
register_rest_route('applicompta/v1', '/ninja/invoices', [
    'methods'  => 'GET',
    'callback' => 'applicompta_get_ninja_invoices',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

// 2. Route pour les DEVIS
register_rest_route('applicompta/v1', '/ninja/quotes', [
    'methods'  => 'GET',
    'callback' => 'applicompta_get_ninja_quotes',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

// Callbacks
function applicompta_get_ninja_invoices() {
    return applicompta_fetch_documents('invoices');
}

function applicompta_get_ninja_quotes() {
    return applicompta_fetch_documents('quotes');
}

// Fonction générique privée pour éviter la duplication
function applicompta_fetch_documents($endpoint_type) {
    $user_id = get_current_user_id();
    $encrypted = get_user_meta($user_id, 'invoiceninja_token_encrypted', true);
    if (!$encrypted) return new WP_Error('no_token', 'Token manquant', ['status' => 403]);
    $token = applicompta_decrypt($encrypted);

    $api_params = [
        'include' => 'client',
        'per_page' => 1000,
    ];

    if ($endpoint_type === 'quotes') {
        $api_params['status'] = 'active'; 
        $api_params['sort'] = 'number|desc';
    } elseif ($endpoint_type === 'invoices') {
        $api_params['sort'] = 'number|desc';
    }

    $query_string = http_build_query($api_params);
    $url = rtrim(INVOICENINJA_API_URL, '/') . '/' . $endpoint_type . '?' . $query_string;

    $response = wp_remote_get($url, [
        'headers' => ['X-API-Token' => $token, 'X-Requested-With' => 'XMLHttpRequest'],
        'timeout' => 20
    ]);

    if (is_wp_error($response)) return $response;
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $raw_data = isset($body['data']) ? $body['data'] : $body;

    $clean_data = [];
    if (is_array($raw_data)) {
        foreach ($raw_data as $doc) {
            if (!empty($doc['is_deleted']) || !empty($doc['archived_at'])) continue;
            if ($endpoint_type === 'quotes' && !empty($doc['invoice_id'])) continue;
            $clean_data[] = $doc;
        }
    }

    return new WP_REST_Response($clean_data, 200);
}

// 3. Créer un devis (POST)
register_rest_route('applicompta/v1', '/ninja/quotes', [
    'methods'  => 'POST',
    'callback' => 'applicompta_create_ninja_quote',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

function applicompta_create_ninja_quote($request) {
    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;

    $params = $request->get_json_params();
    $quote_id = $params['id'] ?? null;

    if (empty($params['client_id'])) {
        return new WP_Error('missing_data', 'Client obligatoire', ['status' => 400]);
    }

    $line_items = [];
    if (!empty($params['line_items']) && is_array($params['line_items'])) {
        foreach ($params['line_items'] as $line) {
            $line_items[] = [
                'notes'    => sanitize_textarea_field($line['notes'] ?? ''),
                'cost'     => (float) ($line['cost'] ?? 0),
                'quantity' => (float) ($line['quantity'] ?? 1),
            ];
        }
    }

    $payload = [
        'client_id' => sanitize_text_field($params['client_id']),
        'date' => sanitize_text_field($params['date']),
        'public_notes' => sanitize_textarea_field($params['public_notes']),
        'line_items' => $line_items
    ];

    $url = rtrim(INVOICENINJA_API_URL, '/') . '/quotes';
    $method = 'POST';

    if ($quote_id) {
        $url .= '/' . $quote_id;
        $method = 'PUT';
    }

    $response = wp_remote_request($url, [
        'method' => $method,
        'headers' => [
            'X-API-Token' => $token, 
            'Content-Type' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest'
        ],
        'body' => json_encode($payload),
        'timeout' => 20
    ]);

    if (is_wp_error($response)) return $response;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200 && $code !== 201) {
        return new WP_Error('ninja_error', 'Erreur sauvegarde : ' . ($body['message'] ?? 'Inconnue'), ['status' => $code]);
    }

    return new WP_REST_Response($body, 200);
}

// 4. Supprimer un devis (DELETE)
register_rest_route('applicompta/v1', '/ninja/quotes/(?P<id>[^/]+)', [
    'methods'  => 'DELETE',
    'callback' => 'applicompta_delete_ninja_quote',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

function applicompta_delete_ninja_quote($request) {
    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;

    $quote_id = $request['id'];
    $url = rtrim(INVOICENINJA_API_URL, '/') . '/quotes/' . $quote_id;

    $response = wp_remote_request($url, [
        'method' => 'DELETE',
        'headers' => ['X-API-Token' => $token, 'X-Requested-With' => 'XMLHttpRequest'],
        'timeout' => 20
    ]);

    if (is_wp_error($response)) return $response;
    return new WP_REST_Response(['success' => true], 200);
}

// 5. Convertir un devis en facture
register_rest_route('applicompta/v1', '/ninja/quotes/(?P<id>[^/]+)/convert', [
    'methods'  => 'POST',
    'callback' => 'applicompta_convert_ninja_quote',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

function applicompta_convert_ninja_quote($request) {
    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;

    $quote_id = $request['id'];
    
    // VERIFICATION SIGNATURE (Utilise l'ID Ninja comme clé méta)
    $is_signed = get_post_meta($quote_id, 'is_quote_signed', true); 

    if (!$is_signed) {
        return new WP_Error('not_signed', 'Le devis doit être signé électroniquement avant d\'être converti.', ['status' => 403]);
    }

    $url = rtrim(INVOICENINJA_API_URL, '/') . '/quotes/bulk';
    $payload = [
        'ids' => [$quote_id],
        'action' => 'convert_to_invoice'
    ];

    $response = wp_remote_post($url, [
        'headers' => [
            'X-API-Token' => $token, 
            'Content-Type' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest'
        ],
        'body' => json_encode($payload),
        'timeout' => 20
    ]);

    if (is_wp_error($response)) return $response;

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) return new WP_Error('convert_error', 'Erreur conversion', ['status' => $code]);

    return new WP_REST_Response(['success' => true, 'message' => 'Devis converti'], 200);
}

// 6. ENVOI DEVIS PAR EMAIL
register_rest_route('applicompta/v1', '/ninja/quotes/(?P<id>[^/]+)/send', [
    'methods'  => 'POST',
    'callback' => 'applicompta_send_quote_email',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

function applicompta_send_quote_email($request) {
    $user_id = get_current_user_id();
    $quote_id = $request->get_param('id');

    error_log("--- TENTATIVE ENVOI DEVIS ID: $quote_id ---");

    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;

    // 1. RÉCUPÉRATION DES DONNÉES NINJA
    $url = rtrim(INVOICENINJA_API_URL, '/') . "/quotes/$quote_id?include=client";
    $res = wp_remote_get($url, [
        'headers' => ['X-API-Token' => $token, 'X-Requested-With' => 'XMLHttpRequest']
    ]);
    
    $body = json_decode(wp_remote_retrieve_body($res), true);
    if (!isset($body['data'])) return new WP_Error('ninja_err', 'Devis introuvable');
    
    $quote = $body['data'];

    // 2. DÉTECTION INTELLIGENTE DE L'EMAIL
    $recipient_email = '';
    
    // Priorité au contact invité
    if (!empty($quote['invitations'])) {
        $contact_id = $quote['invitations'][0]['client_contact_id'];
        foreach ($quote['client']['contacts'] as $contact) {
            if ($contact['id'] === $contact_id && !empty($contact['email'])) {
                $recipient_email = $contact['email'];
                break;
            }
        }
    }

    // Fallback sur n'importe quel contact avec email
    if (empty($recipient_email)) {
        foreach ($quote['client']['contacts'] as $contact) {
            if (!empty($contact['email'])) {
                $recipient_email = $contact['email'];
                break;
            }
        }
    }

    if (empty($recipient_email)) {
        return new WP_Error('no_email', 'Le client n’a aucune adresse email valide.', ['status' => 400]);
    }

    error_log("Email destinataire validé : " . $recipient_email);

    // 3. PRÉPARATION DU TEMPLATE
    $template = get_user_meta($user_id, 'html_template', true);
    $logo_url = get_user_meta($user_id, 'logo_url', true);
    
    $items_html = "";
    foreach ($quote['line_items'] as $item) {
        $total_line = number_format($item['cost'] * $item['quantity'], 2, ',', ' ');
        $unit_price = number_format($item['cost'], 2, ',', ' ');
        $items_html .= "<tr>
            <td style='padding:10px; border-bottom:1px solid #eee;'>{$item['notes']}</td>
            <td style='padding:10px; border-bottom:1px solid #eee; text-align:center;'>{$item['quantity']}</td>
            <td style='padding:10px; border-bottom:1px solid #eee; text-align:right;'>{$unit_price} €</td>
            <td style='padding:10px; border-bottom:1px solid #eee; text-align:right;'><strong>{$total_line} €</strong></td>
        </tr>";
    }

    $sign_token = wp_hash($quote_id . $user_id);
    $sign_url = "https://portal.applicompta.be/sign.html?id=$quote_id&token=$sign_token&u=$user_id";
    $sign_button = "<a href='$sign_url' style='display:inline-block; padding:12px 25px; background:#27ae60; color:white; text-decoration:none; border-radius:5px; font-weight:bold;'>Accepter et Signer en ligne</a>";

    $replacements = [
        '{{LOGO_URL}}'      => $logo_url,
        '{{COMPANY_NAME}}'  => $quote['company_name'] ?? 'Ma Société',
        '{{QUOTE_NUMBER}}'  => $quote['number'],
        '{{QUOTE_DATE}}'    => $quote['date'],
        '{{CLIENT_NAME}}'   => $quote['client']['name'],
        '{{CLIENT_DETAILS}}'=> $quote['client']['address1'] . " " . $quote['client']['city'],
        '{{TABLE_ITEMS}}'   => $items_html,
        '{{TOTAL}}'         => number_format($quote['amount'], 2, ',', ' '),
        '{{SIGN_LINK}}'     => $sign_button
    ];

    $final_html = $template;
    foreach ($replacements as $key => $value) {
        $final_html = str_replace($key, $value, $final_html);
    }
    error_log($final_html);
    // 4. GOTENBERG PDF
    $pdf_content = applicompta_generate_pdf_via_gotenberg($final_html);
    if (!$pdf_content) return new WP_Error('pdf_err', 'Erreur Gotenberg');
    error_log($pdf_content);
    // 5. ENVOI SMTP
    return applicompta_send_custom_smtp_email($user_id, $quote, $recipient_email, $pdf_content);
}

function applicompta_generate_pdf_via_gotenberg($html_content) {
    $gotenberg_url = 'http://127.0.0.1:3000/forms/chromium/convert/html';
    
    // On utilise HttpClient de Symfony (déjà présent dans votre vendor)
    $client = HttpClient::create();

    try {
        $response = $client->request('POST', $gotenberg_url, [
            'body' => [
                // Gotenberg 8 attend un champ nommé "files" contenant le fichier
                'files' => [
                    'filename' => 'index.html',
                    'content'  => $html_content,
                    'content_type' => 'text/html'
                ]
            ],
            'timeout' => 30
        ]);

        if ($response->getStatusCode() !== 200) {
            error_log("Gotenberg Error (" . $response->getStatusCode() . "): " . $response->getContent(false));
            return false;
        }

        return $response->getContent();

    } catch (\Exception $e) {
        error_log("Exception Gotenberg : " . $e->getMessage());
        return false;
    }
}

function applicompta_send_custom_smtp_email($user_id, $quote, $recipient_email, $pdf_content) {
    $host = get_user_meta($user_id, 'smtp_host', true);
    $port = (int)get_user_meta($user_id, 'smtp_port', true);
    $user = get_user_meta($user_id, 'smtp_user', true);
    $pass = applicompta_smtp_decrypt(get_user_meta($user_id, 'smtp_pass_enc', true));

    if (!$host || !$user || !$pass) {
        return new WP_Error('smtp_cfg', 'Configuration SMTP incomplète dans votre profil.');
    }

    require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
    require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
    require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // --- CONFIGURATION DEBUG ---
        // Activez ceci pour voir les erreurs détaillées dans votre debug.log
        $mail->SMTPDebug = 0; 

        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->Port       = $port;
        $mail->CharSet    = 'UTF-8';

        // --- GESTION STARTTLS (Port 587) ---
        if ($port === 587) {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($port === 465) {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        // --- OPTION CRUCIALE : Contournement vérification SSL ---
        // Souvent nécessaire sur mail.domain.com pour STARTTLS
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $company_name = get_user_meta($user_id, 'company_name', true) ?: "Applicompta";
        $mail->setFrom($user, $company_name);
        $mail->addAddress($recipient_email);

        $mail->addStringAttachment($pdf_content, "Devis_" . $quote['number'] . ".pdf");

        $mail->isHTML(true);
        $mail->Subject = "Votre Devis " . $quote['number'];
        $mail->Body    = "Bonjour,<br><br>Veuillez trouver ci-joint notre proposition commerciale.<br><br>Cordialement.";

        $mail->send();
        return new WP_REST_Response(['success' => true, 'message' => "Email envoyé avec succès à $recipient_email"], 200);

    } catch (Exception $e) {
        error_log("Détail Erreur SMTP : " . $mail->ErrorInfo);
        return new WP_Error('smtp_fail', "Erreur d'authentification : " . $mail->ErrorInfo, ['status' => 500]);
    }
}

// Routes Factures Restantes (POST / DELETE)
register_rest_route('applicompta/v1', '/ninja/invoices', [
    'methods'  => 'POST',
    'callback' => 'applicompta_save_ninja_invoice',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

function applicompta_save_ninja_invoice($request) {
    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;

    $params = $request->get_json_params();
    $invoice_id = $params['id'] ?? null;

    $line_items = [];
    if (!empty($params['line_items']) && is_array($params['line_items'])) {
        foreach ($params['line_items'] as $line) {
            $line_items[] = [
                'notes' => sanitize_textarea_field($line['notes'] ?? ''),
                'cost' => (float)($line['cost'] ?? 0),
                'quantity' => (float)($line['quantity'] ?? 1),
            ];
        }
    }

    $payload = [
        'client_id' => sanitize_text_field($params['client_id']),
        'date' => sanitize_text_field($params['date']),
        'public_notes' => sanitize_textarea_field($params['public_notes']),
        'line_items' => $line_items
    ];

    $url = rtrim(INVOICENINJA_API_URL, '/') . '/invoices';
    $method = $invoice_id ? 'PUT' : 'POST';
    if ($invoice_id) $url .= '/' . $invoice_id;

    $response = wp_remote_request($url, [
        'method' => $method,
        'headers' => ['X-API-Token' => $token, 'Content-Type' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
        'body' => json_encode($payload),
        'timeout' => 20
    ]);

    if (is_wp_error($response)) return $response;
    return new WP_REST_Response(json_decode(wp_remote_retrieve_body($response), true), 200);
}

register_rest_route('applicompta/v1', '/ninja/invoices/(?P<id>[^/]+)', [
    'methods'  => 'DELETE',
    'callback' => 'applicompta_delete_ninja_invoice',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

function applicompta_delete_ninja_invoice($request) {
    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;
    $url = rtrim(INVOICENINJA_API_URL, '/') . '/invoices/' . $request['id'];
    $response = wp_remote_request($url, ['method' => 'DELETE', 'headers' => ['X-API-Token' => $token]]);
    return is_wp_error($response) ? $response : new WP_REST_Response(['success' => true], 200);
}