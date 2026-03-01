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
    $lang = sanitize_text_field($params['lang'] ?? '');

    if (!defined('GROQ_API_KEY') || !defined('GROQ_BASE_URL')) {
        return new WP_Error('config_error', __('Configuration Groq manquante.', 'applicompta'), ['status' => 500]);
    }

    try {
        $client = \OpenAI::factory()
            ->withApiKey(GROQ_API_KEY)
            ->withBaseUri(GROQ_BASE_URL) 
            ->make();

        // PROMPT RENFORCÉ : on précise un ton professionnel et des descriptions riches
        $system_prompt = "Tu es un assistant professionnel de génération de devis pour des petites entreprises. \n" .
            "Lorsque l'utilisateur décrit un besoin (produits ou services), tu dois générer un objet JSON complet représentant un devis \"prêt à envoyer\". \n" .
            "Le texte (public_notes et notes de lignes) doit être rédigé dans un style clair, poli et commercial, avec des phrases complètes. \n" .
            "Évite les abréviations ou listes sèches : chaque élément doit ressembler à une ligne d'un devis professionnel. \n" .
            "Tu réponds STRICTEMENT par un JSON valide, sans aucun autre texte.\n\n" .
            "INSTRUCTIONS :\n" .
            "1. Calcule les quantités totales si l'utilisateur les mentionne (ex : 16h x 3 hommes = 48).\n" .
            "2. Fournis un champ \"public_notes\" contenant un court paragraphe de présentation du projet.\n" .
            "3. Dans l'array \"line_items\", chaque objet doit contenir :\n" .
            "   - \"notes\" : description professionnelle du service/produit (une ou deux phrases).\n" .
            "   - \"cost\" : montant numérique sans symbole de devise.\n" .
            "   - \"quantity\" : nombre.\n" .
            "4. N'ajoute AUCUN texte avant ou après le JSON. Aucune balise, aucun commentaire.\n\n" .
            "STRUCTURE ATTENDUE :\n" .
            "{\n" .
            "  \"public_notes\": \"Titre ou résumé du projet\",\n" .
            "  \"line_items\": [\n" .
            "    { \"notes\": \"Description précise\", \"cost\": 100.00, \"quantity\": 1 }\n" .
            "  ]\n" .
            "}\n\n" .
            "EXEMPLE DE SORTIE :\n" .
            "{ \"public_notes\": \"Peinture salon avec préparation soignée des surfaces et deux couches de peinture acrylique.\", \"line_items\": [{ \"notes\": \"Main d'oeuvre : ponçage, apprêt et application de peinture (20 m²)\", \"cost\": 50, \"quantity\": 10 }] }";

        // si une langue de sortie est précisée, on la demande explicitement au modèle
        if ($lang) {
            $languageNames = [
                'fr' => 'français',
                'en' => 'anglais',
                'nl' => 'néerlandais',
                'es' => 'espagnol',
                'pt' => 'portugais',
                'tr' => 'turc',
                'ro' => 'roumain',
                'pl' => 'polonais'
            ];
            $target = $languageNames[$lang] ?? $lang;
            $system_prompt .= "\n\nRédige les textes (public_notes et notes des lignes) en $target.";
        }

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
        error_log( sprintf(__('Erreur IA Applicompta : %s', 'applicompta'), $e->getMessage()) );
        return new WP_Error('ia_api_error', sprintf( __('Erreur : %s', 'applicompta'), $e->getMessage() ), ['status' => 500]);
    }
}

register_rest_route('applicompta/v1', '/ia/devis_langue', [
    'methods'  => 'POST',
    'callback' => 'applicompta_handle_ia_devis_langue',
    'permission_callback' => 'applicompta_check_jwt_permission',
]);

function applicompta_handle_ia_devis_langue(WP_REST_Request $request) {
    $params = $request->get_json_params();
    $user_prompt = sanitize_textarea_field($params['prompt'] ?? '');
    

    if (!defined('GROQ_API_KEY') || !defined('GROQ_BASE_URL')) {
        return new WP_Error('config_error', __('Configuration Groq manquante.', 'applicompta'), ['status' => 500]);
    }
    try {
        $client = \OpenAI::factory()
            ->withApiKey(GROQ_API_KEY)
            ->withBaseUri(GROQ_BASE_URL) 
            ->make();

        $response = $client->chat()->create([
            'model' => defined('GROQ_MODEL') ? GROQ_MODEL : 'llama-3.3-70b-versatile',
            'messages' => [
                ['role' => 'system', 'content' => "Tu es un assistant de traduction professionnel. detecte la langue utilisée dans la description des produits."],
                ['role' => 'user', 'content' => "dans quelle langue est ce texte  ? : " . $user_prompt . "réponds uniquement par le code iso de la langue, sans autre texte ni ponctuation."],
            ],
            'temperature' => 0.1,
        ]);

        $translated_text = $response->choices[0]->message->content;

        return new WP_REST_Response([
            'success' => true,
            'data'    => ['translated_text' => $translated_text]
        ], 200);

    } catch (Exception $e) {
        error_log( sprintf(__('Erreur IA Applicompta : %s', 'applicompta'), $e->getMessage()) );
        return new WP_Error('ia_api_error', sprintf( __('Erreur : %s', 'applicompta'), $e->getMessage() ), ['status' => 500]);
    }

}//fin function