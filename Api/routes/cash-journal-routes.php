<?php
defined('ABSPATH') || exit;


    $namespace = 'applicompta/v1';
    
    // On utilise obligatoirement applicompta_check_jwt_permission 
    // qui est déjà définie dans ninja-routes.php
    $auth_callback = 'applicompta_check_jwt_permission';

    register_rest_route($namespace, '/cash-journal', [
        'methods' => 'GET',
        'callback' => 'applicompta_get_cash_journal',
        'permission_callback' => $auth_callback
    ]);

    register_rest_route($namespace, '/cash-journal/entries', [
        'methods' => 'POST',
        'callback' => 'applicompta_create_cash_entry',
        'permission_callback' => $auth_callback
    ]);

    register_rest_route($namespace, '/cash-journal/close', [
        'methods' => 'POST',
        'callback' => 'applicompta_close_cash_journal',
        'permission_callback' => $auth_callback
    ]);
    
    register_rest_route($namespace, '/cash-journal/entries/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'applicompta_delete_cash_entry',
        'permission_callback' => $auth_callback
    ]);

    register_rest_route('applicompta/v1', '/cash-journal/report/(?P<id>\d+)', [
    'methods'  => 'GET',
    'callback' => 'applicompta_get_z_report_pdf',
    'permission_callback' => $auth_callback
    
    ]);

function applicompta_get_cash_journal($request) {
    global $wpdb;
    
    // 1. Récupérer les paramètres
    $date = $request->get_param('date') ?: date('Y-m-d');
    $user_id = get_current_user_id(); // Très important pour le multi-tenant
    
    // 2. Définir les noms de tables
    $table_journal = $wpdb->prefix . 'gest_cash_journal';
    $table_entries = $wpdb->prefix . 'gest_cash_entries';

    // 3. Récupérer le résumé du journal (L'en-tête bleu)
    $journal = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_journal WHERE date = %s AND created_by = %d", 
        $date, $user_id
    ));

    // 4. Récupérer les entrées (La liste) avec la correction pour le bouton annuler
    // On s'assure de filtrer par date ET par utilisateur
    $entries = $wpdb->get_results($wpdb->prepare(
        "SELECT e.*, 
        (SELECT COUNT(*) FROM $table_entries WHERE parent_id = e.id AND status = 'storno') as is_already_cancelled
        FROM $table_entries e 
        WHERE DATE(e.datetime) = %s 
        AND e.created_by = %d 
        ORDER BY e.datetime ASC", 
        $date, $user_id
    ));

    // 5. Retourner la réponse
    return rest_ensure_response([
        'success' => true,
        'date'    => $date,
        'journal' => $journal,
        'entries' => $entries ? $entries : [] // Retourne un tableau vide si rien n'est trouvé
    ]);
}



function applicompta_create_cash_entry($request) {
    global $wpdb;
    $data = json_decode($request->get_body(), true);
    $table_entries = $wpdb->prefix . 'gest_cash_entries';
    $datetime = date('Y-m-d H:i:s', strtotime($data['datetime']));
    $date_only = date('Y-m-d', strtotime($datetime));
    $table_journal = $wpdb->prefix . 'gest_cash_journal';
    $user_id = get_current_user_id();
    // Basic validation
    if (empty($data['datetime']) || empty($data['type']) || !isset($data['amount'])) {
        return new WP_Error('invalid_data', 'Missing required fields', ['status' => 400]);
    }

    // sanitize and validate fields
    $dt = strtotime($data['datetime']);
    if ($dt === false) return new WP_Error('invalid_datetime', 'Invalid datetime', ['status' => 400]);
    $type = sanitize_text_field($data['type']);
    if (!in_array($type, ['in','out'])) return new WP_Error('invalid_type', 'Invalid type', ['status' => 400]);
    $amount = floatval($data['amount']);
    if (!is_numeric($data['amount']) || $amount <= 0) return new WP_Error('invalid_amount', 'Invalid amount', ['status' => 400]);

    $open_past_journal = $wpdb->get_var($wpdb->prepare(
    "SELECT date FROM $table_journal 
     WHERE created_by = %d AND is_closed = 0 AND date < %s 
     ORDER BY date ASC LIMIT 1",
    $user_id, $date_only
));

if ($open_past_journal) {
    return new WP_Error('past_journal_open', 
        sprintf(__('Vous devez clôturer le journal du %s avant de saisir de nouvelles opérations.', 'applicompta'), $open_past_journal), 
        ['status' => 403]
    );
}

    // Prevent creating entries for closed journals
       // --- 1. TROUVER OU CRÉER LE JOURNAL POUR CETTE DATE ---
    $journal = $wpdb->get_row($wpdb->prepare(
        "SELECT id, is_closed FROM $table_journal WHERE date = %s AND created_by = %d",
        $date_only, $user_id
    ));

    if ($journal && $journal->is_closed) {
        return new WP_Error('journal_closed', 'Le journal de cette date est clôturé.', ['status' => 403]);
    }

    if (!$journal) {
        // Création automatique du journal s'il n'existe pas
        $wpdb->insert($table_journal, [
            'date' => $date_only,
            'created_by' => get_current_user_id(),
            'opening_balance' => 0, // Idéalement, récupérer le closing de la veille
            'created_at' => current_time('mysql')
        ]);
        $journal_id = $wpdb->insert_id;
    } else {
        $journal_id = $journal->id;
    }
    $entry_date = date('Y-m-d', $dt);
    $table_journal = $wpdb->prefix . 'gest_cash_journal';
    $existing_j = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_journal WHERE date = %s", $entry_date));
    if ($existing_j && intval($existing_j->is_closed) === 1) {
        return new WP_Error('journal_closed', 'Journal is closed for this date', ['status' => 403]);
    }

 

    
    //debut
    // 1. Récupérer le row_hash de la dernière entrée insérée (peu importe la date)
$last_entry = $wpdb->get_row("SELECT row_hash FROM $table_entries ORDER BY id DESC LIMIT 1");
$prev_hash = $last_entry ? $last_entry->row_hash : '0000000000000000000000000000000000000000000000000000000000000000';

// 2. Préparer les données pour le hash (tous les champs critiques)
$data_to_hash = $prev_hash . 
                $data['datetime'] . 
                $type . 
                $amount . 
                $data['description'];

// 3. Calculer le hash SHA-256
$row_hash = hash('sha256', $data_to_hash);

// 4. Ajouter au tableau d'insertion
$insert_data = [
    'journal_id' => $journal_id,
        'uuid' => sanitize_text_field($data['uuid'] ?? ''),
        'datetime' => date('Y-m-d H:i:s', $dt),
        'type' => $type,
        'amount' => $amount,
        'currency' => sanitize_text_field($data['currency'] ?? 'EUR'),
        'vat_rate' => floatval($data['vat_rate'] ?? 0),
        'category' => sanitize_text_field($data['category'] ?? ''),
        'payment_method' => sanitize_text_field($data['payment_method'] ?? ''),
        'description' => sanitize_textarea_field($data['description'] ?? ''),
        'receipt_url' => sanitize_text_field($data['receipt_url'] ?? ''),
        'source' => sanitize_text_field($data['source'] ?? 'pwa'),
        'created_by' => get_current_user_id(),
        'created_at' => current_time('mysql'), 
        'synced' => 1,
        'prev_hash' => $prev_hash,
        'row_hash'  => $row_hash,
        'status'    => 'active'
];
            
$inserted = $wpdb->insert($table_entries, $insert_data);
if ($inserted === false) {
        return new WP_Error('db_error', 'DB insert failed', ['status' => 500]);
    }
    //fin

    $id = $wpdb->insert_id;
    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_entries WHERE id = %d", $id));

    // Recalculate journal totals for the entry date
    $entry_date = date('Y-m-d', strtotime($data['datetime']));
    $journal = applicompta_recalc_journal($entry_date);
    applicompta_audit_log('CREATE_ENTRY', 'CASH_ENTRY', $wpdb->insert_id, "Nouvelle entrée de " . $amount . "€");
    return rest_ensure_response([ 'success' => true, 'entry' => $entry, 'journal' => $journal ]);
}



function applicompta_delete_cash_entry($request) {
    global $wpdb;
    $user_id = get_current_user_id();
    $id_to_cancel = intval($request['id']);
    $table_entries = $wpdb->prefix . 'gest_cash_entries';
    $uuid = sanitize_text_field($request->get_param('uuid') ?? '');

    // 1. Vérifier si cette ligne n'a pas DÉJÀ été annulée par une autre ligne
    $already_cancelled = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_entries WHERE parent_id = %d AND status = 'storno'", 
        $id_to_cancel
    ));

    if ($already_cancelled) {
        return new WP_Error('already_storno', 'Cette opération a déjà été annulée.', ['status' => 400]);
    }
    if (!empty($uuid)) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_entries WHERE uuid = %s", $uuid));
        if ($exists) return rest_ensure_response(['success' => true, 'message' => 'Déjà traité']);
    }
    // 1. Récupérer l'entrée originale
    $original = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_entries WHERE id = %d AND created_by = %d", 
        $id_to_cancel, $user_id
    ));

    if (!$original) return new WP_Error('not_found', 'Entrée introuvable');

    // 2. Vérifier si le journal n'est pas déjà clôturé
    $table_journal = $wpdb->prefix . 'gest_cash_journal';
    $entry_date = date('Y-m-d', strtotime($original->datetime));
    $journal = $wpdb->get_row($wpdb->prepare("SELECT is_closed FROM $table_journal WHERE date = %s", $entry_date));
    
    if ($journal && $journal->is_closed) {
        return new WP_Error('closed', 'Journal clôturé, annulation impossible');
    }

    // 3. RÉCUPÉRER LE DERNIER HASH pour la chaîne (Multi-tenant)
    $last_entry = $wpdb->get_row($wpdb->prepare(
        "SELECT row_hash FROM $table_entries WHERE created_by = %d ORDER BY id DESC LIMIT 1", 
        $user_id
    ));
    $prev_hash = $last_entry ? $last_entry->row_hash : str_repeat('0', 64);

    // 4. CRÉER LA LIGNE DE STORNO (Annulation)
    $storno_amount = $original->amount * -1; // On inverse le montant
    $storno_desc = "ANNULATION (ID: " . $original->id . ") " . $original->description;
    $storno_datetime = current_time('mysql');
    
    // Calcul du nouveau hash pour cette nouvelle ligne
    $data_to_hash = $prev_hash . $user_id . $storno_datetime . $original->type . $storno_amount . $storno_desc;
    $row_hash = hash('sha256', $data_to_hash);

    $wpdb->insert($table_entries, [
        'journal_id'     => $original->journal_id,
        'datetime'       => $storno_datetime,
        'uuid'           => $uuid,  
        'type'           => $original->type, // On garde le même type (in ou out)
        'amount'         => $storno_amount,  // Mais montant négatif
        'description'    => $storno_desc,
        'created_by'     => $user_id,
        'created_at'     => current_time('mysql'), 
        'payment_method' => $original->payment_method,
        'status'         => 'storno',       // Nouveau statut pour info visuelle
        'prev_hash'      => $prev_hash,
        'row_hash'       => $row_hash,
        'parent_id'      => $original->id,
        'source'         => 'pwa',
        'synced'         => 1
    ]);

    // 5. Recalculer les totaux (le montant négatif fera le job tout seul)
    applicompta_recalc_journal($entry_date);
    applicompta_audit_log('CANCEL_ENTRY', 'CASH_ENTRY', $id_to_cancel, "Annulation via ligne ID " . $wpdb->insert_id);
    return rest_ensure_response(['success' => true, 'message' => 'Contre-passation effectuée']);
}

/**
 * Recalculate totals for a given date and upsert the journal row.
 */
function applicompta_recalc_journal($date) {
    global $wpdb;
     $user_id = get_current_user_id(); 
    $table_journal = $wpdb->prefix . 'gest_cash_journal';
    $table_entries = $wpdb->prefix . 'gest_cash_entries';

    // Somme des entrées ACTIVES uniquement
    $tot_in = floatval($wpdb->get_var($wpdb->prepare(
        "SELECT SUM(amount) FROM $table_entries 
         WHERE DATE(datetime) = %s AND type = %s AND created_by = %d AND status IN ('active', 'storno')", 
        $date, 'in', $user_id
    )) ?: 0);

    $tot_out = floatval($wpdb->get_var($wpdb->prepare(
        "SELECT SUM(amount) FROM $table_entries 
         WHERE DATE(datetime) = %s AND type = %s AND created_by = %d AND status IN ('active', 'storno')", 
        $date, 'out', $user_id
    )) ?: 0);
    $journal = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_journal WHERE date = %s", $date));
    if ($journal) {
        $opening = floatval($journal->opening_balance ?: 0);
        $closing = $opening + $tot_in - $tot_out;
        $wpdb->update($table_journal, [
            'total_in' => $tot_in,
            'total_out' => $tot_out,
            'closing_balance' => $closing,
            'updated_at' => current_time('mysql')
        ], [ 'id' => $journal->id ]);
    } else {
        $opening = 0;
        $closing = $opening + $tot_in - $tot_out;
        $wpdb->insert($table_journal, [
            'date' => $date,
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'total_in' => $tot_in,
            'total_out' => $tot_out,
            'is_closed' => 0,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);
    }

    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_journal WHERE date = %s", $date));
}

function applicompta_close_cash_journal($request) {
    global $wpdb;
    $user_id = get_current_user_id();
    $user = get_userdata($user_id);
    $date = $request->get_param('date') ?: date('Y-m-d');
    $table_journal = $wpdb->prefix . 'gest_cash_journal';

    // 1. Sécurités habituelles
    $current_journal = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_journal WHERE date = %s AND created_by = %d",
        $date, $user_id
    ));
    if (!$current_journal) return new WP_Error('no_journal', 'Journal introuvable.');
    if ($current_journal->is_closed) return new WP_Error('already_closed', 'Déjà clôturé.');

    // 2. Calcul du chaînage et du numéro Z
    $last_closed_z = $wpdb->get_row($wpdb->prepare(
        "SELECT z_number, fiscal_signature FROM $table_journal 
         WHERE created_by = %d AND is_closed = 1 ORDER BY z_number DESC LIMIT 1",
        $user_id
    ));

    $prev_sig = $last_closed_z ? $last_closed_z->fiscal_signature : str_repeat('0', 64);
    $next_z_num = $last_closed_z ? (intval($last_closed_z->z_number) + 1) : 1;

    // 3. Recalcul des totaux et Signature
    $temp_journal = applicompta_recalc_journal($date); // On s'assure que les totaux IN/OUT sont à jour
    
    $data_to_sign = $prev_sig . $next_z_num . $temp_journal->total_in . $temp_journal->total_out . $temp_journal->closing_balance . $date . $user_id;
    $fiscal_signature = hash('sha256', $data_to_sign);

    // 4. MISE À JOUR DE LA BASE DE DONNÉES
    $wpdb->update($table_journal, [
        'is_closed'        => 1,
        'z_number'         => $next_z_num,
        'prev_z_signature' => $prev_sig,
        'fiscal_signature' => $fiscal_signature,
        'updated_at'       => current_time('mysql')
    ], ['id' => $current_journal->id]);

    // --- CRUCIAL : ON RECHARGE LE JOURNAL DEPUIS LA DB POUR AVOIR LES DONNÉES SCELLÉES ---
    $journal_scelle = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_journal WHERE id = %d AND created_by = %d ", $current_journal->id, $user_id));
    
    // 5. Récupération des entrées pour le PDF
    $entries = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}gest_cash_entries 
     WHERE journal_id = %d 
     AND created_by = %d 
     ORDER BY datetime ASC", 
    $journal_scelle->id, 
    $user_id
));

    // 6. Génération du PDF avec les données scellées
    $html = applicompta_generate_z_report_html($journal_scelle, $entries, $user);
    $pdf_content = applicompta_generate_pdf_via_gotenberg($html);

    $email_sent = false;
    if ($pdf_content) {
        $email_sent = applicompta_send_z_report_email($user, $journal_scelle, $pdf_content);
    }

    // 7. Log Audit
    applicompta_audit_log('CLOSE_JOURNAL', 'JOURNAL', $journal_scelle->id, [
        'z_number' => $next_z_num,
        'sig'      => $fiscal_signature
    ]);

    return rest_ensure_response([
        'success'    => true,
        'z_number'   => $next_z_num,
        'email_sent' => $email_sent
    ]);
}

function applicompta_audit_log($action, $object_type, $object_id, $details = '') {
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'gest_audit_log', [
        'user_id'     => get_current_user_id(),
        'action'      => $action,
        'object_type' => $object_type,
        'object_id'   => $object_id,
        'details'     => is_array($details) ? json_encode($details) : $details,
        'ip_address'  => $_SERVER['REMOTE_ADDR'],
        'created_at' => current_time('mysql')
    ]);
}

function applicompta_generate_z_report_html($journal, $entries, $user) {
    $logo_url = get_user_meta($user->ID, 'logo_url', true);
    
    // Calcul des totaux par méthode pour le résumé
    $cash_total = 0; $card_total = 0;
    foreach ($entries as $e) {
        if ($e->payment_method === 'cash') $cash_total += $e->amount;
        else $card_total += $e->amount;
    }

    ob_start(); ?>
    <style>
        body { font-family: sans-serif; color: #333; font-size: 11px; line-height: 1.4; }
        .header { text-align: center; border-bottom: 2px solid #249191; padding-bottom: 10px; margin-bottom: 20px; }
        .title { font-size: 18px; font-weight: bold; color: #249191; text-transform: uppercase; }
        
        /* Tables */
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: #f4f4f4; padding: 8px; text-align: left; border: 1px solid #ddd; font-weight: bold; }
        td { padding: 8px; border: 1px solid #ddd; vertical-align: top; }
        
        .text-right { text-align: right; }
        .storno-row { color: #d9534f; font-style: italic; background-color: #fff5f5; }
        
        .summary-table td { font-size: 13px; padding: 10px; }
        .total-final { font-weight: bold; font-size: 15px; background: #eef7f7; }

        .footer { margin-top: 30px; padding: 15px; background: #fffcf0; border: 1px dashed #cca; }
        .hash-code { font-family: monospace; font-size: 9px; word-break: break-all; color: #666; display: block; margin-top: 5px; }
    </style>

    <div class="header">
        <?php if($logo_url): ?><img src="<?= $logo_url ?>" style="max-width:140px; margin-bottom:10px;"><br><?php endif; ?>
        <span class="title">Rapport de Clôture Fiscal Z n° <?= $journal->z_number ?></span>
        <p>Date : <?= date('d/m/Y', strtotime($journal->date)) ?> | Utilisateur : <?= $user->display_name ?></p>
    </div>

    <h3>1. Résumé Financier</h3>
    <table class="summary-table">
        <tr><td>Solde Ouverture</td><td class="text-right"><?= number_format($journal->opening_balance, 2) ?> €</td></tr>
        <tr><td>Total des Entrées (+)</td><td class="text-right"><?= number_format($journal->total_in, 2) ?> €</td></tr>
        <tr><td>Total des Sorties (-)</td><td class="text-right"><?= number_format($journal->total_out, 2) ?> €</td></tr>
        <tr class="total-final"><td>SOLDE DE CLÔTURE (Réel en caisse)</td><td class="text-right"><?= number_format($journal->closing_balance, 2) ?> €</td></tr>
    </table>

    <h3>2. Répartition par Mode de Paiement</h3>
    <table>
        <thead>
            <tr><th>Mode</th><th class="text-right">Total</th></tr>
        </thead>
        <tbody>
            <tr><td>Espèces (Cash)</td><td class="text-right"><?= number_format($cash_total, 2) ?> €</td></tr>
            <tr><td>Carte / Bancontact / Virement</td><td class="text-right"><?= number_format($card_total, 2) ?> €</td></tr>
        </tbody>
    </table>

    <h3>3. Détail des Opérations (Journal des ventes)</h3>
    <table>
        <thead>
            <tr>
                <th width="15%">Heure</th>
                <th width="45%">Description</th>
                <th width="20%">Mode</th>
                <th width="20%" class="text-right">Montant</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($entries as $e): 
                $isStorno = ($e->status === 'storno'); ?>
                <tr class="<?= $isStorno ? 'storno-row' : '' ?>">
                    <td><?= date('H:i:s', strtotime($e->datetime)) ?></td>
                    <td>
                        <?= esc_html($e->description) ?>
                        <?php if($isStorno): ?><br><small>(Annulation de la transaction #<?= $e->parent_id ?>)</small><?php endif; ?>
                    </td>
                    <td><?= strtoupper($e->payment_method) ?></td>
                    <td class="text-right"><?= number_format($e->amount, 2) ?> €</td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($entries)): ?>
                <tr><td colspan="4" style="text-align:center;">Aucune opération enregistrée.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="footer">
        <strong>INTÉGRITÉ ET SÉCURITÉ FISCALE</strong><br><br>
        <strong>Sceau Numérique (SHA-256) :</strong>
        <span class="hash-code"><?= $journal->fiscal_signature ?></span>
        
        <strong>Chaînage Précédent :</strong>
        <?php if ($journal->z_number > 1): ?>
            <span style="font-size: 10px;">Lien vers Rapport Z-<?= $journal->z_number - 1 ?></span>
        <?php else: ?>
            <span style="font-size: 10px;">Document initial (Genesis)</span>
        <?php endif; ?>
        <span class="hash-code"><?= $journal->prev_z_signature ?></span>
    </div>
    <?php
    return ob_get_clean();
}

function applicompta_send_z_report_email($user, $journal, $pdf_content) {
    $host = get_user_meta($user->ID, 'smtp_host', true);
    $port = (int)get_user_meta($user->ID, 'smtp_port', true);
    $smtp_user = get_user_meta($user->ID, 'smtp_user', true);
    $pass = applicompta_smtp_decrypt(get_user_meta($user->ID, 'smtp_pass_enc', true));

    if (!$host || !$smtp_user || !$pass) return false;

    require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
    require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
    require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP(); $mail->Host = $host; $mail->SMTPAuth = true; $mail->Username = $smtp_user;
        $mail->Password = $pass; $mail->Port = $port; $mail->CharSet = 'UTF-8';
        $mail->SMTPSecure = ($port === 465) ? 'ssl' : 'tls';
        $mail->setFrom($smtp_user, "Applicompta - Clôture Fiscale");
        $mail->addAddress($user->user_email);
        $mail->addStringAttachment($pdf_content, "Rapport_Z_n" . $journal->z_number . "_" . $journal->date . ".pdf");
        $mail->isHTML(true);
        $mail->Subject = "Votre Rapport Z n°" . $journal->z_number . " (" . $journal->date . ")";
        $mail->Body = "Bonjour,<br><br>Votre journée a été clôturée avec succès. Vous trouverez ci-joint votre rapport scellé pour archivage légal.";
        $mail->send();
        return true;
    } catch (Exception $e) { return false; }
}