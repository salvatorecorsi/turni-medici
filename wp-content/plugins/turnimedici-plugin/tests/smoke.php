<?php
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

$failures = 0;
$check = function ($label, $condition) use (&$failures) {
    if ($condition) {
        echo "PASS  $label\n";
    } else {
        $failures++;
        echo "FAIL  $label\n";
    }
};

$check('tm_slot_hours: 08:00-13:00 vale 5 ore', tm_slot_hours('08:00-13:00') === 5.0 || tm_slot_hours('08:00-13:00') === 5);
$check('tm_slot_hours: chiave malformata vale 0', tm_slot_hours('boh') === 0);

$check('tm_slots_overlap: 13-19 e 15-19 si sovrappongono', tm_slots_overlap('13:00-19:00', '15:00-19:00') === true);
$check('tm_slots_overlap: 08-13 e 13-19 adiacenti non si sovrappongono', tm_slots_overlap('08:00-13:00', '13:00-19:00') === false);

$overnight = tm_slot_range_minutes('23:00-01:00');
$check('tm_slot_range_minutes: fascia notturna scavalca la mezzanotte', is_array($overnight) && $overnight[0] === 1380 && $overnight[1] === 1500);

$inverted = tm_validate_slots([['from' => '15:00', 'to' => '13:00']]);
$check('tm_validate_slots: fascia con inizio dopo la fine rifiutata', is_wp_error($inverted));

$duplicated = tm_validate_slots([['from' => '08:00', 'to' => '13:00'], ['from' => '08:00', 'to' => '13:00']]);
$check('tm_validate_slots: fascia duplicata rifiutata', is_wp_error($duplicated));

$overlapping = tm_validate_slots([['from' => '08:00', 'to' => '13:00'], ['from' => '12:00', 'to' => '14:00']]);
$check('tm_validate_slots: fasce sovrapposte rifiutate', is_wp_error($overlapping));

$valid_week = tm_sanitize_week([
    'viewMonth' => '2026-07',
    'defaults' => ['closedWeekdays' => ['sat', 'sun'], 'slots' => [['from' => '08:00', 'to' => '13:00']]],
    'overrides' => [],
], true);
$check('tm_sanitize_week strict: settimana valida accettata', is_array($valid_week));

$invalid_week = tm_sanitize_week([
    'viewMonth' => '2026-07',
    'defaults' => ['closedWeekdays' => [], 'slots' => [['from' => '15:00', 'to' => '13:00']]],
    'overrides' => [],
], true);
$check('tm_sanitize_week strict: fascia invertita rifiutata', is_wp_error($invalid_week));

$check('tm_valid_month_value: mese reale accettato', tm_valid_month_value('2026-07') === true);
$check('tm_valid_month_value: mese 13 rifiutato', tm_valid_month_value('2026-13') === false);

$check('tm_month_value: parti mese in YYYY-MM', tm_month_value(['year' => 2026, 'month' => 7]) === '2026-07');
$check('tm_month_label: luglio 2026', tm_month_label('2026-07') === 'Luglio 2026');
$check('tm_month_period: luglio 2026 va dal 1 al 31', tm_month_period(['year' => 2026, 'month' => 7]) === ['start' => '2026-07-01', 'end' => '2026-07-31']);
$check('tm_month_period: febbraio bisestile finisce il 29', tm_month_period(['year' => 2028, 'month' => 2])['end'] === '2028-02-29');

$wrong_month_override = tm_sanitize_week([
    'viewMonth' => '2026-07',
    'defaults' => ['closedWeekdays' => [], 'slots' => [['from' => '08:00', 'to' => '13:00']]],
    'overrides' => ['2026-08-01' => ['closed' => true]],
], true);
$check('tm_sanitize_week strict: override fuori mese rifiutato', is_wp_error($wrong_month_override));

$signature_a = tm_schedule_participants_signature_from_ids([1, 2, 3]);
$signature_b = tm_schedule_participants_signature_from_ids([1, 2, 3]);
$signature_c = tm_schedule_participants_signature_from_ids([3, 2, 1]);
$check('participants_signature: stabile a parità di lista', $signature_a !== '' && $signature_a === $signature_b);
$check('participants_signature: sensibile all\'ordine', $signature_a !== $signature_c);

$check('datetime locale: nessun doppio offset', tm_format_local_datetime('2026-07-10 12:34:00', 'd/m/Y H:i') === '10/07/2026 12:34');

$check('qualifiche: ruap_corsista tra le qualifiche ammesse', in_array('ruap_corsista', tm_allowed_doctor_qualifications(), true));
$check('qualifiche: label del corsista RUAP', tm_qualification_label('ruap_corsista') === 'Corsista RUAP');
$corsista_categories = array_filter(tm_default_ranking()['categories'], fn($category) => in_array('ruap_corsista', (array) $category['qualifications'], true));
$check('graduatoria: il corsista RUAP ha categoria interna ed esterna', count($corsista_categories) === 2);
$default_ranking_ids = array_map(fn($category) => $category['id'], tm_default_ranking()['categories']);
$check(
    'graduatoria default: corsisti dopo i vincolati e prima dei volontari',
    array_search('ruap_cors_int', $default_ranking_ids, true) === array_search('ruap_vinc_int', $default_ranking_ids, true) + 1
    && array_search('ruap_cors_int', $default_ranking_ids, true) < array_search('ruap_vol_int', $default_ranking_ids, true)
);
$saved_ranking_ids = array_map(fn($category) => $category['id'] ?? '', tm_get_ranking()['categories']);
$saved_cors_index = array_search('ruap_cors_int', $saved_ranking_ids, true);
$saved_vol_index = array_search('ruap_vol_int', $saved_ranking_ids, true);
$check(
    'graduatoria salvata: corsisti presenti e prima dei volontari',
    $saved_cors_index !== false && ($saved_vol_index === false || $saved_cors_index < $saved_vol_index)
);
$check('quote corsista: 6h obbligatorie fino a 650 assistiti, 0 oltre', tm_corsista_mandatory_weekly_hours(650) === 6.0 && tm_corsista_mandatory_weekly_hours(651) === 0.0);
$check('massimali: 1.000 per corsisti, 1.500 ordinario', tm_doctor_max_patients('ruap_corsista') === 1000 && tm_doctor_max_patients('ruap_vincolato') === 1500);

$roster_horizon = tm_ca_roster_horizon([
    ['ca_start' => '2026-09-01', 'ca_end' => '2026-09-30'],
    ['ca_start' => '2026-07-01', 'ca_end' => '2026-08-31'],
    ['ca_start' => '', 'ca_end' => ''],
    ['ca_start' => '2026-07-15', 'ca_end' => 'boh'],
]);
$check('elenco CA: orizzonte = primo/ultimo giorno di contratto del file', $roster_horizon === ['start' => '2026-07-01', 'end' => '2026-09-30']);
$check('elenco CA: file senza contratti datati non ha orizzonte', tm_ca_roster_horizon([['ca_start' => '', 'ca_end' => '']]) === null);

$quarter_horizon = ['start' => '2026-07-01', 'end' => '2026-09-30'];
$surviving_periods = tm_ca_periods_outside_horizon([
    ['start' => '2026-04-01', 'end' => '2026-06-30'],
    ['start' => '2026-07-01', 'end' => '2026-08-31'],
    ['start' => '2026-06-15', 'end' => '2026-07-15'],
    ['start' => '2026-06-01', 'end' => '2026-10-15'],
], $quarter_horizon);
$check('elenco CA: sopravvivono solo i contratti fuori orizzonte, ritagliati sul bordo', $surviving_periods === [
    ['start' => '2026-04-01', 'end' => '2026-06-30'],
    ['start' => '2026-06-15', 'end' => '2026-06-30'],
    ['start' => '2026-06-01', 'end' => '2026-06-30'],
    ['start' => '2026-10-01', 'end' => '2026-10-15'],
]);
$untouched_periods = [['start' => '2026-04-01', 'end' => '2026-06-30']];
$check('elenco CA: senza orizzonte i contratti restano intatti', tm_ca_periods_outside_horizon($untouched_periods, null) === $untouched_periods);
$check(
    'elenco CA: intersezione con l\'orizzonte rilevata solo quando c\'è',
    tm_ca_periods_intersect_horizon([['start' => '2026-06-15', 'end' => '2026-07-15']], $quarter_horizon) === true
    && tm_ca_periods_intersect_horizon($untouched_periods, $quarter_horizon) === false
    && tm_ca_periods_intersect_horizon($untouched_periods, null) === false
);
$summed_periods = tm_merge_ca_periods(
    [['start' => '2026-04-01', 'end' => '2026-06-30']],
    [['start' => '2026-07-01', 'end' => '2026-09-30'], ['start' => '2026-04-01', 'end' => '2026-06-30']]
);
$check('elenco CA: somma contratti vecchi+nuovi senza duplicati, in ordine', $summed_periods === [
    ['start' => '2026-04-01', 'end' => '2026-06-30'],
    ['start' => '2026-07-01', 'end' => '2026-09-30'],
]);

$roster_report = tm_ca_roster_report([
    ['first_name' => 'Medico', 'last_name' => 'DemoUno', 'email' => 'A@EXAMPLE.INVALID', 'phone' => '333', 'ca_rank' => '207', 'ca_zone' => 'Demo Sud', 'ca_cot' => '', 'ca_start' => '2026-07-01', 'ca_end' => '2026-09-30', 'district' => 'Distretto Demo'],
    ['first_name' => 'DEMOUNO', 'last_name' => 'MEDICO', 'email' => '', 'phone' => '', 'ca_rank' => '', 'ca_zone' => '', 'ca_cot' => '', 'ca_start' => '2026-06-01', 'ca_end' => '2026-06-30'],
    ['first_name' => 'Medico', 'last_name' => 'DemoDue', 'email' => '', 'phone' => '', 'ca_rank' => '', 'ca_zone' => 'Demo Nord', 'ca_cot' => 1, 'ca_start' => '2026-08-01', 'ca_end' => '2026-08-31'],
]);
$check(
    'report elenco CA: righe raggruppate per medico anche con nome invertito',
    count($roster_report['doctors']) === 2 && $roster_report['rows'] === 3 && $roster_report['contracts'] === 3
);
$check(
    'report elenco CA: graduatoria prima, a chiamata in fondo, contratti ordinati',
    $roster_report['doctors'][0]['rank'] === 207
    && $roster_report['doctors'][0]['email'] === 'a@example.invalid'
    && $roster_report['doctors'][0]['periods'] === [
        ['start' => '2026-06-01', 'end' => '2026-06-30'],
        ['start' => '2026-07-01', 'end' => '2026-09-30'],
    ]
    && $roster_report['doctors'][1]['rank'] === null && $roster_report['doctors'][1]['cot'] === true
);
$check(
    'report elenco CA: distretto e orizzonte dedotti dalle righe',
    $roster_report['district'] === 'Distretto Demo'
    && $roster_report['horizon'] === ['start' => '2026-06-01', 'end' => '2026-09-30']
    && $roster_report['ranked'] === 1 && $roster_report['on_call'] === 1
);

$noted_week = [
    'defaults'  => ['closedWeekdays' => [], 'slots' => [['from' => '08:00', 'to' => '14:00']]],
    'overrides' => [
        '2026-06-02' => ['closed' => true, 'note' => 'Festa della Repubblica'],
        '2026-06-03' => ['slots' => [['from' => '08:00', 'to' => '12:00']], 'note' => 'apertura ridotta'],
    ],
];
$closed_noted_day = tm_board_effective_day($noted_week, '2026-06-02');
$open_noted_day = tm_board_effective_day($noted_week, '2026-06-03');
$plain_day = tm_board_effective_day($noted_week, '2026-06-04');
$check(
    'board: la nota dell\'override arriva al giorno effettivo, chiuso o aperto',
    $closed_noted_day['open'] === false && $closed_noted_day['note'] === 'Festa della Repubblica'
    && $open_noted_day['open'] === true && $open_noted_day['note'] === 'apertura ridotta'
    && $plain_day['note'] === ''
);

$incomplete_doctors = [];
$visible_incomplete_doctors = [];
foreach (tm_turnazione_doctor_ids() as $doctor_id) {
    $qualification = (string) get_user_meta($doctor_id, 'tm_qualification', true);
    $aft_id = tm_doctor_home_aft_id($doctor_id);
    if (!in_array($qualification, tm_allowed_doctor_qualifications(), true) || ($qualification !== 'ca_temporaneo' && !$aft_id)) {
        $doctor = get_userdata($doctor_id);
        $name = $doctor ? $doctor->display_name : '#' . $doctor_id;
        $incomplete_doctors[] = $name . ' [qualifica: ' . ($qualification ?: 'manca') . '; AFT: ' . ($aft_id ?: 'manca') . ']';
        if (tm_doctor_is_visible_in_frontend($doctor_id)) $visible_incomplete_doctors[] = $name;
    }
}
$check('anagrafica: i medici incompleti non sono esposti nel frontend', $visible_incomplete_doctors === []);
if ($incomplete_doctors) echo 'INFO  Da completare: ' . implode(', ', $incomplete_doctors) . "\n";

global $wpdb;
$previous_user_id = get_current_user_id();
$fixture_post_ids = [];
$fixture_user_ids = [];
$schedule_id = 0;
$fixture_suffix = str_replace('-', '', wp_generate_uuid4());

try {
    $create_fixture_post = function ($post_type, $title) use (&$fixture_post_ids, $fixture_suffix) {
        $post_id = wp_insert_post([
            'post_type' => $post_type,
            'post_status' => 'draft',
            'post_title' => $title . ' ' . $fixture_suffix,
        ], true);
        if (is_wp_error($post_id)) throw new RuntimeException($post_id->get_error_message());
        $fixture_post_ids[] = $post_id;
        return $post_id;
    };
    $fixture_aft_id = $create_fixture_post('tm_aft', 'AFT Demo Test');
    $facility_id = $create_fixture_post('tm_facility', 'Struttura Demo Test');
    $schedule_id = $create_fixture_post('tm_schedule', 'Turno Demo Test');
    update_post_meta($facility_id, 'tm_aft_id', $fixture_aft_id);
    update_post_meta($schedule_id, 'tm_facility_id', $facility_id);
    update_post_meta($schedule_id, 'tm_schedule_month', '2099-10');
    update_post_meta($facility_id, 'tm_default_schedule', $schedule_id);
    $fixture_week = tm_sanitize_week([
        'viewMonth' => '2099-10',
        'defaults' => ['closedWeekdays' => [], 'slots' => [['from' => '08:00', 'to' => '14:00']]],
        'overrides' => [],
    ], true);
    if (is_wp_error($fixture_week)) throw new RuntimeException($fixture_week->get_error_message());
    update_post_meta($schedule_id, 'tm_schedule_week', $fixture_week);
    update_post_meta($schedule_id, 'tm_schedule_legacy_week', $fixture_week);

    foreach (['doctor-occupied' => 'tm_doctor', 'doctor-free' => 'tm_doctor', 'manager' => 'administrator'] as $fixture_name => $role) {
        $login = 'tm_smoke_' . $fixture_name . '_' . $fixture_suffix;
        $user_id = wp_insert_user([
            'user_login' => $login,
            'user_email' => $login . '@example.invalid',
            'user_pass' => wp_generate_password(32),
            'first_name' => 'Test',
            'last_name' => 'Demo',
            'role' => $role,
        ]);
        if (is_wp_error($user_id)) throw new RuntimeException($user_id->get_error_message());
        $fixture_user_ids[$fixture_name] = $user_id;
        if ($role === 'tm_doctor') {
            update_user_meta($user_id, 'tm_qualification', 'mmg');
            update_user_meta($user_id, 'tm_home_aft', $fixture_aft_id);
            tm_ensure_valid_magic_token($user_id);
        }
    }
    $doctor_id = $fixture_user_ids['doctor-occupied'];
    wp_set_current_user($fixture_user_ids['manager']);
    if ($wpdb->insert(tm_table('assignments'), ['schedule_id' => $schedule_id, 'doctor_id' => $doctor_id, 'position' => 0, 'qualification' => 'mmg']) === false
        || $wpdb->insert(tm_table('shifts'), ['schedule_id' => $schedule_id, 'doctor_id' => $doctor_id, 'shift_date' => '2099-10-01', 'slot' => '08:00-14:00']) === false) {
        throw new RuntimeException('Creazione turni demo per il test non riuscita.');
    }

    $calendar_meta = get_post_meta($schedule_id, 'tm_schedule_week', true);
    $legacy_calendar = tm_schedule_block_week($schedule_id);
    $check('calendario: il meta è la fonte primaria dello schedule', metadata_exists('post', $schedule_id, 'tm_schedule_week') && tm_board_calendar($schedule_id) === $calendar_meta);
    $check('migrazione calendario: payload invariato rispetto al blocco legacy', is_array($legacy_calendar) && $calendar_meta === $legacy_calendar);
    $check('salvataggio calendario: un valore identico è considerato successo', tm_write_schedule_week($schedule_id, $calendar_meta) === true);

    $migration_version = (int) get_option('tm_schedule_week_version');
    $calendar_hash_before = md5(wp_json_encode($calendar_meta));
    $migration_result = tm_migrate_schedule_weeks();
    $calendar_hash_after = md5(wp_json_encode(get_post_meta($schedule_id, 'tm_schedule_week', true)));
    $check('migrazione calendario: seconda esecuzione idempotente', $migration_result === true && $migration_version === (int) get_option('tm_schedule_week_version') && $calendar_hash_before === $calendar_hash_after);

    $totals = tm_schedule_slot_totals($schedule_id);
    $check('tm_schedule_slot_totals: total = taken + free', is_array($totals) && $totals['total'] === $totals['taken'] + $totals['free']);
    $check('tm_schedule_slot_totals: mese con slot aperti', is_array($totals) && $totals['total'] > 0);

    $orphaned = tm_orphaned_shifts_for_week($schedule_id, tm_board_calendar($schedule_id));
    $check('tm_orphaned_shifts_for_week: nessun turno orfano col calendario attuale', $orphaned === []);

    global $wpdb;
    $admin_payload = tm_schedule_admin_payload($schedule_id);
    $check('editor admin: espone i nomi reali delle tabelle', is_array($admin_payload)
        && ($admin_payload['tables']['posts'] ?? '') === $wpdb->posts
        && ($admin_payload['tables']['postmeta'] ?? '') === $wpdb->postmeta
        && ($admin_payload['tables']['assignments'] ?? '') === tm_table('assignments')
        && ($admin_payload['tables']['shifts'] ?? '') === tm_table('shifts')
        && ($admin_payload['tables']['logs'] ?? '') === tm_table('queue_log'));
    $first_log = $admin_payload['logs'][0] ?? null;
    $check('editor admin: il log espone le colonne della tabella', !$first_log || array_keys($first_log) === [
        'id', 'schedule_id', 'doctor_id', 'doctor', 'actor_id', 'actor', 'action', 'detail', 'created_at',
    ]);

    $document = $admin_payload['document'] ?? null;
    $check('documento turno: schema canonico esposto dal payload', is_array($document)
        && ($document['schema'] ?? '') === TM_SCHEDULE_DOCUMENT_SCHEMA
        && (int) ($document['version'] ?? 0) === TM_SCHEDULE_DOCUMENT_VERSION
        && (int) ($document['schedule_id'] ?? 0) === $schedule_id);
    $validated_document = tm_schedule_admin_validate_document($schedule_id, $document);
    $payload_validation = $admin_payload['document_validation'] ?? [];
    $check('documento turno: il payload espone l’esito reale del validatore', is_wp_error($validated_document)
        ? empty($payload_validation['valid']) && ($payload_validation['code'] ?? '') === $validated_document->get_error_code()
        : !empty($payload_validation['valid']));
    if (is_wp_error($validated_document)) {
        echo 'INFO  Validazione documento: ' . $validated_document->get_error_code() . ' · ' . $validated_document->get_error_message() . "\n";
    }

    $repair_document = $document;
    $repair_document['meta']['tm_participants_confirmed_at'] = '';
    $repair_document['meta']['tm_participants_confirmed_signature'] = '';
    $repair_document['meta']['tm_ranking_snapshot'] = [];
    $repair_document['meta']['tm_queue_notified'] = 0;
    $repair_document['meta']['tm_queue_notified_signature'] = '';
    $repair_document['meta']['tm_completed_notified_signature'] = '';
    $validated_repair_document = tm_schedule_admin_validate_document($schedule_id, $repair_document);
    $check('documento turno: lo stato operativo incoerente è riparabile dal JSON', !is_wp_error($validated_repair_document));

    $invalid_document = $document;
    $invalid_document['schema'] = 'turnimedici.invalid';
    $invalid_validation = tm_schedule_admin_validate_document($schedule_id, $invalid_document);
    $check('documento turno: schema errato rifiutato', is_wp_error($invalid_validation) && $invalid_validation->get_error_code() === 'tm_document_schema');

    $snapshot_document = tm_schedule_admin_snapshot_document(tm_schedule_admin_snapshot($schedule_id, 'smoke test'));
    $check('snapshot turno: usa lo stesso schema del documento live', is_array($snapshot_document)
        && ($snapshot_document['schema'] ?? '') === ($document['schema'] ?? '')
        && ($snapshot_document['meta'] ?? null) === ($document['meta'] ?? null)
        && ($snapshot_document['assignments'] ?? null) === ($document['assignments'] ?? null)
        && ($snapshot_document['shifts'] ?? null) === ($document['shifts'] ?? null));

    $expected_state_hash = hash('sha256', wp_json_encode([
        'state'            => tm_schedule_admin_current_state($schedule_id),
        'operational_meta' => tm_schedule_admin_current_operational_meta($schedule_id),
    ]));
    $check('concorrenza turno: hash copre stato e metadati operativi', hash_equals($expected_state_hash, tm_schedule_admin_state_hash($schedule_id)));

    if (!is_wp_error($validated_repair_document)) {
        $hash_before_stale_write = tm_schedule_admin_state_hash($schedule_id);
        $stale_write = tm_schedule_admin_write_state($schedule_id, $validated_repair_document['state'], 'smoke test', str_repeat('0', 64));
        $check('concorrenza turno: hash obsoleto rifiutato con 409', is_wp_error($stale_write)
            && $stale_write->get_error_code() === 'tm_stale_schedule'
            && (int) ($stale_write->get_error_data()['status'] ?? 0) === 409);
        $check('concorrenza turno: il 409 non modifica il database', hash_equals($hash_before_stale_write, tm_schedule_admin_state_hash($schedule_id)));
    }

    $transfer_payload = tm_data_transfer_export_payload(['doctors', 'afts', 'facilities']);
    $first_exported_doctor = $transfer_payload['datasets']['doctors'][0] ?? [];
    $check('trasferimento dati: payload anagrafico conforme allo schema', ($transfer_payload['schema'] ?? '') === TM_DATA_TRANSFER_SCHEMA
        && (int) ($transfer_payload['version'] ?? 0) === TM_DATA_TRANSFER_VERSION
        && isset($transfer_payload['datasets']['doctors'], $transfer_payload['datasets']['afts'], $transfer_payload['datasets']['facilities']));
    $check('trasferimento dati: nessuna password esportata', !array_key_exists('user_pass', $first_exported_doctor)
        && !array_key_exists('user_activation_key', $first_exported_doctor));
    $check('trasferimento dati: i medici espongono relazioni portabili', !$first_exported_doctor || isset($first_exported_doctor['relations']));

    $facility_id = (int) get_post_meta($schedule_id, 'tm_facility_id', true);
    if ($facility_id && tm_user_is_turnazione_doctor($doctor_id)) {
        $token_before = (string) get_user_meta($doctor_id, 'tm_magic_token', true);
        tm_ensure_valid_magic_token($doctor_id);
        $check('magic link: il token esistente resta stabile', $token_before !== '' && $token_before === (string) get_user_meta($doctor_id, 'tm_magic_token', true));

        wp_set_current_user($doctor_id);
        $submit_request = new WP_REST_Request('POST');
        $submit_request->set_url_params(['id' => $facility_id]);
        $submit_request->set_query_params(['schedule' => $schedule_id]);
        $submit_request->set_header('content-type', 'application/json');
        $submit_request->set_body(wp_json_encode(['selections' => []]));
        $submit_result = tm_rest_submit_shifts($submit_request);
        $check('REST turni: lista non confermata blocca la compilazione', is_wp_error($submit_result) && $submit_result->get_error_code() === 'tm_not_confirmed');

        wp_set_current_user($fixture_user_ids['manager']);
        $get_calendar_request = new WP_REST_Request('GET');
        $get_calendar_request->set_url_params(['id' => $facility_id]);
        $get_calendar_request->set_query_params(['schedule' => $schedule_id]);
        $get_calendar_result = tm_rest_get_calendar($get_calendar_request);
        $get_calendar_data = $get_calendar_result instanceof WP_REST_Response ? $get_calendar_result->get_data() : null;
        $check('REST calendario: GET legge il meta migrato', is_array($get_calendar_data) && ($get_calendar_data['week'] ?? null) === $calendar_meta);

        $same_calendar_request = new WP_REST_Request('POST');
        $same_calendar_request->set_url_params(['id' => $facility_id]);
        $same_calendar_request->set_query_params(['schedule' => $schedule_id]);
        $same_calendar_request->set_header('content-type', 'application/json');
        $same_calendar_request->set_body(wp_json_encode(['week' => $calendar_meta]));
        $same_calendar_result = tm_rest_save_calendar($same_calendar_request);
        $check('REST calendario: POST identico non produce un falso errore', !is_wp_error($same_calendar_result) && md5(wp_json_encode(get_post_meta($schedule_id, 'tm_schedule_week', true))) === $calendar_hash_before);

        global $wpdb;
        $used_shift = $wpdb->get_row($wpdb->prepare(
            'SELECT shift_date FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d ORDER BY shift_date ASC, id ASC LIMIT 1',
            $schedule_id
        ));
        if ($used_shift) {
            $orphan_week = $calendar_meta;
            $orphan_week['overrides'][(string) $used_shift->shift_date] = ['closed' => true];
            $orphan_request = new WP_REST_Request('POST');
            $orphan_request->set_url_params(['id' => $facility_id]);
            $orphan_request->set_query_params(['schedule' => $schedule_id]);
            $orphan_request->set_header('content-type', 'application/json');
            $orphan_request->set_body(wp_json_encode(['week' => $orphan_week]));
            $orphan_result = tm_rest_save_calendar($orphan_request);
            $check('REST calendario: modifica con turni orfani rifiutata con 409', is_wp_error($orphan_result) && $orphan_result->get_error_code() === 'tm_orphaned_shifts' && (int) ($orphan_result->get_error_data()['status'] ?? 0) === 409);
            $check('REST calendario: il 409 non modifica il meta', md5(wp_json_encode(get_post_meta($schedule_id, 'tm_schedule_week', true))) === $calendar_hash_before);
        } else {
            echo "SKIP  nessun turno assegnato: test 409 orfani saltato\n";
        }

        $calendar_request = new WP_REST_Request('POST');
        $calendar_request->set_url_params(['id' => $facility_id]);
        $calendar_request->set_query_params(['schedule' => $schedule_id]);
        $calendar_request->set_header('content-type', 'application/json');
        $calendar_request->set_body(wp_json_encode(['week' => [
            'viewMonth' => '2026-13',
            'defaults' => ['closedWeekdays' => [], 'slots' => [['from' => '08:00', 'to' => '13:00']]],
            'overrides' => [],
        ]]));
        $calendar_result = tm_rest_save_calendar($calendar_request);
        $check('REST calendario: mese impossibile rifiutato senza scritture', is_wp_error($calendar_result) && $calendar_result->get_error_code() === 'tm_bad_month');
        wp_set_current_user($fixture_user_ids['manager']);
    }

    $conflict = tm_doctor_shift_conflict($fixture_user_ids['doctor-occupied'], '2099-10-01', '12:00-14:00');
    $check('tm_doctor_shift_conflict: sovrapposizione rilevata per medico occupato', $conflict !== null && (int) $conflict['schedule_id'] === $schedule_id);

    $no_conflict = tm_doctor_shift_conflict($fixture_user_ids['doctor-free'], '2099-10-01', '12:00-14:00');
    $check('tm_doctor_shift_conflict: nessun conflitto per medico libero', $no_conflict === null);
} catch (Throwable $error) {
    $check('fixture demo: ' . $error->getMessage(), false);
} finally {
    wp_set_current_user($previous_user_id);
    if ($schedule_id) {
        foreach (['assignments', 'shifts', 'queue_log'] as $table) {
            $wpdb->delete(tm_table($table), ['schedule_id' => $schedule_id]);
        }
    }
    foreach (array_reverse($fixture_post_ids) as $post_id) wp_delete_post($post_id, true);
    foreach ($fixture_user_ids as $user_id) wp_delete_user($user_id);
}

echo $failures === 0 ? "\nTutti i test passano.\n" : "\n$failures test falliti.\n";
if ($failures) WP_CLI::halt(1);
