<?php
function applicompta_generate_pdf($html_content) {
    $gotenberg_url = 'http://127.0.0.1:3000/forms/chromium/convert/html';
    
    // Création d'un fichier index.html temporaire pour Gotenberg
    $boundary = wp_generate_password(24);
    $payload = '';
    $payload .= '--' . $boundary . "\r\n";
    $payload .= 'Content-Disposition: form-data; name="files"; filename="index.html"' . "\r\n";
    $payload .= 'Content-Type: text/html' . "\r\n\r\n";
    $payload .= $html_content . "\r\n";
    $payload .= '--' . $boundary . '--';

    $response = wp_remote_post($gotenberg_url, [
        'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
        'body'    => $payload,
        'timeout' => 30
    ]);

    if (is_wp_error($response)) return false;
    return wp_remote_retrieve_body($response);
}