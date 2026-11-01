<?php

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', 'tm_schedule_admin_register_rest_routes');
add_action('init', 'tm_schedule_admin_migrate_editor_blocks', 30);

const TM_SCHEDULE_ADMIN_BLOCK_VERSION = 1;
const TM_SCHEDULE_DOCUMENT_SCHEMA = 'turnimedici.schedule-state';
const TM_SCHEDULE_DOCUMENT_VERSION = 1;

function tm_schedule_admin_block_content() {
    return get_comment_delimited_block_content('turnimedici/schedule-admin', ['values' => ['panel' => ['revision' => 0]]], '');
}

function tm_schedule_admin_has_block($content) {
    foreach (parse_blocks((string) $content) as $block) {
        if (($block['blockName'] ?? '') === 'turnimedici/schedule-admin') return true;
    }
    return false;
}

function tm_schedule_admin_migrate_editor_blocks() {
    if ((int) get_option('tm_schedule_admin_block_version') >= TM_SCHEDULE_ADMIN_BLOCK_VERSION) return;
    if (!function_exists('defb_get_block')) return;
    $result = tm_with_database_lock('schedule_admin_blocks', function () {
        if ((int) get_option('tm_schedule_admin_block_version') >= TM_SCHEDULE_ADMIN_BLOCK_VERSION) return true;
        $errors = [];
        $schedule_ids = get_posts([
            'post_type'      => 'tm_schedule',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);
        foreach ($schedule_ids as $schedule_id) {
            $post = get_post($schedule_id);
            if (!$post || tm_schedule_admin_has_block($post->post_content)) continue;
            $legacy_week = tm_schedule_block_week($schedule_id);
            if (is_array($legacy_week) && !metadata_exists('post', $schedule_id, 'tm_schedule_legacy_week')) {
                update_post_meta($schedule_id, 'tm_schedule_legacy_week', $legacy_week);
            }
            if ($post->post_content !== '' && !metadata_exists('post', $schedule_id, 'tm_schedule_legacy_content')) {
                update_post_meta($schedule_id, 'tm_schedule_legacy_content', $post->post_content);
            }
            $updated = wp_update_post([
                'ID'           => $schedule_id,
                'post_content' => tm_schedule_admin_block_content(),
            ], true);
            if (is_wp_error($updated)) $errors[$schedule_id] = $updated->get_error_message();
        }
        if ($errors) {
            update_option('tm_schedule_admin_block_errors', $errors, false);
            return false;
        }
        delete_option('tm_schedule_admin_block_errors');
        update_option('tm_schedule_admin_block_version', TM_SCHEDULE_ADMIN_BLOCK_VERSION, false);
        return true;
    }, 4);
    return !is_wp_error($result) && $result === true;
}

function tm_schedule_admin_doctor_name($doctor_id, $with_email = false) {
    $user = get_userdata((int) $doctor_id);
    if (!$user) return '#' . (int) $doctor_id . ' (utente mancante)';
    $name = trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name;
    return $with_email && $user->user_email ? $name . ' · ' . $user->user_email : $name;
}

function tm_schedule_admin_doctors($extra_ids = []) {
    $ids = function_exists('tm_turnazione_doctor_ids') ? tm_turnazione_doctor_ids() : [];
    $ids = array_values(array_unique(array_filter(array_merge(array_map('intval', $ids), array_map('intval', (array) $extra_ids)))));
    usort($ids, fn($a, $b) => strcasecmp(tm_schedule_admin_doctor_name($a), tm_schedule_admin_doctor_name($b)));
    return $ids;
}

function tm_schedule_admin_facilities() {
    return get_posts([
        'post_type'      => 'tm_facility',
        'post_status'    => ['publish', 'draft'],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);
}

function tm_schedule_admin_assignments($schedule_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        'SELECT assignments.*, COUNT(shifts.id) AS shift_count
         FROM ' . tm_table('assignments') . ' assignments
         LEFT JOIN ' . tm_table('shifts') . ' shifts ON shifts.schedule_id = assignments.schedule_id AND shifts.doctor_id = assignments.doctor_id
         WHERE assignments.schedule_id = %d
         GROUP BY assignments.id
         ORDER BY assignments.position ASC, assignments.id ASC',
        (int) $schedule_id
    ));
}

function tm_schedule_admin_shifts($schedule_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        'SELECT id, shift_date, slot, doctor_id FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d ORDER BY shift_date ASC, slot ASC, id ASC',
        (int) $schedule_id
    ));
}

function tm_schedule_admin_snapshot($schedule_id, $action = 'manuale') {
    global $wpdb;
    $schedule_id = (int) $schedule_id;
    $post = get_post($schedule_id);
    $facility_id = (int) get_post_meta($schedule_id, 'tm_facility_id', true);
    $meta_keys = [
        'tm_schedule_month',
        'tm_schedule_closed',
        'tm_schedule_week',
        'tm_participants_confirmed_at',
        'tm_participants_confirmed_signature',
        'tm_ranking_snapshot',
        'tm_queue_notified',
        'tm_queue_notified_signature',
        'tm_completed_notified_signature',
    ];
    $meta = [];
    foreach ($meta_keys as $meta_key) $meta[$meta_key] = get_post_meta($schedule_id, $meta_key, true);
    return [
        'schema'         => TM_SCHEDULE_DOCUMENT_SCHEMA,
        'version'        => 1,
        'created_at'     => current_time('mysql'),
        'actor_id'       => get_current_user_id(),
        'action'         => sanitize_text_field($action),
        'schedule_id'    => $schedule_id,
        'post'           => [
            'title'  => $post ? $post->post_title : '',
            'status' => $post ? $post->post_status : '',
        ],
        'facility_id'    => $facility_id,
        'default'        => $facility_id && (int) get_post_meta($facility_id, 'tm_default_schedule', true) === $schedule_id,
        'meta'           => $meta,
        'assignments'    => $wpdb->get_results($wpdb->prepare(
            'SELECT doctor_id, position, required_hours, status, turn_started_at, qualification FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d ORDER BY position ASC, id ASC',
            $schedule_id
        ), ARRAY_A),
        'shifts'         => $wpdb->get_results($wpdb->prepare(
            'SELECT shift_date, slot, doctor_id FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d ORDER BY shift_date ASC, slot ASC, id ASC',
            $schedule_id
        ), ARRAY_A),
    ];
}

function tm_schedule_admin_snapshot_document($snapshot) {
    if (!is_array($snapshot)) return null;
    return [
        'schema'        => TM_SCHEDULE_DOCUMENT_SCHEMA,
        'version'       => TM_SCHEDULE_DOCUMENT_VERSION,
        'revision_id'   => !empty($snapshot['snapshot_id']) ? sanitize_text_field((string) $snapshot['snapshot_id']) : null,
        'created_at'    => sanitize_text_field((string) ($snapshot['created_at'] ?? '')),
        'actor_id'      => absint($snapshot['actor_id'] ?? 0),
        'action'        => sanitize_text_field((string) ($snapshot['action'] ?? '')),
        'schedule_id'   => absint($snapshot['schedule_id'] ?? 0),
        'facility_id'   => absint($snapshot['facility_id'] ?? 0),
        'default'       => !empty($snapshot['default']),
        'meta'          => is_array($snapshot['meta'] ?? null) ? $snapshot['meta'] : [],
        'assignments'   => array_values((array) ($snapshot['assignments'] ?? [])),
        'shifts'        => array_values((array) ($snapshot['shifts'] ?? [])),
        'write_options' => [
            'month_strategy'  => sanitize_key((string) ($snapshot['write_options']['month_strategy'] ?? 'reject')),
            'orphan_strategy' => sanitize_key((string) ($snapshot['write_options']['orphan_strategy'] ?? 'reject')),
        ],
    ];
}

function tm_schedule_admin_operational_meta($meta) {
    $meta = is_array($meta) ? $meta : [];
    return [
        'tm_participants_confirmed_at'        => sanitize_text_field((string) ($meta['tm_participants_confirmed_at'] ?? '')),
        'tm_participants_confirmed_signature' => sanitize_text_field((string) ($meta['tm_participants_confirmed_signature'] ?? '')),
        'tm_ranking_snapshot'                 => is_array($meta['tm_ranking_snapshot'] ?? null) ? $meta['tm_ranking_snapshot'] : '',
        'tm_queue_notified'                   => absint($meta['tm_queue_notified'] ?? 0),
        'tm_queue_notified_signature'         => sanitize_text_field((string) ($meta['tm_queue_notified_signature'] ?? '')),
        'tm_completed_notified_signature'     => sanitize_text_field((string) ($meta['tm_completed_notified_signature'] ?? '')),
    ];
}

function tm_schedule_admin_current_operational_meta($schedule_id) {
    $meta = [];
    foreach (array_keys(tm_schedule_admin_operational_meta([])) as $key) {
        $meta[$key] = get_post_meta((int) $schedule_id, $key, true);
    }
    return tm_schedule_admin_operational_meta($meta);
}

function tm_schedule_admin_apply_operational_meta($schedule_id, $meta) {
    foreach (tm_schedule_admin_operational_meta($meta) as $key => $value) {
        if ($value === '' || $value === 0 || $value === []) {
            if (!delete_post_meta($schedule_id, $key) && metadata_exists('post', $schedule_id, $key)) {
                return new WP_Error('tm_operational_meta_failed', 'Ripristino dello stato operativo non riuscito.');
            }
            continue;
        }
        if (!tm_schedule_admin_update_meta($schedule_id, $key, $value)) {
            return new WP_Error('tm_operational_meta_failed', 'Ripristino dello stato operativo non riuscito.');
        }
    }
    return true;
}

function tm_schedule_admin_store_snapshot($schedule_id, $action) {
    $snapshots = get_post_meta((int) $schedule_id, 'tm_admin_repair_snapshots', true);
    $snapshots = is_array($snapshots) ? $snapshots : [];
    $snapshot = tm_schedule_admin_snapshot($schedule_id, $action);
    $snapshot['snapshot_id'] = wp_generate_uuid4();
    array_unshift($snapshots, $snapshot);
    $snapshots = array_slice($snapshots, 0, 50);
    $updated = update_post_meta((int) $schedule_id, 'tm_admin_repair_snapshots', $snapshots);
    return $updated !== false || get_post_meta((int) $schedule_id, 'tm_admin_repair_snapshots', true) === $snapshots;
}

function tm_schedule_admin_snapshot_reference($snapshot) {
    if (!empty($snapshot['snapshot_id'])) return sanitize_text_field((string) $snapshot['snapshot_id']);
    return hash('sha256', wp_json_encode($snapshot));
}

function tm_schedule_admin_find_snapshot($schedule_id, $reference) {
    $reference = sanitize_text_field((string) $reference);
    $snapshots = get_post_meta((int) $schedule_id, 'tm_admin_repair_snapshots', true);
    if (!$reference || !is_array($snapshots)) return null;
    foreach ($snapshots as $snapshot) {
        if (hash_equals(tm_schedule_admin_snapshot_reference($snapshot), $reference)) return $snapshot;
    }
    return null;
}

function tm_schedule_admin_external_conflict($schedule_id, $doctor_id, $date, $slot) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT shifts.schedule_id, shifts.slot
         FROM ' . tm_table('shifts') . ' shifts
         INNER JOIN ' . $wpdb->posts . " schedules ON schedules.ID = shifts.schedule_id AND schedules.post_type = 'tm_schedule' AND schedules.post_status IN ('publish', 'draft')
         WHERE shifts.schedule_id <> %d AND shifts.doctor_id = %d AND shifts.shift_date = %s",
        (int) $schedule_id,
        (int) $doctor_id,
        (string) $date
    ));
    foreach ($rows as $row) {
        if (!tm_slots_overlap($slot, $row->slot)) continue;
        $facility_id = (int) get_post_meta((int) $row->schedule_id, 'tm_facility_id', true);
        return [
            'schedule_id' => (int) $row->schedule_id,
            'facility'    => $facility_id ? get_the_title($facility_id) : '',
            'slot'        => (string) $row->slot,
        ];
    }
    return null;
}

function tm_schedule_admin_diagnostics($schedule_id) {
    global $wpdb;
    $schedule_id = (int) $schedule_id;
    $items = [];
    $add = function ($level, $message) use (&$items) {
        $items[] = ['level' => $level, 'message' => $message];
    };
    $facility_id = (int) get_post_meta($schedule_id, 'tm_facility_id', true);
    if (get_post_type($facility_id) !== 'tm_facility') {
        $add('error', 'Struttura mancante o non valida.');
    } elseif (!tm_facility_has_aft($facility_id)) {
        $add('error', 'La struttura non ha una AFT valida.');
    }
    $month = (string) get_post_meta($schedule_id, 'tm_schedule_month', true);
    if (!tm_valid_month_value($month)) $add('error', 'Mese non valido.');
    $week = tm_board_calendar($schedule_id);
    if (!metadata_exists('post', $schedule_id, 'tm_schedule_week')) $add('warning', 'Il calendario usa ancora il fallback legacy.');
    if (!is_array($week)) {
        $add('error', 'Calendario mancante o illeggibile.');
    } else {
        $validated = tm_sanitize_week($week, true);
        if (is_wp_error($validated)) $add('error', $validated->get_error_message());
        if (tm_valid_month_value($month) && ($week['viewMonth'] ?? '') !== $month) $add('error', 'Il mese del calendario non coincide con il turno.');
        $orphaned = tm_orphaned_shifts_for_week($schedule_id, $week);
        if ($orphaned) {
            $total = array_sum(array_map(fn($row) => (int) ($row['count'] ?? 0), $orphaned));
            $add('error', $total . ' turni non appartengono più alle fasce del calendario.');
        }
    }
    if (tm_valid_month_value($month)) {
        $outside = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND DATE_FORMAT(shift_date, %s) <> %s',
            $schedule_id,
            '%Y-%m',
            $month
        ));
        if ($outside) $add('error', $outside . ' turni hanno una data fuori dal mese.');
    }
    $assignments = tm_schedule_admin_assignments($schedule_id);
    $positions = array_map(fn($row) => (int) $row->position, $assignments);
    $expected_positions = $positions ? range(0, count($positions) - 1) : [];
    if ($positions !== $expected_positions) $add('warning', 'Le posizioni della coda non sono consecutive.');
    $assignment_ids = array_map(fn($row) => (int) $row->doctor_id, $assignments);
    foreach ($assignments as $assignment) {
        $doctor_id = (int) $assignment->doctor_id;
        if (!get_userdata($doctor_id) || !tm_user_is_turnazione_doctor($doctor_id)) {
            $add('error', 'Partecipante non valido: #' . $doctor_id . '.');
        } elseif (!tm_doctor_professional_data_complete($doctor_id)) {
            $add('warning', tm_schedule_admin_doctor_name($doctor_id) . ' ha dati professionali incompleti.');
        }
        if (!in_array((string) $assignment->status, ['pending', 'done', 'skipped'], true)) {
            $add('error', tm_schedule_admin_doctor_name($doctor_id) . ' ha uno stato coda non valido.');
        }
        if ((float) $assignment->required_hours < 0) $add('error', tm_schedule_admin_doctor_name($doctor_id) . ' ha ore richieste negative.');
        if ($assignment->turn_started_at && !tm_local_datetime($assignment->turn_started_at)) {
            $add('error', tm_schedule_admin_doctor_name($doctor_id) . ' ha una data di inizio turno non valida.');
        }
        if (!in_array((string) $assignment->qualification, tm_allowed_doctor_qualifications(), true)) {
            $add('warning', tm_schedule_admin_doctor_name($doctor_id) . ' ha una qualifica storica non valida nella coda.');
        }
    }
    $shifts = tm_schedule_admin_shifts($schedule_id);
    foreach ($shifts as $shift) {
        $doctor_id = (int) $shift->doctor_id;
        if (!tm_valid_iso_date($shift->shift_date)) $add('error', 'Turno con data non valida: ' . $shift->shift_date . '.');
        if (!tm_slot_range_minutes($shift->slot)) $add('error', 'Turno con fascia non valida: ' . $shift->slot . '.');
        if (!in_array($doctor_id, $assignment_ids, true)) $add('error', tm_schedule_admin_doctor_name($doctor_id) . ' ha turni ma non è nella lista.');
        if (!get_userdata($doctor_id) || !tm_user_is_turnazione_doctor($doctor_id)) $add('error', 'Turno assegnato a un medico non valido: #' . $doctor_id . '.');
        $conflict = tm_schedule_admin_external_conflict($schedule_id, $doctor_id, $shift->shift_date, $shift->slot);
        if ($conflict) $add('error', tm_schedule_admin_doctor_name($doctor_id) . ' ha un conflitto in ' . $conflict['facility'] . ' il ' . $shift->shift_date . '.');
    }
    if ($facility_id && tm_valid_month_value($month)) {
        $duplicates = get_posts([
            'post_type'      => 'tm_schedule',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post__not_in'   => [$schedule_id],
            'meta_query'     => [
                ['key' => 'tm_facility_id', 'value' => $facility_id],
                ['key' => 'tm_schedule_month', 'value' => $month],
            ],
        ]);
        if ($duplicates) $add('error', 'Esiste un altro turno per la stessa struttura e lo stesso mese.');
    }
    if (get_post_meta($schedule_id, 'tm_participants_confirmed_signature', true) && !tm_schedule_participants_confirmed($schedule_id)) {
        $add('warning', 'La conferma partecipanti non corrisponde alla lista attuale.');
    }
    $current = tm_queue_current_row($schedule_id);
    $notified = (int) get_post_meta($schedule_id, 'tm_queue_notified', true);
    $notified_signature = (string) get_post_meta($schedule_id, 'tm_queue_notified_signature', true);
    $signature = tm_schedule_participants_signature($schedule_id);
    if ($notified && (!$current || $notified !== (int) $current->doctor_id || !$signature || !$notified_signature || !hash_equals($notified_signature, $signature))) {
        $add('warning', 'Lo stato delle notifiche non coincide con il medico corrente.');
    }
    if ($facility_id) {
        $default = (int) get_post_meta($facility_id, 'tm_default_schedule', true);
        if ($default && (get_post_type($default) !== 'tm_schedule' || (int) get_post_meta($default, 'tm_facility_id', true) !== $facility_id)) {
            $add('warning', 'Il mese predefinito della struttura punta a un turno non valido.');
        }
    }
    $unique = [];
    foreach ($items as $item) $unique[$item['level'] . '|' . $item['message']] = $item;
    return array_values($unique);
}

function tm_schedule_admin_parse_datetime($value) {
    $value = trim((string) $value);
    if ($value === '') return null;
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, wp_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
        return new WP_Error('tm_bad_datetime', 'Una data di inizio turno non è valida.');
    }
    return $parsed->format('Y-m-d H:i:s');
}

function tm_schedule_admin_validate_doctor($doctor_id, $allow_existing = false) {
    if (!$doctor_id) {
        return new WP_Error('tm_bad_doctor', 'Medico non valido: #' . (int) $doctor_id . '.', ['status' => 400]);
    }
    if (!get_userdata($doctor_id) || !tm_user_is_turnazione_doctor($doctor_id)) {
        return $allow_existing
            ? true
            : new WP_Error('tm_bad_doctor', 'Medico non valido: #' . (int) $doctor_id . '.', ['status' => 400]);
    }
    if (!tm_doctor_professional_data_complete($doctor_id) && !$allow_existing) {
        return new WP_Error('tm_incomplete_doctor', 'Completa prima qualifica e AFT di ' . tm_schedule_admin_doctor_name($doctor_id) . '.', ['status' => 400]);
    }
    return true;
}

function tm_schedule_admin_prepare_state($schedule_id, $posted, $allow_snapshot_values = false) {
    $config = is_array($posted['tm_admin_config'] ?? null) ? $posted['tm_admin_config'] : [];
    $facility_id = absint($config['facility_id'] ?? 0);
    if (get_post_type($facility_id) !== 'tm_facility' || !tm_facility_has_aft($facility_id)) {
        return new WP_Error('tm_bad_facility', 'Seleziona una struttura con AFT valida.');
    }
    $month = sanitize_text_field($config['month'] ?? '');
    if (!tm_valid_month_value($month)) return new WP_Error('tm_bad_month', 'Mese non valido.');
    $decoded_week = json_decode((string) ($config['week'] ?? ''), true);
    if (!is_array($decoded_week) || json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('tm_bad_week_json', 'Il JSON del calendario non è valido: ' . json_last_error_msg() . '.');
    }
    $decoded_week['viewMonth'] = $month;
    $week = tm_sanitize_week($decoded_week, true);
    if (is_wp_error($week)) return $week;
    $duplicates = get_posts([
        'post_type'      => 'tm_schedule',
        'post_status'    => ['publish', 'draft'],
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'post__not_in'   => [(int) $schedule_id],
        'meta_query'     => [
            ['key' => 'tm_facility_id', 'value' => $facility_id],
            ['key' => 'tm_schedule_month', 'value' => $month],
        ],
    ]);
    if ($duplicates) return new WP_Error('tm_duplicate_schedule', 'Esiste già un turno per questa struttura e questo mese.');

    $existing_assignments = [];
    $existing_doctors = [];
    foreach (tm_schedule_admin_assignments($schedule_id) as $assignment) {
        $doctor_id = (int) $assignment->doctor_id;
        $existing_assignments[$doctor_id] = $assignment;
        $existing_doctors[$doctor_id] = true;
    }
    foreach (tm_schedule_admin_shifts($schedule_id) as $shift) {
        $existing_doctors[(int) $shift->doctor_id] = true;
    }

    $assignments = [];
    $deleted_shift_doctors = [];
    $seen_doctors = [];
    foreach ((array) ($posted['tm_admin_assignments'] ?? []) as $key => $row) {
        if (!is_array($row)) continue;
        $doctor_id = absint($row['doctor_id'] ?? 0);
        if ($key === 'new' && !$doctor_id) continue;
        if (!empty($row['delete'])) {
            if (!empty($row['delete_shifts']) && $doctor_id) $deleted_shift_doctors[$doctor_id] = true;
            continue;
        }
        $valid_doctor = tm_schedule_admin_validate_doctor($doctor_id, $allow_snapshot_values || isset($existing_doctors[$doctor_id]));
        if (is_wp_error($valid_doctor)) return $valid_doctor;
        if (isset($seen_doctors[$doctor_id])) return new WP_Error('tm_duplicate_doctor', tm_schedule_admin_doctor_name($doctor_id) . ' compare due volte nella lista.');
        $seen_doctors[$doctor_id] = true;
        $status = sanitize_key($row['status'] ?? 'pending');
        $existing_assignment = $existing_assignments[$doctor_id] ?? null;
        $existing_status = $existing_assignment ? (string) $existing_assignment->status : '';
        if (!in_array($status, ['pending', 'done', 'skipped'], true) && !$allow_snapshot_values && $status !== $existing_status) {
            return new WP_Error('tm_bad_status', 'Stato coda non valido.', ['status' => 400]);
        }
        $started_at = tm_schedule_admin_parse_datetime($row['turn_started_at'] ?? '');
        if (is_wp_error($started_at)) return $started_at;
        $qualification = sanitize_key($row['qualification'] ?? '');
        if (!$qualification && !$existing_assignment && !$allow_snapshot_values) {
            $qualification = (string) get_user_meta($doctor_id, 'tm_qualification', true);
        }
        $existing_qualification = $existing_assignment ? (string) $existing_assignment->qualification : '';
        if (!in_array($qualification, tm_allowed_doctor_qualifications(), true) && !$allow_snapshot_values && $qualification !== $existing_qualification) {
            return new WP_Error('tm_bad_qualification', 'Qualifica non valida per ' . tm_schedule_admin_doctor_name($doctor_id) . '.', ['status' => 400]);
        }
        $assignments[] = [
            'doctor_id'      => $doctor_id,
            'position'       => max(1, (int) ($row['position'] ?? count($assignments) + 1)),
            'required_hours' => min(999.99, max(0, round((float) ($row['required_hours'] ?? 0), 2))),
            'status'         => $status,
            'turn_started_at'=> $started_at,
            'qualification'  => $qualification,
        ];
    }
    usort($assignments, fn($a, $b) => $a['position'] <=> $b['position']);
    foreach ($assignments as $index => &$assignment) $assignment['position'] = $index;
    unset($assignment);

    $shifts = [];
    foreach ((array) ($posted['tm_admin_shifts'] ?? []) as $key => $row) {
        if (!is_array($row) || !empty($row['delete'])) continue;
        $date = sanitize_text_field($row['date'] ?? '');
        $slot = sanitize_text_field($row['slot'] ?? '');
        $doctor_id = absint($row['doctor_id'] ?? 0);
        if ($key === 'new' && !$date && !$slot && !$doctor_id) continue;
        if (!$date || !$slot || !$doctor_id) return new WP_Error('tm_incomplete_shift', 'Completa tutti i campi del nuovo turno.');
        if (isset($deleted_shift_doctors[$doctor_id])) continue;
        $valid_doctor = tm_schedule_admin_validate_doctor($doctor_id, $allow_snapshot_values || isset($existing_doctors[$doctor_id]));
        if (is_wp_error($valid_doctor)) return $valid_doctor;
        $shifts[] = ['date' => $date, 'slot' => $slot, 'doctor_id' => $doctor_id];
    }

    $old_month = (string) get_post_meta($schedule_id, 'tm_schedule_month', true);
    $month_strategy = sanitize_key($config['month_strategy'] ?? 'reject');
    if ($old_month !== $month && $shifts) {
        if ($month_strategy === 'delete') {
            $shifts = [];
        } elseif ($month_strategy === 'move') {
            foreach ($shifts as &$shift) {
                if (substr($shift['date'], 0, 7) !== $old_month) continue;
                $target = $month . '-' . substr($shift['date'], 8, 2);
                if (!tm_valid_iso_date($target)) return new WP_Error('tm_bad_moved_date', 'Il giorno ' . substr($shift['date'], 8, 2) . ' non esiste nel nuovo mese.');
                $shift['date'] = $target;
            }
            unset($shift);
        } elseif (array_filter($shifts, fn($shift) => substr($shift['date'], 0, 7) !== $month)) {
            return new WP_Error('tm_month_has_shifts', 'Il mese contiene turni: scegli se spostarli o eliminarli.');
        }
    }

    $orphan_strategy = sanitize_key($config['orphan_strategy'] ?? 'reject');
    $valid_shifts = [];
    $slot_keys = [];
    foreach ($shifts as $shift) {
        if (!tm_valid_iso_date($shift['date']) || substr($shift['date'], 0, 7) !== $month) {
            if ($orphan_strategy === 'delete') continue;
            return new WP_Error('tm_bad_shift_date', 'Il turno del ' . $shift['date'] . ' non appartiene al mese selezionato.');
        }
        $effective = tm_board_effective_day($week, $shift['date']);
        $available = !empty($effective['open']) ? array_map('tm_slot_key', (array) $effective['slots']) : [];
        if (!in_array($shift['slot'], $available, true)) {
            if ($orphan_strategy === 'delete') continue;
            return new WP_Error('tm_bad_shift_slot', 'La fascia ' . tm_slot_label($shift['slot']) . ' del ' . $shift['date'] . ' non esiste nel calendario.');
        }
        foreach ($slot_keys[$shift['date']] ?? [] as $existing_slot) {
            if (tm_slots_overlap($shift['slot'], $existing_slot)) {
                return new WP_Error('tm_overlapping_shift', 'Due turni del ' . $shift['date'] . ' si sovrappongono.');
            }
        }
        $slot_keys[$shift['date']][] = $shift['slot'];
        $external = tm_schedule_admin_external_conflict($schedule_id, $shift['doctor_id'], $shift['date'], $shift['slot']);
        if ($external) return tm_shift_conflict_error($external + ['date' => $shift['date']]);
        $valid_shifts[] = $shift;
    }
    $shifts = $valid_shifts;

    $assignment_by_doctor = [];
    foreach ($assignments as $assignment) $assignment_by_doctor[$assignment['doctor_id']] = true;
    foreach ($shifts as $shift) {
        if (isset($assignment_by_doctor[$shift['doctor_id']])) continue;
        $assignments[] = [
            'doctor_id'       => $shift['doctor_id'],
            'position'        => count($assignments),
            'required_hours'  => 0,
            'status'          => 'done',
            'turn_started_at' => null,
            'qualification'   => (string) get_user_meta($shift['doctor_id'], 'tm_qualification', true),
        ];
        $assignment_by_doctor[$shift['doctor_id']] = true;
    }
    foreach ($assignments as $index => &$assignment) $assignment['position'] = $index;
    unset($assignment);
    usort($shifts, fn($a, $b) => [$a['date'], $a['slot'], $a['doctor_id']] <=> [$b['date'], $b['slot'], $b['doctor_id']]);

    return [
        'facility_id' => $facility_id,
        'month'       => $month,
        'closed'      => !empty($config['closed']),
        'default'     => !empty($config['default']),
        'week'        => $week,
        'assignments' => $assignments,
        'shifts'      => $shifts,
    ];
}

function tm_schedule_admin_current_state($schedule_id) {
    $facility_id = (int) get_post_meta($schedule_id, 'tm_facility_id', true);
    $assignments = array_map(fn($row) => [
        'doctor_id'       => (int) $row->doctor_id,
        'position'        => (int) $row->position,
        'required_hours'  => round((float) $row->required_hours, 2),
        'status'          => (string) $row->status,
        'turn_started_at' => $row->turn_started_at ?: null,
        'qualification'   => (string) $row->qualification,
    ], tm_schedule_admin_assignments($schedule_id));
    $shifts = array_map(fn($row) => [
        'date'      => (string) $row->shift_date,
        'slot'      => (string) $row->slot,
        'doctor_id' => (int) $row->doctor_id,
    ], tm_schedule_admin_shifts($schedule_id));
    return [
        'facility_id' => $facility_id,
        'month'       => (string) get_post_meta($schedule_id, 'tm_schedule_month', true),
        'closed'      => (bool) get_post_meta($schedule_id, 'tm_schedule_closed', true),
        'default'     => $facility_id && (int) get_post_meta($facility_id, 'tm_default_schedule', true) === (int) $schedule_id,
        'week'        => tm_board_calendar($schedule_id),
        'assignments' => $assignments,
        'shifts'      => $shifts,
    ];
}

function tm_schedule_admin_state_hash($schedule_id) {
    $schedule_id = (int) $schedule_id;
    return hash('sha256', wp_json_encode([
        'state'            => tm_schedule_admin_current_state($schedule_id),
        'operational_meta' => tm_schedule_admin_current_operational_meta($schedule_id),
    ]));
}

function tm_schedule_admin_update_meta($post_id, $key, $value) {
    if (metadata_exists('post', $post_id, $key) && get_post_meta($post_id, $key, true) == $value) return true;
    $updated = update_post_meta($post_id, $key, $value);
    return $updated !== false || get_post_meta($post_id, $key, true) == $value;
}

function tm_schedule_admin_reset_notifications($schedule_id) {
    foreach (['tm_queue_notified', 'tm_queue_notified_signature', 'tm_completed_notified_signature'] as $key) delete_post_meta($schedule_id, $key);
}

/* Con object cache persistente il ROLLBACK non invalida la cache dei postmeta
   già scritti in transazione: va svuotata a mano o le letture restano stale. */
function tm_schedule_admin_flush_rollback_cache($schedule_id, $facility_ids = []) {
    clean_post_cache((int) $schedule_id);
    foreach (array_unique(array_filter(array_map('intval', (array) $facility_ids))) as $facility_id) {
        clean_post_cache($facility_id);
    }
}

function tm_schedule_admin_write_state($schedule_id, $state, $action = 'salvataggio editor', $expected_hash = '') {
    global $wpdb;
    $result = tm_with_shift_write_lock(function () use ($wpdb, $schedule_id, $state, $action, $expected_hash) {
        $current = tm_schedule_admin_current_state($schedule_id);
        $current_operational_meta = tm_schedule_admin_current_operational_meta($schedule_id);
        $current_hash = tm_schedule_admin_state_hash($schedule_id);
        if ($expected_hash && !hash_equals($current_hash, $expected_hash)) {
            return new WP_Error('tm_stale_schedule', 'Il turno è stato modificato dopo l’apertura della pagina. Ricarica e applica di nuovo le correzioni.', ['status' => 409]);
        }
        $core_state = $state;
        unset($core_state['operational_meta']);
        $target_operational_meta = array_key_exists('operational_meta', $state)
            ? tm_schedule_admin_operational_meta($state['operational_meta'])
            : $current_operational_meta;
        if ($current == $core_state && $current_operational_meta == $target_operational_meta) return ['changed' => false];
        $old_signature = tm_schedule_participants_signature($schedule_id);
        $old_facility_id = (int) $current['facility_id'];
        $assignments_changed = $current['assignments'] != $state['assignments'];
        if (!tm_schedule_admin_store_snapshot($schedule_id, $action)) {
            return new WP_Error('tm_snapshot_failed', 'Impossibile creare lo snapshot di sicurezza.');
        }
        $wpdb->query('START TRANSACTION');
        $writes = [
            tm_schedule_admin_update_meta($schedule_id, 'tm_facility_id', (int) $state['facility_id']),
            tm_schedule_admin_update_meta($schedule_id, 'tm_schedule_month', (string) $state['month']),
            tm_schedule_admin_update_meta($schedule_id, 'tm_schedule_closed', (bool) $state['closed']),
            tm_schedule_admin_update_meta($schedule_id, 'tm_schedule_week', $state['week']),
        ];
        if (in_array(false, $writes, true)) {
            $wpdb->query('ROLLBACK');
            tm_schedule_admin_flush_rollback_cache($schedule_id, [$state['facility_id'], $old_facility_id]);
            return new WP_Error('tm_meta_failed', 'Salvataggio della configurazione non riuscito.');
        }
        if ($old_facility_id && $old_facility_id !== (int) $state['facility_id'] && (int) get_post_meta($old_facility_id, 'tm_default_schedule', true) === (int) $schedule_id) {
            delete_post_meta($old_facility_id, 'tm_default_schedule');
        }
        if ($state['default']) {
            if (!tm_schedule_admin_update_meta((int) $state['facility_id'], 'tm_default_schedule', (int) $schedule_id)) {
                $wpdb->query('ROLLBACK');
                tm_schedule_admin_flush_rollback_cache($schedule_id, [$state['facility_id'], $old_facility_id]);
                return new WP_Error('tm_default_failed', 'Impostazione del mese predefinito non riuscita.');
            }
        } elseif ((int) get_post_meta((int) $state['facility_id'], 'tm_default_schedule', true) === (int) $schedule_id) {
            delete_post_meta((int) $state['facility_id'], 'tm_default_schedule');
        }
        if ($wpdb->delete(tm_table('shifts'), ['schedule_id' => (int) $schedule_id]) === false || $wpdb->delete(tm_table('assignments'), ['schedule_id' => (int) $schedule_id]) === false) {
            $wpdb->query('ROLLBACK');
            tm_schedule_admin_flush_rollback_cache($schedule_id, [$state['facility_id'], $old_facility_id]);
            return new WP_Error('tm_delete_failed', 'Pulizia dei dati precedenti non riuscita.');
        }
        foreach ($state['assignments'] as $assignment) {
            $inserted = $wpdb->insert(tm_table('assignments'), [
                'schedule_id'     => (int) $schedule_id,
                'doctor_id'       => (int) $assignment['doctor_id'],
                'position'        => (int) $assignment['position'],
                'required_hours'  => (float) $assignment['required_hours'],
                'status'          => (string) $assignment['status'],
                'turn_started_at' => $assignment['turn_started_at'],
                'qualification'   => (string) $assignment['qualification'],
            ]);
            if ($inserted !== 1) {
                $wpdb->query('ROLLBACK');
                tm_schedule_admin_flush_rollback_cache($schedule_id, [$state['facility_id'], $old_facility_id]);
                return new WP_Error('tm_assignment_failed', 'Salvataggio dei partecipanti non riuscito.');
            }
        }
        foreach ($state['shifts'] as $shift) {
            $inserted = $wpdb->insert(tm_table('shifts'), [
                'schedule_id' => (int) $schedule_id,
                'shift_date'  => (string) $shift['date'],
                'slot'        => (string) $shift['slot'],
                'doctor_id'   => (int) $shift['doctor_id'],
            ]);
            if ($inserted !== 1) {
                $wpdb->query('ROLLBACK');
                tm_schedule_admin_flush_rollback_cache($schedule_id, [$state['facility_id'], $old_facility_id]);
                return new WP_Error('tm_shift_failed', 'Salvataggio dei turni non riuscito.');
            }
        }
        $new_signature = tm_schedule_participants_signature($schedule_id);
        if ($old_signature !== $new_signature) tm_refresh_schedule_participants_confirmation($schedule_id);
        if ($assignments_changed || $old_signature !== $new_signature || $current['facility_id'] !== $state['facility_id'] || $current['month'] !== $state['month'] || $current['closed'] !== $state['closed']) {
            tm_schedule_admin_reset_notifications($schedule_id);
        }
        if (array_key_exists('operational_meta', $state)) {
            $operational_meta = tm_schedule_admin_apply_operational_meta($schedule_id, $state['operational_meta']);
            if (is_wp_error($operational_meta)) {
                $wpdb->query('ROLLBACK');
                tm_schedule_admin_flush_rollback_cache($schedule_id, [$state['facility_id'], $old_facility_id]);
                return $operational_meta;
            }
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            tm_schedule_admin_flush_rollback_cache($schedule_id, [$state['facility_id'], $old_facility_id]);
            return new WP_Error('tm_commit_failed', 'Salvataggio non confermato dal database.');
        }
        return ['changed' => true];
    });
    if (is_wp_error($result)) return $result;
    if (!empty($result['changed'])) {
        tm_queue_log_add($schedule_id, 0, 'admin_repair', get_current_user_id(), $action);
        tm_schedule_admin_retry_calendar_migration($schedule_id);
    }
    return true;
}

function tm_schedule_admin_confirm_participants($schedule_id) {
    $facility_id = (int) get_post_meta($schedule_id, 'tm_facility_id', true);
    $request = new WP_REST_Request('POST');
    $request->set_param('id', $facility_id);
    $request->set_param('schedule', $schedule_id);
    $response = tm_rest_confirm_schedule_doctors($request);
    if (is_wp_error($response)) return $response;
    return true;
}

function tm_schedule_admin_state_from_snapshot($schedule_id, $snapshot, $allow_snapshot_values = true) {
    if (!is_array($snapshot) || (int) ($snapshot['schedule_id'] ?? 0) !== (int) $schedule_id) {
        return new WP_Error('tm_bad_snapshot', 'Snapshot non valido per questo turno.');
    }
    $month = (string) ($snapshot['meta']['tm_schedule_month'] ?? '');
    $week = $snapshot['meta']['tm_schedule_week'] ?? null;
    $assignments = [];
    foreach ((array) ($snapshot['assignments'] ?? []) as $index => $row) {
        $assignments[$index] = [
            'doctor_id'       => (int) ($row['doctor_id'] ?? 0),
            'position'        => (int) ($row['position'] ?? $index) + 1,
            'required_hours'  => (float) ($row['required_hours'] ?? 0),
            'status'          => (string) ($row['status'] ?? 'pending'),
            'turn_started_at' => !empty($row['turn_started_at']) ? str_replace(' ', 'T', substr((string) $row['turn_started_at'], 0, 16)) : '',
            'qualification'   => (string) ($row['qualification'] ?? ''),
        ];
    }
    $shifts = [];
    foreach ((array) ($snapshot['shifts'] ?? []) as $index => $row) {
        $shifts[$index] = [
            'date'      => (string) ($row['shift_date'] ?? ''),
            'slot'      => (string) ($row['slot'] ?? ''),
            'doctor_id' => (int) ($row['doctor_id'] ?? 0),
        ];
    }
    $state = tm_schedule_admin_prepare_state($schedule_id, [
        'tm_admin_config' => [
            'facility_id'    => (int) ($snapshot['facility_id'] ?? 0),
            'month'          => $month,
            'closed'         => !empty($snapshot['meta']['tm_schedule_closed']),
            'default'        => !empty($snapshot['default']),
            'week'           => wp_json_encode($week),
            'month_strategy' => (string) ($snapshot['write_options']['month_strategy'] ?? 'reject'),
            'orphan_strategy'=> (string) ($snapshot['write_options']['orphan_strategy'] ?? 'reject'),
        ],
        'tm_admin_assignments' => $assignments,
        'tm_admin_shifts'      => $shifts,
    ], $allow_snapshot_values);
    if (is_wp_error($state)) return $state;
    $state['operational_meta'] = tm_schedule_admin_operational_meta($snapshot['meta'] ?? []);
    return $state;
}

function tm_schedule_admin_remove_orphan_shifts($schedule_id) {
    global $wpdb;
    $week = tm_board_calendar($schedule_id);
    if (!is_array($week)) return new WP_Error('tm_no_week', 'Calendario non disponibile.');
    $rows = tm_schedule_admin_shifts($schedule_id);
    $ids = [];
    foreach ($rows as $row) {
        $effective = tm_board_effective_day($week, $row->shift_date);
        $valid_slots = !empty($effective['open']) ? array_map('tm_slot_key', (array) $effective['slots']) : [];
        if (!in_array($row->slot, $valid_slots, true)) $ids[] = (int) $row->id;
    }
    if (!$ids) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $query = $wpdb->prepare('DELETE FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND id IN (' . $placeholders . ')', array_merge([(int) $schedule_id], $ids));
    $deleted = $wpdb->query($query);
    return $deleted === false ? new WP_Error('tm_delete_failed', 'Rimozione dei turni invisibili non riuscita.') : (int) $deleted;
}

function tm_schedule_admin_normalize_positions($schedule_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT id FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d ORDER BY position ASC, id ASC',
        (int) $schedule_id
    ));
    foreach ($rows as $position => $row) {
        if ($wpdb->update(tm_table('assignments'), ['position' => $position], ['id' => (int) $row->id]) === false) {
            return new WP_Error('tm_position_failed', 'Rinumerazione della coda non riuscita.');
        }
    }
    return count($rows);
}

function tm_schedule_admin_add_missing_assignments($schedule_id) {
    global $wpdb;
    $assigned = array_map('intval', $wpdb->get_col($wpdb->prepare(
        'SELECT doctor_id FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d',
        (int) $schedule_id
    )));
    $shift_doctors = array_map('intval', $wpdb->get_col($wpdb->prepare(
        'SELECT DISTINCT doctor_id FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND doctor_id > 0',
        (int) $schedule_id
    )));
    $missing = array_values(array_diff($shift_doctors, $assigned));
    $position = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COALESCE(MAX(position), -1) FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d',
        (int) $schedule_id
    ));
    foreach ($missing as $doctor_id) {
        $position++;
        $inserted = $wpdb->insert(tm_table('assignments'), [
            'schedule_id'    => (int) $schedule_id,
            'doctor_id'      => (int) $doctor_id,
            'position'       => $position,
            'required_hours' => 0,
            'status'         => 'done',
            'qualification'  => (string) get_user_meta($doctor_id, 'tm_qualification', true),
        ]);
        if ($inserted !== 1) return new WP_Error('tm_assignment_failed', 'Aggiunta dei medici mancanti non riuscita.');
    }
    return count($missing);
}

function tm_schedule_admin_retry_calendar_migration($schedule_id) {
    $schedule_id = (int) $schedule_id;
    $week = tm_board_calendar($schedule_id);
    $validated = tm_validate_schedule_week_for_post($schedule_id, $week);
    $errors = get_option('tm_schedule_week_migration_errors');
    $errors = is_array($errors) ? $errors : [];
    if (is_wp_error($validated)) {
        $errors[$schedule_id] = $validated->get_error_message();
        update_option('tm_schedule_week_migration_errors', $errors, false);
        return $validated;
    }
    unset($errors[$schedule_id]);
    if ($errors) {
        update_option('tm_schedule_week_migration_errors', $errors, false);
        return true;
    }
    delete_option('tm_schedule_week_migration_errors');
    if ((int) get_option('tm_schedule_week_version') < TM_SCHEDULE_WEEK_VERSION && !tm_migrate_schedule_weeks(true)) {
        $next_errors = get_option('tm_schedule_week_migration_errors');
        $message = is_array($next_errors) && !empty($next_errors[$schedule_id])
            ? $next_errors[$schedule_id]
            : 'Migrazione del calendario non completata.';
        return new WP_Error('tm_migration_failed', $message);
    }
    return true;
}

function tm_schedule_admin_document_signature($assignments) {
    if (!$assignments) return '';
    $rows = array_map(fn($row) => [
        'doctor_id'      => (int) $row['doctor_id'],
        'required_hours' => round((float) $row['required_hours'], 2),
    ], $assignments);
    return hash('sha256', wp_json_encode($rows));
}

function tm_schedule_admin_validate_document($schedule_id, $document) {
    $schedule_id = (int) $schedule_id;
    if (!is_array($document)) return new WP_Error('tm_document_type', 'Il documento JSON deve essere un oggetto.', ['status' => 400]);
    if (($document['schema'] ?? '') !== TM_SCHEDULE_DOCUMENT_SCHEMA) {
        return new WP_Error('tm_document_schema', 'Schema JSON non riconosciuto.', ['status' => 400]);
    }
    if ((int) ($document['version'] ?? 0) !== TM_SCHEDULE_DOCUMENT_VERSION) {
        return new WP_Error('tm_document_version', 'Versione dello schema JSON non supportata.', ['status' => 400]);
    }
    if ((int) ($document['schedule_id'] ?? 0) !== $schedule_id) {
        return new WP_Error('tm_document_schedule', 'Il JSON appartiene a un altro turno.', ['status' => 400]);
    }
    if (!is_array($document['meta'] ?? null)) return new WP_Error('tm_document_meta', 'La proprietà meta deve essere un oggetto.', ['status' => 400]);
    if (!is_array($document['assignments'] ?? null)) return new WP_Error('tm_document_assignments', 'La proprietà assignments deve essere un array.', ['status' => 400]);
    if (!is_array($document['shifts'] ?? null)) return new WP_Error('tm_document_shifts', 'La proprietà shifts deve essere un array.', ['status' => 400]);
    foreach (['tm_schedule_month', 'tm_schedule_closed', 'tm_schedule_week'] as $meta_key) {
        if (!array_key_exists($meta_key, $document['meta'])) {
            return new WP_Error('tm_document_meta_key', 'Nel JSON manca meta.' . $meta_key . '.', ['status' => 400]);
        }
    }
    if (!is_array($document['meta']['tm_schedule_week'])) {
        return new WP_Error('tm_document_week', 'meta.tm_schedule_week deve essere un oggetto.', ['status' => 400]);
    }
    foreach ($document['assignments'] as $index => $row) {
        if (!is_array($row)) return new WP_Error('tm_document_assignment', 'assignments[' . $index . '] non è un oggetto.', ['status' => 400]);
        if (absint($row['doctor_id'] ?? 0) < 1) return new WP_Error('tm_document_doctor', 'assignments[' . $index . '].doctor_id non è valido.', ['status' => 400]);
        if (!isset($row['position']) || !is_numeric($row['position']) || (int) $row['position'] < 0) return new WP_Error('tm_document_position', 'assignments[' . $index . '].position non è valida.', ['status' => 400]);
        if (!isset($row['required_hours']) || !is_numeric($row['required_hours'])) return new WP_Error('tm_document_hours', 'assignments[' . $index . '].required_hours non è valida.', ['status' => 400]);
        if (!is_string($row['status'] ?? null)) return new WP_Error('tm_document_status', 'assignments[' . $index . '].status non è valida.', ['status' => 400]);
    }
    foreach ($document['shifts'] as $index => $row) {
        if (!is_array($row)) return new WP_Error('tm_document_shift', 'shifts[' . $index . '] non è un oggetto.', ['status' => 400]);
        if (!is_string($row['shift_date'] ?? null) || !is_string($row['slot'] ?? null) || absint($row['doctor_id'] ?? 0) < 1) {
            return new WP_Error('tm_document_shift_fields', 'shifts[' . $index . '] non contiene data, fascia e medico validi.', ['status' => 400]);
        }
    }
    $snapshot = tm_schedule_admin_snapshot_document($document);
    $state = tm_schedule_admin_state_from_snapshot($schedule_id, $snapshot, false);
    if (is_wp_error($state)) {
        $data = $state->get_error_data();
        if (!is_array($data)) $data = [];
        $data['status'] = (int) ($data['status'] ?? 400);
        $state->add_data($data);
        return $state;
    }
    $signature = tm_schedule_admin_document_signature($state['assignments']);
    $operational_meta = $state['operational_meta'];
    foreach (['tm_participants_confirmed_signature', 'tm_queue_notified_signature', 'tm_completed_notified_signature'] as $signature_key) {
        $stored_signature = (string) ($operational_meta[$signature_key] ?? '');
        if ($stored_signature && (!preg_match('/^[a-f0-9]{64}$/', $stored_signature) || !$signature || !hash_equals($signature, $stored_signature))) {
            return new WP_Error('tm_document_signature', 'meta.' . $signature_key . ' non coincide con assignments.', ['status' => 400]);
        }
    }
    if (!empty($operational_meta['tm_participants_confirmed_at']) && empty($operational_meta['tm_participants_confirmed_signature'])) {
        return new WP_Error('tm_document_confirmation', 'La data di conferma richiede tm_participants_confirmed_signature.', ['status' => 400]);
    }
    if (!empty($operational_meta['tm_queue_notified']) && empty($operational_meta['tm_queue_notified_signature'])) {
        return new WP_Error('tm_document_notification', 'tm_queue_notified richiede tm_queue_notified_signature.', ['status' => 400]);
    }
    return ['document' => $snapshot, 'state' => $state];
}

function tm_schedule_admin_payload($schedule_id) {
    global $wpdb;
    $schedule_id = (int) $schedule_id;
    $post = get_post($schedule_id);
    if (!$post || $post->post_type !== 'tm_schedule') return null;
    $facility_id = (int) get_post_meta($schedule_id, 'tm_facility_id', true);
    $month = (string) get_post_meta($schedule_id, 'tm_schedule_month', true);
    $week = tm_board_calendar($schedule_id);
    if (!is_array($week) && $facility_id && tm_valid_month_value($month)) {
        $week = tm_new_schedule_week($facility_id, $month);
    }
    $assignment_rows = tm_schedule_admin_assignments($schedule_id);
    $shift_rows = tm_schedule_admin_shifts($schedule_id);
    $extra_doctors = array_merge(
        array_map(fn($row) => (int) $row->doctor_id, $assignment_rows),
        array_map(fn($row) => (int) $row->doctor_id, $shift_rows)
    );
    $doctors = [];
    foreach (tm_schedule_admin_doctors($extra_doctors) as $doctor_id) {
        $qualification = (string) get_user_meta($doctor_id, 'tm_qualification', true);
        $doctors[] = [
            'id'            => (int) $doctor_id,
            'name'          => tm_schedule_admin_doctor_name($doctor_id),
            'label'         => tm_schedule_admin_doctor_name($doctor_id, true),
            'qualification' => $qualification,
        ];
    }
    $facilities = [];
    foreach (tm_schedule_admin_facilities() as $facility) {
        $aft_id = (int) get_post_meta($facility->ID, 'tm_aft_id', true);
        $aft = $aft_id ? get_post($aft_id) : null;
        $facilities[] = [
            'id'    => (int) $facility->ID,
            'label' => ($aft ? preg_replace('/^AFT\s+/i', '', $aft->post_title) . ' · ' : '') . $facility->post_title,
            'valid' => tm_facility_has_aft($facility->ID),
        ];
    }
    $assignments = array_map(function ($row) {
        $doctor_id = (int) $row->doctor_id;
        $live_qualification = (string) get_user_meta($doctor_id, 'tm_qualification', true);
        $live_home_aft = function_exists('tm_doctor_home_aft_id') ? (int) tm_doctor_home_aft_id($doctor_id) : (int) get_user_meta($doctor_id, 'tm_home_aft', true);
        return [
            'id'              => (int) $row->id,
            'doctor_id'       => $doctor_id,
            'doctor_name'     => tm_schedule_admin_doctor_name($doctor_id),
            'position'        => (int) $row->position + 1,
            'required_hours'  => (float) $row->required_hours,
            'status'          => (string) $row->status,
            'turn_started_at' => $row->turn_started_at ? tm_format_local_datetime($row->turn_started_at, 'Y-m-d\TH:i') : '',
            'qualification'   => (string) $row->qualification,
            'shift_count'     => (int) $row->shift_count,
            'live'            => [
                'qualification'       => $live_qualification,
                'qualification_label' => function_exists('tm_qualification_label') ? tm_qualification_label($live_qualification) : $live_qualification,
                'weekly_hours'        => tm_doctor_weekly_hours($doctor_id),
                'mandatory_monthly_hours' => (function_exists('tm_doctor_is_mandatory') && tm_doctor_is_mandatory($doctor_id)) ? tm_doctor_monthly_target($doctor_id) : 0,
                'home_aft_id'         => $live_home_aft,
                'home_aft_name'       => $live_home_aft ? preg_replace('/^AFT\s+/i', '', get_the_title($live_home_aft)) : '',
            ],
        ];
    }, $assignment_rows);
    $shifts = array_map(fn($row) => [
        'id'        => (int) $row->id,
        'date'      => (string) $row->shift_date,
        'slot'      => (string) $row->slot,
        'doctor_id' => (int) $row->doctor_id,
    ], $shift_rows);
    $logs = $wpdb->get_results($wpdb->prepare(
        'SELECT id, schedule_id, doctor_id, actor_id, action, detail, created_at FROM ' . tm_table('queue_log') . ' WHERE schedule_id = %d ORDER BY id DESC LIMIT 50',
        $schedule_id
    ));
    $log_payload = array_map(function ($row) {
        $actor = $row->actor_id ? get_userdata((int) $row->actor_id) : null;
        return [
            'id'          => (int) $row->id,
            'schedule_id' => (int) $row->schedule_id,
            'doctor_id'   => (int) $row->doctor_id,
            'doctor'      => $row->doctor_id ? tm_schedule_admin_doctor_name((int) $row->doctor_id) : '',
            'actor_id'    => (int) $row->actor_id,
            'actor'       => $actor ? ($actor->display_name ?: $actor->user_login) : 'Sistema',
            'action'      => (string) $row->action,
            'detail'      => (string) $row->detail,
            'created_at'  => (string) $row->created_at,
        ];
    }, $logs);
    $snapshots = get_post_meta($schedule_id, 'tm_admin_repair_snapshots', true);
    $snapshots = is_array($snapshots) ? $snapshots : [];
    $snapshot_payload = [];
    foreach ($snapshots as $snapshot) {
        $actor = !empty($snapshot['actor_id']) ? get_userdata((int) $snapshot['actor_id']) : null;
        $snapshot_payload[] = [
            'reference' => tm_schedule_admin_snapshot_reference($snapshot),
            'created_at'=> tm_format_local_datetime($snapshot['created_at'] ?? '', 'd/m/Y H:i:s'),
            'action'    => (string) ($snapshot['action'] ?? ''),
            'actor'     => $actor ? ($actor->display_name ?: $actor->user_login) : 'Sistema',
        ];
    }
    $migration_errors = get_option('tm_schedule_week_migration_errors');
    $schedule_aft_id = function_exists('tm_schedule_aft_id') ? (int) tm_schedule_aft_id($schedule_id) : 0;
    $current = tm_queue_current_row($schedule_id);
    $current_snapshot = tm_schedule_admin_snapshot($schedule_id, 'stato live');
    $current_document = tm_schedule_admin_snapshot_document($current_snapshot);
    $current_document['references'] = [
        'facilities' => array_map(fn($facility) => ['id' => $facility['id'], 'label' => $facility['label']], $facilities),
        'doctors'    => array_map(fn($doctor) => ['id' => $doctor['id'], 'name' => $doctor['name'], 'label' => $doctor['label']], $doctors),
    ];
    $document_validation = tm_schedule_admin_validate_document($schedule_id, $current_document);
    return [
        'id'              => $schedule_id,
        'title'           => $post->post_title,
        'status'          => $post->post_status,
        'post_type'       => $post->post_type,
        'app_url'         => tm_schedule_app_url($schedule_id),
        'state_hash'      => tm_schedule_admin_state_hash($schedule_id),
        'document'        => $current_document,
        'document_validation' => is_wp_error($document_validation)
            ? [
                'valid'   => false,
                'code'    => $document_validation->get_error_code(),
                'message' => $document_validation->get_error_message(),
            ]
            : ['valid' => true, 'code' => '', 'message' => 'JSON valido e compatibile con il turno.'],
        'tables'          => [
            'posts'       => $wpdb->posts,
            'postmeta'    => $wpdb->postmeta,
            'assignments' => tm_table('assignments'),
            'shifts'      => tm_table('shifts'),
            'logs'        => tm_table('queue_log'),
        ],
        'config'          => [
            'facility_id'    => $facility_id,
            'month'          => $month,
            'closed'         => (bool) get_post_meta($schedule_id, 'tm_schedule_closed', true),
            'default'        => $facility_id && (int) get_post_meta($facility_id, 'tm_default_schedule', true) === $schedule_id,
            'week'           => $week,
            'month_strategy' => 'reject',
            'orphan_strategy'=> 'reject',
        ],
        'facilities'      => $facilities,
        'doctors'         => $doctors,
        'qualifications'  => array_map(fn($key) => [
            'value' => $key,
            'label' => function_exists('tm_qualification_label') ? tm_qualification_label($key) : $key,
        ], tm_allowed_doctor_qualifications()),
        'assignments'     => $assignments,
        'shifts'          => $shifts,
        'diagnostics'     => tm_schedule_admin_diagnostics($schedule_id),
        'aft'             => [
            'id'   => $schedule_aft_id,
            'name' => $schedule_aft_id ? preg_replace('/^AFT\s+/i', '', get_the_title($schedule_aft_id)) : '',
        ],
        'confirmed'       => tm_schedule_participants_confirmed($schedule_id),
        'confirmed_at'    => (string) get_post_meta($schedule_id, 'tm_participants_confirmed_at', true),
        'confirmed_signature' => (string) get_post_meta($schedule_id, 'tm_participants_confirmed_signature', true),
        'current_doctor_id'=> $current ? (int) $current->doctor_id : 0,
        'logs'            => $log_payload,
        'snapshots'       => $snapshot_payload,
        'migration_error' => is_array($migration_errors) ? (string) ($migration_errors[$schedule_id] ?? '') : '',
    ];
}

function tm_schedule_admin_prepare_rest_state($schedule_id, $body) {
    $config = is_array($body['config'] ?? null) ? $body['config'] : [];
    $assignments = [];
    foreach ((array) ($body['assignments'] ?? []) as $index => $row) {
        if (!is_array($row)) continue;
        $assignments[$index] = [
            'doctor_id'       => (int) ($row['doctor_id'] ?? 0),
            'position'        => (int) ($row['position'] ?? $index + 1),
            'required_hours'  => (float) ($row['required_hours'] ?? 0),
            'status'          => (string) ($row['status'] ?? 'pending'),
            'turn_started_at' => (string) ($row['turn_started_at'] ?? ''),
            'qualification'   => (string) ($row['qualification'] ?? ''),
        ];
    }
    $shifts = [];
    foreach ((array) ($body['shifts'] ?? []) as $index => $row) {
        if (!is_array($row)) continue;
        $shifts[$index] = [
            'date'      => (string) ($row['date'] ?? ''),
            'slot'      => (string) ($row['slot'] ?? ''),
            'doctor_id' => (int) ($row['doctor_id'] ?? 0),
        ];
    }
    return tm_schedule_admin_prepare_state($schedule_id, [
        'tm_admin_config' => [
            'facility_id'    => (int) ($config['facility_id'] ?? 0),
            'month'          => (string) ($config['month'] ?? ''),
            'closed'         => !empty($config['closed']),
            'default'        => !empty($config['default']),
            'week'           => wp_json_encode($config['week'] ?? null),
            'month_strategy' => (string) ($config['month_strategy'] ?? 'reject'),
            'orphan_strategy'=> (string) ($config['orphan_strategy'] ?? 'reject'),
        ],
        'tm_admin_assignments' => $assignments,
        'tm_admin_shifts'      => $shifts,
    ]);
}

function tm_schedule_admin_realign_signatures($schedule_id) {
    $signature = tm_schedule_participants_signature($schedule_id);
    if (!$signature) return;
    if ((string) get_post_meta($schedule_id, 'tm_participants_confirmed_at', true) !== '') {
        update_post_meta($schedule_id, 'tm_participants_confirmed_signature', $signature);
        if (function_exists('tm_update_schedule_ranking_snapshot')) tm_update_schedule_ranking_snapshot($schedule_id);
    }
    if ((string) get_post_meta($schedule_id, 'tm_queue_notified', true) !== '') {
        update_post_meta($schedule_id, 'tm_queue_notified_signature', $signature);
    }
    if ((string) get_post_meta($schedule_id, 'tm_completed_notified_signature', true) !== '') {
        update_post_meta($schedule_id, 'tm_completed_notified_signature', $signature);
    }
}

function tm_schedule_admin_update_assignment($schedule_id, $payload, $expected_hash = '') {
    $schedule_id = (int) $schedule_id;
    $payload = is_array($payload) ? $payload : [];
    $doctor_id = absint($payload['doctor_id'] ?? 0);
    if (!$doctor_id) return new WP_Error('tm_bad_doctor', 'Medico non valido.', ['status' => 400]);

    $result = tm_with_shift_write_lock(function () use ($schedule_id, $doctor_id, $payload, $expected_hash) {
        global $wpdb;
        if ($expected_hash && !hash_equals(tm_schedule_admin_state_hash($schedule_id), $expected_hash)) {
            return new WP_Error('tm_stale_schedule', 'Il turno è stato modificato. Ricarica la pagina.', ['status' => 409]);
        }
        $assignment = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d AND doctor_id = %d',
            $schedule_id, $doctor_id
        ));
        if (!$assignment) return new WP_Error('tm_assignment_missing', 'Il medico non è nella coda di questo turno.', ['status' => 404]);

        $update = [];
        $changes = [];
        if (array_key_exists('required_hours', $payload)) {
            $required_hours = min(999.99, max(0, round((float) $payload['required_hours'], 2)));
            if ($required_hours !== round((float) $assignment->required_hours, 2)) {
                $update['required_hours'] = $required_hours;
                $changes[] = 'ore ' . round((float) $assignment->required_hours, 2) . '->' . $required_hours;
            }
        }
        if (array_key_exists('status', $payload)) {
            $status = sanitize_key((string) $payload['status']);
            if (!in_array($status, ['pending', 'done', 'skipped'], true)) {
                return new WP_Error('tm_bad_status', 'Stato coda non valido.', ['status' => 400]);
            }
            if ($status !== (string) $assignment->status) {
                $update['status'] = $status;
                $changes[] = 'stato ' . ((string) $assignment->status ?: 'pending') . '->' . $status;
            }
        }
        if (array_key_exists('qualification', $payload)) {
            $qualification = sanitize_key((string) $payload['qualification']);
            if (!in_array($qualification, tm_allowed_doctor_qualifications(), true)) {
                return new WP_Error('tm_bad_qualification', 'Qualifica non valida.', ['status' => 400]);
            }
            if ($qualification !== (string) $assignment->qualification) {
                $update['qualification'] = $qualification;
                $changes[] = 'qualifica ' . ((string) $assignment->qualification ?: '—') . '->' . $qualification;
            }
        }
        if (!$update) return ['changed' => false];

        if (!tm_schedule_admin_store_snapshot($schedule_id, 'modifica coda')) {
            return new WP_Error('tm_snapshot_failed', 'Impossibile creare lo snapshot di sicurezza.');
        }
        $wpdb->query('START TRANSACTION');
        $written = $wpdb->update(tm_table('assignments'), $update, ['id' => (int) $assignment->id]);
        if ($written === false || $wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('tm_save_failed', 'Aggiornamento della coda non riuscito.', ['status' => 500]);
        }
        tm_schedule_admin_realign_signatures($schedule_id);
        return ['changed' => true, 'detail' => implode(', ', $changes)];
    });
    if (is_wp_error($result)) return $result;
    if (empty($result['changed'])) return ['message' => 'Nessuna modifica da applicare.', 'type' => 'warning'];
    tm_queue_log_add($schedule_id, $doctor_id, 'admin_override', get_current_user_id(), $result['detail']);
    return ['message' => 'Coda aggiornata, firme riallineate. Nessuna email inviata.', 'type' => 'success'];
}

function tm_schedule_admin_reorder_assignments($schedule_id, $order, $expected_hash = '') {
    $schedule_id = (int) $schedule_id;
    $order = array_values(array_map('absint', (array) $order));
    if (!$order || in_array(0, $order, true) || count($order) !== count(array_unique($order))) {
        return new WP_Error('tm_bad_order', 'Ordine della coda non valido.', ['status' => 400]);
    }

    $result = tm_with_shift_write_lock(function () use ($schedule_id, $order, $expected_hash) {
        global $wpdb;
        if ($expected_hash && !hash_equals(tm_schedule_admin_state_hash($schedule_id), $expected_hash)) {
            return new WP_Error('tm_stale_schedule', 'Il turno è stato modificato. Ricarica la pagina.', ['status' => 409]);
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, doctor_id FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d ORDER BY position ASC, id ASC',
            $schedule_id
        ));
        $current_order = array_map(fn($row) => (int) $row->doctor_id, $rows);
        $current_set = $current_order;
        $next_set = $order;
        sort($current_set);
        sort($next_set);
        if ($current_set !== $next_set) {
            return new WP_Error('tm_bad_order', 'La lista non corrisponde ai medici in coda. Ricarica la pagina.', ['status' => 409]);
        }
        if ($current_order === $order) return ['changed' => false];

        if (!tm_schedule_admin_store_snapshot($schedule_id, 'riordino coda')) {
            return new WP_Error('tm_snapshot_failed', 'Impossibile creare lo snapshot di sicurezza.');
        }
        $assignment_ids = [];
        foreach ($rows as $row) $assignment_ids[(int) $row->doctor_id] = (int) $row->id;
        $wpdb->query('START TRANSACTION');
        foreach ($order as $position => $doctor_id) {
            if ($wpdb->update(tm_table('assignments'), ['position' => $position], ['id' => $assignment_ids[$doctor_id]]) === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('tm_save_failed', 'Riordino della coda non riuscito.', ['status' => 500]);
            }
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('tm_save_failed', 'Riordino della coda non riuscito.', ['status' => 500]);
        }
        tm_schedule_admin_realign_signatures($schedule_id);
        return ['changed' => true];
    });
    if (is_wp_error($result)) return $result;
    if (empty($result['changed'])) return ['message' => 'Nessuna modifica da applicare.', 'type' => 'warning'];
    tm_queue_log_add($schedule_id, 0, 'admin_override', get_current_user_id(), 'riordino coda');
    return ['message' => 'Coda riordinata, firme riallineate. Nessuna email inviata.', 'type' => 'success'];
}

function tm_schedule_admin_operation($schedule_id, $operation, $reference = '', $expected_hash = '', $payload = []) {
    $schedule_id = (int) $schedule_id;
    $operation = sanitize_key($operation);
    if (!$expected_hash) {
        return new WP_Error('tm_missing_hash', 'Ricarica il turno prima di eseguire l’operazione.', ['status' => 400]);
    }
    if ($operation === 'update_assignment') {
        return tm_schedule_admin_update_assignment($schedule_id, $payload, $expected_hash);
    }
    if ($operation === 'reorder_assignments') {
        return tm_schedule_admin_reorder_assignments($schedule_id, $payload['order'] ?? [], $expected_hash);
    }
    if ($operation === 'restore_snapshot') {
        $snapshot = tm_schedule_admin_find_snapshot($schedule_id, $reference);
        $state = $snapshot ? tm_schedule_admin_state_from_snapshot($schedule_id, $snapshot) : new WP_Error('tm_snapshot_missing', 'Snapshot non trovato.');
        if (is_wp_error($state)) return $state;
        $result = tm_schedule_admin_write_state($schedule_id, $state, 'ripristino snapshot', $expected_hash);
        return is_wp_error($result) ? $result : ['message' => 'Snapshot ripristinato. Verifica la conferma partecipanti prima di riavviare la coda.', 'type' => 'success'];
    }
    if ($operation === 'confirm_participants') {
        if (!hash_equals(tm_schedule_admin_state_hash($schedule_id), $expected_hash)) {
            return new WP_Error('tm_stale_schedule', 'Il turno è stato modificato. Ricarica la pagina.', ['status' => 409]);
        }
        $result = tm_with_shift_write_lock(function () use ($schedule_id) {
            if (!tm_schedule_admin_store_snapshot($schedule_id, 'conferma partecipanti')) {
                return new WP_Error('tm_snapshot_failed', 'Impossibile creare lo snapshot di sicurezza.');
            }
            return tm_schedule_admin_confirm_participants($schedule_id);
        });
        return is_wp_error($result) ? $result : ['message' => 'Partecipanti confermati e coda avviata.', 'type' => 'success'];
    }
    if ($operation === 'notify_current') {
        if (!hash_equals(tm_schedule_admin_state_hash($schedule_id), $expected_hash)) {
            return new WP_Error('tm_stale_schedule', 'Il turno è stato modificato. Ricarica la pagina.', ['status' => 409]);
        }
        $result = tm_with_shift_write_lock(function () use ($schedule_id) {
            if (!tm_schedule_admin_store_snapshot($schedule_id, 'invio medico corrente')) {
                return new WP_Error('tm_snapshot_failed', 'Impossibile creare lo snapshot di sicurezza.');
            }
            return tm_queue_notify($schedule_id);
        });
        if (is_wp_error($result)) return $result;
        return [
            'message' => !empty($result['sent']) ? 'Email inviata al medico corrente.' : 'Nessuna email inviata: ' . ($result['message'] ?? $result['reason'] ?? 'stato non disponibile') . '.',
            'type'    => !empty($result['sent']) ? 'success' : 'warning',
        ];
    }
    $result = tm_with_shift_write_lock(function () use ($schedule_id, $operation, $expected_hash) {
        global $wpdb;
        if ($expected_hash && !hash_equals(tm_schedule_admin_state_hash($schedule_id), $expected_hash)) {
            return new WP_Error('tm_stale_schedule', 'Il turno è stato modificato. Ricarica la pagina.', ['status' => 409]);
        }
        if (!tm_schedule_admin_store_snapshot($schedule_id, $operation)) return new WP_Error('tm_snapshot_failed', 'Impossibile creare lo snapshot di sicurezza.');
        $wpdb->query('START TRANSACTION');
        if ($operation === 'create_snapshot') {
            $operation_result = true;
        } elseif ($operation === 'normalize_positions') {
            $operation_result = tm_schedule_admin_normalize_positions($schedule_id);
            if (!is_wp_error($operation_result)) tm_schedule_admin_reset_notifications($schedule_id);
        } elseif ($operation === 'add_missing_assignments') {
            $operation_result = tm_schedule_admin_add_missing_assignments($schedule_id);
            if (!is_wp_error($operation_result)) {
                tm_refresh_schedule_participants_confirmation($schedule_id);
                tm_schedule_admin_reset_notifications($schedule_id);
            }
        } elseif ($operation === 'remove_orphan_shifts') {
            $operation_result = tm_schedule_admin_remove_orphan_shifts($schedule_id);
        } elseif ($operation === 'revoke_confirmation') {
            delete_post_meta($schedule_id, 'tm_participants_confirmed_at');
            delete_post_meta($schedule_id, 'tm_participants_confirmed_signature');
            delete_post_meta($schedule_id, 'tm_ranking_snapshot');
            tm_schedule_admin_reset_notifications($schedule_id);
            $operation_result = true;
        } elseif ($operation === 'reset_notifications') {
            tm_schedule_admin_reset_notifications($schedule_id);
            $operation_result = true;
        } elseif ($operation === 'rebuild_ranking') {
            $operation_result = tm_update_schedule_ranking_snapshot($schedule_id) ? true : new WP_Error('tm_snapshot_failed', 'Rigenerazione della graduatoria non riuscita.');
        } else {
            $operation_result = new WP_Error('tm_bad_operation', 'Operazione non valida.', ['status' => 400]);
        }
        if (is_wp_error($operation_result)) {
            $wpdb->query('ROLLBACK');
            tm_schedule_admin_flush_rollback_cache($schedule_id);
            return $operation_result;
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            tm_schedule_admin_flush_rollback_cache($schedule_id);
            return new WP_Error('tm_commit_failed', 'Operazione non confermata dal database.');
        }
        return true;
    });
    if (is_wp_error($result)) return $result;
    $messages = [
        'create_snapshot'         => 'Snapshot creato.',
        'normalize_positions'     => 'Posizioni della coda rinumerate.',
        'add_missing_assignments' => 'Medici con turni aggiunti alla lista.',
        'remove_orphan_shifts'    => 'Turni invisibili rimossi.',
        'revoke_confirmation'     => 'Conferma partecipanti revocata.',
        'reset_notifications'     => 'Stato notifiche azzerato. Nessuna email è stata inviata.',
        'rebuild_ranking'         => 'Snapshot della graduatoria rigenerato.',
    ];
    tm_queue_log_add($schedule_id, 0, 'admin_repair', get_current_user_id(), $operation);
    tm_schedule_admin_retry_calendar_migration($schedule_id);
    return ['message' => $messages[$operation] ?? 'Operazione completata.', 'type' => 'success'];
}

function tm_schedule_admin_register_rest_routes() {
    $permission = fn($request) => current_user_can('manage_options') && get_post_type((int) $request['id']) === 'tm_schedule';
    register_rest_route('turnimedici/v1', '/admin/schedules/(?P<id>\d+)', [
        ['methods' => 'GET', 'callback' => 'tm_schedule_admin_rest_get', 'permission_callback' => $permission],
        ['methods' => 'PUT', 'callback' => 'tm_schedule_admin_rest_save', 'permission_callback' => $permission],
    ]);
    register_rest_route('turnimedici/v1', '/admin/schedules/(?P<id>\d+)/action', [
        'methods'             => 'POST',
        'callback'            => 'tm_schedule_admin_rest_action',
        'permission_callback' => $permission,
    ]);
    register_rest_route('turnimedici/v1', '/admin/schedules/(?P<id>\d+)/validate', [
        'methods'             => 'POST',
        'callback'            => 'tm_schedule_admin_rest_validate',
        'permission_callback' => $permission,
    ]);
    register_rest_route('turnimedici/v1', '/admin/schedules/(?P<id>\d+)/snapshots/(?P<reference>[A-Za-z0-9-]+)', [
        'methods'             => 'GET',
        'callback'            => 'tm_schedule_admin_rest_snapshot',
        'permission_callback' => $permission,
    ]);
}

function tm_schedule_admin_rest_get($request) {
    return rest_ensure_response(tm_schedule_admin_payload((int) $request['id']));
}

function tm_schedule_admin_rest_save($request) {
    $schedule_id = (int) $request['id'];
    $body = $request->get_json_params();
    if (!is_array($body)) return new WP_Error('tm_bad_payload', 'Dati non validi.', ['status' => 400]);
    $expected_hash = sanitize_text_field((string) ($body['state_hash'] ?? ''));
    if (!$expected_hash) return new WP_Error('tm_missing_hash', 'Ricarica il turno prima di salvare.', ['status' => 400]);
    if (array_key_exists('document', $body)) {
        $validated = tm_schedule_admin_validate_document($schedule_id, $body['document']);
        if (is_wp_error($validated)) return $validated;
        $state = $validated['state'];
        $action = 'caricamento JSON';
    } else {
        $state = tm_schedule_admin_prepare_rest_state($schedule_id, $body);
        if (is_wp_error($state)) return $state;
        $action = 'salvataggio editor React';
    }
    $saved = tm_schedule_admin_write_state($schedule_id, $state, $action, $expected_hash);
    if (is_wp_error($saved)) return $saved;
    return rest_ensure_response(tm_schedule_admin_payload($schedule_id));
}

function tm_schedule_admin_rest_validate($request) {
    $schedule_id = (int) $request['id'];
    $body = $request->get_json_params();
    $document = is_array($body) && array_key_exists('document', $body) ? $body['document'] : $body;
    $validated = tm_schedule_admin_validate_document($schedule_id, $document);
    if (is_wp_error($validated)) return $validated;
    return rest_ensure_response(['valid' => true, 'message' => 'JSON valido e compatibile con il turno.']);
}

function tm_schedule_admin_rest_action($request) {
    $schedule_id = (int) $request['id'];
    $body = $request->get_json_params();
    $body = is_array($body) ? $body : [];
    $payload = is_array($body['assignment'] ?? null) ? $body['assignment'] : [];
    if (is_array($body['order'] ?? null)) $payload['order'] = $body['order'];
    $result = tm_schedule_admin_operation(
        $schedule_id,
        sanitize_key($body['operation'] ?? ''),
        sanitize_text_field((string) ($body['reference'] ?? '')),
        sanitize_text_field((string) ($body['state_hash'] ?? '')),
        $payload
    );
    if (is_wp_error($result)) return $result;
    return rest_ensure_response([
        'notice' => $result,
        'state'  => tm_schedule_admin_payload($schedule_id),
    ]);
}

function tm_schedule_admin_rest_snapshot($request) {
    $schedule_id = (int) $request['id'];
    $snapshot = tm_schedule_admin_find_snapshot($schedule_id, sanitize_text_field((string) $request['reference']));
    if (!$snapshot) return new WP_Error('tm_snapshot_missing', 'Snapshot non trovato.', ['status' => 404]);
    $document = tm_schedule_admin_snapshot_document($snapshot);
    $payload = tm_schedule_admin_payload($schedule_id);
    $document['references'] = $payload['document']['references'] ?? [];
    return rest_ensure_response($document);
}
