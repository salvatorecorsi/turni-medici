<?php

if (!defined('ABSPATH')) exit;

const TM_DB_VERSION = '4';

function tm_table($name) {
    global $wpdb;
    return $wpdb->prefix . 'tm_' . $name;
}

function tm_create_scheduling_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $assignments = tm_table('assignments');
    $shifts = tm_table('shifts');
    $estar_dump = tm_table('estar_dump');

    dbDelta("CREATE TABLE $assignments (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        schedule_id bigint(20) unsigned NOT NULL,
        doctor_id bigint(20) unsigned NOT NULL,
        position int NOT NULL DEFAULT 0,
        required_hours decimal(5,2) NOT NULL DEFAULT 0,
        status varchar(20) NOT NULL DEFAULT 'pending',
        turn_started_at datetime NULL DEFAULT NULL,
        qualification varchar(30) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        UNIQUE KEY schedule_doctor (schedule_id, doctor_id),
        KEY schedule_id (schedule_id)
    ) $charset_collate;");

    dbDelta("CREATE TABLE $shifts (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        schedule_id bigint(20) unsigned NOT NULL,
        shift_date date NOT NULL,
        slot varchar(20) NOT NULL,
        doctor_id bigint(20) unsigned NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY schedule_date_slot (schedule_id, shift_date, slot),
        KEY schedule_id (schedule_id),
        KEY doctor_id (doctor_id)
    ) $charset_collate;");

    $queue_log = tm_table('queue_log');
    dbDelta("CREATE TABLE $queue_log (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        schedule_id bigint(20) unsigned NOT NULL,
        doctor_id bigint(20) unsigned NOT NULL,
        actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
        action varchar(20) NOT NULL,
        detail varchar(190) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY schedule_id (schedule_id)
    ) $charset_collate;");

    dbDelta("CREATE TABLE $estar_dump (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        estar_id varchar(20) NOT NULL,
        nome varchar(120) NOT NULL DEFAULT '',
        cognome varchar(120) NOT NULL DEFAULT '',
        tipologia varchar(20) NOT NULL DEFAULT '',
        ambito varchar(190) NOT NULL DEFAULT '',
        aft varchar(190) NOT NULL DEFAULT '',
        codice_regionale varchar(20) NOT NULL DEFAULT '',
        scelte_disponibili int NOT NULL DEFAULT 0,
        ambulatori longtext NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY estar_id (estar_id),
        KEY cognome (cognome),
        KEY nome (nome)
    ) $charset_collate;");

    update_option('tm_db_version', TM_DB_VERSION);
}

add_action('init', 'tm_maybe_upgrade_db', 5);
function tm_maybe_upgrade_db() {
    if (get_option('tm_db_version') !== TM_DB_VERSION) {
        tm_create_scheduling_tables();
    }
}

/* ============================================================
   Scadenza del turno di compilazione: se il medico di turno non
   risponde entro il limite, viene saltato e la coda avanza.
   ============================================================ */

function tm_queue_expiry_rules() {
    $ranking = tm_get_ranking();
    $rules = is_array($ranking['queue_expiry'] ?? null) ? $ranking['queue_expiry'] : [];
    return [
        'enabled' => !empty($rules['enabled']),
        'days'    => max(1, (int) ($rules['days'] ?? 2)),
    ];
}

add_action('init', function () {
    if (!wp_next_scheduled('tm_queue_expiry_tick')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'tm_queue_expiry_tick');
    }
});

add_action('tm_queue_expiry_tick', 'tm_run_queue_expiry');
function tm_run_queue_expiry() {
    tm_maybe_upgrade_db();
    $rules = tm_queue_expiry_rules();
    if (!$rules['enabled']) return;

    $deadline = $rules['days'] * DAY_IN_SECONDS;

    $schedules = get_posts(['post_type' => 'tm_schedule', 'numberposts' => -1, 'post_status' => 'publish', 'fields' => 'ids']);
    foreach ($schedules as $schedule_id) {
        if (get_post_meta($schedule_id, 'tm_schedule_closed', true)) continue;
        if (!tm_schedule_participants_confirmed($schedule_id)) continue;

        $result = tm_with_shift_write_lock(function () use ($schedule_id, $deadline) {
            global $wpdb;
            $totals = tm_schedule_slot_totals($schedule_id);
            if ($totals !== null && $totals['free'] === 0) return null;

            $table = tm_table('assignments');
            $skipped = [];
            $reminder = null;
            for ($step = 0; $step < 2; $step++) {
                $current = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, doctor_id, turn_started_at FROM $table WHERE schedule_id = %d AND (status IS NULL OR status = '' OR status NOT IN ('done', 'skipped')) ORDER BY position ASC, id ASC LIMIT 1",
                    $schedule_id
                ));
                if (!$current) break;
                if (!$current->turn_started_at) {
                    $current->turn_started_at = current_time('mysql');
                    $wpdb->update($table, ['turn_started_at' => $current->turn_started_at], ['id' => $current->id]);
                    break;
                }
                $started_at = tm_local_datetime($current->turn_started_at);
                if (!$started_at || time() - $started_at->getTimestamp() < $deadline) {
                    $reminder = $current;
                    break;
                }
                if ($wpdb->update($table, ['status' => 'skipped'], ['id' => $current->id]) === false) break;
                $skipped[] = (int) $current->doctor_id;
            }
            return ['skipped' => $skipped, 'reminder' => $reminder];
        });
        if (is_wp_error($result) || $result === null) continue;
        foreach ($result['skipped'] as $doctor_id) {
            tm_queue_log_add($schedule_id, $doctor_id, 'auto_skip', 0, $rules['days'] . ' giorni superati');
            tm_queue_notify_skipped($schedule_id, $doctor_id);
        }
        if ($result['reminder']) tm_queue_maybe_send_reminder($schedule_id, $result['reminder'], $rules);
        tm_queue_notify($schedule_id);
    }
}

/* ============================================================
   Log e notifiche della coda di compilazione
   ============================================================ */

function tm_queue_log_add($schedule_id, $doctor_id, $action, $actor_id = 0, $detail = '') {
    global $wpdb;
    $wpdb->insert(tm_table('queue_log'), [
        'schedule_id' => (int) $schedule_id,
        'doctor_id'   => (int) $doctor_id,
        'actor_id'    => (int) $actor_id,
        'action'      => sanitize_key($action),
        'detail'      => sanitize_text_field($detail),
        'created_at'  => current_time('mysql'),
    ]);
}

function tm_queue_month_label($schedule_id) {
    return tm_month_label(tm_month_value(tm_schedule_month($schedule_id)));
}

function tm_queue_current_row($schedule_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT id, doctor_id, turn_started_at FROM " . tm_table('assignments') . " WHERE schedule_id = %d AND (status IS NULL OR status = '' OR status NOT IN ('done', 'skipped')) ORDER BY position ASC, id ASC LIMIT 1",
        $schedule_id
    ));
}

function tm_schedule_participants_signature_from_ids($doctor_ids) {
    $doctor_ids = array_values(array_map('intval', (array) $doctor_ids));
    return $doctor_ids ? hash('sha256', implode(',', $doctor_ids)) : '';
}

function tm_schedule_participants_signature($schedule_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT doctor_id, required_hours FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d ORDER BY position ASC, id ASC',
        $schedule_id
    ), ARRAY_A);
    if (!$rows) return '';
    $signature_rows = array_map(fn($row) => [
        'doctor_id'      => (int) $row['doctor_id'],
        'required_hours' => round((float) $row['required_hours'], 2),
    ], $rows);
    return hash('sha256', wp_json_encode($signature_rows));
}

function tm_schedule_participants_confirmed($schedule_id) {
    $signature = tm_schedule_participants_signature($schedule_id);
    if (!$signature) return false;

    $confirmed_signature = (string) get_post_meta($schedule_id, 'tm_participants_confirmed_signature', true);
    if ($confirmed_signature && hash_equals($confirmed_signature, $signature)) return true;

    return false;
}

function tm_refresh_schedule_participants_confirmation($schedule_id) {
    $signature = tm_schedule_participants_signature($schedule_id);
    $confirmed_signature = (string) get_post_meta($schedule_id, 'tm_participants_confirmed_signature', true);
    if ($signature && $confirmed_signature && hash_equals($confirmed_signature, $signature)) return;

    delete_post_meta($schedule_id, 'tm_participants_confirmed_at');
    delete_post_meta($schedule_id, 'tm_participants_confirmed_signature');
    delete_post_meta($schedule_id, 'tm_ranking_snapshot');
}

function tm_queue_notify_log($schedule_id, $doctor_id, $action, $detail = '') {
    if (function_exists('tm_queue_log_add')) {
        tm_queue_log_add($schedule_id, (int) $doctor_id, $action, get_current_user_id(), substr((string) $detail, 0, 180));
    }
}

/* Avvisa il medico appena la coda arriva a lui (una sola volta per medico per mese) */
function tm_queue_notify($schedule_id) {
    $result = tm_with_database_lock('queue_notify_' . (int) $schedule_id, fn() => tm_queue_notify_unlocked($schedule_id));
    if (is_wp_error($result)) {
        return ['sent' => false, 'reason' => 'busy', 'message' => $result->get_error_message()];
    }
    return $result;
}

function tm_queue_notify_unlocked($schedule_id) {
    if (get_post_status($schedule_id) !== 'publish') {
        tm_queue_notify_log($schedule_id, 0, 'notify_blocked', 'schedule_not_publish');
        return ['sent' => false, 'reason' => 'schedule_not_publish'];
    }
    if (get_post_meta($schedule_id, 'tm_schedule_closed', true)) {
        tm_queue_notify_log($schedule_id, 0, 'notify_blocked', 'schedule_closed');
        return ['sent' => false, 'reason' => 'schedule_closed'];
    }
    if (!tm_schedule_participants_confirmed($schedule_id)) {
        tm_queue_notify_log($schedule_id, 0, 'notify_blocked', 'not_confirmed');
        return ['sent' => false, 'reason' => 'not_confirmed'];
    }

    $current = tm_queue_current_row($schedule_id);
    if (!$current) {
        tm_queue_notify_log($schedule_id, 0, 'notify_blocked', 'no_current');
        tm_queue_notify_completed($schedule_id);
        return ['sent' => false, 'reason' => 'no_current'];
    }

    $totals = tm_schedule_slot_totals($schedule_id);
    if ($totals !== null && $totals['free'] === 0) {
        tm_queue_notify_log($schedule_id, (int) $current->doctor_id, 'notify_blocked', 'no_free_slots');
        return ['sent' => false, 'reason' => 'no_free_slots'];
    }

    $signature = tm_schedule_participants_signature($schedule_id);
    $notified_doctor_id = (int) get_post_meta($schedule_id, 'tm_queue_notified', true);
    $notified_signature = (string) get_post_meta($schedule_id, 'tm_queue_notified_signature', true);
    if ($notified_doctor_id === (int) $current->doctor_id && $signature && $notified_signature && hash_equals($notified_signature, $signature)) {
        tm_queue_notify_log($schedule_id, (int) $current->doctor_id, 'notify_blocked', 'already_notified');
        return ['sent' => false, 'reason' => 'already_notified', 'doctor_id' => (int) $current->doctor_id];
    }

    global $wpdb;
    $rules = tm_queue_expiry_rules();
    if ($rules['enabled'] && !$current->turn_started_at) {
        $current->turn_started_at = current_time('mysql');
        $wpdb->update(tm_table('assignments'), ['turn_started_at' => $current->turn_started_at], ['id' => $current->id]);
    }

    $user = get_userdata((int) $current->doctor_id);
    if (!$user || !$user->user_email) {
        tm_queue_notify_log($schedule_id, (int) $current->doctor_id, 'notify_failed', 'missing_email');
        return ['sent' => false, 'reason' => 'missing_email', 'doctor_id' => (int) $current->doctor_id];
    }
    if (function_exists('tm_ensure_valid_magic_token')) tm_ensure_valid_magic_token($user->ID);

    $structure = get_the_title((int) get_post_meta($schedule_id, 'tm_facility_id', true));
    $month_label = tm_queue_month_label($schedule_id);
    $deadline_at = ($rules['enabled'] && $current->turn_started_at) ? tm_add_local_days($current->turn_started_at, $rules['days']) : '';
    $deadline = $deadline_at ? tm_format_local_datetime($deadline_at, 'd/m/Y H:i') : '';
    $link = function_exists('tm_doctor_magic_url') ? tm_doctor_magic_url($user->ID) : home_url('/');

    tm_queue_notify_log($schedule_id, $user->ID, 'notify_attempt', $user->user_email);

    $sent = wp_mail(
        $user->user_email,
        "È il tuo turno — {$structure} {$month_label}",
        "Ciao {$user->display_name},\n\nè il tuo turno di compilare le disponibilità per {$structure} — {$month_label}."
        . ($deadline ? "\nHai tempo fino al {$deadline}: dopo, la coda passa definitivamente al medico successivo." : '')
        . "\n\nCompila da qui:\n{$link}\n"
    );
    if (!$sent) {
        tm_queue_notify_log($schedule_id, $user->ID, 'notify_failed', 'send_failed');
        return ['sent' => false, 'reason' => 'send_failed', 'doctor_id' => (int) $current->doctor_id];
    }

    update_post_meta($schedule_id, 'tm_queue_notified', (int) $current->doctor_id);
    update_post_meta($schedule_id, 'tm_queue_notified_signature', $signature);
    tm_queue_notify_log($schedule_id, $user->ID, 'notify_sent', $user->user_email);
    return ['sent' => true, 'doctor_id' => (int) $current->doctor_id, 'email' => $user->user_email];
}

function tm_schedule_manager_emails($schedule_id) {
    $facility_id = (int) get_post_meta($schedule_id, 'tm_facility_id', true);
    $aft_id = $facility_id ? (int) get_post_meta($facility_id, 'tm_aft_id', true) : 0;

    $emails = [];
    if (defined('TM_COORDINATOR_ROLE')) {
        foreach (get_users(['role' => TM_COORDINATOR_ROLE]) as $user) {
            if (!$user->user_email) continue;
            $coordinated_facility = (int) get_user_meta($user->ID, 'tm_coordinated_facility', true);
            $coordinated_aft = (int) get_user_meta($user->ID, 'tm_coordinated_aft', true);
            if (($facility_id && $coordinated_facility === $facility_id) || ($aft_id && $coordinated_aft === $aft_id)) {
                $emails[] = $user->user_email;
            }
        }
    }
    if (!$emails) $emails[] = get_option('admin_email');
    return array_values(array_unique(array_filter($emails)));
}

function tm_schedule_slot_totals($schedule_id) {
    global $wpdb;
    if (!function_exists('tm_board_calendar') || !function_exists('tm_board_effective_day')) return null;

    $week = tm_board_calendar($schedule_id);
    $month = tm_schedule_month($schedule_id);
    if (!is_array($week) || empty($month['year']) || empty($month['month'])) return null;

    $shifts = [];
    foreach ($wpdb->get_results($wpdb->prepare(
        'SELECT shift_date, slot FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d',
        $schedule_id
    )) as $shift) {
        $shifts[$shift->shift_date][$shift->slot] = true;
    }

    $days_in_month = (int) date('t', mktime(0, 0, 0, (int) $month['month'], 1, (int) $month['year']));
    $total = 0;
    $taken = 0;
    for ($day = 1; $day <= $days_in_month; $day++) {
        $iso = sprintf('%04d-%02d-%02d', $month['year'], $month['month'], $day);
        $effective = tm_board_effective_day($week, $iso);
        if (empty($effective['open'])) continue;
        foreach ((array) ($effective['slots'] ?? []) as $slot) {
            $total++;
            if (isset($shifts[$iso][tm_slot_key($slot)])) $taken++;
        }
    }

    return ['total' => $total, 'taken' => $taken, 'free' => max(0, $total - $taken)];
}

/* Avvisa i gestori quando l'ultimo medico in coda ha compilato o saltato (una volta per roster) */
function tm_queue_notify_completed($schedule_id) {
    global $wpdb;
    $participants = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d',
        $schedule_id
    ));
    if (!$participants) return;

    $signature = tm_schedule_participants_signature($schedule_id);
    $notified = (string) get_post_meta($schedule_id, 'tm_completed_notified_signature', true);
    if ($signature && $notified && hash_equals($notified, $signature)) return;

    $structure = get_the_title((int) get_post_meta($schedule_id, 'tm_facility_id', true));
    $month_label = tm_queue_month_label($schedule_id);
    $totals = tm_schedule_slot_totals($schedule_id);
    $coverage = '';
    if ($totals !== null) {
        $coverage = $totals['free'] === 0
            ? 'Tutti gli slot del mese sono coperti.'
            : "Restano {$totals['free']} slot scoperti su {$totals['total']}.";
    }

    $sent_any = false;
    foreach (tm_schedule_manager_emails($schedule_id) as $email) {
        $sent = wp_mail(
            $email,
            "Turnazione completata — {$structure} {$month_label}",
            "Tutti i medici in coda per {$structure} — {$month_label} hanno compilato o saltato il proprio turno.\n"
            . ($coverage ? $coverage . "\n" : '')
            . "\nApri la board per completare le assegnazioni mancanti:\n" . home_url('/') . "\n"
        );
        $sent_any = $sent_any || $sent;
    }
    if ($sent_any) {
        update_post_meta($schedule_id, 'tm_completed_notified_signature', $signature);
        tm_queue_notify_log($schedule_id, 0, 'notify_completed', $coverage ?: 'coda esaurita');
    }
}

function tm_queue_reminder_already_sent($schedule_id, $doctor_id, $turn_started_at) {
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . tm_table('queue_log') . " WHERE schedule_id = %d AND doctor_id = %d AND action = 'notify_reminder' AND created_at >= %s LIMIT 1",
        $schedule_id,
        $doctor_id,
        $turn_started_at
    ));
}

/* Sollecito al medico corrente superata metà della finestra di compilazione */
function tm_queue_maybe_send_reminder($schedule_id, $current, $rules) {
    if (!$current || !$current->turn_started_at) return;
    if (get_post_meta($schedule_id, 'tm_schedule_closed', true)) return;
    $totals = tm_schedule_slot_totals($schedule_id);
    if ($totals !== null && $totals['free'] === 0) return;

    $deadline_seconds = $rules['days'] * DAY_IN_SECONDS;
    $started_at = tm_local_datetime($current->turn_started_at);
    if (!$started_at) return;
    $elapsed = time() - $started_at->getTimestamp();
    if ($elapsed < $deadline_seconds / 2 || $elapsed >= $deadline_seconds) return;
    if (tm_queue_reminder_already_sent($schedule_id, (int) $current->doctor_id, $current->turn_started_at)) return;

    $user = get_userdata((int) $current->doctor_id);
    if (!$user || !$user->user_email) return;
    if (function_exists('tm_ensure_valid_magic_token')) tm_ensure_valid_magic_token($user->ID);

    $structure = get_the_title((int) get_post_meta($schedule_id, 'tm_facility_id', true));
    $month_label = tm_queue_month_label($schedule_id);
    $deadline = tm_format_local_datetime(tm_add_local_days($current->turn_started_at, $rules['days']), 'd/m/Y H:i');
    $link = function_exists('tm_doctor_magic_url') ? tm_doctor_magic_url($user->ID) : home_url('/');

    $sent = wp_mail(
        $user->user_email,
        "Promemoria: disponibilità da compilare — {$structure} {$month_label}",
        "Ciao {$user->display_name},\n\nti ricordiamo che è ancora il tuo turno di compilare le disponibilità per {$structure} — {$month_label}.\nHai tempo fino al {$deadline}: dopo, la coda passa al medico successivo.\n\nCompila da qui:\n{$link}\n"
    );
    if ($sent) {
        tm_queue_notify_log($schedule_id, $user->ID, 'notify_reminder', $user->user_email);
    }
}

function tm_queue_notify_skipped($schedule_id, $doctor_id) {
    $user = get_userdata((int) $doctor_id);
    if (!$user || !$user->user_email) return;
    if (function_exists('tm_ensure_valid_magic_token')) tm_ensure_valid_magic_token($user->ID);

    $structure = get_the_title((int) get_post_meta($schedule_id, 'tm_facility_id', true));
    $month_label = tm_queue_month_label($schedule_id);

    wp_mail(
        $user->user_email,
        "Turno passato al successivo — {$structure} {$month_label}",
        "Ciao {$user->display_name},\n\nil tempo per compilare le disponibilità di {$structure} — {$month_label} è scaduto e la coda è passata al medico successivo. Per eventuali modifiche contatta il coordinatore orario.\n"
    );
}
