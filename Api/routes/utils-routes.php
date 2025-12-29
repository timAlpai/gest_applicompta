<?php
defined('ABSPATH') || exit;

// Route : GET /wp-json/applicompta/v1/utils/lists
register_rest_route('applicompta/v1', '/utils/lists', [
    'methods'  => 'GET',
    'callback' => 'applicompta_get_dynamic_lists',
    'permission_callback' => 'applicompta_check_jwt_permission', // Sécurisé
]);

function applicompta_get_dynamic_lists(WP_REST_Request $request) {
    // 1. Vérifier si les données sont déjà en cache (pour la vitesse)
    // On garde le cache 7 jours (60 * 60 * 24 * 7)
    $cached_data = get_transient('ninja_static_lists');
    
    if (false !== $cached_data) {
        return new WP_REST_Response($cached_data, 200);
    }

    // 2. Si pas de cache, on va chercher les données chez Invoice Ninja
    // On a besoin d'un token pour interroger l'API. On prend celui de l'utilisateur courant.
    $user_id = get_current_user_id();
    $encrypted_token = get_user_meta($user_id, 'invoiceninja_token_encrypted', true);
    
    if (!$encrypted_token) {
        return new WP_Error('no_token', 'Token requis pour charger les listes.', ['status' => 403]);
    }
    $api_token = applicompta_decrypt($encrypted_token);

    // L'endpoint /statics contient TOUTES les définitions (Pays, Devises, Langues...)
    $url = rtrim(INVOICENINJA_API_URL, '/') . '/statics';

    $response = wp_remote_get($url, [
        'headers' => [
            'X-API-Token' => $api_token,
            'X-Requested-With' => 'XMLHttpRequest',
            'Content-Type' => 'application/json',
        ],
        'timeout' => 30 // C'est une grosse requête
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('ninja_api_error', $response->get_error_message(), ['status' => 502]);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $data = isset($body['data']) ? $body['data'] : $body;

    // 3. On extrait et nettoie seulement ce dont on a besoin
    // Invoice Ninja renvoie beaucoup trop de données, on allège pour le mobile
    
    // Pays
    $countries = [];
    if (!empty($data['countries'])) {
        foreach ($data['countries'] as $c) {
            $countries[] = [
                'id' => $c['id'],
                'name' => $c['name'],
                'iso_3166_2' => $c['iso_3166_2'] // Code à 2 lettres (BE, FR) utile pour l'affichage
            ];
        }
        // Tri alphabétique par nom
        usort($countries, function($a, $b) { return strcmp($a['name'], $b['name']); });
    }

    // Devises
    $currencies = [];
    if (!empty($data['currencies'])) {
        foreach ($data['currencies'] as $c) {
            $currencies[] = [
                'id' => $c['id'],
                'name' => $c['name'],
                'code' => $c['code'], // EUR, USD
                'symbol' => $c['symbol'] // €, $
            ];
        }
    }

    $clean_lists = [
        'countries' => $countries,
        'currencies' => $currencies
    ];

    // 4. On sauvegarde en cache et on renvoie
    set_transient('ninja_static_lists', $clean_lists, 60 * 60 * 24 * 7);

    return new WP_REST_Response($clean_lists, 200);
}