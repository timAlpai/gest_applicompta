<?php
defined('ABSPATH') || exit;

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
    // 1. AUTHENTIFICATION
    $user_id = get_current_user_id();
    $encrypted = get_user_meta($user_id, 'invoiceninja_token_encrypted', true);
    if (!$encrypted) return new WP_Error('no_token', 'Token manquant', ['status' => 403]);
    $token = applicompta_decrypt($encrypted);

    // 2. PRÉPARATION DES PARAMÈTRES API
    // Paramètres communs
    $api_params = [
        'include' => 'client', // On veut toujours les infos du client
        'per_page' => 1000,    // On s'assure de récupérer une large liste
    ];

    // LOGIQUE DE DIFFÉRENCIATION
    if ($endpoint_type === 'quotes') {
        // --- CAS DES DEVIS ---
        // On demande à Ninja de ne renvoyer que les "actifs" (masque les archivés/supprimés)
        $api_params['status'] = 'active'; 
        // On trie par numéro décroissant (le plus récent en haut)
        $api_params['sort'] = 'number|desc';

    } elseif ($endpoint_type === 'invoices') {
        // --- CAS DES FACTURES ---
        // ATTENTION : On ne met SURTOUT PAS 'status' => 'active' ici, car cela renvoie 0 résultat.
        // On peut soit laisser vide (tout récupérer), soit lister explicitement les statuts voulus.
        // Ici, on laisse vide pour avoir : Draft, Sent, Paid, Partial, Past Due, etc.
        
        // On peut aussi trier les factures
        $api_params['sort'] = 'number|desc';
    }

    // 3. CRÉATION DE LA CHAÎNE DE REQUÊTE (Query String)
    // http_build_query transforme le tableau ['a'=>'b'] en "a=b" proprement
    $query_string = http_build_query($api_params);
    
    // Construction de l'URL finale
    $url = rtrim(INVOICENINJA_API_URL, '/') . '/' . $endpoint_type . '?' . $query_string;

    // 4. APPEL API
    $response = wp_remote_get($url, [
        'headers' => ['X-API-Token' => $token, 'X-Requested-With' => 'XMLHttpRequest'],
        'timeout' => 20
    ]);

    if (is_wp_error($response)) return $response;
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $raw_data = isset($body['data']) ? $body['data'] : $body;

    // 5. NETTOYAGE FINAL (FILTRAGE PHP)
    // On garde ce filtrage pour les cas spécifiques que l'API ne gère pas (ex: devis convertis)
    $clean_data = [];
    
    if (is_array($raw_data)) {
        foreach ($raw_data as $doc) {
            
            // Sécurité : ignorer les supprimés si l'API en renvoie quand même
            if (!empty($doc['is_deleted']) || !empty($doc['archived_at'])) {
                continue;
            }

            // Spécifique DEVIS : Cacher ceux qui sont convertis en facture
            if ($endpoint_type === 'quotes' && !empty($doc['invoice_id'])) {
                continue;
            }

            $clean_data[] = $doc;
        }
    }

    return new WP_REST_Response($clean_data, 200);
}

// Garder les routes GET existantes

// 3. Créer un devis (POST)
register_rest_route('applicompta/v1', '/ninja/quotes', [
    'methods'  => 'POST',
    'callback' => 'applicompta_create_ninja_quote',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

// Callback pour la création
function applicompta_create_ninja_quote($request) {
    if (!function_exists('applicompta_get_ninja_token')) {
        return new WP_Error('sys_error', 'Erreur interne.', ['status' => 500]);
    }
    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;

    $params = $request->get_json_params();
    
    // 1. Récupération de l'ID pour savoir si c'est une édition
    $quote_id = $params['id'] ?? null;

    if (empty($params['client_id'])) {
        return new WP_Error('missing_data', 'Client obligatoire', ['status' => 400]);
    }

    // Traitement des lignes de produits
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

    // 2. Détermination de l'URL et de la Méthode (POST ou PUT)
    $url = rtrim(INVOICENINJA_API_URL, '/') . '/quotes';
    $method = 'POST';

    if ($quote_id) {
        $url .= '/' . $quote_id; // URL devient .../quotes/le_id
        $method = 'PUT';         // Méthode de mise à jour
    }

    // 3. Envoi de la requête (wp_remote_request gère POST et PUT)
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

    if (is_wp_error($response)) {
        return new WP_Error('ninja_connect_error', $response->get_error_message(), ['status' => 500]);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200 && $code !== 201) {
        error_log('Erreur Ninja Quote: ' . print_r($body, true));
        $msg = $body['message'] ?? 'Erreur inconnue';
        return new WP_Error('ninja_error', 'Erreur sauvegarde : ' . $msg, ['status' => $code]);
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
    // 1. Récupération du token
    if (!function_exists('applicompta_get_ninja_token')) {
        return new WP_Error('sys_error', 'Erreur interne.', ['status' => 500]);
    }
    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;

    // 2. ID du devis à supprimer
    $quote_id = $request['id'];

    // 3. Appel à Invoice Ninja
    // Note: Ninja utilise DELETE pour archiver/supprimer
    $url = rtrim(INVOICENINJA_API_URL, '/') . '/quotes/' . $quote_id;

    $response = wp_remote_request($url, [
        'method' => 'DELETE',
        'headers' => [
            'X-API-Token' => $token, 
            'X-Requested-With' => 'XMLHttpRequest'
        ],
        'timeout' => 20
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('ninja_connect_error', $response->get_error_message(), ['status' => 500]);
    }

    $code = wp_remote_retrieve_response_code($response);
    
    if ($code !== 200) {
        return new WP_Error('delete_error', 'Impossible de supprimer le devis.', ['status' => $code]);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Devis supprimé'], 200);
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
    
    // L'endpoint est /quotes/bulk pour les actions en masse (ou unitaires)
    $url = rtrim(INVOICENINJA_API_URL, '/') . '/quotes/bulk';

    // Payload spécifique pour la conversion
    $payload = [
        'ids' => [$quote_id], // Tableau d'IDs
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

    if (is_wp_error($response)) {
        return new WP_Error('ninja_error', $response->get_error_message(), ['status' => 500]);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200) {
        return new WP_Error('convert_error', 'Erreur lors de la conversion.', ['status' => $code]);
    }

    // Invoice Ninja renvoie la liste des objets modifiés/créés.
    // On renvoie le succès.
    return new WP_REST_Response([
        'success' => true, 
        'message' => 'Devis converti en facture',
        'data' => $body
    ], 200);
}


// ... (Après les routes existantes)

// 6. CRÉER / MAJ FACTURE (POST)
register_rest_route('applicompta/v1', '/ninja/invoices', [
    'methods'  => 'POST',
    'callback' => 'applicompta_save_ninja_invoice',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

// 7. SUPPRIMER FACTURE (DELETE)
register_rest_route('applicompta/v1', '/ninja/invoices/(?P<id>[^/]+)', [
    'methods'  => 'DELETE',
    'callback' => 'applicompta_delete_ninja_invoice',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

// --- CALLBACKS FACTURES ---

function applicompta_save_ninja_invoice($request) {
    if (!function_exists('applicompta_get_ninja_token')) {
        return new WP_Error('sys_error', 'Erreur interne.', ['status' => 500]);
    }
    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;

    $params = $request->get_json_params();
    $invoice_id = $params['id'] ?? null;

    if (empty($params['client_id'])) {
        return new WP_Error('missing_data', 'Client obligatoire', ['status' => 400]);
    }

    // Traitement des lignes
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

    // Détermination URL (POST ou PUT)
    $url = rtrim(INVOICENINJA_API_URL, '/') . '/invoices';
    $method = 'POST';

    if ($invoice_id) {
        $url .= '/' . $invoice_id;
        $method = 'PUT';
    }

    $response = wp_remote_request($url, [
        'method' => $method, // PUT ou POST
        'headers' => [
            'X-API-Token' => $token, 
            'Content-Type' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest'
        ],
        'body' => json_encode($payload),
        'timeout' => 20
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('ninja_connect_error', $response->get_error_message(), ['status' => 500]);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200 && $code !== 201) {
        $msg = $body['message'] ?? 'Erreur inconnue';
        return new WP_Error('ninja_error', 'Erreur sauvegarde : ' . $msg, ['status' => $code]);
    }

    return new WP_REST_Response($body, 200);
}

function applicompta_delete_ninja_invoice($request) {
    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;

    $invoice_id = $request['id'];
    // Invoice Ninja utilise DELETE pour supprimer/archiver
    $url = rtrim(INVOICENINJA_API_URL, '/') . '/invoices/' . $invoice_id;

    $response = wp_remote_request($url, [
        'method' => 'DELETE',
        'headers' => ['X-API-Token' => $token, 'X-Requested-With' => 'XMLHttpRequest'],
        'timeout' => 20
    ]);

    if (is_wp_error($response)) return $response;
    
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return new WP_Error('delete_error', 'Impossible de supprimer la facture.', ['status' => $code]);
    }

    return new WP_REST_Response(['success' => true], 200);
}