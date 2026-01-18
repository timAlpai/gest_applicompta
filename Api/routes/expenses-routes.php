<?php
defined('ABSPATH') || exit;

// 1. Route pour l'analyse du ticket par Llama 4 Vision
register_rest_route('applicompta/v1', '/ia/scan-receipt', [
    'methods'  => 'POST',
    'callback' => 'applicompta_handle_receipt_scan',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

// 2. Route pour l'enregistrement final dans Invoice Ninja
register_rest_route('applicompta/v1', '/ninja/expenses', [
    'methods'  => 'POST',
    'callback' => 'applicompta_create_ninja_expense',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

function applicompta_handle_receipt_scan(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $image_data = $params['image'] ?? ''; 

    if (empty($image_data)) {
        return new WP_Error('no_image', 'Image manquante', ['status' => 400]);
    }

    // Nettoyage du base64 (on retire le préfixe data:image/jpeg;base64,)
    $base64_clean = preg_replace('#^data:image/[^;]+;base64,#', '', $image_data);

    try {
        $client = \OpenAI::factory()
            ->withApiKey(GROQ_API_KEY)
            ->withBaseUri(GROQ_BASE_URL)
            ->make();

        // Utilisation du modèle Llama 4 Maverick (le plus performant cité dans votre doc)
       $response = $client->chat()->create([
    'model' => 'meta-llama/llama-4-maverick-17b-128e-instruct',
    'messages' => [
        [
            'role' => 'system',
            'content' => 'Tu es un expert en reconnaissance de texte et en extraction de données à partir d\'images de tickets de caisse ou de facture. Ton objectif est d\'extraire les informations pertinentes et de les structurer au format JSON selon les instructions fournies.'
        ],
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text', 
                    'text' => "Analyse ce ticket de caisse. Extrais les informations au format JSON pur uniquement.



STRUCTURE JSON :
{
  \"vendor\": \"\",
  \"vendor_address\": \"\",
  \"vendor_vat\": \"\",
  \"date\": \"\",
  \"payment_method\": \"\",
  \"fiscal_id\": \"\",
  \"total_amount\": 0.00,
  \"line_items\": [
    {
      \"notes\": \"\",
      \"cost\": 0.00,
      \"qty\": 0,
      \"tax_name\": \"\"
    }
  ]
}
Si une info est absente, retourne une chaîne vide \"\". Ne réponds aucun texte en dehors du JSON."
                ],
                [
                    'type' => 'image_url',
                    'image_url' => ['url' => "data:image/jpeg;base64,$base64_clean"]
                ]
            ]
        ]
    ],
    'response_format' => ['type' => 'json_object'],
    'temperature' => 0.1,
]);
        $content = $response->choices[0]->message->content;
        $decoded_data = json_decode($content, true);

        return new WP_REST_Response([
            'success' => true,
            'data'    => $decoded_data
        ], 200);

    } catch (Exception $e) {
        error_log('Erreur Vision Groq : ' . $e->getMessage());
        return new WP_Error('vision_error', $e->getMessage(), ['status' => 500]);
    }
}
function applicompta_create_ninja_expense(WP_REST_Request $request) {
    $token = applicompta_get_ninja_token();
    $params = $request->get_json_params();
    $baseUrl = rtrim(INVOICENINJA_API_URL, '/');

    $vendor_id = $params['vendor_id'];

    // 1. SI LE FOURNISSEUR N'EXISTE PAS -> ON LE CRÉE
    if (empty($vendor_id) && !empty($params['vendor_name'])) {
        $vendor_payload = [
            'name' => sanitize_text_field($params['vendor_name']),
            'address1' => sanitize_text_field($params['vendor_address']),
            'vat_number' => sanitize_text_field($params['vendor_vat'])
        ];
        $v_res = wp_remote_post("$baseUrl/vendors", [
            'headers' => ['X-API-Token' => $token, 'Content-Type' => 'application/json'],
            'body' => json_encode($vendor_payload)
        ]);
        $v_data = json_decode(wp_remote_retrieve_body($v_res), true);
        $vendor_id = $v_data['data']['id'] ?? null;
    }

    // 2. CRÉATION DE LA DÉPENSE DÉTAILLÉE
    $payload = [
        'vendor_id'   => $vendor_id,
        'category_id' => $params['category_id'],
        'client_id'   => $params['client_id'],
        'project_id'  => $params['project_id'],
        'date'        => $params['date'],
        'expense_items' => $params['items'], // Tes 14 lignes détaillées
        'public_notes' => $params['public_notes'], // Tes métadonnées OCR
        'is_paid'     => $params['is_paid'],
        'should_be_invoiced' => $params['should_be_invoiced']
    ];

    $response = wp_remote_post("$baseUrl/expenses", [
        'headers' => ['X-API-Token' => $token, 'Content-Type' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
        'body' => json_encode($payload)
    ]);

    return new WP_REST_Response(json_decode(wp_remote_retrieve_body($response), true), 200);
}

// NOUVELLE ROUTE : Ressources pour le formulaire de dépenses
register_rest_route('applicompta/v1', '/ninja/expense-resources', [
    'methods'  => 'GET',
    'callback' => 'applicompta_get_expense_resources',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

function applicompta_get_expense_resources(WP_REST_Request $request) {
    $token = applicompta_get_ninja_token();
    if (is_wp_error($token)) return $token;

    $baseUrl = rtrim(INVOICENINJA_API_URL, '/');
    $headers = [
        'X-API-Token' => $token,
        'X-Requested-With' => 'XMLHttpRequest',
        'Content-Type' => 'application/json',
    ];

    // Appel groupé vers Invoice Ninja pour remplir les selects du formulaire
    // On demande 1000 items pour être sûr d'avoir tout
    $v_res = wp_remote_get("$baseUrl/vendors?per_page=1000", ['headers' => $headers, 'timeout' => 20]);
    $cat_res = wp_remote_get("$baseUrl/expense_categories?per_page=1000", ['headers' => $headers, 'timeout' => 20]);
    $prj_res = wp_remote_get("$baseUrl/projects?per_page=1000", ['headers' => $headers, 'timeout' => 20]);
    $clt_res = wp_remote_get("$baseUrl/clients?per_page=1000", ['headers' => $headers, 'timeout' => 20]);

    // Décodage
    $vendors = json_decode(wp_remote_retrieve_body($v_res), true);
    $categories = json_decode(wp_remote_retrieve_body($cat_res), true);
    $projects = json_decode(wp_remote_retrieve_body($prj_res), true);
    $clients = json_decode(wp_remote_retrieve_body($clt_res), true);

    return new WP_REST_Response([
        'vendors'    => $vendors['data'] ?? [],
        'categories' => $categories['data'] ?? [],
        'projects'   => $projects['data'] ?? [],
        'clients'    => $clients['data'] ?? []
    ], 200);
}