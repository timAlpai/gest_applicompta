<?php
defined('ABSPATH') || exit;

// 1. LISTER LES CLIENTS
register_rest_route('applicompta/v1', '/ninja/clients', [
    'methods'  => 'GET',
    'callback' => 'applicompta_get_ninja_clients',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

// 2. CRÉER / MAJ CLIENT
register_rest_route('applicompta/v1', '/ninja/clients', [
    'methods'  => 'POST',
    'callback' => 'applicompta_save_ninja_client',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

// 3. SUPPRIMER CLIENT
register_rest_route('applicompta/v1', '/ninja/clients/(?P<id>[a-zA-Z0-9]+)', [
    'methods'  => 'DELETE',
    'callback' => 'applicompta_delete_ninja_client',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

// FONCTIONS 
function applicompta_get_ninja_clients() {
    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;

    $url = rtrim(INVOICENINJA_API_URL, '/') . '/clients';
    
    // On peut ajouter des filtres ici si besoin (?sort=name)
    $response = wp_remote_get($url, [
        'headers' => ['X-API-Token' => $token, 'X-Requested-With' => 'XMLHttpRequest'],
        'timeout' => 20
    ]);

    if (is_wp_error($response)) return $response;
    return new WP_REST_Response(json_decode(wp_remote_retrieve_body($response), true), 200);
}

function applicompta_save_ninja_client($request) {
    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;

    $params = $request->get_json_params();
    $client_id = $params['client_id'] ?? null;
    
    // Payload minimal pour un client
    $payload = [
        'name' => sanitize_text_field($params['name']),
        'id_number' => sanitize_text_field($params['id_number']), // Numéro TVA ou autre
        // Dans Ninja v5, les contacts sont un tableau d'objets
        'contacts' => [
            [
                'first_name' => sanitize_text_field($params['first_name']),
                'last_name' => sanitize_text_field($params['last_name']),
                'email' => sanitize_email($params['email']),
                'phone' => sanitize_text_field($params['phone']),
            ]
        ]
    ];

    $url = rtrim(INVOICENINJA_API_URL, '/') . '/clients';
    $method = 'POST';

    if ($client_id) {
        $url .= '/' . $client_id;
        $method = 'PUT';
    }

    $response = wp_remote_request($url, [
        'method' => $method,
        'headers' => ['X-API-Token' => $token, 'Content-Type' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
        'body' => json_encode($payload),
        'timeout' => 20
    ]);

    if (is_wp_error($response)) return $response;
    return new WP_REST_Response(json_decode(wp_remote_retrieve_body($response), true), 200);
}

function applicompta_delete_ninja_client($request) {
    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;

    $client_id = $request['id'];
    $url = rtrim(INVOICENINJA_API_URL, '/') . '/clients/' . $client_id; // Ninja utilise souvent DELETE pour archiver

    $response = wp_remote_request($url, [
        'method' => 'DELETE',
        'headers' => ['X-API-Token' => $token, 'X-Requested-With' => 'XMLHttpRequest'],
        'timeout' => 20
    ]);

    if (is_wp_error($response)) return $response;
    return new WP_REST_Response(['success' => true], 200);
}

// Helper pour récupérer le token (évite la duplication)
function applicompta_get_ninja_token() {
    $user_id = get_current_user_id();
    $encrypted = get_user_meta($user_id, 'invoiceninja_token_encrypted', true);
    if (!$encrypted) return new WP_Error('no_token', 'Token manquant', ['status' => 403]);
    return applicompta_decrypt($encrypted);
}