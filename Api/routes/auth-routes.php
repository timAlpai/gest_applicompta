<?php
defined('ABSPATH') || exit;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

register_rest_route('applicompta/v1', '/auth/login', [
    'methods'  => 'POST',
    'callback' => 'applicompta_handle_login',
    'permission_callback' => '__return_true',
]);

function applicompta_handle_login(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $username = sanitize_user($params['username'] ?? '');
    $password = $params['password'] ?? ''; 

    if (empty($username) || empty($password)) {
        return new WP_Error('missing_creds', 'Identifiants manquants.', ['status' => 400]);
    }

    $user = get_user_by('login', $username);
    if (!$user) $user = get_user_by('email', $username);

    if (!$user) {
        return new WP_Error('invalid_user', 'Utilisateur introuvable.', ['status' => 403]);
    }

    $is_valid = false;

    // --- VÉRIFICATION VIA NOTRE CHAMP PERSONNALISÉ ---
    // C'est notre méthode prioritaire et stable
    global $wp_hasher;
    if (empty($wp_hasher)) {
        require_once ABSPATH . WPINC . '/class-phpass.php';
        $wp_hasher = new PasswordHash(8, true);
    }

    $stored_hash = get_user_meta($user->ID, 'pwa_access_code_hash', true);

    if ($stored_hash && $wp_hasher->CheckPassword($password, $stored_hash)) {
        $is_valid = true; // Succès via Code PWA
    }

    // --- FALLBACK : MOT DE PASSE PRINCIPAL ---
    if (!$is_valid && wp_check_password($password, $user->user_pass, $user->ID)) {
        $is_valid = true; // Succès via Mot de passe Admin
    }

    if (!$is_valid) {
        return new WP_Error('invalid_auth', 'Mot de passe incorrect.', ['status' => 403]);
    }

    // --- GÉNÉRATION JWT ---
    $issuedAt = time();
    $expire   = $issuedAt + 3600; 
    $secret   = defined('APPLICOMPTA_JWT_SECRET') ? APPLICOMPTA_JWT_SECRET : 'secret_fallback';
    
    $payload = [
        'iss' => get_bloginfo('url'),
        'iat' => $issuedAt,
        'exp' => $expire,
        'data' => [
            'user_id' => $user->ID,
            'email'   => $user->user_email,
            'name'    => $user->display_name,
            'roles'   => $user->roles
        ]
    ];

    try {
        $jwt = JWT::encode($payload, $secret, 'HS256');
    } catch (Exception $e) {
        return new WP_Error('jwt_error', 'Erreur technique.', ['status' => 500]);
    }

    return new WP_REST_Response([
        'success' => true,
        'token'   => $jwt,
        'user'    => [
            'id' => $user->ID,
            'display_name' => $user->display_name,
            'email' => $user->user_email
        ]
    ], 200);
}

register_rest_route('applicompta/v1', '/auth/refresh', [
    'methods'  => 'POST',
    'callback' => 'applicompta_handle_refresh',
    'permission_callback' => '__return_true', // On traite la validation JWT dans le callback
]);

function applicompta_handle_refresh(WP_REST_Request $request) {
    $auth_header = $request->get_header('Authorization');
    if (!$auth_header || !preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        return new WP_Error('missing_token', 'Token manquant.', ['status' => 400]);
    }

    $jwt = $matches[1];
    $secret = defined('APPLICOMPTA_JWT_SECRET') ? APPLICOMPTA_JWT_SECRET : 'secret_fallback';

    try {
        // On utilise **sans vérifier 'exp'** pour permettre le refresh juste avant expiration
        // Mais on **doit quand même valider la signature et le format**
 
        $decoded = JWT::decode($jwt, new Key($secret, 'HS256'));

        // On autorise le refresh **tant que le token n’est pas trop vieux**
        // → ici, on autorise si expiré depuis moins de 5 minutes (grâce à `leeway`)
        // Mais pour plus de contrôle, on peut aussi vérifier manuellement `exp`
        $now = time();
        if ($decoded->exp < $now - 300) { // refus si expiré depuis +5 min
            return new WP_Error('token_expired', 'Token expiré.', ['status' => 403]);
        }

        // On récupère les données utilisateur via l’ID (sécurité supplémentaire)
        $user = get_user_by('ID', $decoded->data->user_id);
        if (!$user) {
            return new WP_Error('invalid_user', 'Utilisateur introuvable.', ['status' => 403]);
        }

        // Générer un **nouveau token** avec +60 minutes
        $issuedAt = time();
        $expire   = $issuedAt + 3600; // 60 min
        $payload = [
            'iss' => get_bloginfo('url'),
            'iat' => $issuedAt,
            'exp' => $expire,
            'data' => [
                'user_id' => $user->ID,
                'email'   => $user->user_email,
                'name'    => $user->display_name,
                'roles'   => $user->roles
            ]
        ];

        $new_jwt = JWT::encode($payload, $secret, 'HS256');

        return new WP_REST_Response([
            'success' => true,
            'token'   => $new_jwt,
            'expires_in' => 3600
        ], 200);

    } catch (Exception $e) {
        return new WP_Error('invalid_token', 'Token invalide ou corrompu.', ['status' => 403]);
    }
}