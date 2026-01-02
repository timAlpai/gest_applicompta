<?php
defined('ABSPATH') || exit;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// 1. Route pour récupérer les infos du compte (Companies)
register_rest_route('applicompta/v1', '/ninja/account', [
    'methods'  => 'GET',
    'callback' => 'applicompta_get_ninja_account',
    'permission_callback' => 'applicompta_check_jwt_permission', // Notre middleware de sécu
]);

// Fonction principale
function applicompta_get_ninja_account(WP_REST_Request $request) {
    // L'utilisateur courant a été défini par le permission_callback
    $user_id = get_current_user_id();

    // 1. Récupérer et décrypter le token
    $encrypted_token = get_user_meta($user_id, 'invoiceninja_token_encrypted', true);
    
    if (!$encrypted_token) {
        return new WP_Error('no_token', 'Aucune clé API Invoice Ninja configurée pour cet utilisateur.', ['status' => 404]);
    }

    $api_token = applicompta_decrypt($encrypted_token);

    if (!$api_token) {
        return new WP_Error('decrypt_error', 'Erreur de déchiffrement de la clé.', ['status' => 500]);
    }

    // 2. Appel à Invoice Ninja
    // Endpoint pour lister les compagnies/comptes : /companies
    $url = rtrim(INVOICENINJA_API_URL, '/') . '/companies';

    $response = wp_remote_get($url, [
        'headers' => [
            'X-API-Token' => $api_token,
            'X-Requested-With' => 'XMLHttpRequest',
            'Content-Type' => 'application/json',
        ],
        'timeout' => 20
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('ninja_api_error', $response->get_error_message(), ['status' => 502]);
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // 3. Retourner les données
    return new WP_REST_Response($data, 200);
}

// Middleware de sécurité JWT
function applicompta_check_jwt_permission(WP_REST_Request $request) {
    $auth_header = $request->get_header('Authorization');
    
    if (!$auth_header || !preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        return false;
    }
    
    $jwt = $matches[1];
    
    try {
        $decoded = JWT::decode($jwt, new Key(APPLICOMPTA_JWT_SECRET, 'HS256'));
        // On connecte l'utilisateur pour le contexte WordPress
        wp_set_current_user($decoded->data->user_id);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// 2. Nouvelle route : Mise à jour des infos (PUT)
register_rest_route('applicompta/v1', '/ninja/account', [
    'methods'  => 'POST', // On utilise POST pour simplifier, mais on fera un PUT vers Ninja
    'callback' => 'applicompta_update_ninja_account',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

function applicompta_update_ninja_account(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    $params = $request->get_json_params();
    
    // 1. Décrypter le token
    $encrypted_token = get_user_meta($user_id, 'invoiceninja_token_encrypted', true);
    if (!$encrypted_token) return new WP_Error('no_token', 'Token manquant', ['status' => 403]);
    $api_token = applicompta_decrypt($encrypted_token);

    $company_id = $params['company_id'];
    $url = rtrim(INVOICENINJA_API_URL, '/') . '/companies/' . $company_id;
    
    $headers = [
        'X-API-Token' => $api_token,
        'X-Requested-With' => 'XMLHttpRequest',
        'Content-Type' => 'application/json',
    ];

    // 2. D'abord, on récupère la fiche actuelle complète pour ne rien perdre
    $get_response = wp_remote_get($url, ['headers' => $headers, 'timeout' => 20]);

    if (is_wp_error($get_response)) {
        return new WP_Error('ninja_read_error', 'Impossible de lire les données actuelles.', ['status' => 502]);
    }

    $current_data = json_decode(wp_remote_retrieve_body($get_response), true);
    // Invoice Ninja met parfois les données dans une clé 'data', parfois à la racine selon l'endpoint
    $company_data = isset($current_data['data']) ? $current_data['data'] : $current_data;

    // 3. Ensuite, on modifie juste l'adresse et le contact dans l'objet existant
    // On ne touche PAS à currency_id, language_id, etc.
    if (!isset($company_data['settings'])) { $company_data['settings'] = []; }

    // On écrase les anciennes valeurs par celles du formulaire PWA
    $company_data['settings']['address1'] = sanitize_text_field($params['address1']);
    $company_data['settings']['address2'] = sanitize_text_field($params['address2']);
    $company_data['settings']['city']     = sanitize_text_field($params['city']);
    $company_data['settings']['state']    = sanitize_text_field($params['state']);
    $company_data['settings']['postal_code'] = sanitize_text_field($params['postal_code']);
    
    // Si le pays est envoyé, on le met, sinon on garde l'ancien
    if (!empty($params['country_id'])) {
        $company_data['settings']['country_id'] = sanitize_text_field($params['country_id']);
    }

    $company_data['settings']['phone']    = sanitize_text_field($params['phone']);
    $company_data['settings']['email']    = sanitize_email($params['email']);

    // 4. ENFIN : On renvoie le tout (PUT)
    $put_response = wp_remote_request($url, [
        'method' => 'PUT',
        'headers' => $headers,
        'body' => json_encode($company_data), // On renvoie l'objet complet mis à jour
        'timeout' => 20
    ]);

    if (is_wp_error($put_response)) {
        return new WP_Error('ninja_write_error', $put_response->get_error_message(), ['status' => 502]);
    }

    $body = json_decode(wp_remote_retrieve_body($put_response), true);
    return new WP_REST_Response($body, 200);
}