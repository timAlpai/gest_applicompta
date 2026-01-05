<?php
register_rest_route('applicompta/v1', '/ia/devis', [
    'methods'  => 'POST',
    'callback' => 'applicompta_handle_ia_devis',
    'permission_callback' => 'applicompta_check_jwt_permission' // 👈 suffit !
]);

function applicompta_handle_ia_devis(WP_REST_Request $request) {
    $prompt = $request->get_param('prompt');
    if (!$prompt) {
        return new WP_Error('missing_prompt', 'Prompt requis', ['status' => 400]);
    }

    // 🔧 À adapter selon ton LLM :
    // Exemple avec un appel à Ollama, OpenRouter, ou ton propre endpoint
    $ia_response = call_your_llm_api($prompt);

    // Format attendu : texte ou JSON avec titre, description, lignes, etc.
    return new WP_REST_Response([
        'success' => true,
        'proposal' => $ia_response['text'] ?? 'Aucune réponse',
        // Optionnel : structure JSON pour remplir directement le devis
        // 'structured' => $ia_response['structured'] ?? null
    ]);
}

function call_your_llm_api($user_prompt) {
    // 🔐 À sécuriser : ne jamais exposer ta clé API côté client
    // Exemple avec un proxy backend vers Ollama :
    $system_prompt = "Tu es un expert comptable et commercial. L'utilisateur te décrit un besoin. Réponds en proposant un devis clair, avec titre, description, et liste de prestations. Sois concis et professionnel.";

    $body = json_encode([
        'model' => 'llama3.2', // ou ton modèle
        'prompt' => $system_prompt . "\n\nBesoin client : " . $user_prompt,
        'stream' => false
    ]);

    $ch = curl_init('http://localhost:11434/api/generate'); // ou ton endpoint LLM
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return ['text' => "Erreur de connexion à l'IA."];
    }

    $data = json_decode($response, true);
    return ['text' => $data['response'] ?? 'Pas de réponse.'];
}