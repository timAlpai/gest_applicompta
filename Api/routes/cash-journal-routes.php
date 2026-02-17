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



function applicompta_get_cash_journal($request) {
    global $wpdb;
    $date = $request->get_param('date') ?: date('Y-m-d');
    $table_journal = $wpdb->prefix . 'gest_cash_journal';
    $table_entries = $wpdb->prefix . 'gest_cash_entries';

    $journal = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_journal WHERE date = %s", $date));
    $entries = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_entries WHERE DATE(datetime) = %s ORDER BY datetime ASC", $date));

    return rest_ensure_response([
        'success' => true,
        'date' => $date,
        'journal' => $journal,
        'entries' => $entries
    ]);
}



function applicompta_create_cash_entry($request) {
    global $wpdb;
    $data = json_decode($request->get_body(), true);
    $table_entries = $wpdb->prefix . 'gest_cash_entries';

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

    // Prevent creating entries for closed journals
    $entry_date = date('Y-m-d', $dt);
    $table_journal = $wpdb->prefix . 'gest_cash_journal';
    $existing_j = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_journal WHERE date = %s", $entry_date));
    if ($existing_j && intval($existing_j->is_closed) === 1) {
        return new WP_Error('journal_closed', 'Journal is closed for this date', ['status' => 403]);
    }

    $inserted = $wpdb->insert($table_entries, [
        'journal_id' => $data['journal_id'] ?? null,
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
        'synced' => 1
    ]);

    if ($inserted === false) {
        return new WP_Error('db_error', 'DB insert failed', ['status' => 500]);
    }

    $id = $wpdb->insert_id;
    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_entries WHERE id = %d", $id));

    // Recalculate journal totals for the entry date
    $entry_date = date('Y-m-d', strtotime($data['datetime']));
    $journal = applicompta_recalc_journal($entry_date);

    return rest_ensure_response([ 'success' => true, 'entry' => $entry, 'journal' => $journal ]);
}

function applicompta_update_cash_entry($request) {
    global $wpdb;
    $id = intval($request['id']);
    $data = json_decode($request->get_body(), true);
    $table_entries = $wpdb->prefix . 'gest_cash_entries';
    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_entries WHERE id = %d", $id));
    if (!$entry) return new WP_Error('not_found', 'Entry not found', ['status' => 404]);

    // Prevent updates if the journal is closed for that date
    $table_journal = $wpdb->prefix . 'gest_cash_journal';
    $entry_date = date('Y-m-d', strtotime($entry->datetime));
    $existing_j = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_journal WHERE date = %s", $entry_date));
    if ($existing_j && intval($existing_j->is_closed) === 1) {
        return new WP_Error('journal_closed', 'Journal is closed for this date', ['status' => 403]);
    }

    $update = [];
    if (isset($data['amount'])) {
        if (!is_numeric($data['amount']) || floatval($data['amount']) <= 0) return new WP_Error('invalid_amount', 'Invalid amount', ['status' => 400]);
        $update['amount'] = floatval($data['amount']);
    }
    if (isset($data['type'])) {
        $t = sanitize_text_field($data['type']);
        if (!in_array($t, ['in','out'])) return new WP_Error('invalid_type', 'Invalid type', ['status' => 400]);
        $update['type'] = $t;
    }
    if (isset($data['description'])) $update['description'] = sanitize_textarea_field($data['description']);

    if (empty($update)) return new WP_Error('invalid_data', 'No fields to update', ['status' => 400]);

    $res = $wpdb->update($table_entries, $update, [ 'id' => $id ]);
    if ($res === false) return new WP_Error('db_error', 'Update failed', ['status' => 500]);

    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_entries WHERE id = %d", $id));
    // Recalculate journal totals for the entry date
    $entry_date = date('Y-m-d', strtotime($entry->datetime));
    $journal = applicompta_recalc_journal($entry_date);

    return rest_ensure_response([ 'success' => true, 'entry' => $entry, 'journal' => $journal ]);
}

function applicompta_delete_cash_entry($request) {
    global $wpdb;
    $id = intval($request['id']);
    $table_entries = $wpdb->prefix . 'gest_cash_entries';

    // fetch entry to determine date before deleting
    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_entries WHERE id = %d", $id));
    if (!$entry) return new WP_Error('not_found', 'Entry not found', ['status' => 404]);

    // Prevent deleting if journal is closed for that date
    $table_journal = $wpdb->prefix . 'gest_cash_journal';
    $entry_date = date('Y-m-d', strtotime($entry->datetime));
    $existing_j = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_journal WHERE date = %s", $entry_date));
    if ($existing_j && intval($existing_j->is_closed) === 1) {
        return new WP_Error('journal_closed', 'Journal is closed for this date', ['status' => 403]);
    }

    $res = $wpdb->delete($table_entries, [ 'id' => $id ]);
    if ($res === false) return new WP_Error('db_error', 'Delete failed', ['status' => 500]);

    // Recalculate journal totals for that date
    $journal = applicompta_recalc_journal($entry_date);

    return rest_ensure_response([ 'success' => true, 'deleted' => $res, 'journal' => $journal ]);
}

/**
 * Recalculate totals for a given date and upsert the journal row.
 */
function applicompta_recalc_journal($date) {
    global $wpdb;
    $table_journal = $wpdb->prefix . 'gest_cash_journal';
    $table_entries = $wpdb->prefix . 'gest_cash_entries';

    $tot_in = floatval($wpdb->get_var($wpdb->prepare("SELECT SUM(amount) FROM $table_entries WHERE DATE(datetime) = %s AND type = %s", $date, 'in')) ?: 0);
    $tot_out = floatval($wpdb->get_var($wpdb->prepare("SELECT SUM(amount) FROM $table_entries WHERE DATE(datetime) = %s AND type = %s", $date, 'out')) ?: 0);

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
    $date = $request->get_param('date') ?: date('Y-m-d');
    $table_journal = $wpdb->prefix . 'gest_cash_journal';

    // ensure totals are up to date
    $journal = applicompta_recalc_journal($date);
    if (!$journal) return new WP_Error('no_journal', 'Journal not found', ['status' => 404]);

    $res = $wpdb->update($table_journal, [ 'is_closed' => 1, 'updated_at' => current_time('mysql') ], [ 'id' => $journal->id ]);
    if ($res === false) return new WP_Error('db_error', 'Failed to close journal', ['status' => 500]);

    $journal = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_journal WHERE id = %d", $journal->id));
    return rest_ensure_response([ 'success' => true, 'journal' => $journal ]);
}
