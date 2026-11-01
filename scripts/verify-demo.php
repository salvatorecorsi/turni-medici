<?php

if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) exit;

if (!defined('TM_DEMO_MODE') || TM_DEMO_MODE !== true || !in_array(wp_get_environment_type(), ['local', 'development'], true)) {
    WP_CLI::error('La verifica richiede una installazione demo local o development.');
}

function tm_demo_verify($condition, $message) {
    if (!$condition) WP_CLI::error($message);
}

function tm_demo_verify_request($route, $user_id, $query = []) {
    wp_set_current_user($user_id);
    $request = new WP_REST_Request('GET', '/turnimedici/v1/' . $route);
    if ($user_id) $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $request->set_query_params($query);
    return rest_do_request($request);
}

tm_demo_verify(get_option('tm_demo_seed_version') === '1', 'Seed incompleto o mancante.');
tm_demo_verify(function_exists('define_block_type') && function_exists('tm_board_calendar'), 'Plugin richiesti non attivi.');
tm_demo_verify((int) get_option('users_can_register') === 0, 'Registrazione pubblica abilitata.');

$afts = get_posts(['post_type' => 'tm_aft', 'numberposts' => -1, 'meta_key' => '_tm_demo_seed', 'meta_value' => '1']);
$facilities = get_posts(['post_type' => 'tm_facility', 'numberposts' => -1, 'meta_key' => '_tm_demo_seed', 'meta_value' => '1']);
$doctors = get_users(['role' => 'tm_doctor', 'meta_key' => '_tm_demo_seed', 'meta_value' => '1']);
$coordinators = get_users(['role' => TM_COORDINATOR_ROLE, 'meta_key' => '_tm_demo_seed', 'meta_value' => '1']);
tm_demo_verify(count($afts) === 1 && count($facilities) === 2 && count($doctors) === 3 && count($coordinators) === 1, 'Numero di AFT, strutture o utenti demo inatteso.');
$aft_id = (int) $afts[0]->ID;
$doctor_ids = array_map(fn($doctor) => (int) $doctor->ID, $doctors);
$month = get_option('tm_demo_seed_month');
tm_demo_verify(tm_valid_month_value($month), 'Mese demo non valido.');
tm_demo_verify(tm_coordinator_aft_id($coordinators[0]->ID) === $aft_id, 'Il coordinatore non gestisce AFT Demo.');

foreach (array_merge($doctors, $coordinators) as $user) {
    tm_demo_verify((bool) preg_match('/@[^@]+\.invalid$/', $user->user_email), 'Email non sintetica in un utente demo.');
}
foreach ($doctors as $doctor) {
    tm_demo_verify(tm_doctor_professional_data_complete($doctor->ID) && tm_doctor_home_aft_id($doctor->ID) === $aft_id, 'Profilo medico incompleto o AFT errata.');
}

global $wpdb;
$all_shifts = [];
foreach ($facilities as $facility) {
    tm_demo_verify((int) get_post_meta($facility->ID, 'tm_aft_id', true) === $aft_id, 'Struttura senza AFT demo.');
    $schedule_id = (int) get_post_meta($facility->ID, 'tm_default_schedule', true);
    tm_demo_verify(get_post_type($schedule_id) === 'tm_schedule' && get_post_meta($schedule_id, '_tm_demo_seed', true) === '1', 'Calendario predefinito mancante.');
    tm_demo_verify(get_post_meta($schedule_id, 'tm_schedule_month', true) === $month, 'Il calendario usa un mese diverso dal seed.');
    $week = tm_board_calendar($schedule_id);
    tm_demo_verify(is_array($week) && count($week['defaults']['slots'] ?? []) === 2, 'Fasce del calendario mancanti.');
    $shifts = $wpdb->get_results($wpdb->prepare('SELECT schedule_id, shift_date, slot, doctor_id FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d', $schedule_id));
    tm_demo_verify(is_array($shifts) && count($shifts) >= 20, 'Turni sintetici insufficienti.');
    tm_demo_verify(count($shifts) < tm_schedule_cells_total($schedule_id), 'Mancano fasce libere per provare le assegnazioni.');
    foreach ($shifts as $shift) {
        $effective = tm_board_effective_day($week, $shift->shift_date);
        tm_demo_verify(in_array((int) $shift->doctor_id, $doctor_ids, true), 'Un turno appartiene a un medico non demo.');
        tm_demo_verify(tm_valid_schedule_date($schedule_id, $shift->shift_date) && $effective['open']
            && in_array($shift->slot, array_map('tm_slot_key', $effective['slots']), true), 'Turno fuori mese o fuori dalle fasce di apertura.');
        foreach ($all_shifts as $previous) {
            if ((int) $previous->doctor_id !== (int) $shift->doctor_id || $previous->shift_date !== $shift->shift_date) continue;
            tm_demo_verify(!tm_slots_overlap($previous->slot, $shift->slot), 'Un medico ha due turni sovrapposti.');
        }
        $all_shifts[] = $shift;
    }
    foreach ([$coordinators[0]->ID, $doctors[0]->ID] as $user_id) {
        $response = tm_demo_verify_request('structures/' . $facility->ID . '/board', $user_id, ['schedule' => $schedule_id]);
        tm_demo_verify($response->get_status() === 200 && !empty($response->get_data()['days']), 'La board REST non è accessibile a un utente demo autorizzato.');
    }
    $anonymous = tm_demo_verify_request('structures/' . $facility->ID . '/board', 0);
    tm_demo_verify(in_array($anonymous->get_status(), [401, 403], true), 'La board riservata è visibile senza autenticazione.');
    $calendar = tm_demo_verify_request('structures/' . $facility->ID . '/calendar', $doctors[0]->ID, ['schedule' => $schedule_id]);
    tm_demo_verify($calendar->get_status() === 403, 'Un medico può accedere alla gestione del calendario.');
}

$guide = get_page_by_path('guida-demo', OBJECT, 'page');
tm_demo_verify($guide && get_post_meta($guide->ID, 'tm_show_in_app', true), 'Guida demo non disponibile nell’app.');
tm_demo_verify(str_contains(do_blocks($guide->post_content), '<p>La demo mostra') && !str_contains(do_blocks($guide->post_content), 'u003c'), 'Il markup dei campi editoriali non è stato preservato.');
foreach (parse_blocks($guide->post_content) as $block) {
    if (!$block['blockName']) continue;
    tm_demo_verify(WP_Block_Type_Registry::get_instance()->is_registered($block['blockName']), 'Blocco Define Blocks non registrato: ' . $block['blockName']);
    tm_demo_verify(trim(render_block($block)) !== '', 'Un blocco della guida non produce contenuto.');
}
$info = tm_demo_verify_request('me/info', $doctors[0]->ID);
tm_demo_verify($info->get_status() === 200 && in_array((int) $guide->ID, array_column($info->get_data(), 'id'), true), 'Guida assente dalle informazioni REST.');

$mail = ['to' => 'verify@demo.invalid', 'subject' => 'Demo verification', 'message' => 'Synthetic verification', 'headers' => '', 'attachments' => []];
tm_demo_verify(apply_filters('pre_wp_mail', null, $mail) === false, 'Il filtro demo non blocca le email.');
tm_demo_verify(wp_mail($mail['to'], $mail['subject'], $mail['message']) === false, 'wp_mail non è bloccato in modalità demo.');
foreach (['https://servizi.estar.toscana.it/'] as $url) {
    $blocked = apply_filters('pre_http_request', false, ['method' => 'GET'], $url);
    tm_demo_verify(is_wp_error($blocked) && $blocked->get_error_code() === 'tm_demo_integration_disabled', 'Una integrazione esterna non viene bloccata prima della richiesta HTTP.');
    $response = wp_remote_get($url);
    tm_demo_verify(is_wp_error($response) && $response->get_error_code() === 'tm_demo_integration_disabled', 'Il client HTTP non rispetta il blocco delle integrazioni demo.');
}
$administrators = get_users(['role' => 'administrator']);
tm_demo_verify(count($administrators) === 1, 'Amministratore installatore non univoco.');
tm_demo_verify(user_can($administrators[0], 'manage_corsivo_developer_tools'), 'Il menu Core Editor non è disponibile all’amministratore demo.');
foreach (array_merge($doctors, $coordinators) as $user) {
    tm_demo_verify(!user_can($user, 'manage_corsivo_developer_tools'), 'I profili dimostrativi non devono gestire gli strumenti sviluppatore.');
}
foreach (['estar-status'] as $route) {
    $response = tm_demo_verify_request($route, $administrators[0]->ID);
    tm_demo_verify($response->get_status() === 403 && ($response->get_data()['code'] ?? '') === 'tm_demo_integration_disabled', 'Una integrazione è disponibile via REST in modalità demo.');
}
$rosters = tm_demo_verify_request('doctors/ca-rosters', $administrators[0]->ID);
tm_demo_verify($rosters->get_status() === 200 && is_array($rosters->get_data()), 'L’archivio CSV locale non è disponibile all’amministratore demo.');
foreach ([$doctors[0]->ID, $coordinators[0]->ID, 0] as $user_id) {
    $rosters = tm_demo_verify_request('doctors/ca-rosters', $user_id);
    tm_demo_verify(in_array($rosters->get_status(), [401, 403], true), 'L’archivio CSV è accessibile senza permessi amministrativi.');
}
wp_set_current_user(0);
WP_CLI::success('Demo verificata: dati sintetici, calendari, assenza di sovrapposizioni, blocchi, permessi REST ed esclusione di email e integrazioni esterne.');
