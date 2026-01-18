<?php
defined('ABSPATH') || exit;

register_rest_route('applicompta/v1', '/ia/devis', [
    'methods'  => 'POST',
    'callback' => 'applicompta_handle_ia_devis',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

function applicompta_handle_ia_devis(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $user_prompt = sanitize_textarea_field($params['prompt'] ?? '');

    if (!defined('GROQ_API_KEY') || !defined('GROQ_BASE_URL')) {
        return new WP_Error('config_error', 'Configuration Groq manquante.', ['status' => 500]);
    }

    try {
        $client = \OpenAI::factory()
            ->withApiKey(GROQ_API_KEY)
            ->withBaseUri(GROQ_BASE_URL) 
            ->make();

        // PROMPT RENFORCÉ : On donne des instructions de calcul et un exemple
        $system_prompt = "Tu es un assistant de facturation. Tu dois transformer une description en devis structuré.
        INSTRUCTIONS :
        1. Calcule les quantités totales (ex: 16h x 3 hommes = 48).
        2. Réponds UNIQUEMENT par un objet JSON valide.
        3. N'ajoute AUCUN texte avant ou après le JSON.

        STRUCTURE ATTENDUE :
        {
          \"public_notes\": \"Titre ou résumé du projet\",
          \"line_items\": [
            { \"notes\": \"Description précise\", \"cost\": 100.00, \"quantity\": 1 }
          ]
        }

        EXEMPLE DE SORTIE :
        { \"public_notes\": \"Peinture salon\", \"line_items\": [{ \"notes\": \"Main d'oeuvre\", \"cost\": 50, \"quantity\": 10 }] }";

        $response = $client->chat()->create([
            'model' => defined('GROQ_MODEL') ? GROQ_MODEL : 'llama-3.3-70b-versatile',
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                // On rappelle au modèle de rester en JSON dans le prompt utilisateur
                ['role' => 'user', 'content' => "Génère le JSON pour ce besoin : " . $user_prompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.1, // On baisse encore la température pour plus de rigueur
        ]);

        $raw_json = $response->choices[0]->message->content;
        $decoded = json_decode($raw_json, true);

        // Vérification si le décodage a fonctionné
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("L'IA a renvoyé un format JSON invalide.");
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => $decoded
        ], 200);

    } catch (Exception $e) {
        error_log('Erreur IA Applicompta : ' . $e->getMessage());
        return new WP_Error('ia_api_error', 'Erreur : ' . $e->getMessage(), ['status' => 500]);
    }
}