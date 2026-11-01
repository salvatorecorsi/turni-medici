<?php

if (!defined('ABSPATH')) exit;

const TM_BOARD_WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const TM_BOARD_WEEKDAY_SHORT = ['mon' => 'lun', 'tue' => 'mar', 'wed' => 'mer', 'thu' => 'gio', 'fri' => 'ven', 'sat' => 'sab', 'sun' => 'dom'];
const TM_SCHEDULE_WEEK_VERSION = 2;

function tm_valid_month_value($month) {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month)) return false;
    $parsed = DateTimeImmutable::createFromFormat('!Y-m', (string) $month, wp_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed && ($errors === false || (!$errors['warning_count'] && !$errors['error_count'])) && $parsed->format('Y-m') === $month;
}

function tm_valid_iso_date($date) {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date, wp_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed && ($errors === false || (!$errors['warning_count'] && !$errors['error_count'])) && $parsed->format('Y-m-d') === $date;
}

function tm_local_datetime($value) {
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $value, wp_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) return null;
    return $parsed;
}

function tm_format_local_datetime($value, $format) {
    $parsed = tm_local_datetime($value);
    return $parsed ? $parsed->format($format) : '';
}

function tm_add_local_days($value, $days) {
    $parsed = tm_local_datetime($value);
    return $parsed ? $parsed->modify('+' . max(0, (int) $days) . ' days')->format('Y-m-d H:i:s') : '';
}

function tm_slot_key($slot) {
    return trim($slot['from'] ?? '') . '-' . trim($slot['to'] ?? '');
}

function tm_time_to_min($time) {
    $parts = explode(':', (string) $time);
    return ((int) ($parts[0] ?? 0)) * 60 + (int) ($parts[1] ?? 0);
}

function tm_valid_time($time) {
    if (!preg_match('/^(\d{2}):(\d{2})$/', (string) $time, $m)) return false;
    return (int) $m[1] >= 0 && (int) $m[1] <= 23 && (int) $m[2] >= 0 && (int) $m[2] <= 59;
}

function tm_valid_schedule_date($schedule_id, $date) {
    $date = (string) $date;
    if (!tm_valid_iso_date($date)) return false;
    $month = (string) get_post_meta((int) $schedule_id, 'tm_schedule_month', true);
    return tm_valid_month_value($month) && substr($date, 0, 7) === $month;
}

function tm_with_database_lock($scope, $callback, $timeout = 8) {
    global $wpdb;
    $lock_name = substr($wpdb->prefix . 'tm_' . sanitize_key($scope), 0, 64);
    $acquired = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, max(1, (int) $timeout)));
    if ($acquired !== 1) {
        return new WP_Error('tm_busy', 'Operazione già in corso: riprova tra pochi secondi.', ['status' => 503]);
    }
    try {
        return $callback();
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }
}

function tm_with_shift_write_lock($callback) {
    return tm_with_database_lock('shift_write', $callback);
}

function tm_slot_hours($slot_key) {
    $parts = explode('-', $slot_key);
    if (count($parts) !== 2) return 0;
    return max(0, (tm_time_to_min($parts[1]) - tm_time_to_min($parts[0])) / 60);
}

function tm_slot_range_minutes($slot_key) {
    if (!preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', (string) $slot_key, $m)) return null;
    if (!tm_valid_time($m[1]) || !tm_valid_time($m[2])) return null;
    $start = tm_time_to_min($m[1]);
    $end = tm_time_to_min($m[2]);
    if ($end <= $start) $end += 24 * 60;
    return [$start, $end];
}

function tm_slots_overlap($a, $b) {
    $ra = tm_slot_range_minutes($a);
    $rb = tm_slot_range_minutes($b);
    if (!$ra || !$rb) return false;
    return max($ra[0], $rb[0]) < min($ra[1], $rb[1]);
}

function tm_slot_label($slot_key) {
    return str_replace('-', '–', (string) $slot_key);
}

function tm_clean_slots($slots) {
    $out = [];
    foreach ((array) $slots as $slot) {
        $from = trim((string) ($slot['from'] ?? ''));
        $to = trim((string) ($slot['to'] ?? ''));
        if (tm_valid_time($from) && tm_valid_time($to) && tm_time_to_min($from) < tm_time_to_min($to)) {
            $out[] = ['from' => $from, 'to' => $to];
        }
    }
    return $out;
}

function tm_validate_slots($slots, $label = 'Fasce orarie') {
    $seen = [];
    $valid = [];
    foreach ((array) $slots as $index => $slot) {
        $from = trim((string) ($slot['from'] ?? ''));
        $to = trim((string) ($slot['to'] ?? ''));
        $row = $index + 1;

        if ($from === '' || $to === '') {
            return new WP_Error('tm_bad_week', "$label: compila inizio e fine della fascia $row.", ['status' => 400]);
        }
        if (!tm_valid_time($from) || !tm_valid_time($to)) {
            return new WP_Error('tm_bad_week', "$label: formato ora non valido nella fascia $row.", ['status' => 400]);
        }
        if (tm_time_to_min($from) >= tm_time_to_min($to)) {
            return new WP_Error('tm_bad_week', $label . ': la fascia ' . $from . '–' . $to . ' non è valida.', ['status' => 400]);
        }

        $key = "$from-$to";
        if (isset($seen[$key])) {
            return new WP_Error('tm_bad_week', $label . ': la fascia ' . $from . '–' . $to . ' è duplicata.', ['status' => 400]);
        }
        foreach ($valid as $existing) {
            if (tm_slots_overlap($key, tm_slot_key($existing))) {
                return new WP_Error('tm_bad_week', $label . ': la fascia ' . $from . '–' . $to . ' si sovrappone a ' . tm_slot_label(tm_slot_key($existing)) . '.', ['status' => 400]);
            }
        }

        $seen[$key] = true;
        $valid[] = ['from' => $from, 'to' => $to];
    }

    return true;
}

function tm_schedule_slot_conflict($schedule_id, $date, $slot_key) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT slot, doctor_id FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND shift_date = %s',
        (int) $schedule_id,
        sanitize_text_field($date)
    ));
    foreach ($rows as $row) {
        if ((string) $row->slot === (string) $slot_key) continue;
        if (!tm_slots_overlap($slot_key, $row->slot)) continue;

        $user = get_userdata((int) $row->doctor_id);
        return [
            'slot'      => (string) $row->slot,
            'doctor_id' => (int) $row->doctor_id,
            'doctor'    => $user ? (trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name) : '#' . (int) $row->doctor_id,
        ];
    }
    return null;
}

function tm_slot_taken_error($conflict) {
    $doctor = $conflict['doctor'] ?? 'un medico';
    $slot = tm_slot_label($conflict['slot'] ?? '');
    return new WP_Error('tm_slot_overlap', "Questa fascia si sovrappone al turno $slot già assegnato a $doctor.", ['status' => 409, 'conflict' => $conflict]);
}

function tm_doctor_shift_conflict($doctor_id, $date, $slot_key, $ignore = []) {
    global $wpdb;
    $doctor_id = (int) $doctor_id;
    $date = sanitize_text_field($date);
    $slot_key = sanitize_text_field($slot_key);
    if (!$doctor_id || !$date || !$slot_key) return null;

    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT shifts.schedule_id, shifts.shift_date, shifts.slot
         FROM ' . tm_table('shifts') . ' shifts
         INNER JOIN ' . $wpdb->posts . " schedules ON schedules.ID = shifts.schedule_id AND schedules.post_type = 'tm_schedule' AND schedules.post_status IN ('publish', 'draft')
         WHERE shifts.doctor_id = %d AND shifts.shift_date = %s",
        $doctor_id,
        $date
    ));
    foreach ($rows as $row) {
        if (
            (int) ($ignore['schedule_id'] ?? 0) === (int) $row->schedule_id
            && (string) ($ignore['date'] ?? '') === (string) $row->shift_date
            && (string) ($ignore['slot'] ?? '') === (string) $row->slot
        ) {
            continue;
        }
        if (!tm_slots_overlap($slot_key, $row->slot)) continue;

        $facility_id = (int) get_post_meta((int) $row->schedule_id, 'tm_facility_id', true);
        $facility = $facility_id ? get_post($facility_id) : null;
        return [
            'schedule_id' => (int) $row->schedule_id,
            'facility'    => $facility ? $facility->post_title : '',
            'date'        => (string) $row->shift_date,
            'slot'        => (string) $row->slot,
        ];
    }
    return null;
}

function tm_shift_conflict_error($conflict) {
    $where = trim(($conflict['facility'] ?? '') . ' ' . ($conflict['date'] ?? '') . ' ' . tm_slot_label($conflict['slot'] ?? ''));
    return new WP_Error('tm_shift_conflict', 'Il medico ha già un turno sovrapposto' . ($where ? ': ' . $where : '.'), ['status' => 409, 'conflict' => $conflict]);
}

function tm_doctor_next_shift($user_id) {
    global $wpdb;
    $today = current_time('Y-m-d');
    $now = current_time('H:i');
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT shifts.shift_date, shifts.slot, shifts.schedule_id
         FROM ' . tm_table('shifts') . ' shifts
         INNER JOIN ' . $wpdb->posts . " schedules ON schedules.ID = shifts.schedule_id AND schedules.post_type = 'tm_schedule' AND schedules.post_status IN ('publish', 'draft')
         WHERE shifts.doctor_id = %d
           AND (shifts.shift_date > %s OR (shifts.shift_date = %s AND SUBSTRING_INDEX(shifts.slot, %s, -1) > %s))
         ORDER BY shifts.shift_date ASC, shifts.slot ASC LIMIT 1",
        $user_id, $today, $today, '-', $now
    ));
    if (!$row) return null;
    $facility_id = (int) get_post_meta($row->schedule_id, 'tm_facility_id', true);
    $facility = $facility_id ? get_post($facility_id) : null;
    return [
        'date'     => ucfirst(date_i18n('D j F', strtotime($row->shift_date))),
        'slot'     => str_replace('-', '–', $row->slot),
        'facility' => $facility ? $facility->post_title : '',
    ];
}

function tm_doctor_hours_bank($user_id) {
    global $wpdb;
    $year = (int) current_time('Y');
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT shifts.shift_date, shifts.slot
         FROM ' . tm_table('shifts') . ' shifts
         INNER JOIN ' . $wpdb->posts . " schedules ON schedules.ID = shifts.schedule_id AND schedules.post_type = 'tm_schedule' AND schedules.post_status IN ('publish', 'draft')
         WHERE shifts.doctor_id = %d AND shifts.shift_date BETWEEN %s AND %s",
        $user_id, $year . '-01-01', $year . '-12-31'
    ));
    $months = array_fill(0, 12, 0.0);
    $total = 0.0;
    foreach ($rows as $r) {
        $h = tm_slot_hours($r->slot);
        $idx = (int) substr($r->shift_date, 5, 2) - 1;
        if ($idx >= 0 && $idx < 12) {
            $months[$idx] += $h;
            $total += $h;
        }
    }
    return [
        'year'      => $year,
        'total'     => round($total, 1),
        'limit'     => max(0, (int) get_option('tm_annual_hours_reference', 0)),
        'mandatory' => tm_doctor_is_mandatory($user_id),
        'months'    => array_map(fn($h) => round($h, 1), $months),
    ];
}

function tm_schedule_block_week($post_id) {
    if (!function_exists('defb_get_block')) return null;
    $week = defb_get_block((int) $post_id, 'turnimedici/weekly-schedule', 'week');
    if (!is_array($week) && get_post_type((int) $post_id) === 'tm_schedule') {
        $legacy = get_post_meta((int) $post_id, 'tm_schedule_legacy_week', true);
        if (is_array($legacy)) return $legacy;
    }
    return is_array($week) ? $week : null;
}

function tm_board_calendar($post_id) {
    $post_id = (int) $post_id;
    $post_type = get_post_type($post_id);
    if ($post_type === 'tm_schedule') {
        if (metadata_exists('post', $post_id, 'tm_schedule_week')) {
            $week = get_post_meta($post_id, 'tm_schedule_week', true);
            return is_array($week) ? $week : null;
        }
        return tm_schedule_block_week($post_id);
    }
    if ($post_type === 'tm_facility') return tm_schedule_block_week($post_id);
    return null;
}

function tm_board_effective_day($week, $iso) {
    $defaults = is_array($week['defaults'] ?? null) ? $week['defaults'] : [];
    $default_slots = tm_clean_slots($defaults['slots'] ?? []);
    $override = $week['overrides'][$iso] ?? null;
    if (is_array($override)) {
        if (array_key_exists('closed', $override)) {
            $closed = !empty($override['closed']);
        } elseif (array_key_exists('open', $override)) {
            $closed = empty($override['open']);
        } else {
            $closed = false;
        }
        $slots = (is_array($override['slots'] ?? null) && $override['slots']) ? tm_clean_slots($override['slots']) : $default_slots;
        return ['open' => !$closed, 'slots' => $closed ? [] : $slots, 'note' => trim((string) ($override['note'] ?? ''))];
    }
    $key = TM_BOARD_WEEKDAYS[(int) date('N', strtotime($iso)) - 1];
    $closed = in_array($key, $defaults['closedWeekdays'] ?? [], true);
    return ['open' => !$closed, 'slots' => $closed ? [] : $default_slots, 'note' => ''];
}

function tm_schedule_month($schedule_id) {
    $raw = get_post_meta($schedule_id, 'tm_schedule_month', true);
    if (tm_valid_month_value($raw) && preg_match('/^(\d{4})-(\d{2})$/', (string) $raw, $m)) {
        return ['year' => (int) $m[1], 'month' => (int) $m[2]];
    }
    return ['year' => (int) current_time('Y'), 'month' => (int) current_time('n')];
}

function tm_doctor_month_shifts($doctor_id, $year, $month) {
    global $wpdb;
    $doctor_id = (int) $doctor_id;
    if (!$doctor_id) return [];

    $start = sprintf('%04d-%02d-01', (int) $year, (int) $month);
    $end = date('Y-m-t', strtotime($start));
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT shifts.schedule_id, shifts.shift_date, shifts.slot
         FROM ' . tm_table('shifts') . ' shifts
         INNER JOIN ' . $wpdb->posts . " schedules ON schedules.ID = shifts.schedule_id AND schedules.post_type = 'tm_schedule' AND schedules.post_status IN ('publish', 'draft')
         WHERE shifts.doctor_id = %d AND shifts.shift_date BETWEEN %s AND %s
         ORDER BY shifts.shift_date ASC, shifts.slot ASC",
        $doctor_id,
        $start,
        $end
    ));
    if (!$rows) return [];

    $facility_names = [];
    $out = [];
    foreach ($rows as $row) {
        $schedule_id = (int) $row->schedule_id;
        if (!array_key_exists($schedule_id, $facility_names)) {
            $facility_id = (int) get_post_meta($schedule_id, 'tm_facility_id', true);
            $facility = $facility_id ? get_post($facility_id) : null;
            $facility_names[$schedule_id] = $facility ? $facility->post_title : '';
        }
        $out[(string) $row->shift_date][] = [
            'schedule_id' => $schedule_id,
            'facility'    => $facility_names[$schedule_id],
            'slot'        => (string) $row->slot,
        ];
    }
    return $out;
}

function tm_facility_schedules($facility_id) {
    $default = (int) get_post_meta($facility_id, 'tm_default_schedule', true);
    $posts = get_posts([
        'post_type'   => 'tm_schedule',
        'numberposts' => -1,
        'post_status' => ['publish', 'draft'],
        'meta_key'    => 'tm_facility_id',
        'meta_value'  => (int) $facility_id,
    ]);
    $months = [];
    foreach ($posts as $post) {
        $months[] = [
            'id'         => $post->ID,
            'month'      => tm_schedule_month($post->ID),
            'closed'     => (bool) get_post_meta($post->ID, 'tm_schedule_closed', true),
            'is_default' => $default === (int) $post->ID,
        ];
    }
    usort($months, function ($a, $b) {
        return [$b['month']['year'], $b['month']['month']] <=> [$a['month']['year'], $a['month']['month']];
    });
    return $months;
}

function tm_facility_schedule_for_month($facility_id, $month) {
    $posts = get_posts([
        'post_type'   => 'tm_schedule',
        'numberposts' => 1,
        'post_status' => ['publish', 'draft'],
        'meta_query'  => [
            ['key' => 'tm_facility_id', 'value' => (int) $facility_id],
            ['key' => 'tm_schedule_month', 'value' => (string) $month],
        ],
    ]);
    return $posts ? $posts[0] : null;
}

function tm_next_facility_schedule_month($facility_id) {
    $months = tm_facility_schedules($facility_id);
    if (!$months) return current_time('Y-m');

    $latest = $months[0]['month'];
    $base = mktime(0, 0, 0, (int) $latest['month'] + 1, 1, (int) $latest['year']);
    return date('Y-m', $base);
}

function tm_schedule_source_week($facility_id, $target_month) {
    $posts = get_posts([
        'post_type'   => 'tm_schedule',
        'numberposts' => -1,
        'post_status' => ['publish', 'draft'],
        'meta_key'    => 'tm_facility_id',
        'meta_value'  => (int) $facility_id,
    ]);
    usort($posts, function ($a, $b) {
        $am = (string) get_post_meta($a->ID, 'tm_schedule_month', true);
        $bm = (string) get_post_meta($b->ID, 'tm_schedule_month', true);
        return [$bm, $b->ID] <=> [$am, $a->ID];
    });

    foreach ($posts as $post) {
        $month = (string) get_post_meta($post->ID, 'tm_schedule_month', true);
        if ($month && $month < $target_month) {
            $week = tm_board_calendar($post->ID);
            if (is_array($week)) return $week;
        }
    }
    foreach ($posts as $post) {
        $week = tm_board_calendar($post->ID);
        if (is_array($week)) return $week;
    }

    $facility_week = tm_board_calendar($facility_id);
    return is_array($facility_week) ? $facility_week : null;
}

function tm_new_schedule_week($facility_id, $month) {
    $source = tm_schedule_source_week($facility_id, $month);
    $defaults = is_array($source['defaults'] ?? null) ? $source['defaults'] : [];
    $week = tm_sanitize_week([
        'viewMonth' => $month,
        'defaults'  => [
            'closedWeekdays' => is_array($defaults['closedWeekdays'] ?? null) ? $defaults['closedWeekdays'] : ['sat', 'sun'],
            'slots'          => is_array($defaults['slots'] ?? null) ? $defaults['slots'] : [
                ['from' => '08:00', 'to' => '13:00'],
                ['from' => '15:00', 'to' => '19:00'],
            ],
        ],
        'overrides' => [],
    ]);
    return $week ?: [
        'viewMonth' => $month,
        'defaults'  => [
            'closedWeekdays' => ['sat', 'sun'],
            'slots'          => [
                ['from' => '08:00', 'to' => '13:00'],
                ['from' => '15:00', 'to' => '19:00'],
            ],
        ],
        'overrides' => [],
    ];
}

function tm_schedule_title_for_month($facility_id, $month) {
    $facility = get_post($facility_id);
    $aft_id = $facility ? (int) get_post_meta($facility_id, 'tm_aft_id', true) : 0;
    $aft = $aft_id ? get_post($aft_id) : null;
    $label = function_exists('tm_month_label') ? tm_month_label($month) : $month;
    return implode(' - ', array_filter([
        $aft ? $aft->post_title : '',
        $label,
        $facility ? $facility->post_title : '',
    ]));
}

function tm_requested_schedule($facility_id, $request) {
    $schedule_id = (int) $request->get_param('schedule');
    if (!$schedule_id) {
        return tm_doctor_current_schedule($facility_id);
    }
    $post = get_post($schedule_id);
    $valid = $post
        && $post->post_type === 'tm_schedule'
        && in_array($post->post_status, ['publish', 'draft'], true)
        && (int) get_post_meta($post->ID, 'tm_facility_id', true) === (int) $facility_id;
    return $valid ? $post : null;
}

function tm_board($facility_id, $doctor, $schedule = null) {
    global $wpdb;
    if (!$doctor) $doctor = (object) ['ID' => 0];
    $facility = get_post($facility_id);
    if (!$facility || $facility->post_type !== 'tm_facility') return null;
    if (!tm_facility_has_aft($facility_id)) return null;

    $months = tm_facility_schedules($facility_id);
    if (!$schedule) $schedule = tm_doctor_current_schedule($facility_id);
    if (!$schedule) {
        return ['structure' => ['id' => $facility_id, 'name' => $facility->post_title], 'schedule' => null, 'months' => $months, 'invited' => false, 'requested' => false, 'can_manage' => tm_user_manages_facility($facility_id), 'me' => null, 'days' => [], 'roster' => []];
    }
    $schedule_id = $schedule->ID;
    $closed = (bool) get_post_meta($schedule_id, 'tm_schedule_closed', true);
    $participants_confirmed = function_exists('tm_schedule_participants_confirmed') && tm_schedule_participants_confirmed($schedule_id);

    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT doctor_id, position, required_hours, status FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d ORDER BY position ASC',
        $schedule_id
    ));
    $roster = [];
    $names = [];
    $my_position = null;
    $my_assignment = null;
    foreach ($rows as $row) {
        $did = (int) $row->doctor_id;
        $roster[] = ['doctor_id' => $did, 'position' => (int) $row->position, 'required_hours' => (float) $row->required_hours, 'status' => $row->status];
        $user = get_userdata($did);
        $names[$did] = $user ? trim($user->first_name . ' ' . $user->last_name) : '#' . $did;
        if ($did === $doctor->ID) {
            $my_position = (int) $row->position;
            $my_assignment = end($roster);
        }
    }
    $invited = $my_position !== null;
    $is_admin = tm_user_manages_facility($facility_id);
    $doctor_complete = !$doctor->ID || tm_doctor_professional_data_complete($doctor->ID);
    $requested = (!$invited && function_exists('tm_get_invite_request_ids'))
        ? in_array($doctor->ID, tm_get_invite_request_ids($schedule_id), true)
        : false;

    $shifts = [];
    foreach ($wpdb->get_results($wpdb->prepare('SELECT shift_date, slot, doctor_id FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d', $schedule_id)) as $s) {
        $shifts[$s->shift_date][$s->slot] = (int) $s->doctor_id;
    }
    foreach ($shifts as $slotmap) {
        foreach ($slotmap as $did) {
            if (!isset($names[$did])) {
                $u = get_userdata($did);
                $names[$did] = $u ? trim($u->first_name . ' ' . $u->last_name) : '#' . $did;
            }
        }
    }

    $week = tm_board_calendar($schedule_id);
    $orphaned_shifts = $week ? tm_orphaned_shifts_for_week($schedule_id, $week) : [];
    $month = tm_schedule_month($schedule_id);
    $doctor_month_shifts = tm_doctor_month_shifts($doctor->ID, $month['year'], $month['month']);
    $days_in_month = (int) date('t', mktime(0, 0, 0, $month['month'], 1, $month['year']));

    $days = [];
    for ($d = 1; $d <= $days_in_month; $d++) {
        $iso = sprintf('%04d-%02d-%02d', $month['year'], $month['month'], $d);
        $wk = TM_BOARD_WEEKDAYS[(int) date('N', strtotime($iso)) - 1];
        $eff = $week ? tm_board_effective_day($week, $iso) : ['open' => false, 'slots' => []];

        $slots = [];
        foreach ($eff['slots'] as $slot) {
            $key = tm_slot_key($slot);
            if ($key === '-') continue;
            $taken = $shifts[$iso][$key] ?? null;
            $unavailable = null;
            if (!$taken && $doctor->ID) {
                foreach (($doctor_month_shifts[$iso] ?? []) as $other_shift) {
                    if ((int) $other_shift['schedule_id'] === (int) $schedule_id && (string) $other_shift['slot'] === (string) $key) continue;
                    if (!tm_slots_overlap($key, $other_shift['slot'])) continue;
                    $unavailable = $other_shift;
                    break;
                }
            }
            $slots[] = [
                'key'                  => $key,
                'hours'                => tm_slot_hours($key),
                'taken'                => $taken !== null,
                'taken_by'             => ($taken !== null && ($invited || $is_admin)) ? ($names[$taken] ?? '#' . $taken) : null,
                'mine'                 => $taken === $doctor->ID,
                'unavailable'          => $unavailable !== null,
                'unavailable_facility' => $unavailable['facility'] ?? '',
                'unavailable_slot'     => $unavailable['slot'] ?? '',
            ];
        }
        $days[] = [
            'date'    => $iso,
            'day'     => $d,
            'weekday' => TM_BOARD_WEEKDAY_SHORT[$wk],
            'open'    => $eff['open'] && count($slots) > 0,
            'slots'   => $slots,
            'note'    => (string) ($eff['note'] ?? ''),
        ];
    }

    $me = null;
    if ($invited) {
        $done = 0;
        foreach ($shifts as $slotmap) {
            foreach ($slotmap as $slot_key => $did) {
                if ($did === $doctor->ID) $done += tm_slot_hours($slot_key);
            }
        }
        $waiting_for = null;
        foreach ($roster as $row) {
            if ($row['position'] < $my_position && !in_array((string) $row['status'], ['done', 'skipped'], true)) {
                $waiting_for = $names[$row['doctor_id']];
                break;
            }
        }
        $assignment_status = (string) ($my_assignment['status'] ?? '');
        $assignment_closed = in_array($assignment_status, ['done', 'skipped'], true);
        $me = [
            'position'       => $my_position,
            'required_hours' => (float) $my_assignment['required_hours'],
            'done_hours'     => $done,
            'status'         => $assignment_status,
            'is_mandatory'   => tm_doctor_is_mandatory($doctor->ID),
            'participants_confirmed' => $participants_confirmed,
            'can_compile'    => $doctor_complete && !$closed && $participants_confirmed && $waiting_for === null && !$assignment_closed,
            'waiting_for'    => $waiting_for,
        ];
    }

    $base_slots = [];
    foreach (tm_clean_slots(is_array($week) ? ($week['defaults']['slots'] ?? []) : []) as $slot) {
        $key = tm_slot_key($slot);
        if ($key !== '-') $base_slots[] = $key;
    }

    return [
        'structure' => ['id' => $facility_id, 'name' => $facility->post_title],
        'schedule'  => ['id' => $schedule_id, 'name' => $schedule->post_title, 'month' => $month, 'closed' => $closed],
        'months'    => $months,
        'invited'   => $invited,
        'requested' => $requested,
        'can_manage' => $is_admin,
        'me'        => $me,
        'days'      => $days,
        'base_slots' => $base_slots,
        'roster'    => $invited ? array_map(fn($r) => ['name' => $names[$r['doctor_id']], 'position' => $r['position'], 'status' => $r['status'], 'mine' => $r['doctor_id'] === $doctor->ID], $roster) : [],
        'orphaned_shifts' => $is_admin ? $orphaned_shifts : [],
    ];
}

/* Con ?as=ID chi gestisce la struttura ottiene la board dal punto di vista di quel medico
   (coda, ore, compilabilità): è la base del "Compila al posto di…" */
function tm_board_acting_doctor($facility_id, $request) {
    $target_id = (int) $request->get_param('as');
    if (!$target_id) return null;
    if (!tm_can_manage_scheduling() || !tm_user_manages_facility($facility_id)) {
        return new WP_Error('tm_forbidden', 'Non puoi compilare al posto di un medico.', ['status' => 403]);
    }
    $target = get_userdata($target_id);
    if (!$target || !in_array('tm_doctor', (array) $target->roles, true)) {
        return new WP_Error('tm_bad_doctor', 'Medico non valido.', ['status' => 400]);
    }
    return $target;
}

function tm_rest_board($request) {
    $doctor = tm_current_doctor();
    if (!$doctor && !tm_can_manage_scheduling()) {
        return new WP_Error('tm_unauthorized', 'Accesso riservato.', ['status' => 401]);
    }
    $facility_id = (int) $request['id'];
    if (!$doctor && tm_can_manage_scheduling() && !tm_user_manages_facility($facility_id)) {
        return new WP_Error('tm_forbidden', 'Struttura fuori dal tuo ambito.', ['status' => 403]);
    }
    $acting = tm_board_acting_doctor($facility_id, $request);
    if (is_wp_error($acting)) return $acting;
    if ($acting) $doctor = $acting;

    $schedule = tm_requested_schedule($facility_id, $request);
    if ($request->get_param('schedule') && !$schedule) {
        return new WP_Error('tm_no_schedule', 'Orario non disponibile.', ['status' => 404]);
    }
    $board = tm_board($facility_id, $doctor, $schedule);
    if (!$board) {
        return new WP_Error('tm_not_found', 'Struttura non trovata.', ['status' => 404]);
    }
    if ($acting) {
        $board['acting_as'] = ['id' => $acting->ID, 'name' => trim($acting->first_name . ' ' . $acting->last_name) ?: $acting->display_name];
        $board['can_manage'] = false;
    }
    return rest_ensure_response($board);
}

function tm_require_facility_management($facility_id) {
    $facility_id = (int) $facility_id;
    if (!$facility_id || !tm_can_manage_scheduling() || !tm_user_manages_facility($facility_id)) {
        return new WP_Error('tm_forbidden', 'Struttura fuori dal tuo ambito.', ['status' => 403]);
    }
    return true;
}

function tm_rest_submit_shifts($request) {
    global $wpdb;
    $facility_id = (int) $request['id'];
    $doctor = tm_current_doctor();
    $on_behalf_id = (int) ($request->get_json_params()['on_behalf_of'] ?? 0);
    $acting_user_id = 0;
    if ($on_behalf_id) {
        if (!tm_can_manage_scheduling() || !tm_user_manages_facility($facility_id)) {
            return new WP_Error('tm_forbidden', 'Non puoi compilare al posto di un medico.', ['status' => 403]);
        }
        $target = get_userdata($on_behalf_id);
        if (!$target || !in_array('tm_doctor', (array) $target->roles, true)) {
            return new WP_Error('tm_bad_doctor', 'Medico non valido.', ['status' => 400]);
        }
        $acting_user_id = get_current_user_id();
        $doctor = $target;
    }
    if (!$doctor) {
        return new WP_Error('tm_unauthorized', 'Accesso riservato.', ['status' => 401]);
    }
    if (!$on_behalf_id && !tm_doctor_professional_data_complete($doctor->ID)) {
        return new WP_Error('tm_incomplete_profile', 'Completa i dati professionali prima di compilare i turni.', ['status' => 403]);
    }
    $schedule = tm_requested_schedule($facility_id, $request);
    if (!$schedule) {
        return new WP_Error('tm_no_schedule', 'Nessun orario per questa struttura.', ['status' => 404]);
    }
    $schedule_id = (int) $schedule->ID;
    $selections = (array) ($request->get_json_params()['selections'] ?? []);
    $result = tm_with_shift_write_lock(function () use ($wpdb, $schedule_id, $doctor, $selections) {
        if (get_post_meta($schedule_id, 'tm_schedule_closed', true)) {
            return new WP_Error('tm_closed', 'La turnazione di questo mese è chiusa.', ['status' => 403]);
        }
        if (!function_exists('tm_schedule_participants_confirmed') || !tm_schedule_participants_confirmed($schedule_id)) {
            return new WP_Error('tm_not_confirmed', 'La lista dei partecipanti non è ancora stata confermata.', ['status' => 403]);
        }

        $wpdb->query('START TRANSACTION');
        $assignment = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d AND doctor_id = %d FOR UPDATE',
            $schedule_id,
            $doctor->ID
        ));
        if (!$assignment) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('tm_not_invited', 'Non sei in turnazione in questa struttura.', ['status' => 403]);
        }
        if (in_array((string) $assignment->status, ['done', 'skipped'], true)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('tm_already_done', 'Hai già completato questo passaggio.', ['status' => 409]);
        }

        $blocking = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . tm_table('assignments') . " WHERE schedule_id = %d AND position < %d AND (status IS NULL OR status = '' OR status NOT IN ('done', 'skipped'))",
            $schedule_id,
            $assignment->position
        ));
        if ($blocking > 0) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('tm_blocked', 'Non è ancora il tuo turno di compilazione.', ['status' => 403]);
        }

        $week = tm_board_calendar($schedule_id);
        $to_insert = [];
        $seen = [];
        foreach ($selections as $date => $slot_keys) {
            $date = sanitize_text_field($date);
            if (!tm_valid_schedule_date($schedule_id, $date)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('tm_bad_date', 'La data selezionata non appartiene a questo turno.', ['status' => 400]);
            }
            $effective = $week ? tm_board_effective_day($week, $date) : ['open' => false, 'slots' => []];
            $valid_slots = !empty($effective['open']) ? array_map('tm_slot_key', $effective['slots']) : [];
            foreach ((array) $slot_keys as $slot_key) {
                $slot_key = sanitize_text_field($slot_key);
                if (!in_array($slot_key, $valid_slots, true)) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('tm_bad_slot', 'Uno degli slot selezionati non è valido.', ['status' => 400]);
                }
                $selection_key = $date . '|' . $slot_key;
                if (isset($seen[$selection_key])) continue;
                foreach ($to_insert as $pending) {
                    if ($pending['date'] === $date && tm_slots_overlap($pending['slot'], $slot_key)) {
                        $wpdb->query('ROLLBACK');
                        return new WP_Error('tm_shift_overlap_selection', 'Hai selezionato due fasce sovrapposte nello stesso giorno.', ['status' => 400]);
                    }
                }

                $taken = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT doctor_id FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND shift_date = %s AND slot = %s',
                    $schedule_id,
                    $date,
                    $slot_key
                ));
                if ($taken && $taken !== (int) $doctor->ID) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('tm_slot_taken', 'Uno degli slot selezionati è già stato assegnato.', ['status' => 409]);
                }
                if ($taken === (int) $doctor->ID) {
                    $seen[$selection_key] = true;
                    continue;
                }

                $slot_conflict = tm_schedule_slot_conflict($schedule_id, $date, $slot_key);
                if ($slot_conflict) {
                    $wpdb->query('ROLLBACK');
                    return tm_slot_taken_error($slot_conflict);
                }
                $conflict = tm_doctor_shift_conflict($doctor->ID, $date, $slot_key);
                if ($conflict) {
                    $wpdb->query('ROLLBACK');
                    return tm_shift_conflict_error($conflict);
                }
                $seen[$selection_key] = true;
                $to_insert[] = ['date' => $date, 'slot' => $slot_key];
            }
        }

        $month = (string) get_post_meta($schedule_id, 'tm_schedule_month', true);
        $month_start = $month . '-01';
        $month_end = date('Y-m-t', strtotime($month_start));
        $existing_slots = $wpdb->get_col($wpdb->prepare(
            'SELECT slot FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND doctor_id = %d AND shift_date BETWEEN %s AND %s',
            $schedule_id,
            $doctor->ID,
            $month_start,
            $month_end
        ));
        $existing_hours = array_sum(array_map('tm_slot_hours', $existing_slots));
        $new_hours = array_sum(array_map(fn($row) => tm_slot_hours($row['slot']), $to_insert));
        $total_hours = $existing_hours + $new_hours;
        /* Il monte ore dei vincolati è un riferimento, non un blocco: la conferma sotto soglia
           passa dal confirm lato app. Resta obbligatorio selezionare almeno un turno. */
        $is_mandatory = tm_doctor_is_mandatory($doctor->ID);
        if ($is_mandatory && $total_hours <= 0) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('tm_no_shifts', 'Seleziona almeno un turno prima di confermare.', ['status' => 400]);
        }

        foreach ($to_insert as $insert) {
            $inserted = $wpdb->insert(tm_table('shifts'), [
                'schedule_id' => $schedule_id,
                'shift_date'  => $insert['date'],
                'slot'        => $insert['slot'],
                'doctor_id'   => $doctor->ID,
            ]);
            if ($inserted !== 1) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('tm_save_failed', 'Salvataggio dei turni non riuscito.', ['status' => 500]);
            }
        }

        $final_status = $total_hours > 0 ? 'done' : 'skipped';
        $updated = $wpdb->update(tm_table('assignments'), ['status' => $final_status], ['id' => $assignment->id]);
        if ($updated === false || $wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('tm_save_failed', 'Salvataggio dei turni non riuscito.', ['status' => 500]);
        }
        return ['status' => $final_status];
    });
    if (is_wp_error($result)) return $result;

    $final_status = $result['status'];
    if (function_exists('tm_queue_log_add')) {
        /* la colonna action è varchar(20): tenere i nomi corti */
        $log_action = $final_status === 'skipped'
            ? ($acting_user_id ? 'coord_skip' : 'manual_skip')
            : ($acting_user_id ? 'coord_submitted' : 'submitted');
        tm_queue_log_add($schedule_id, $doctor->ID, $log_action, $acting_user_id ?: $doctor->ID);
    }
    if (function_exists('tm_queue_notify')) tm_queue_notify($schedule_id);
    tm_notify_submit_watchers($facility_id, $schedule_id, $doctor, $final_status, $acting_user_id);

    $board = tm_board($facility_id, $doctor, $schedule);
    if ($acting_user_id) {
        $board['acting_as'] = ['id' => $doctor->ID, 'name' => trim($doctor->first_name . ' ' . $doctor->last_name) ?: $doctor->display_name];
        $board['can_manage'] = false;
    }
    return rest_ensure_response($board);
}

function tm_assignments_missing_shift_doctors($schedule_id) {
    global $wpdb;
    $current = array_map('intval', $wpdb->get_col($wpdb->prepare(
        'SELECT doctor_id FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d',
        $schedule_id
    )));
    $shift_doctors = array_map('intval', $wpdb->get_col($wpdb->prepare(
        'SELECT DISTINCT doctor_id FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND doctor_id > 0',
        $schedule_id
    )));
    return ['current' => $current, 'missing' => array_values(array_diff($shift_doctors, $current))];
}

function tm_ensure_shift_doctors_are_assigned($schedule_id) {
    $schedule_id = (int) $schedule_id;
    if ($schedule_id <= 0) return;
    if (!tm_assignments_missing_shift_doctors($schedule_id)['missing']) return;

    tm_with_shift_write_lock(function () use ($schedule_id) {
        global $wpdb;
        $assignment_table = tm_table('assignments');
        $state = tm_assignments_missing_shift_doctors($schedule_id);
        $current = $state['current'];
        $missing = $state['missing'];
        if (!$missing) return true;

        $aft_id = function_exists('tm_schedule_aft_id') ? tm_schedule_aft_id($schedule_id) : 0;
        $next = array_values(array_unique(array_merge($current, $missing)));
        if (function_exists('tm_sort_doctor_ids_by_rank')) {
            $next = tm_sort_doctor_ids_by_rank($next, $aft_id, $schedule_id);
        }

        $wpdb->query('START TRANSACTION');
        $ok = true;
        foreach ($next as $position => $doctor_id) {
            if (in_array($doctor_id, $current, true)) {
                $result = $wpdb->update($assignment_table, ['position' => $position], ['schedule_id' => $schedule_id, 'doctor_id' => $doctor_id]);
            } else {
                $result = $wpdb->insert($assignment_table, [
                    'schedule_id'    => $schedule_id,
                    'doctor_id'      => $doctor_id,
                    'position'       => $position,
                    'required_hours' => 0,
                    'status'         => 'done',
                    'qualification'  => (string) get_user_meta($doctor_id, 'tm_qualification', true),
                ]);
            }
            if ($result === false) $ok = false;
        }
        $wpdb->query($ok ? 'COMMIT' : 'ROLLBACK');
        return true;
    });
}

function tm_schedule_doctors($schedule_id) {
    global $wpdb;
    tm_ensure_shift_doctors_are_assigned($schedule_id);
    $aft_id = function_exists('tm_schedule_aft_id') ? tm_schedule_aft_id($schedule_id) : 0;
    $ranking = function_exists('tm_get_ranking') ? tm_get_ranking() : null;
    $period = function_exists('tm_schedule_period_for_filter') ? tm_schedule_period_for_filter($schedule_id) : '';
    $snapshot_rows = function_exists('tm_schedule_ranking_snapshot_rows') ? tm_schedule_ranking_snapshot_rows($schedule_id) : [];
    $shift_counts = [];
    $shift_rows = $wpdb->get_results($wpdb->prepare(
        'SELECT doctor_id, COUNT(*) AS total FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d GROUP BY doctor_id',
        $schedule_id
    ));
    foreach ($shift_rows as $shift_row) {
        $shift_counts[(int) $shift_row->doctor_id] = (int) $shift_row->total;
    }
    $contacts_all = current_user_can('edit_users');
    $contacts_aft = (!$contacts_all && function_exists('tm_coordinator_aft_id')) ? (int) tm_coordinator_aft_id() : 0;
    $doctor_payload = function ($user, $position = null, $required_hours = null, $snapshot = null) use ($aft_id, $ranking, $shift_counts, $contacts_all, $contacts_aft) {
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $doctor_id = (int) $user->ID;
        $qualification = (string) ($snapshot['qualification'] ?? get_user_meta($doctor_id, 'tm_qualification', true));
        $home_aft = array_key_exists('home_aft', $snapshot)
            ? (int) $snapshot['home_aft']
            : (function_exists('tm_doctor_home_aft_id') ? tm_doctor_home_aft_id($doctor_id) : (int) get_user_meta($doctor_id, 'tm_home_aft', true));
        $qualification_label = (string) ($snapshot['qualification_label'] ?? tm_qualification_label($qualification));
        $category = (string) ($snapshot['category'] ?? (function_exists('tm_doctor_ranking_category') ? tm_doctor_ranking_category($doctor_id, $aft_id, $ranking) : $qualification_label));
        $in_contacts_scope = $contacts_all || ($contacts_aft > 0 && (
            $home_aft === $contacts_aft
            || (function_exists('tm_doctor_is_ca_temporaneo') && tm_doctor_is_ca_temporaneo($doctor_id))
        ));
        $payload = [
            'doctor_id'         => $doctor_id,
            'name'              => trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name,
            'qualification'     => $category,
            'qualification_label' => $qualification_label,
            'qualification_key' => $qualification,
            'category'          => $category,
            'scope'             => array_key_exists('scope', $snapshot) ? $snapshot['scope'] : (($aft_id && function_exists('tm_doctor_scope')) ? tm_doctor_scope($doctor_id, $aft_id) : null),
            'aft_id'            => $home_aft,
            'email'             => $in_contacts_scope ? $user->user_email : '',
            'phone'             => $in_contacts_scope ? get_user_meta($doctor_id, 'tm_phone', true) : '',
            'has_shifts'        => !empty($shift_counts[$doctor_id]),
            'required_hours'    => $required_hours === null
                ? (tm_doctor_is_mandatory($doctor_id) ? tm_doctor_monthly_target($doctor_id) : 0)
                : (float) $required_hours,
        ];
        if ($position !== null) $payload['position'] = (int) $position;
        return $payload;
    };
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT doctor_id, position, required_hours, status FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d ORDER BY position ASC',
        $schedule_id
    ));
    $assigned = [];
    $seen = [];
    $current_found = false;
    foreach ($rows as $r) {
        $did = (int) $r->doctor_id;
        $u = get_userdata($did);
        if (!$u) continue;
        $seen[$did] = true;
        $row_payload = $doctor_payload($u, (int) $r->position + 1, (float) $r->required_hours, $snapshot_rows[$did] ?? null);
        $row_payload['status'] = (string) $r->status ?: 'pending';
        $row_payload['is_current'] = false;
        if (!$current_found && !in_array($row_payload['status'], ['done', 'skipped'], true)) {
            $row_payload['is_current'] = true;
            $current_found = true;
        }
        $assigned[] = $row_payload;
    }
    $other_ids = [];
    $candidate_ids = function_exists('tm_turnazione_doctor_ids') ? tm_turnazione_doctor_ids() : get_users(['role' => 'tm_doctor', 'fields' => 'ID']);
    foreach ($candidate_ids as $id) {
        $id = (int) $id;
        if (isset($seen[$id])) continue;
        if (!tm_doctor_is_visible_in_frontend($id, $period)) continue;
        $other_ids[] = $id;
    }
    $other_ids = function_exists('tm_sort_doctor_ids_by_rank') ? tm_sort_doctor_ids_by_rank($other_ids, $aft_id, $schedule_id) : $other_ids;
    $others = [];
    foreach ($other_ids as $id) {
        $u = get_userdata($id);
        if ($u) $others[] = $doctor_payload($u);
    }
    return [
        'assigned'     => $assigned,
        'others'       => $others,
        'confirmed'    => function_exists('tm_schedule_participants_confirmed') ? tm_schedule_participants_confirmed($schedule_id) : false,
        'confirmed_at' => (string) get_post_meta($schedule_id, 'tm_participants_confirmed_at', true),
    ];
}

function tm_rest_schedule_doctors($request) {
    $facility_id = (int) $request['id'];
    $allowed = tm_require_facility_management($facility_id);
    if (is_wp_error($allowed)) return $allowed;

    $schedule = tm_requested_schedule($facility_id, $request);
    if (!$schedule) return new WP_Error('tm_no_schedule', 'Nessun orario per questa struttura.', ['status' => 404]);
    return rest_ensure_response(tm_schedule_doctors($schedule->ID));
}

function tm_rest_update_schedule_doctors($request) {
    global $wpdb;
    $facility_id = (int) $request['id'];
    $allowed = tm_require_facility_management($facility_id);
    if (is_wp_error($allowed)) return $allowed;

    $schedule = tm_requested_schedule($facility_id, $request);
    if (!$schedule) return new WP_Error('tm_no_schedule', 'Nessun orario per questa struttura.', ['status' => 404]);

    $schedule_id = (int) $schedule->ID;
    $body = $request->get_json_params();
    $action = sanitize_key($body['action'] ?? 'add');
    $required_hours = [];
    foreach ((array) ($body['required_hours'] ?? []) as $doctor_id => $hours) {
        $doctor_id = absint($doctor_id);
        if ($doctor_id) $required_hours[$doctor_id] = min(999.99, max(0, round((float) $hours, 2)));
    }
    $doctor_ids = [];
    if (!empty($body['doctor_ids']) && is_array($body['doctor_ids'])) {
        $doctor_ids = array_values(array_unique(array_filter(array_map('absint', $body['doctor_ids']))));
    } elseif (!empty($body['doctor_id'])) {
        $doctor_ids = [absint($body['doctor_id'])];
    }
    if (!$doctor_ids && $action !== 'set') {
        return new WP_Error('tm_bad_doctor', 'Medico non valido.', ['status' => 400]);
    }

    $current = tm_assignment_doctor_ids($schedule_id);
    $period = function_exists('tm_schedule_period_for_filter') ? tm_schedule_period_for_filter($schedule_id) : '';
    if ($action === 'set') {
        foreach ($doctor_ids as $doctor_id) {
            if (!tm_user_is_turnazione_doctor($doctor_id)) {
                return new WP_Error('tm_bad_doctor', 'Medico non valido.', ['status' => 400]);
            }
            if (!tm_doctor_is_visible_in_frontend($doctor_id, $period)) {
                return new WP_Error('tm_bad_doctor', 'Medico non disponibile per questa turnazione.', ['status' => 400]);
            }
        }
        $shift_doctor_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT doctor_id FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND doctor_id > 0',
            $schedule_id
        )));
        $missing_shift_doctors = array_values(array_diff($shift_doctor_ids, $doctor_ids));
        if ($missing_shift_doctors) {
            $names = array_map(function ($doctor_id) {
                $user = get_userdata($doctor_id);
                return $user ? (trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name) : '#' . $doctor_id;
            }, $missing_shift_doctors);
            return new WP_Error('tm_doctor_has_shifts', 'Rimuovi prima i turni assegnati a: ' . implode(', ', $names), ['status' => 409]);
        }
        $aft_id = function_exists('tm_schedule_aft_id') ? tm_schedule_aft_id($schedule_id) : 0;
        $next = function_exists('tm_sort_doctor_ids_by_rank') ? tm_sort_doctor_ids_by_rank($doctor_ids, $aft_id, $schedule_id) : $doctor_ids;
    } elseif ($action === 'add') {
        foreach ($doctor_ids as $doctor_id) {
            if (!tm_user_is_turnazione_doctor($doctor_id)) {
                return new WP_Error('tm_bad_doctor', 'Medico non valido.', ['status' => 400]);
            }
            if (!tm_doctor_is_visible_in_frontend($doctor_id, $period)) {
                return new WP_Error('tm_bad_doctor', 'Medico non disponibile per questa turnazione.', ['status' => 400]);
            }
            if (!in_array($doctor_id, $current, true)) {
                $current[] = $doctor_id;
            }
        }
        $aft_id = function_exists('tm_schedule_aft_id') ? tm_schedule_aft_id($schedule_id) : 0;
        $next = function_exists('tm_sort_doctor_ids_by_rank') ? tm_sort_doctor_ids_by_rank($current, $aft_id, $schedule_id) : $current;
    } elseif ($action === 'remove') {
        if (count($doctor_ids) !== 1) {
            return new WP_Error('tm_bad_doctor', 'Puoi rimuovere un medico alla volta.', ['status' => 400]);
        }
        $doctor_id = $doctor_ids[0];
        if (!tm_user_is_turnazione_doctor($doctor_id)) {
            return new WP_Error('tm_bad_doctor', 'Medico non valido.', ['status' => 400]);
        }
        $has_shifts = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND doctor_id = %d',
            $schedule_id,
            $doctor_id
        ));
        if ($has_shifts > 0) {
            return new WP_Error('tm_doctor_has_shifts', 'Il medico ha già turni assegnati: rimuovi prima i turni.', ['status' => 409]);
        }
        $next = array_values(array_filter($current, fn($id) => (int) $id !== $doctor_id));
    } else {
        return new WP_Error('tm_bad_action', 'Azione non valida.', ['status' => 400]);
    }

    if (!tm_sync_assignments($schedule_id, $next, $action === 'set' ? $required_hours : null)) {
        return new WP_Error('tm_save_failed', 'Lista medici non salvata.', ['status' => 500]);
    }
    $notified_doctor_id = (int) get_post_meta($schedule_id, 'tm_queue_notified', true);
    if ($notified_doctor_id && in_array($notified_doctor_id, $current, true) && !in_array($notified_doctor_id, $next, true)) {
        delete_post_meta($schedule_id, 'tm_queue_notified');
        delete_post_meta($schedule_id, 'tm_queue_notified_signature');
        if (function_exists('tm_queue_log_add')) {
            tm_queue_log_add($schedule_id, $notified_doctor_id, 'notify_reset', get_current_user_id(), 'medico rimosso dalla lista');
        }
    }
    if (function_exists('tm_refresh_schedule_participants_confirmation')) {
        tm_refresh_schedule_participants_confirmation($schedule_id);
    }

    if (function_exists('tm_queue_log_add')) {
        if ($action === 'set') {
            foreach (array_diff($next, $current) as $doctor_id) {
                tm_queue_log_add($schedule_id, $doctor_id, 'admin_roster_add', get_current_user_id());
            }
            foreach (array_diff($current, $next) as $doctor_id) {
                tm_queue_log_add($schedule_id, $doctor_id, 'admin_roster_remove', get_current_user_id());
            }
        } else {
            foreach ($doctor_ids as $doctor_id) {
                tm_queue_log_add($schedule_id, $doctor_id, $action === 'remove' ? 'admin_roster_remove' : 'admin_roster_add', get_current_user_id());
            }
        }
    }

    return rest_ensure_response([
        'doctors' => tm_schedule_doctors($schedule_id),
        'board'   => tm_board($facility_id, tm_current_doctor(), $schedule),
    ]);
}

function tm_rest_confirm_schedule_doctors($request) {
    $facility_id = (int) $request['id'];
    $allowed = tm_require_facility_management($facility_id);
    if (is_wp_error($allowed)) return $allowed;

    $schedule = tm_requested_schedule($facility_id, $request);
    if (!$schedule) return new WP_Error('tm_no_schedule', 'Nessun orario per questa struttura.', ['status' => 404]);

    $schedule_id = (int) $schedule->ID;
    $result = tm_with_database_lock('participants_confirm_' . $schedule_id, function () use ($schedule_id) {
        $prepared = tm_with_shift_write_lock(function () use ($schedule_id) {
            $doctor_ids = tm_assignment_doctor_ids($schedule_id);
            if (!$doctor_ids) {
                return new WP_Error('tm_empty_roster', 'Aggiungi almeno un medico alla lista del turno.', ['status' => 400]);
            }
            $period = function_exists('tm_schedule_period_for_filter') ? tm_schedule_period_for_filter($schedule_id) : '';
            $invalid_doctors = array_values(array_filter($doctor_ids, fn($doctor_id) => !tm_doctor_is_visible_in_frontend($doctor_id, $period)));
            if ($invalid_doctors) {
                $names = array_map(function ($doctor_id) {
                    $user = get_userdata($doctor_id);
                    return $user ? (trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name) : '#' . $doctor_id;
                }, $invalid_doctors);
                return new WP_Error('tm_incomplete_doctors', 'Completa i dati professionali prima di confermare: ' . implode(', ', $names), ['status' => 409]);
            }
            if (function_exists('tm_schedule_participants_confirmed') && tm_schedule_participants_confirmed($schedule_id)) {
                return ['already_confirmed' => true];
            }

            $signature = function_exists('tm_schedule_participants_signature') ? tm_schedule_participants_signature($schedule_id) : '';
            if (!$signature) {
                return new WP_Error('tm_empty_roster', 'Aggiungi almeno un medico alla lista del turno.', ['status' => 400]);
            }
            $confirmed_at_value = current_time('mysql');
            $confirmed_at = update_post_meta($schedule_id, 'tm_participants_confirmed_at', $confirmed_at_value) !== false
                || (string) get_post_meta($schedule_id, 'tm_participants_confirmed_at', true) === $confirmed_at_value;
            $confirmed_signature = update_post_meta($schedule_id, 'tm_participants_confirmed_signature', $signature) !== false
                || (string) get_post_meta($schedule_id, 'tm_participants_confirmed_signature', true) === $signature;
            $snapshot = !function_exists('tm_update_schedule_ranking_snapshot') || tm_update_schedule_ranking_snapshot($schedule_id);
            if (!$confirmed_at || !$confirmed_signature || !$snapshot) {
                delete_post_meta($schedule_id, 'tm_participants_confirmed_at');
                delete_post_meta($schedule_id, 'tm_participants_confirmed_signature');
                delete_post_meta($schedule_id, 'tm_ranking_snapshot');
                return new WP_Error('tm_confirm_failed', 'Conferma dei partecipanti non riuscita.', ['status' => 500]);
            }
            return ['already_confirmed' => false];
        });
        if (is_wp_error($prepared)) return $prepared;
        if ($prepared['already_confirmed']) {
            return ['notification' => ['sent' => false, 'reason' => 'already_confirmed']];
        }

        if (function_exists('tm_queue_log_add')) {
            tm_queue_log_add($schedule_id, 0, 'participants_confirm', get_current_user_id());
        }
        $notification = function_exists('tm_queue_notify')
            ? tm_queue_notify($schedule_id)
            : ['sent' => false, 'reason' => 'not_available'];
        if (empty($notification['sent']) && in_array($notification['reason'] ?? '', ['schedule_not_publish', 'schedule_closed', 'missing_email', 'template_unavailable', 'send_failed', 'busy'], true)) {
            tm_with_shift_write_lock(function () use ($schedule_id) {
                delete_post_meta($schedule_id, 'tm_participants_confirmed_at');
                delete_post_meta($schedule_id, 'tm_participants_confirmed_signature');
                delete_post_meta($schedule_id, 'tm_ranking_snapshot');
                return true;
            });
            return new WP_Error('tm_notify_failed', $notification['message'] ?? ('Partecipanti non confermati: email non inviata (' . ($notification['reason'] ?? 'errore') . ').'), ['status' => 500]);
        }
        return ['notification' => $notification];
    }, 12);
    if (is_wp_error($result)) return $result;

    return rest_ensure_response([
        'doctors'      => tm_schedule_doctors($schedule_id),
        'board'        => tm_board($facility_id, tm_current_doctor(), $schedule),
        'notification' => $result['notification'],
    ]);
}

function tm_rest_set_cell($request) {
    global $wpdb;
    $facility_id = (int) $request['id'];
    $allowed = tm_require_facility_management($facility_id);
    if (is_wp_error($allowed)) return $allowed;

    $schedule = tm_requested_schedule($facility_id, $request);
    if (!$schedule) return new WP_Error('tm_no_schedule', 'Nessun orario per questa struttura.', ['status' => 404]);
    $schedule_id = $schedule->ID;

    $body = $request->get_json_params();
    $date = sanitize_text_field($body['date'] ?? '');
    $slot = sanitize_text_field($body['slot'] ?? '');
    $doctor_id = (int) ($body['doctor_id'] ?? 0);

    if (!tm_valid_schedule_date($schedule_id, $date)) {
        return new WP_Error('tm_bad_date', 'La data selezionata non appartiene a questo turno.', ['status' => 400]);
    }

    $week = tm_board_calendar($schedule_id);
    $eff = $week ? tm_board_effective_day($week, $date) : ['open' => false, 'slots' => []];
    if (empty($eff['open']) || !in_array($slot, array_map('tm_slot_key', $eff['slots']), true)) {
        return new WP_Error('tm_bad_slot', 'Slot non valido per questo giorno.', ['status' => 400]);
    }

    if ($doctor_id > 0) {
        if (!tm_user_is_turnazione_doctor($doctor_id)) {
            return new WP_Error('tm_bad_doctor', 'Medico non valido.', ['status' => 400]);
        }
        $period = function_exists('tm_schedule_period_for_filter') ? tm_schedule_period_for_filter($schedule_id) : '';
        if (!tm_doctor_is_visible_in_frontend($doctor_id, $period)) {
            return new WP_Error('tm_bad_doctor', 'Medico non disponibile per questa turnazione.', ['status' => 400]);
        }
    }

    $result = tm_with_shift_write_lock(function () use ($wpdb, $schedule_id, $date, $slot, $doctor_id) {
        $wpdb->query('START TRANSACTION');
        $previous = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT doctor_id FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND shift_date = %s AND slot = %s FOR UPDATE',
            $schedule_id,
            $date,
            $slot
        ));
        $assignment_id = 0;
        $assignment_added = false;
        $assignment_removed = false;

        if ($doctor_id > 0) {
            $slot_conflict = tm_schedule_slot_conflict($schedule_id, $date, $slot);
            if ($slot_conflict) {
                $wpdb->query('ROLLBACK');
                return tm_slot_taken_error($slot_conflict);
            }
            $conflict = tm_doctor_shift_conflict($doctor_id, $date, $slot, ['schedule_id' => $schedule_id, 'date' => $date, 'slot' => $slot]);
            if ($conflict) {
                $wpdb->query('ROLLBACK');
                return tm_shift_conflict_error($conflict);
            }
        }

        $deleted = $wpdb->delete(tm_table('shifts'), ['schedule_id' => $schedule_id, 'shift_date' => $date, 'slot' => $slot]);
        if ($deleted === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('tm_save_failed', 'Aggiornamento del turno non riuscito.', ['status' => 500]);
        }

        if ($doctor_id > 0) {
            $assignment_id = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d AND doctor_id = %d FOR UPDATE',
                $schedule_id,
                $doctor_id
            ));
            if (!$assignment_id) {
                $max = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(position) FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d', $schedule_id));
                $inserted_assignment = $wpdb->insert(tm_table('assignments'), [
                    'schedule_id'    => $schedule_id,
                    'doctor_id'      => $doctor_id,
                    'position'       => $max + 1,
                    'required_hours' => 0,
                    'status'         => 'done',
                    'qualification'  => (string) get_user_meta($doctor_id, 'tm_qualification', true),
                ]);
                if ($inserted_assignment !== 1) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('tm_save_failed', 'Inserimento del medico non riuscito.', ['status' => 500]);
                }
                $assignment_added = true;
            }
            $inserted_shift = $wpdb->insert(tm_table('shifts'), [
                'schedule_id' => $schedule_id,
                'shift_date'  => $date,
                'slot'        => $slot,
                'doctor_id'   => $doctor_id,
            ]);
            if ($inserted_shift !== 1) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('tm_save_failed', 'Assegnazione del turno non riuscita.', ['status' => 500]);
            }
        }

        if ($previous && $previous !== $doctor_id) {
            $remaining = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND doctor_id = %d',
                $schedule_id,
                $previous
            ));
            if ($remaining === 0) {
                $removed = $wpdb->query($wpdb->prepare(
                    'DELETE FROM ' . tm_table('assignments') . " WHERE schedule_id = %d AND doctor_id = %d AND status = 'done' AND required_hours = 0",
                    $schedule_id,
                    $previous
                ));
                if ($removed === false) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('tm_save_failed', 'Aggiornamento della lista medici non riuscito.', ['status' => 500]);
                }
                $assignment_removed = $removed > 0;
            }
        }

        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('tm_save_failed', 'Aggiornamento del turno non riuscito.', ['status' => 500]);
        }
        return ['previous' => $previous, 'roster_changed' => $assignment_added || $assignment_removed];
    });
    if (is_wp_error($result)) return $result;
    $prev = (int) $result['previous'];

    $detail = $date . ' ' . str_replace('-', '–', $slot);
    if ($prev && $prev !== $doctor_id && function_exists('tm_queue_log_add')) {
        tm_queue_log_add($schedule_id, $prev, 'admin_cell_clear', get_current_user_id(), $detail);
    }
    if ($doctor_id > 0 && function_exists('tm_queue_log_add')) {
        tm_queue_log_add($schedule_id, $doctor_id, 'admin_cell_assign', get_current_user_id(), $detail);
    }

    if (!empty($result['roster_changed']) && function_exists('tm_refresh_schedule_participants_confirmation')) {
        tm_refresh_schedule_participants_confirmation($schedule_id);
    }

    return rest_ensure_response(tm_board($facility_id, tm_current_doctor(), $schedule));
}

function tm_sanitize_week($raw, $strict = false) {
    if (!is_array($raw)) return null;
    $view_month = (string) ($raw['viewMonth'] ?? '');
    if ($strict && !tm_valid_month_value($view_month)) {
        return new WP_Error('tm_bad_month', 'Mese del calendario non valido.', ['status' => 400]);
    }

    $defaults_raw = is_array($raw['defaults'] ?? null) ? $raw['defaults'] : [];
    if ($strict) {
        $valid_defaults = tm_validate_slots($defaults_raw['slots'] ?? [], 'Fasce standard');
        if (is_wp_error($valid_defaults)) return $valid_defaults;
    }
    $closed_weekdays = array_values(array_intersect(TM_BOARD_WEEKDAYS, array_map('strval', (array) ($defaults_raw['closedWeekdays'] ?? []))));

    $overrides = [];
    foreach ((array) ($raw['overrides'] ?? []) as $iso => $override) {
        if (!tm_valid_iso_date((string) $iso) || !is_array($override)) {
            if ($strict) return new WP_Error('tm_bad_date', 'Data personalizzata non valida.', ['status' => 400]);
            continue;
        }
        if ($view_month && substr((string) $iso, 0, 7) !== $view_month) {
            if ($strict) return new WP_Error('tm_bad_date', 'Una data personalizzata non appartiene al mese del turno.', ['status' => 400]);
            continue;
        }
        $clean = [];
        if (array_key_exists('closed', $override)) $clean['closed'] = !empty($override['closed']);
        if (array_key_exists('slots', $override)) {
            if ($strict && empty($clean['closed'])) {
                $valid_override = tm_validate_slots($override['slots'], 'Fasce del giorno ' . date_i18n('d/m/Y', strtotime($iso)));
                if (is_wp_error($valid_override)) return $valid_override;
            }
            $clean['slots'] = tm_clean_slots($override['slots']);
        }
        $note = trim((string) ($override['note'] ?? ''));
        if ($note !== '') $clean['note'] = sanitize_text_field($note);
        if ($clean) $overrides[$iso] = $clean;
    }

    $week = [
        'defaults'  => [
            'closedWeekdays' => $closed_weekdays,
            'slots'          => tm_clean_slots($defaults_raw['slots'] ?? []),
        ],
        'overrides' => $overrides,
    ];
    if (tm_valid_month_value($view_month)) {
        $week['viewMonth'] = $view_month;
    }
    return $week;
}

function tm_write_schedule_week($schedule_id, $week) {
    $schedule_id = (int) $schedule_id;
    if (get_post_type($schedule_id) !== 'tm_schedule' || !is_array($week)) return false;
    if (metadata_exists('post', $schedule_id, 'tm_schedule_week') && get_post_meta($schedule_id, 'tm_schedule_week', true) === $week) {
        return true;
    }
    return update_post_meta($schedule_id, 'tm_schedule_week', $week) !== false;
}

function tm_orphaned_shifts_for_week($schedule_id, $week) {
    global $wpdb;
    if (!$week) return [];

    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT shift_date, slot, COUNT(*) AS total FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d GROUP BY shift_date, slot ORDER BY shift_date ASC, slot ASC',
        (int) $schedule_id
    ));
    $orphaned = [];
    foreach ($rows as $row) {
        $eff = tm_board_effective_day($week, (string) $row->shift_date);
        $valid = [];
        if (!empty($eff['open'])) {
            foreach ((array) ($eff['slots'] ?? []) as $slot) {
                $key = tm_slot_key($slot);
                if ($key !== '-') $valid[$key] = true;
            }
        }
        if (isset($valid[(string) $row->slot])) continue;

        $key = (string) $row->slot;
        if (!isset($orphaned[$key])) {
            $orphaned[$key] = [
                'slot'  => $key,
                'count' => 0,
                'dates' => [],
            ];
        }
        $orphaned[$key]['count'] += (int) $row->total;
        $orphaned[$key]['dates'][] = (string) $row->shift_date;
    }
    return array_values($orphaned);
}

function tm_orphaned_shifts_error($orphaned) {
    $total = array_sum(array_map(fn($row) => (int) ($row['count'] ?? 0), $orphaned));
    $slots = implode(', ', array_map(fn($row) => tm_slot_label($row['slot'] ?? ''), $orphaned));
    return new WP_Error(
        'tm_orphaned_shifts',
        "Ci sono $total turni già assegnati su fasce non presenti nel calendario: $slots. Ripristina quelle fasce o rimuovi prima i turni.",
        ['status' => 409, 'orphaned_shifts' => $orphaned]
    );
}

function tm_validate_schedule_week_for_post($schedule_id, $raw_week) {
    $schedule_id = (int) $schedule_id;
    $week = tm_sanitize_week($raw_week, true);
    if (is_wp_error($week)) return $week;
    if (!$week) return new WP_Error('tm_bad_week', 'Calendario non valido.');

    $month = (string) get_post_meta($schedule_id, 'tm_schedule_month', true);
    if (!tm_valid_month_value($month) || ($week['viewMonth'] ?? '') !== $month) {
        return new WP_Error('tm_month_mismatch', 'Il mese del calendario non coincide con quello del turno.');
    }

    $orphaned = tm_orphaned_shifts_for_week($schedule_id, $week);
    if ($orphaned) return tm_orphaned_shifts_error($orphaned);
    return $week;
}

function tm_schedule_week_migration_value($schedule_id, $raw_week) {
    $month = (string) get_post_meta($schedule_id, 'tm_schedule_month', true);
    if (is_array($raw_week) && tm_valid_month_value($month) && ($raw_week['viewMonth'] ?? '') !== $month) {
        $candidate = $raw_week;
        $candidate['viewMonth'] = $month;
        $candidate = tm_validate_schedule_week_for_post($schedule_id, $candidate);
        return is_wp_error($candidate) ? $candidate : ['week' => $candidate, 'changed' => true];
    }

    $validated = tm_validate_schedule_week_for_post($schedule_id, $raw_week);
    if (!is_wp_error($validated)) return ['week' => $validated, 'changed' => false];
    if (!tm_valid_month_value($month)) return $validated;

    if (!is_array($raw_week)) {
        $facility_id = (int) get_post_meta($schedule_id, 'tm_facility_id', true);
        if (get_post_type($facility_id) !== 'tm_facility') return $validated;
        $candidate = tm_new_schedule_week($facility_id, $month);
    } else {
        return $validated;
    }

    $candidate = tm_validate_schedule_week_for_post($schedule_id, $candidate);
    return is_wp_error($candidate) ? $candidate : ['week' => $candidate, 'changed' => true];
}

function tm_migrate_schedule_weeks($force = false) {
    if ((int) get_option('tm_schedule_week_version') >= TM_SCHEDULE_WEEK_VERSION) return true;
    if (!$force && (int) get_option('tm_schedule_week_attempted_version') >= TM_SCHEDULE_WEEK_VERSION) return false;

    $result = tm_with_database_lock('schedule_week_migration', function () use ($force) {
        if ((int) get_option('tm_schedule_week_version') >= TM_SCHEDULE_WEEK_VERSION) return true;
        if (!$force && (int) get_option('tm_schedule_week_attempted_version') >= TM_SCHEDULE_WEEK_VERSION) return false;

        update_option('tm_schedule_week_attempted_version', TM_SCHEDULE_WEEK_VERSION, false);

        $errors = [];
        $schedule_ids = get_posts([
            'post_type'   => 'tm_schedule',
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'fields'      => 'ids',
            'orderby'     => 'ID',
            'order'       => 'ASC',
        ]);

        foreach ($schedule_ids as $schedule_id) {
            $has_meta = metadata_exists('post', $schedule_id, 'tm_schedule_week');
            $raw_week = $has_meta
                ? get_post_meta($schedule_id, 'tm_schedule_week', true)
                : tm_schedule_block_week($schedule_id);
            $migration = tm_schedule_week_migration_value($schedule_id, $raw_week);
            if (is_wp_error($migration)) {
                $errors[$schedule_id] = $migration->get_error_message();
                continue;
            }
            if ((!$has_meta || !empty($migration['changed'])) && !tm_write_schedule_week($schedule_id, $migration['week'])) {
                $errors[$schedule_id] = 'Scrittura del calendario non riuscita.';
            }
        }

        if ($errors) {
            update_option('tm_schedule_week_migration_errors', $errors, false);
            return false;
        }

        delete_option('tm_schedule_week_migration_errors');
        update_option('tm_schedule_week_version', TM_SCHEDULE_WEEK_VERSION, false);
        return true;
    }, 2);

    return !is_wp_error($result) && $result === true;
}
add_action('init', 'tm_migrate_schedule_weeks', 20);

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (sanitize_key($_GET['tm_schedule_week_migration'] ?? '') === 'success') {
        echo '<div class="notice notice-success is-dismissible"><p>Migrazione calendari completata.</p></div>';
    }
    $errors = get_option('tm_schedule_week_migration_errors');
    if (!is_array($errors) || !$errors) return;
    $retry_url = wp_nonce_url(
        admin_url('admin-post.php?action=tm_retry_schedule_week_migration'),
        'tm_retry_schedule_week_migration'
    );
    echo '<div class="notice notice-error"><p><strong>Turni Medici:</strong> migrazione calendario non completata. Il fallback legacy resta attivo.</p><ul>';
    foreach ($errors as $schedule_id => $message) {
        $edit_url = admin_url('post.php?post=' . (int) $schedule_id . '&action=edit');
        echo '<li><a href="' . esc_url($edit_url) . '">Turno ' . (int) $schedule_id . '</a>: ' . esc_html((string) $message) . '</li>';
    }
    echo '</ul><p><a class="button button-secondary" href="' . esc_url($retry_url) . '">Riprova migrazione</a></p></div>';
});

add_action('admin_post_tm_retry_schedule_week_migration', function () {
    if (!current_user_can('manage_options')) wp_die('Non autorizzato.');
    check_admin_referer('tm_retry_schedule_week_migration');
    $success = tm_migrate_schedule_weeks(true);
    $url = add_query_arg(
        'tm_schedule_week_migration',
        $success ? 'success' : 'failed',
        admin_url('admin.php?page=tm-schedules')
    );
    wp_safe_redirect($url);
    exit;
});

function tm_rest_get_calendar($request) {
    $facility_id = (int) $request['id'];
    $allowed = tm_require_facility_management($facility_id);
    if (is_wp_error($allowed)) return $allowed;

    $schedule = tm_requested_schedule($facility_id, $request);
    if (!$schedule) {
        return new WP_Error('tm_no_schedule', 'Nessun orario per questa struttura.', ['status' => 404]);
    }
    $week = tm_board_calendar($schedule->ID);
    return rest_ensure_response([
        'schedule_id' => $schedule->ID,
        'month'       => tm_schedule_month($schedule->ID),
        'week'        => $week ?: ['defaults' => ['closedWeekdays' => ['sat', 'sun'], 'slots' => []], 'overrides' => []],
    ]);
}

function tm_rest_save_calendar($request) {
    $facility_id = (int) $request['id'];
    $allowed = tm_require_facility_management($facility_id);
    if (is_wp_error($allowed)) return $allowed;

    $schedule = tm_requested_schedule($facility_id, $request);
    if (!$schedule) {
        return new WP_Error('tm_no_schedule', 'Nessun orario per questa struttura.', ['status' => 404]);
    }
    $week = tm_sanitize_week($request->get_json_params()['week'] ?? null, true);
    if (is_wp_error($week)) return $week;
    if (!$week) {
        return new WP_Error('tm_bad_week', 'Calendario non valido.', ['status' => 400]);
    }
    $schedule_month = (string) get_post_meta($schedule->ID, 'tm_schedule_month', true);
    if (!tm_valid_month_value($schedule_month) || ($week['viewMonth'] ?? '') !== $schedule_month) {
        return new WP_Error('tm_month_mismatch', 'Il mese del calendario non può essere cambiato da questa schermata.', ['status' => 409]);
    }
    $force_remove = !empty($request->get_json_params()['force_remove_shifts']);
    $saved = tm_with_shift_write_lock(function () use ($schedule, $week, $force_remove) {
        $orphaned = tm_orphaned_shifts_for_week($schedule->ID, $week);
        if ($orphaned && !$force_remove) return tm_orphaned_shifts_error($orphaned);
        if ($orphaned) tm_delete_orphaned_shifts($schedule->ID, $orphaned);
        if (!tm_write_schedule_week($schedule->ID, $week)) {
            return new WP_Error('tm_save_failed', 'Salvataggio non riuscito.', ['status' => 500]);
        }
        return true;
    });
    if (is_wp_error($saved)) return $saved;
    return rest_ensure_response(tm_board($facility_id, tm_current_doctor(), $schedule));
}

function tm_delete_orphaned_shifts($schedule_id, $orphaned) {
    global $wpdb;
    foreach ($orphaned as $group) {
        $slot = (string) ($group['slot'] ?? '');
        $dates = array_values(array_filter(array_map('strval', (array) ($group['dates'] ?? []))));
        if ($slot === '' || !$dates) continue;
        $placeholders = implode(',', array_fill(0, count($dates), '%s'));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . tm_table('shifts') . " WHERE schedule_id = %d AND slot = %s AND shift_date IN ($placeholders)",
            array_merge([(int) $schedule_id, $slot], $dates)
        ));
    }
}

function tm_rest_create_structure_schedule($request) {
    $facility_id = (int) $request['id'];
    $allowed = tm_require_facility_management($facility_id);
    if (is_wp_error($allowed)) return $allowed;

    $facility = get_post($facility_id);
    if (!$facility || $facility->post_type !== 'tm_facility') {
        return new WP_Error('tm_not_found', 'Struttura non trovata.', ['status' => 404]);
    }
    if (!tm_facility_has_aft($facility_id)) {
        return new WP_Error('tm_not_found', 'Struttura non trovata.', ['status' => 404]);
    }

    $body = $request->get_json_params();
    if (!is_array($body) || !$body) {
        $body = $request->get_body_params();
    }
    $month = sanitize_text_field($body['month'] ?? '');
    if ($month === '') $month = tm_next_facility_schedule_month($facility_id);
    if (!tm_valid_month_value($month)) {
        return new WP_Error('tm_bad_month', 'Mese non valido.', ['status' => 400]);
    }

    $created = tm_with_database_lock('schedule_' . $facility_id . '_' . $month, function () use ($facility_id, $month, $body) {
        $existing = tm_facility_schedule_for_month($facility_id, $month);
        if ($existing) {
            return new WP_Error('tm_duplicate_month', 'Esiste già un orario per questo mese.', ['status' => 409, 'schedule_id' => $existing->ID]);
        }

        $week = tm_new_schedule_week($facility_id, $month);
        $post_id = wp_insert_post([
            'post_type'   => 'tm_schedule',
            'post_status' => 'publish',
            'post_title'  => tm_schedule_title_for_month($facility_id, $month),
            'post_content'=> function_exists('tm_schedule_admin_block_content') ? tm_schedule_admin_block_content() : '',
        ], true);
        if (is_wp_error($post_id)) return $post_id;

        $saved = update_post_meta($post_id, 'tm_facility_id', $facility_id) !== false
            && update_post_meta($post_id, 'tm_schedule_month', $month) !== false
            && update_post_meta($post_id, 'tm_schedule_closed', false) !== false
            && tm_write_schedule_week($post_id, $week);
        if (!$saved) {
            wp_delete_post($post_id, true);
            return new WP_Error('tm_save_failed', 'Creazione del calendario non riuscita.', ['status' => 500]);
        }

        $set_default = !empty($body['set_default']) || !(int) get_post_meta($facility_id, 'tm_default_schedule', true);
        if ($set_default && update_post_meta($facility_id, 'tm_default_schedule', $post_id) === false) {
            wp_delete_post($post_id, true);
            return new WP_Error('tm_save_failed', 'Creazione del calendario non riuscita.', ['status' => 500]);
        }
        return ['post_id' => (int) $post_id, 'week' => $week];
    });
    if (is_wp_error($created)) return $created;
    $post_id = $created['post_id'];
    $week = $created['week'];

    $schedule = get_post($post_id);
    return rest_ensure_response([
        'schedule' => ['id' => $post_id, 'month' => tm_schedule_month($post_id), 'closed' => false],
        'board'    => tm_board($facility_id, tm_current_doctor(), $schedule),
        'calendar' => ['schedule_id' => $post_id, 'month' => tm_schedule_month($post_id), 'week' => $week],
    ]);
}

function tm_render_magic_message($template, $doctor, $facility_name, $month_label) {
    tm_ensure_valid_magic_token($doctor->ID);
    $name = trim($doctor->first_name . ' ' . $doctor->last_name) ?: $doctor->display_name;
    return strtr($template, [
        '{{first_name}}' => $doctor->first_name ?: $name,
        '{{name}}'       => $name,
        '{{magic_link}}' => tm_doctor_magic_url($doctor->ID),
        '{{structure}}'  => $facility_name,
        '{{month}}'      => $month_label,
    ]);
}

/* Messaggi pronti da incollare (WhatsApp/SMS) con i magic link della coda del turno */
function tm_rest_link_messages($request) {
    $facility_id = (int) $request['id'];
    $allowed = tm_require_facility_management($facility_id);
    if (is_wp_error($allowed)) return $allowed;

    $schedule = tm_requested_schedule($facility_id, $request);
    if (!$schedule) {
        return new WP_Error('tm_no_schedule', 'Nessun orario per questa struttura.', ['status' => 404]);
    }

    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT doctor_id, status FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d ORDER BY position ASC, id ASC',
        $schedule->ID
    ));
    $template = tm_magic_message_template();
    $facility_name = get_the_title($facility_id);
    $month_label = function_exists('tm_queue_month_label') ? tm_queue_month_label($schedule->ID) : '';

    $messages = [];
    $next = null;
    foreach ($rows as $row) {
        $doctor = get_userdata((int) $row->doctor_id);
        if (!$doctor) continue;
        $message = tm_render_magic_message($template, $doctor, $facility_name, $month_label);
        $messages[] = $message;
        if (!$next && !in_array((string) $row->status, ['done', 'skipped'], true)) {
            $next = [
                'name'    => trim($doctor->first_name . ' ' . $doctor->last_name) ?: $doctor->display_name,
                'message' => $message,
            ];
        }
    }

    return rest_ensure_response([
        'count' => count($messages),
        'all'   => implode("\n\n———\n\n", $messages),
        'next'  => $next,
    ]);
}

function tm_notify_submit_watchers($facility_id, $schedule_id, $doctor, $status, $acting_user_id = 0) {
    if (!function_exists('tm_submit_watchers')) return;
    $watchers = array_filter(tm_submit_watchers(), fn($watcher) => !$watcher['facility_id'] || $watcher['facility_id'] === (int) $facility_id);
    if (!$watchers) return;

    global $wpdb;
    $name = trim($doctor->first_name . ' ' . $doctor->last_name) ?: $doctor->display_name;
    $facility_name = get_the_title($facility_id);
    $month_label = function_exists('tm_queue_month_label') ? tm_queue_month_label($schedule_id) : '';
    $shift_count = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND doctor_id = %d',
        $schedule_id, $doctor->ID
    ));
    $verb = $status === 'skipped' ? 'ha saltato il turno' : 'ha compilato le disponibilità';
    $by = $acting_user_id ? "\nInserite dal coordinatore al posto del medico." : '';

    $subject = "[Turni Medici] {$name} — {$facility_name} · {$month_label}";
    $body = "{$name} {$verb} per {$facility_name} ({$month_label}).{$by}\nTurni inseriti: {$shift_count}.\n\nApri la struttura: " . home_url('/' . get_post_field('post_name', $facility_id));
    foreach ($watchers as $watcher) {
        wp_mail($watcher['email'], $subject, $body);
    }
}

add_action('rest_api_init', function () {
    $auth = fn() => tm_current_doctor() !== null || tm_can_manage_scheduling();
    $access = fn() => tm_can_access_turni();
    $manage = fn($request) => tm_require_facility_management((int) $request['id']);

    register_rest_route('turnimedici/v1', '/structures/(?P<id>\d+)/board', [
        'methods'             => 'GET',
        'callback'            => 'tm_rest_board',
        'permission_callback' => $access,
    ]);
    register_rest_route('turnimedici/v1', '/structures/(?P<id>\d+)/shifts', [
        'methods'             => 'POST',
        'callback'            => 'tm_rest_submit_shifts',
        'permission_callback' => $auth,
    ]);
    register_rest_route('turnimedici/v1', '/structures/(?P<id>\d+)/doctors', [
        ['methods' => 'GET', 'callback' => 'tm_rest_schedule_doctors', 'permission_callback' => $manage],
        ['methods' => 'POST', 'callback' => 'tm_rest_update_schedule_doctors', 'permission_callback' => $manage],
    ]);
    register_rest_route('turnimedici/v1', '/structures/(?P<id>\d+)/doctors/confirm', [
        'methods'             => 'POST',
        'callback'            => 'tm_rest_confirm_schedule_doctors',
        'permission_callback' => $manage,
    ]);
    register_rest_route('turnimedici/v1', '/structures/(?P<id>\d+)/cell', [
        'methods'             => 'POST',
        'callback'            => 'tm_rest_set_cell',
        'permission_callback' => $manage,
    ]);
    register_rest_route('turnimedici/v1', '/structures/(?P<id>\d+)/calendar', [
        ['methods' => 'GET', 'callback' => 'tm_rest_get_calendar', 'permission_callback' => $manage],
        ['methods' => 'POST', 'callback' => 'tm_rest_save_calendar', 'permission_callback' => $manage],
    ]);
    register_rest_route('turnimedici/v1', '/structures/(?P<id>\d+)/schedules', [
        'methods'             => 'POST',
        'callback'            => 'tm_rest_create_structure_schedule',
        'permission_callback' => $manage,
    ]);
    register_rest_route('turnimedici/v1', '/structures/(?P<id>\d+)/link-messages', [
        'methods'             => 'POST',
        'callback'            => 'tm_rest_link_messages',
        'permission_callback' => $manage,
    ]);
});
