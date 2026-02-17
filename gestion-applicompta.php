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
/**
 * Charge les fichiers de traduction (.mo) depuis le dossier /languages
 */
function applicompta_load_textdomain() {
    load_plugin_textdomain('applicompta', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'applicompta_load_textdomain');
/**
 * Détecte la langue envoyée par la PWA et change le local de WordPress
 */
add_action('rest_api_init', function() {
    
    // Gérer les requêtes de pré-vérification (OPTIONS) immédiatement
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header("Access-Control-Allow-Origin: https://portal.applicompta.be");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, Accept-Language, X-App-Lang");
        status_header(204);
        exit;
    }

    // Détection de la langue
    $accept_lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'fr';
    $lang = substr($accept_lang, 0, 2);
    $locales = [
        'fr' => 'fr_FR', 'en' => 'en_US', 'nl' => 'nl_NL', 'es' => 'es_ES',
        'pl' => 'pl_PL', 'pt' => 'pt_PT', 'tr' => 'tr_TR', 'ro' => 'ro_RO'
    ];
    $locale = $locales[$lang] ?? 'fr_FR';
    
    unload_textdomain('applicompta');
    switch_to_locale($locale);
    load_plugin_textdomain('applicompta', false, dirname(plugin_basename(__FILE__)) . '/languages');

}, 0);

/**
 * Filtre de secours pour forcer le CORS sur TOUTES les réponses REST (même en cas de succès 200)
 */
add_filter('rest_pre_serve_request', function($value) {
    header("Access-Control-Allow-Origin: https://portal.applicompta.be");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, Accept-Language, X-App-Lang");
    return $value;
});
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

/**
 * Create DB tables for cash journal on plugin activation
 */
function applicompta_install_cash_journal() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_journal = $wpdb->prefix . 'gest_cash_journal';
    $table_entries = $wpdb->prefix . 'gest_cash_entries';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE $table_journal (\n" .
           " id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
           " date DATE NOT NULL,\n" .
           " opening_balance DECIMAL(20,2) DEFAULT 0.00,\n" .
           " closing_balance DECIMAL(20,2) DEFAULT 0.00,\n" .
           " total_in DECIMAL(20,2) DEFAULT 0.00,\n" .
           " total_out DECIMAL(20,2) DEFAULT 0.00,\n" .
           " is_closed TINYINT(1) DEFAULT 0,\n" .
           " created_by BIGINT(20) DEFAULT 0,\n" .
           " created_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n" .
           " updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n" .
           " PRIMARY KEY  (id),\n" .
           " UNIQUE KEY date_unique (date)\n" .
           ") $charset_collate;";

    dbDelta($sql);

    $sql2 = "CREATE TABLE $table_entries (\n" .
            " id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
            " journal_id BIGINT(20) UNSIGNED DEFAULT NULL,\n" .
            " uuid VARCHAR(100) DEFAULT NULL,\n" .
            " datetime DATETIME NOT NULL,\n" .
            " type VARCHAR(10) NOT NULL,\n" .
            " amount DECIMAL(20,2) NOT NULL,\n" .
            " currency VARCHAR(10) DEFAULT 'EUR',\n" .
            " vat_rate DECIMAL(5,2) DEFAULT 0.00,\n" .
            " category VARCHAR(100) DEFAULT NULL,\n" .
            " payment_method VARCHAR(50) DEFAULT NULL,\n" .
            " description TEXT,\n" .
            " receipt_url TEXT,\n" .
            " source VARCHAR(20) DEFAULT 'pwa',\n" .
            " created_by BIGINT(20) DEFAULT 0,\n" .
            " created_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n" .
            " synced TINYINT(1) DEFAULT 0,\n" .
            " PRIMARY KEY  (id),\n" .
            " KEY journal_idx (journal_id),\n" .
            " KEY uuid_idx (uuid)\n" .
            ") $charset_collate;";

    dbDelta($sql2);
}

register_activation_hook(__FILE__, 'applicompta_install_cash_journal');

// 3. Initialisation de l'API REST
require_once APPLICOMPTA_PLUGIN_DIR . 'Api/rest-routes.php';

// 1. GESTION CODE PWA (Alternative aux App Passwords cassés) 

// 1. Afficher le champ dans le profil
add_action('show_user_profile', 'gce_pwa_custom_password_field');
add_action('edit_user_profile', 'gce_pwa_custom_password_field');

function gce_pwa_custom_password_field($user) {
    ?>
    <h3><?php echo esc_html__('Connexion PWA Applicompta', 'applicompta'); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="pwa_access_code"><?php echo esc_html__('Code d\'accès PWA', 'applicompta'); ?></label></th>
            <td>
                <input type="text" name="pwa_access_code" id="pwa_access_code" value="" class="regular-text" placeholder="<?php echo esc_attr__("Définir un nouveau code ici", 'applicompta'); ?>" />
                <p class="description"><?php echo esc_html__("Utilisez ce code spécifique pour vous connecter à l'application mobile. (Laissez vide pour ne pas changer)", 'applicompta'); ?></p>
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
    <h3><?php echo esc_html__('Intégration Invoice Ninja v5', 'applicompta'); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="ninja_api_key"><?php echo esc_html__('Clé API (Token)', 'applicompta'); ?></label></th>
            <td>
                <input type="text" name="ninja_api_key" id="ninja_api_key" value="" class="regular-text" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
                <p class="description"><?php echo esc_html__('La clé sera chiffrée en base de données.', 'applicompta'); ?></p>
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

// 1. Chiffrement bidirectionnel pour le SMTP
function applicompta_smtp_encrypt($data) {
    if (empty($data)) return false;
    return base64_encode(openssl_encrypt($data, 'aes-256-cbc', APPLICOMPTA_SHARED_SECRET_KEY, 0, APPLICOMPTA_SHARED_IV));
}

function applicompta_smtp_decrypt($encrypted_data) {
    if (empty($encrypted_data)) return false;
    return openssl_decrypt(base64_decode($encrypted_data), 'aes-256-cbc', APPLICOMPTA_SHARED_SECRET_KEY, 0, APPLICOMPTA_SHARED_IV);
}

// 2. Champs Admin pour les réglages SMTP et Template
add_action('show_user_profile', 'applicompta_user_settings_fields');
add_action('edit_user_profile', 'applicompta_user_settings_fields');

function applicompta_user_settings_fields($user) {
    $smtp_pass = applicompta_smtp_decrypt(get_user_meta($user->ID, 'smtp_pass_enc', true));
    ?>
    <h3>Paramètres d'envoi Devis & Factures</h3>
    <table class="form-table">
        <tr>
            <th>SMTP Host / Port</th>
            <td>
                <input type="text" name="smtp_host" value="<?php echo esc_attr(get_user_meta($user->ID, 'smtp_host', true)); ?>" placeholder="smtp.domain.com" />
                <input type="number" name="smtp_port" value="<?php echo esc_attr(get_user_meta($user->ID, 'smtp_port', true)); ?>" placeholder="465" />
            </td>
        </tr>
        <tr>
            <th>SMTP User / Pass</th>
            <td>
                <input type="text" name="smtp_user" value="<?php echo esc_attr(get_user_meta($user->ID, 'smtp_user', true)); ?>" />
                <input type="password" name="smtp_pass" value="<?php echo esc_attr($smtp_pass); ?>" />
            </td>
        </tr>
        <tr>
            <th>Template HTML Personnel</th>
            <td>
                <textarea name="html_template" rows="10" style="width:100%; font-family:monospace;"><?php echo esc_textarea(get_user_meta($user->ID, 'html_template', true)); ?></textarea>
                <p class="description">Utilisez {{CLIENT_NAME}}, {{TOTAL}}, {{TABLE_ITEMS}}, {{SIGN_LINK}} comme variables.</p>
            </td>
        </tr>
    </table>
    <?php
}

add_action('personal_options_update', 'applicompta_save_user_settings');
add_action('edit_user_profile_update', 'applicompta_save_user_settings');

function applicompta_save_user_settings($user_id) {
    update_user_meta($user_id, 'smtp_host', sanitize_text_field($_POST['smtp_host']));
    update_user_meta($user_id, 'smtp_port', sanitize_text_field($_POST['smtp_port']));
    update_user_meta($user_id, 'smtp_user', sanitize_text_field($_POST['smtp_user']));
    if(!empty($_POST['smtp_pass'])) {
        update_user_meta($user_id, 'smtp_pass_enc', applicompta_smtp_encrypt($_POST['smtp_pass']));
    }
    update_user_meta($user_id, 'html_template', $_POST['html_template']);
}