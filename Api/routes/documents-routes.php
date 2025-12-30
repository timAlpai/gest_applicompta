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

// --- CALLBACKS ---

function applicompta_get_ninja_invoices() {
    return applicompta_fetch_documents('invoices');
}

function applicompta_get_ninja_quotes() {
    return applicompta_fetch_documents('quotes');
}

// Fonction générique privée pour éviter la duplication
function applicompta_fetch_documents($endpoint_type) {
    // Récup token
    $user_id = get_current_user_id();
    $encrypted = get_user_meta($user_id, 'invoiceninja_token_encrypted', true);
    if (!$encrypted) return new WP_Error('no_token', 'Token manquant', ['status' => 403]);
    $token = applicompta_decrypt($encrypted);

    // URL Ninja (ex: .../invoices ou .../quotes)
    // On ajoute ?include=client pour avoir le nom du client directement
    $url = rtrim(INVOICENINJA_API_URL, '/') . '/' . $endpoint_type . '?include=client';

    $response = wp_remote_get($url, [
        'headers' => ['X-API-Token' => $token, 'X-Requested-With' => 'XMLHttpRequest'],
        'timeout' => 20
    ]);

    if (is_wp_error($response)) return $response;
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return new WP_REST_Response($body, 200);
}

// ... (Gardez les routes GET existantes) ...

// 3. CRÉER UN DEVIS (POST)
register_rest_route('applicompta/v1', '/ninja/quotes', [
    'methods'  => 'POST',
    'callback' => 'applicompta_create_ninja_quote',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);


// --- CALLBACK POUR CRÉATION ---

function applicompta_create_ninja_quote($request) {
    if (!function_exists('applicompta_get_ninja_token')) {
        return new WP_Error('sys_error', 'Erreur interne.', ['status' => 500]);
    }
    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;

    $params = $request->get_json_params();
    
    if (empty($params['client_id'])) {
        return new WP_Error('missing_data', 'Client obligatoire', ['status' => 400]);
    }

    // Traitement des lignes de produits
    $line_items = [];
    if (!empty($params['line_items']) && is_array($params['line_items'])) {
        foreach ($params['line_items'] as $line) {
            $line_items[] = [
                // 'product_key' => 'Item', // Optionnel
                'notes'    => sanitize_textarea_field($line['notes'] ?? ''), // Description
                'cost'     => (float) ($line['cost'] ?? 0),
                'quantity' => (float) ($line['quantity'] ?? 1),
            ];
        }
    }

    $payload = [
        'client_id' => sanitize_text_field($params['client_id']),
        'date' => sanitize_text_field($params['date']),
        'public_notes' => sanitize_textarea_field($params['public_notes']),
        'line_items' => $line_items // On ajoute le tableau ici
    ];

    $url = rtrim(INVOICENINJA_API_URL, '/') . '/quotes';

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
        return new WP_Error('ninja_connect_error', $response->get_error_message(), ['status' => 500]);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200 && $code !== 201) {
        // Log pour le débug si besoin
        error_log('Erreur Ninja Quote: ' . print_r($body, true));
        $msg = $body['message'] ?? 'Erreur inconnue';
        return new WP_Error('ninja_error', 'Erreur création : ' . $msg, ['status' => $code]);
    }

    return new WP_REST_Response($body, 200);
}