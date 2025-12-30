<?php
/**
 * Plugin Name: Gestionnaire d'Application pour le systeme applicompta
 * Plugin URI: https://applicompta.be
 * Description: Un plugin simple pour afficher.
 * Version: 1.0
 * Author: T&G Consulting
 * Author URI: 
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Sortie si accès direct
    exit; 
}

error_log("🚀 [APPLICOMPTA] Le plugin principal est chargé !");

// 1. Chargement de l'autoloader Composer (pour JWT)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// 2. Chargement des secrets
if (file_exists(__DIR__ . '/applicompta-secrets.php')) {
    require_once __DIR__ . '/applicompta-secrets.php';
}

// Constantes globales
define('APPLICOMPTA_VERSION', '1.0.0');
define('APPLICOMPTA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('APPLICOMPTA_PLUGIN_URL', plugin_dir_url(__FILE__));

// 3. Initialisation de l'API REST
require_once APPLICOMPTA_PLUGIN_DIR . 'Api/rest-routes.php';

// 1. GESTION CODE PWA (Alternative aux App Passwords cassés) 

// 1. Afficher le champ dans le profil
add_action('show_user_profile', 'gce_pwa_custom_password_field');
add_action('edit_user_profile', 'gce_pwa_custom_password_field');

function gce_pwa_custom_password_field($user) {
    ?>
    <h3>Connexion PWA Applicompta</h3>
    <table class="form-table">
        <tr>
            <th><label for="pwa_access_code">Code d'accès PWA</label></th>
            <td>
                <input type="text" name="pwa_access_code" id="pwa_access_code" value="" class="regular-text" placeholder="Définir un nouveau code ici" />
                <p class="description">Utilisez ce code spécifique pour vous connecter à l'application mobile. (Laissez vide pour ne pas changer)</p>
            </td>
        </tr>
    </table>
    <?php
}

// 2. Sauvegarder le code (Hashé pour la sécurité)
add_action('personal_options_update', 'gce_save_pwa_custom_password');
add_action('edit_user_profile_update', 'gce_save_pwa_custom_password');

function gce_save_pwa_custom_password($user_id) {
    if (!current_user_can('edit_user', $user_id)) return false;

    if (!empty($_POST['pwa_access_code'])) {
        // On hache le mot de passe avec le hasher standard WP (phpass) qui est fiable
        global $wp_hasher;
        if (empty($wp_hasher)) {
            require_once ABSPATH . WPINC . '/class-phpass.php';
            $wp_hasher = new PasswordHash(8, true);
        }
        $hash = $wp_hasher->HashPassword(trim($_POST['pwa_access_code']));
        update_user_meta($user_id, 'pwa_access_code_hash', $hash);
    }
}

function applicompta_encrypt($data) {
    if (empty($data)) return false;
    return base64_encode(openssl_encrypt($data, 'aes-256-cbc', APPLICOMPTA_SHARED_SECRET_KEY, 0, APPLICOMPTA_SHARED_IV));
}

function applicompta_decrypt($encrypted_data) {
    if (empty($encrypted_data)) return false;
    return openssl_decrypt(base64_decode($encrypted_data), 'aes-256-cbc', APPLICOMPTA_SHARED_SECRET_KEY, 0, APPLICOMPTA_SHARED_IV);
}

// 2. INTERFACE ADMIN : CHAMP CLÉ API INVOICE NINJA
add_action('show_user_profile', 'applicompta_add_ninja_field');
add_action('edit_user_profile', 'applicompta_add_ninja_field');

function applicompta_add_ninja_field($user) {
    // On vérifie si une clé existe déjà (on ne l'affiche pas en clair pour la sécurité)
    $encrypted_key = get_user_meta($user->ID, 'invoiceninja_token_encrypted', true);
    $placeholder = $encrypted_key ? "Une clé est déjà enregistrée (laissez vide pour conserver)" : "Collez la clé API ici";
    ?>
    <h3>Intégration Invoice Ninja v5</h3>
    <table class="form-table">
        <tr>
            <th><label for="ninja_api_key">Clé API (Token)</label></th>
            <td>
                <input type="text" name="ninja_api_key" id="ninja_api_key" value="" class="regular-text" placeholder="<?php echo esc_attr($placeholder); ?>" />
                <p class="description">La clé sera chiffrée en base de données.</p>
            </td>
        </tr>
    </table>
    <?php
}

add_action('personal_options_update', 'applicompta_save_ninja_field');
add_action('edit_user_profile_update', 'applicompta_save_ninja_field');

function applicompta_save_ninja_field($user_id) {
    if (!current_user_can('edit_user', $user_id)) return false;

    if (!empty($_POST['ninja_api_key'])) {
        $token = sanitize_text_field($_POST['ninja_api_key']);
        $encrypted = applicompta_encrypt($token);
        update_user_meta($user_id, 'invoiceninja_token_encrypted', $encrypted);
    }
}