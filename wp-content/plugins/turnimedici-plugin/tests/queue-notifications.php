<?php

if (!defined('WP_CLI') || !WP_CLI) exit;
if (function_exists('tm_queue_notify')) {
    WP_CLI::error('Eseguire con --skip-plugins --skip-themes.');
}

require_once __DIR__ . '/../includes/board-api.php';
require_once __DIR__ . '/../includes/scheduling.php';
require_once __DIR__ . '/../includes/ranking.php';
require_once __DIR__ . '/../includes/schedules.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$GLOBALS['tm_test_emails'] = [];
add_filter('pre_wp_mail', function ($result, $message) {
    $GLOBALS['tm_test_emails'][] = $message;
    return true;
}, PHP_INT_MAX, 2);
add_filter('pre_option_tm_db_version', fn() => TM_DB_VERSION);
add_filter('pre_option_admin_email', fn() => 'coordinator@example.invalid');
add_filter('pre_option_tm_ranking_rules', fn() => array_replace(tm_default_ranking(), ['queue_expiry' => ['enabled' => true, 'days' => 2]]));

global $wpdb;
$failures = 0;
$check = function ($label, $condition) use (&$failures) {
    echo ($condition ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$condition) $failures++;
};
$schedule_id = 0;
$doctor_ids = [];
try {
    register_post_type('tm_schedule');
    $schedule_id = wp_insert_post(['post_type' => 'tm_schedule', 'post_status' => 'publish', 'post_title' => 'Queue notification regression'], true);
    if (is_wp_error($schedule_id)) throw new RuntimeException($schedule_id->get_error_message());
    foreach ([1, 2] as $index) {
        $login = 'tm_queue_test_' . wp_generate_uuid4();
        $doctor_id = wp_insert_user(['user_login' => $login, 'user_email' => $login . '@example.invalid', 'user_pass' => wp_generate_password()]);
        if (is_wp_error($doctor_id)) throw new RuntimeException($doctor_id->get_error_message());
        $doctor_ids[] = $doctor_id;
        $wpdb->insert(tm_table('assignments'), ['schedule_id' => $schedule_id, 'doctor_id' => $doctor_id, 'position' => $index, 'status' => $index === 1 ? 'done' : 'pending']);
    }
    $week = [
        'defaults' => ['closedWeekdays' => TM_BOARD_WEEKDAYS, 'slots' => [['from' => '08:00', 'to' => '14:00']]],
        'overrides' => ['2099-10-01' => ['closed' => false, 'slots' => [['from' => '08:00', 'to' => '10:00'], ['from' => '10:00', 'to' => '14:00']]]],
    ];
    update_post_meta($schedule_id, 'tm_schedule_month', '2099-10');
    update_post_meta($schedule_id, 'tm_schedule_week', $week);
    update_post_meta($schedule_id, 'tm_participants_confirmed_signature', tm_schedule_participants_signature($schedule_id));
    foreach (['08:00-10:00', '10:00-14:00'] as $slot) {
        $wpdb->insert(tm_table('shifts'), ['schedule_id' => $schedule_id, 'shift_date' => '2099-10-01', 'slot' => $slot, 'doctor_id' => $doctor_ids[0]]);
    }
    add_filter('posts_pre_query', function ($posts, $query) use ($schedule_id) {
        return $query->get('post_type') === 'tm_schedule' && $query->get('fields') === 'ids' ? [$schedule_id] : $posts;
    }, 10, 2);
    $reset_notifications = function () use ($schedule_id) {
        $GLOBALS['tm_test_emails'] = [];
        delete_post_meta($schedule_id, 'tm_queue_notified');
        delete_post_meta($schedule_id, 'tm_queue_notified_signature');
    };
    $set_pending = function ($started_at = null) use ($wpdb, $schedule_id, $doctor_ids) {
        $wpdb->update(tm_table('assignments'), ['status' => 'pending', 'turn_started_at' => $started_at], ['schedule_id' => $schedule_id, 'doctor_id' => $doctor_ids[1]]);
    };

    $result = tm_queue_notify($schedule_id);
    $check('ultimo slot occupato: nessun invito al medico ancora in coda', !$result['sent'] && $result['reason'] === 'no_free_slots' && !$GLOBALS['tm_test_emails']);
    $check('nessun timer o marcatore di notifica per il medico in attesa', !tm_queue_current_row($schedule_id)->turn_started_at && !get_post_meta($schedule_id, 'tm_queue_notified', true));

    $reset_notifications();
    $set_pending(wp_date('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS));
    tm_run_queue_expiry();
    $check('calendario pieno: il cron non salta medici e non invia email', tm_queue_current_row($schedule_id) !== null && !$GLOBALS['tm_test_emails']);

    $reset_notifications();
    $set_pending(wp_date('Y-m-d H:i:s', time() - 36 * HOUR_IN_SECONDS));
    tm_queue_maybe_send_reminder($schedule_id, tm_queue_current_row($schedule_id), tm_queue_expiry_rules());
    $check('calendario pieno: nessun promemoria al medico gia avvisato', !$GLOBALS['tm_test_emails']);

    $reset_notifications();
    $set_pending();
    $wpdb->delete(tm_table('shifts'), ['schedule_id' => $schedule_id, 'slot' => '10:00-14:00']);
    $result = tm_queue_notify($schedule_id);
    $check('uno slot torna libero: il prossimo invito parte', $result['sent'] && count($GLOBALS['tm_test_emails']) === 1 && str_starts_with($GLOBALS['tm_test_emails'][0]['subject'], 'È il tuo turno'));
    $result = tm_queue_notify($schedule_id);
    $check('invito non duplicato', !$result['sent'] && $result['reason'] === 'already_notified' && count($GLOBALS['tm_test_emails']) === 1);

    $reset_notifications();
    $set_pending();
    $wpdb->insert(tm_table('shifts'), ['schedule_id' => $schedule_id, 'shift_date' => '2099-10-02', 'slot' => '08:00-14:00', 'doctor_id' => $doctor_ids[0]]);
    $check('turni orfani esclusi dalla copertura del calendario effettivo', tm_schedule_slot_totals($schedule_id) === ['total' => 2, 'taken' => 1, 'free' => 1]);
    $result = tm_queue_notify($schedule_id);
    $check('un turno orfano non blocca un invito con slot ancora libero', $result['sent']);

    $reset_notifications();
    update_post_meta($schedule_id, 'tm_schedule_closed', true);
    $result = tm_queue_notify($schedule_id);
    $check('chiusura manuale: invito bloccato', !$result['sent'] && $result['reason'] === 'schedule_closed' && !$GLOBALS['tm_test_emails']);
    delete_post_meta($schedule_id, 'tm_schedule_closed');

    $reset_notifications();
    $closed_week = $week;
    $closed_week['overrides'] = [];
    update_post_meta($schedule_id, 'tm_schedule_week', $closed_week);
    $result = tm_queue_notify($schedule_id);
    $check('mese senza slot aperti: nessun invito', !$result['sent'] && $result['reason'] === 'no_free_slots' && !$GLOBALS['tm_test_emails']);
    update_post_meta($schedule_id, 'tm_schedule_week', $week);

    $reset_notifications();
    $set_pending(wp_date('Y-m-d H:i:s', time() - 36 * HOUR_IN_SECONDS));
    $wpdb->delete(tm_table('queue_log'), ['schedule_id' => $schedule_id, 'action' => 'notify_reminder']);
    tm_queue_maybe_send_reminder($schedule_id, tm_queue_current_row($schedule_id), tm_queue_expiry_rules());
    $check('slot libero: il promemoria ordinario resta attivo', count($GLOBALS['tm_test_emails']) === 1 && str_starts_with($GLOBALS['tm_test_emails'][0]['subject'], 'Promemoria:'));

    $reset_notifications();
    $set_pending(wp_date('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS));
    tm_run_queue_expiry();
    $subjects = array_column($GLOBALS['tm_test_emails'], 'subject');
    $check('slot libero: la scadenza ordinaria resta attiva', tm_queue_current_row($schedule_id) === null && count(array_filter($subjects, fn($subject) => str_starts_with($subject, 'Turno passato al successivo'))) === 1);
    $check('coda esaurita: conservato avviso di completamento ai gestori', count(array_filter($subjects, fn($subject) => str_starts_with($subject, 'Turnazione completata'))) === 1);
} finally {
    if ($schedule_id) {
        foreach (['assignments', 'shifts', 'queue_log'] as $table) {
            $wpdb->delete(tm_table($table), ['schedule_id' => $schedule_id]);
        }
        wp_delete_post($schedule_id, true);
    }
    foreach ($doctor_ids as $doctor_id) wp_delete_user($doctor_id);
}

if ($failures) WP_CLI::error($failures . ' test falliti.');
WP_CLI::success('Notifiche verificate; dati di prova rimossi, nessuna email inviata.');
