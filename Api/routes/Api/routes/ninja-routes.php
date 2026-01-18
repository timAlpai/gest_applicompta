<?php
// Récupérer les ressources pour les dépenses
register_rest_route('applicompta/v1', '/ninja/expense-resources', [
    'methods'  => 'GET',
    'callback' => 'applicompta_get_expense_resources',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

function applicompta_get_expense_resources() {
    $token = applicompta_get_ninja_token();
    $baseUrl = rtrim(INVOICENINJA_API_URL, '/');

    // On récupère tout en parallèle (ou séquentiel pour simplifier)
    $vendors = json_decode(wp_remote_retrieve_body(wp_remote_get("$baseUrl/vendors", ['headers' => ['X-API-Token' => $token]])));
    $categories = json_decode(wp_remote_retrieve_body(wp_remote_get("$baseUrl/expense_categories", ['headers' => ['X-API-Token' => $token]])));
    $projects = json_decode(wp_remote_retrieve_body(wp_remote_get("$baseUrl/projects", ['headers' => ['X-API-Token' => $token]])));

    return new WP_REST_Response([
        'vendors'    => $vendors->data ?? [],
        'categories' => $categories->data ?? [],
        'projects'   => $projects->data ?? []
    ], 200);
}