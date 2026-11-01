<?php

if (!defined('ABSPATH')) exit;

function tm_schedule_aft_id($schedule_id) {
    $facility_id = (int) get_post_meta($schedule_id, 'tm_facility_id', true);
    return $facility_id ? (int) get_post_meta($facility_id, 'tm_aft_id', true) : 0;
}

function tm_resolve_aft_id($schedule_id, $facility_id = 0) {
    if ($facility_id > 0) {
        return (int) get_post_meta($facility_id, 'tm_aft_id', true);
    }
    return tm_schedule_aft_id($schedule_id);
}

function tm_doctor_scope($doctor_id, $aft_id) {
    $qualification = get_user_meta($doctor_id, 'tm_qualification', true);
    if ($qualification === 'ca_temporaneo') return null;
    $home_aft = function_exists('tm_doctor_home_aft_id') ? tm_doctor_home_aft_id($doctor_id) : (int) get_user_meta($doctor_id, 'tm_home_aft', true);
    return $home_aft === (int) $aft_id ? 'interno' : 'esterno';
}

function tm_doctor_rank($doctor_id, $aft_id, $ranking) {
    $qualification = get_user_meta($doctor_id, 'tm_qualification', true);
    $scope = tm_doctor_scope($doctor_id, $aft_id);
    foreach ($ranking['categories'] as $index => $category) {
        $scope_ok = empty($category['scope']) || $category['scope'] === $scope;
        if (in_array($qualification, (array) $category['qualifications'], true) && $scope_ok) {
            return $index;
        }
    }
    return 999;
}

function tm_ca_rank_value($doctor_id) {
    $value = get_user_meta($doctor_id, 'tm_ca_rank', true);
    return ($value === '' || $value === false) ? null : (int) $value;
}

function tm_normalize_ca_zone($zone) {
    $zone = trim((string) $zone);
    $zone = str_replace(['–', '—'], '-', $zone);
    $zone = preg_replace('/\s+/', ' ', $zone);
    return mb_strtolower($zone);
}

function tm_ca_zone_order($zone, $ranking) {
    $needle = tm_normalize_ca_zone($zone);
    foreach ((array) ($ranking['ca_zones'] ?? []) as $index => $known_zone) {
        if (tm_normalize_ca_zone($known_zone) === $needle) return $index;
    }
    return PHP_INT_MAX;
}

function tm_ca_sort_key($doctor_id, $ranking) {
    $qualification = get_user_meta($doctor_id, 'tm_qualification', true);
    if ($qualification !== 'ca_temporaneo') {
        return [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX];
    }

    $rank = tm_ca_rank_value($doctor_id);
    $cot = get_user_meta($doctor_id, 'tm_ca_cot', true) ? 1 : 0;
    $zone = get_user_meta($doctor_id, 'tm_ca_zone', true);

    /* ca_order 'rank': a parità di fascia decide solo il numero aziendale; 'zone' (in dismissione) antepone la località */
    return [
        $rank === null ? 2 : $cot,
        ($ranking['ca_order'] ?? 'rank') === 'zone' ? tm_ca_zone_order($zone, $ranking) : 0,
        $rank ?? PHP_INT_MAX,
    ];
}

function tm_rank_tiebreak($a_id, $b_id, $tiebreakers, $ranking = null) {
    $ranking = $ranking ?: tm_get_ranking();
    $key_a = tm_ca_sort_key($a_id, $ranking);
    $key_b = tm_ca_sort_key($b_id, $ranking);
    if ($key_a !== $key_b) return $key_a <=> $key_b;
    foreach ($tiebreakers as $tiebreaker) {
        $cmp = tm_tiebreak_compare($a_id, $b_id, $tiebreaker);
        if ($cmp !== 0) return $cmp;
    }
    return 0;
}

function tm_tiebreak_compare($a_id, $b_id, $tiebreaker) {
    if ($tiebreaker === 'role_start') {
        return strcmp(get_user_meta($a_id, 'tm_role_start', true) ?: '9999', get_user_meta($b_id, 'tm_role_start', true) ?: '9999');
    }
    if ($tiebreaker === 'last_name') {
        return strcmp((string) get_the_author_meta('last_name', $a_id), (string) get_the_author_meta('last_name', $b_id));
    }
    if ($tiebreaker === 'patients') {
        return (int) get_user_meta($a_id, 'tm_patients', true) - (int) get_user_meta($b_id, 'tm_patients', true);
    }
    return 0;
}

function tm_schedule_period_for_filter($schedule_id) {
    $raw = (string) get_post_meta((int) $schedule_id, 'tm_schedule_month', true);
    if (function_exists('tm_valid_month_value') && tm_valid_month_value($raw) && preg_match('/^(\d{4})-(\d{2})$/', $raw, $m)) {
        $start = sprintf('%04d-%02d-01', (int) $m[1], (int) $m[2]);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $start, wp_timezone());
        return ['start' => $start, 'end' => $date->format('Y-m-t')];
    }
    $today = current_time('Y-m-d');
    return ['start' => $today, 'end' => $today];
}

function tm_only_doctor_ids($doctor_ids, $schedule_id = 0) {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $doctor_ids))));
    if (empty($ids)) return [];
    $period = $schedule_id ? tm_schedule_period_for_filter($schedule_id) : '';
    return array_values(array_filter($ids, fn($id) => tm_user_is_turnazione_doctor($id) && tm_doctor_is_visible_in_frontend($id, $period)));
}

function tm_assignment_doctor_ids($schedule_id) {
    global $wpdb;
    $table = tm_table('assignments');
    return array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT doctor_id FROM $table WHERE schedule_id = %d ORDER BY position ASC, id ASC", $schedule_id
    )));
}

/* La graduatoria imposta della struttura come mappa doctor_id → posizione */
function tm_schedule_imposed_positions($schedule_id) {
    $facility_id = (int) get_post_meta((int) $schedule_id, 'tm_facility_id', true);
    if (!$facility_id || !function_exists('tm_facility_ranking_ids')) return [];
    return array_flip(tm_facility_ranking_ids($facility_id));
}

function tm_sort_doctor_ids_by_rank($doctor_ids, $aft_id, $schedule_id = 0) {
    $ranking = tm_get_ranking();
    $imposed = $schedule_id ? tm_schedule_imposed_positions($schedule_id) : [];
    $ids = tm_only_doctor_ids($doctor_ids, $schedule_id);
    usort($ids, function ($a, $b) use ($aft_id, $ranking, $imposed) {
        $a_imposed = $imposed[$a] ?? null;
        $b_imposed = $imposed[$b] ?? null;
        if ($a_imposed !== null || $b_imposed !== null) {
            if ($a_imposed === null) return 1;
            if ($b_imposed === null) return -1;
            return $a_imposed <=> $b_imposed;
        }
        $diff = tm_doctor_rank($a, $aft_id, $ranking) - tm_doctor_rank($b, $aft_id, $ranking);
        if ($diff !== 0) return $diff;
        return tm_rank_tiebreak($a, $b, $ranking['tiebreakers'], $ranking);
    });
    return $ids;
}

function tm_schedule_ranking_snapshot($schedule_id) {
    $snapshot = get_post_meta((int) $schedule_id, 'tm_ranking_snapshot', true);
    return is_array($snapshot) ? $snapshot : null;
}

function tm_schedule_ranking_snapshot_rows($schedule_id) {
    $snapshot = tm_schedule_ranking_snapshot($schedule_id);
    $rows = is_array($snapshot['rows'] ?? null) ? $snapshot['rows'] : [];
    $out = [];
    foreach ($rows as $row) {
        $doctor_id = (int) ($row['doctor_id'] ?? 0);
        if ($doctor_id) $out[$doctor_id] = $row;
    }
    return $out;
}

function tm_build_schedule_ranking_snapshot($schedule_id) {
    global $wpdb;
    $schedule_id = (int) $schedule_id;
    $aft_id = tm_schedule_aft_id($schedule_id);
    $ranking = tm_get_ranking();
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT doctor_id, position, required_hours, qualification FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d ORDER BY position ASC, id ASC',
        $schedule_id
    ));
    $snapshot_rows = [];
    foreach ($rows as $row) {
        $doctor_id = (int) $row->doctor_id;
        $user = get_userdata($doctor_id);
        if (!$user) continue;
        $qualification = (string) ($row->qualification ?: get_user_meta($doctor_id, 'tm_qualification', true));
        $rank_index = $aft_id ? tm_doctor_rank($doctor_id, $aft_id, $ranking) : 999;
        $home_aft = function_exists('tm_doctor_home_aft_id') ? tm_doctor_home_aft_id($doctor_id) : (int) get_user_meta($doctor_id, 'tm_home_aft', true);
        $aft = $home_aft ? get_post($home_aft) : null;
        $snapshot_rows[] = [
            'doctor_id'           => $doctor_id,
            'position'            => (int) $row->position + 1,
            'name'                => trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name,
            'qualification'       => $qualification,
            'qualification_label' => tm_qualification_label($qualification),
            'category'            => $ranking['categories'][$rank_index]['label'] ?? tm_qualification_label($qualification),
            'scope'               => $aft_id ? tm_doctor_scope($doctor_id, $aft_id) : null,
            'rank_index'          => $rank_index,
            'home_aft'            => $home_aft,
            'home_aft_name'       => $aft ? $aft->post_title : '',
            'ca_rank'             => tm_ca_rank_value($doctor_id),
            'ca_zone'             => get_user_meta($doctor_id, 'tm_ca_zone', true),
            'ca_cot'              => (bool) get_user_meta($doctor_id, 'tm_ca_cot', true),
            'required_hours'      => (float) $row->required_hours,
        ];
    }
    return [
        'version'    => 1,
        'created_at' => current_time('mysql'),
        'created_by' => get_current_user_id(),
        'aft_id'     => $aft_id,
        'rules'      => [
            'categories'  => $ranking['categories'] ?? [],
            'tiebreakers' => $ranking['tiebreakers'] ?? [],
            'ca_zones'    => $ranking['ca_zones'] ?? [],
        ],
        'rows'       => $snapshot_rows,
    ];
}

function tm_update_schedule_ranking_snapshot($schedule_id) {
    $schedule_id = (int) $schedule_id;
    if ($schedule_id <= 0) return false;
    $snapshot = tm_build_schedule_ranking_snapshot($schedule_id);
    if (get_post_meta($schedule_id, 'tm_ranking_snapshot', true) === $snapshot) return true;
    return update_post_meta($schedule_id, 'tm_ranking_snapshot', $snapshot) !== false;
}

function tm_sync_assignments($schedule_id, $doctor_ids, $required_hours_by_doctor = null) {
    $result = tm_with_shift_write_lock(function () use ($schedule_id, $doctor_ids, $required_hours_by_doctor) {
        global $wpdb;
        $table = tm_table('assignments');
        $doctor_ids = tm_only_doctor_ids($doctor_ids, $schedule_id);
        $shift_doctors = array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT doctor_id FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND doctor_id > 0',
            $schedule_id
        )));
        if (array_diff($shift_doctors, $doctor_ids)) return false;

        $existing = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT doctor_id FROM $table WHERE schedule_id = %d", $schedule_id
        )));

        $wpdb->query('START TRANSACTION');
        $ok = true;

        foreach (array_diff($existing, $doctor_ids) as $doctor_id) {
            if ($wpdb->delete($table, ['schedule_id' => $schedule_id, 'doctor_id' => $doctor_id]) === false) $ok = false;
        }

        foreach ($doctor_ids as $position => $doctor_id) {
            $has_required_hours = is_array($required_hours_by_doctor) && array_key_exists($doctor_id, $required_hours_by_doctor);
            $required_hours = $has_required_hours
                ? min(999.99, max(0, round((float) $required_hours_by_doctor[$doctor_id], 2)))
                : tm_doctor_monthly_target($doctor_id);
            if (in_array($doctor_id, $existing, true)) {
                $values = ['position' => $position];
                if ($has_required_hours) $values['required_hours'] = $required_hours;
                $write = $wpdb->update($table, $values, ['schedule_id' => $schedule_id, 'doctor_id' => $doctor_id]);
            } else {
                $write = $wpdb->insert($table, [
                    'schedule_id'    => $schedule_id,
                    'doctor_id'      => $doctor_id,
                    'position'       => $position,
                    'required_hours' => $required_hours,
                    'qualification'  => (string) get_user_meta($doctor_id, 'tm_qualification', true),
                ]);
            }
            if ($write === false) $ok = false;
        }

        if (!$ok) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        return true;
    });
    return !is_wp_error($result) && $result === true;
}

add_action('before_delete_post', 'tm_cleanup_schedule_rows');
function tm_cleanup_schedule_rows($post_id) {
    if (get_post_type($post_id) !== 'tm_schedule') return;
    global $wpdb;
    $facility_id = (int) get_post_meta($post_id, 'tm_facility_id', true);
    if ($facility_id && (int) get_post_meta($facility_id, 'tm_default_schedule', true) === (int) $post_id) {
        delete_post_meta($facility_id, 'tm_default_schedule');
    }
    $wpdb->delete(tm_table('assignments'), ['schedule_id' => $post_id]);
    $wpdb->delete(tm_table('shifts'), ['schedule_id' => $post_id]);
}

add_action('trashed_post', 'tm_clear_trashed_default_schedule');
function tm_clear_trashed_default_schedule($post_id) {
    if (get_post_type($post_id) !== 'tm_schedule') return;
    $facility_id = (int) get_post_meta($post_id, 'tm_facility_id', true);
    if ($facility_id && (int) get_post_meta($facility_id, 'tm_default_schedule', true) === (int) $post_id) {
        delete_post_meta($facility_id, 'tm_default_schedule');
    }
}

add_action('delete_user', 'tm_cleanup_doctor_rows');
function tm_cleanup_doctor_rows($user_id) {
    global $wpdb;
    $user_id = (int) $user_id;
    $wpdb->delete(tm_table('assignments'), ['doctor_id' => $user_id]);
    $wpdb->delete(tm_table('shifts'), ['doctor_id' => $user_id]);
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . tm_table('queue_log') . ' WHERE doctor_id = %d OR actor_id = %d',
        $user_id,
        $user_id
    ));
    foreach (get_posts(['post_type' => 'tm_schedule', 'post_status' => ['publish', 'draft', 'trash'], 'numberposts' => -1, 'fields' => 'ids']) as $schedule_id) {
        $ids = function_exists('tm_get_invite_request_ids') ? tm_get_invite_request_ids($schedule_id) : [];
        if (!in_array($user_id, $ids, true)) continue;
        tm_set_invite_request_ids($schedule_id, array_values(array_diff($ids, [$user_id])));
    }
}

/* Stato coda per l'admin: chi è di turno, da quando, entro quando; l'admin può sempre forzare */
function tm_queue_state($schedule_id) {
    global $wpdb;
    $rules = function_exists('tm_queue_expiry_rules') ? tm_queue_expiry_rules() : ['enabled' => false, 'days' => 2];
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT doctor_id, position, status, turn_started_at, qualification FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d ORDER BY position ASC, id ASC',
        $schedule_id
    ));
    $current_found = false;
    $out = [];
    foreach ($rows as $row) {
        $is_current = false;
        if (!$current_found && !in_array($row->status, ['done', 'skipped'], true)) {
            $is_current = true;
            $current_found = true;
        }
        $deadline = null;
        if ($rules['enabled'] && $row->turn_started_at && !in_array($row->status, ['done', 'skipped'], true)) {
            $deadline = tm_format_local_datetime(tm_add_local_days($row->turn_started_at, $rules['days']), 'd/m H:i');
        }
        $out[(int) $row->doctor_id] = [
            'status'        => $row->status,
            'is_current'    => $is_current,
            'deadline'      => $deadline,
            'qualification' => $row->qualification,
        ];
    }
    return ['expiry_enabled' => $rules['enabled'], 'doctors' => $out];
}

function tm_rest_queue_state($request) {
    $schedule_id = (int) $request['id'];
    if (get_post_type($schedule_id) !== 'tm_schedule') {
        return new WP_Error('tm_invalid_schedule', 'Orario inesistente.', ['status' => 404]);
    }
    return rest_ensure_response(tm_queue_state($schedule_id));
}

function tm_rest_queue_update($request) {
    $schedule_id = (int) $request['id'];
    $doctor_id = (int) $request['did'];
    if (get_post_type($schedule_id) !== 'tm_schedule') {
        return new WP_Error('tm_invalid_schedule', 'Orario inesistente.', ['status' => 404]);
    }
    $body = $request->get_json_params();
    $action = sanitize_key($body['action'] ?? '');
    $actor = get_current_user_id();
    if (!in_array($action, ['done', 'pending', 'skipped', 'extend'], true)) {
        return new WP_Error('tm_bad_action', 'Azione non valida.', ['status' => 400]);
    }
    $result = tm_with_shift_write_lock(function () use ($schedule_id, $doctor_id, $action, $body) {
        global $wpdb;
        $table = tm_table('assignments');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, turn_started_at FROM $table WHERE schedule_id = %d AND doctor_id = %d FOR UPDATE",
            $schedule_id,
            $doctor_id
        ));
        if (!$row) return new WP_Error('tm_not_assigned', 'Il medico non è in questa coda.', ['status' => 404]);

        if ($action === 'done') {
            $values = ['status' => 'done'];
            $log_action = 'admin_done';
            $detail = '';
        } elseif ($action === 'pending') {
            $values = ['status' => 'pending', 'turn_started_at' => null];
            $log_action = 'admin_pending';
            $detail = '';
        } elseif ($action === 'skipped') {
            $values = ['status' => 'skipped'];
            $log_action = 'admin_skip';
            $detail = '';
        } else {
            $days = max(1, (int) ($body['days'] ?? 1));
            $base = $row->turn_started_at ?: current_time('mysql');
            $values = ['turn_started_at' => tm_add_local_days($base, $days)];
            $log_action = 'admin_extend';
            $detail = '+' . $days . ' giorni';
        }
        if ($wpdb->update($table, $values, ['id' => $row->id]) === false) {
            return new WP_Error('tm_save_failed', 'Aggiornamento della coda non riuscito.', ['status' => 500]);
        }
        return ['log_action' => $log_action, 'detail' => $detail];
    });
    if (is_wp_error($result)) return $result;
    tm_queue_log_add($schedule_id, $doctor_id, $result['log_action'], $actor, $result['detail']);
    tm_queue_notify($schedule_id);
    return rest_ensure_response(tm_queue_state($schedule_id));
}

function tm_rest_ranking_snapshot($request) {
    $schedule_id = (int) $request['id'];
    if (get_post_type($schedule_id) !== 'tm_schedule') {
        return new WP_Error('tm_invalid_schedule', 'Orario inesistente.', ['status' => 404]);
    }
    $snapshot = tm_schedule_ranking_snapshot($schedule_id);
    if ($snapshot === null) {
        return rest_ensure_response(['snapshot' => null]);
    }
    $creator = !empty($snapshot['created_by']) ? get_userdata((int) $snapshot['created_by']) : null;
    return rest_ensure_response([
        'snapshot' => [
            'created_at'      => tm_format_local_datetime($snapshot['created_at'], 'd/m/Y H:i'),
            'created_by_name' => $creator ? ($creator->first_name ?: $creator->display_name) : '',
            'categories'      => array_map(fn($category) => (string) ($category['label'] ?? ''), $snapshot['rules']['categories'] ?? []),
            'rows'            => array_map(fn($row) => [
                'position'       => (int) ($row['position'] ?? 0),
                'name'           => (string) ($row['name'] ?? ''),
                'category'       => (string) ($row['category'] ?? ''),
                'scope'          => (string) ($row['scope'] ?? ''),
                'home_aft_name'  => (string) ($row['home_aft_name'] ?? ''),
                'required_hours' => (float) ($row['required_hours'] ?? 0),
            ], $snapshot['rows'] ?? []),
        ],
    ]);
}

function tm_rest_queue_log($request) {
    $schedule_id = (int) $request['id'];
    if (get_post_type($schedule_id) !== 'tm_schedule') {
        return new WP_Error('tm_invalid_schedule', 'Orario inesistente.', ['status' => 404]);
    }
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT doctor_id, actor_id, action, detail, created_at FROM ' . tm_table('queue_log') . ' WHERE schedule_id = %d ORDER BY id DESC LIMIT 50',
        $schedule_id
    ));
    $out = [];
    foreach ($rows as $row) {
        $doctor = get_userdata((int) $row->doctor_id);
        $doctor_name = $doctor ? (trim($doctor->first_name . ' ' . $doctor->last_name) ?: $doctor->display_name) : '#' . $row->doctor_id;
        $actor = $row->actor_id ? get_userdata((int) $row->actor_id) : null;
        $actor_name = $actor ? ($actor->first_name ?: $actor->display_name) : 'Admin';
        $texts = [
            'auto_skip'     => "{$doctor_name} saltato automaticamente ({$row->detail})",
            'admin_done'    => "{$actor_name} ha segnato completato {$doctor_name}",
            'admin_pending' => "{$actor_name} ha rimesso in coda {$doctor_name}",
            'admin_skip'    => "{$actor_name} ha saltato {$doctor_name}",
            'admin_extend'  => "{$actor_name} ha esteso la scadenza a {$doctor_name} ({$row->detail})",
            'submitted'     => "{$doctor_name} ha inviato le disponibilità",
            'manual_skip'   => "{$doctor_name} ha saltato il turno senza disponibilità",
            'coord_submitted' => "{$actor_name} ha compilato le disponibilità al posto di {$doctor_name}",
            'coord_skip'      => "{$actor_name} ha saltato il turno al posto di {$doctor_name}",
            'invite_request' => "{$doctor_name} ha chiesto di entrare in turnazione",
            'invite_request_admin' => "{$actor_name} ha inserito una richiesta per {$doctor_name}",
            'invite_approved' => "{$actor_name} ha approvato la richiesta di {$doctor_name}",
            'invite_rejected' => "{$actor_name} ha rifiutato la richiesta di {$doctor_name}",
            'admin_roster_add' => "{$actor_name} ha aggiunto {$doctor_name} alla lista del turno",
            'admin_roster_remove' => "{$actor_name} ha rimosso {$doctor_name} dalla lista del turno",
            'participants_confirm' => "{$actor_name} ha confermato i partecipanti del turno",
            'admin_cell_assign' => "{$actor_name} ha assegnato un turno a {$doctor_name} ({$row->detail})",
            'admin_cell_clear' => "{$actor_name} ha rimosso un turno a {$doctor_name} ({$row->detail})",
            'notify_attempt' => "Invio dell'avviso di turno a {$doctor_name}" . ($row->detail ? " ({$row->detail})" : ''),
            'notify_sent'    => "Avviso di turno inviato a {$doctor_name}",
            'notify_failed'  => "Invio dell'avviso a {$doctor_name} non riuscito" . ($row->detail ? " ({$row->detail})" : ''),
            'notify_blocked' => 'Nessun avviso inviato' . ($row->detail ? " ({$row->detail})" : ''),
            'notify_reset'   => "Coda notifiche azzerata" . ($row->detail ? " ({$row->detail})" : ''),
            'notify_reminder' => "Promemoria di compilazione inviato a {$doctor_name}",
            'notify_completed' => 'Turnazione completata: avviso inviato ai gestori' . ($row->detail ? " ({$row->detail})" : ''),
        ];
        $out[] = [
            'when' => tm_format_local_datetime($row->created_at, 'd/m/Y H:i'),
            'text' => $texts[$row->action] ?? trim($row->action . ' ' . $doctor_name),
        ];
    }
    return rest_ensure_response($out);
}

add_action('rest_api_init', 'tm_register_assignment_routes');
function tm_register_assignment_routes() {
    $permission = fn($request) => tm_can_manage_scheduling() && tm_user_manages_schedule((int) $request['id']);

    register_rest_route('turnimedici/v1', '/schedules/(?P<id>\d+)/queue', [
        ['methods' => 'GET', 'callback' => 'tm_rest_queue_state', 'permission_callback' => $permission],
    ]);
    register_rest_route('turnimedici/v1', '/schedules/(?P<id>\d+)/queue-log', [
        ['methods' => 'GET', 'callback' => 'tm_rest_queue_log', 'permission_callback' => $permission],
    ]);
    register_rest_route('turnimedici/v1', '/schedules/(?P<id>\d+)/snapshot', [
        ['methods' => 'GET', 'callback' => 'tm_rest_ranking_snapshot', 'permission_callback' => $permission],
    ]);
    register_rest_route('turnimedici/v1', '/schedules/(?P<id>\d+)/queue/(?P<did>\d+)', [
        ['methods' => 'POST', 'callback' => 'tm_rest_queue_update', 'permission_callback' => $permission],
    ]);

}
