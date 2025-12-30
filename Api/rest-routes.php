<?php
defined('ABSPATH') || exit;
error_log("🚀 [APPLICOMPTA] Le rest-routes est chargé !");

/**
 * Enregistrement des routes API lors de l'initialisation REST de WordPress
 */
add_action('rest_api_init', function () {
    
    // Inclusion de la route d'authentification
    require_once __DIR__ . '/routes/auth-routes.php';
    require_once __DIR__ . '/routes/ninja-routes.php'; 
    require_once __DIR__ . '/routes/utils-routes.php'; 
    require_once __DIR__ . '/routes/clients-routes.php';
    require_once __DIR__ . '/routes/documents-routes.php';
    // Ici, vous pourrez ajouter d'autres fichiers de routes plus tard :
    // require_once __DIR__ . '/routes/comptes-routes.php';
    // require_once __DIR__ . '/routes/factures-routes.php';


     register_rest_route('applicompta/v1', '/test', [
        'methods'  => 'GET',
        'callback' => function() {
            return [
                'success' => true,
                'message' => 'Hello World! Le plugin est bien connecté.'
            ];
        },
        'permission_callback' => '__return_true', // Public
    ]);
});
