<?php

if (!defined('ABSPATH')) exit;

function tm_current_doctor() {
    $user = wp_get_current_user();
    if ($user && $user->ID && tm_user_is_turnazione_doctor($user->ID)) {
        return $user;
    }

    return null;
}

function tm_can_access_turni() {
    return tm_current_doctor() !== null || tm_can_manage_scheduling();
}

function tm_coordinated_aft_payload() {
    $aft_id = tm_coordinator_aft_id();
    if (!$aft_id) return null;
    $aft = get_post($aft_id);
    return $aft ? ['id' => $aft->ID, 'name' => $aft->post_title] : null;
}

function tm_coordinated_facility_payload() {
    if (!function_exists('tm_coordinator_facility_id')) return null;
    $facility_id = tm_coordinator_facility_id();
    if (!$facility_id) return null;
    $facility = get_post($facility_id);
    return $facility ? ['id' => $facility->ID, 'name' => $facility->post_title, 'slug' => $facility->post_name] : null;
}

function tm_doctor_assigned_schedule($facility_id, $doctor_id, $active_only = true) {
    global $wpdb;
    $doctor_id = (int) $doctor_id;
    $facility_id = (int) $facility_id;
    if (!$doctor_id || !$facility_id) return null;

    $status_where = $active_only ? "AND (a.status IS NULL OR a.status = '' OR a.status NOT IN ('done', 'skipped'))" : '';
    $query = 'SELECT a.schedule_id
        FROM ' . tm_table('assignments') . ' a
        INNER JOIN ' . $wpdb->posts . " p ON p.ID = a.schedule_id AND p.post_type = 'tm_schedule' AND p.post_status IN ('publish', 'draft')
        INNER JOIN " . $wpdb->postmeta . " pm ON pm.post_id = a.schedule_id AND pm.meta_key = 'tm_facility_id'
        LEFT JOIN " . $wpdb->postmeta . " mm ON mm.post_id = a.schedule_id AND mm.meta_key = 'tm_schedule_month'
        WHERE a.doctor_id = %d
          AND CAST(pm.meta_value AS UNSIGNED) = %d
          " . $status_where . "
        ORDER BY COALESCE(NULLIF(a.turn_started_at, ''), '0000-00-00 00:00:00') DESC, mm.meta_value ASC, a.position ASC, a.schedule_id ASC";

    $schedule_ids = array_map('intval', $wpdb->get_col($wpdb->prepare($query, $doctor_id, $facility_id)));
    foreach ($schedule_ids as $schedule_id) {
        if ($active_only && get_post_meta($schedule_id, 'tm_schedule_closed', true)) continue;
        if ($active_only && function_exists('tm_schedule_participants_confirmed') && !tm_schedule_participants_confirmed($schedule_id)) continue;
        $schedule = get_post($schedule_id);
        if ($schedule) return $schedule;
    }
    return null;
}

function tm_doctor_current_schedule($facility_id, $doctor_id = 0) {
    if (!$doctor_id) {
        $doctor = tm_current_doctor();
        $doctor_id = $doctor ? (int) $doctor->ID : 0;
    }

    if ($doctor_id) {
        $assigned = tm_doctor_assigned_schedule($facility_id, $doctor_id, true);
        if ($assigned) return $assigned;
    }

    $default = (int) get_post_meta($facility_id, 'tm_default_schedule', true);
    if ($default) {
        $ds = get_post($default);
        if ($ds && $ds->post_type === 'tm_schedule' && in_array($ds->post_status, ['publish', 'draft'], true)) {
            return $ds;
        }
    }
    $schedules = get_posts([
        'post_type'   => 'tm_schedule',
        'numberposts' => -1,
        'post_status' => ['publish', 'draft'],
        'meta_key'    => 'tm_facility_id',
        'meta_value'  => (int) $facility_id,
    ]);
    usort($schedules, function ($a, $b) {
        return strcmp((string) get_post_meta($b->ID, 'tm_schedule_month', true), (string) get_post_meta($a->ID, 'tm_schedule_month', true));
    });
    foreach ($schedules as $schedule) {
        $week = function_exists('tm_board_calendar') ? tm_board_calendar($schedule->ID) : null;
        if (is_array($week) && (!empty($week['defaults']) || !empty($week['overrides']))) {
            return $schedule;
        }
    }
    return $schedules ? $schedules[0] : null;
}

function tm_doctor_is_invited($schedule_id, $doctor_id) {
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d AND doctor_id = %d',
        $schedule_id, $doctor_id
    ));
}

function tm_facility_app_payload($facility_id) {
    $facility_id = (int) $facility_id;
    $facility = $facility_id ? get_post($facility_id) : null;
    if (!$facility || $facility->post_type !== 'tm_facility') return null;
    if (!tm_facility_in_turnazione($facility_id) || !tm_facility_has_aft($facility_id)) return null;

    $aft_id = (int) get_post_meta($facility_id, 'tm_aft_id', true);
    $aft = $aft_id ? get_post($aft_id) : null;

    return [
        'id'       => $facility_id,
        'name'     => $facility->post_title,
        'slug'     => $facility->post_name,
        'aft_id'   => $aft_id,
        'aft_name' => $aft ? $aft->post_title : '',
    ];
}

function tm_app_facilities_for_select() {
    $ids = get_posts([
        'post_type'   => 'tm_facility',
        'numberposts' => -1,
        'post_status' => 'publish',
        'orderby'     => 'title',
        'order'       => 'ASC',
        'fields'      => 'ids',
    ]);

    $facilities = [];
    foreach ($ids as $id) {
        $facility = tm_facility_app_payload($id);
        if ($facility) $facilities[] = $facility;
    }
    return $facilities;
}

function tm_preferred_facility_payload($user_id) {
    $facility_id = (int) get_user_meta((int) $user_id, 'tm_preferred_facility', true);
    return $facility_id ? tm_facility_app_payload($facility_id) : null;
}

function tm_doctor_payload($user) {
    $qualification = get_user_meta($user->ID, 'tm_qualification', true);
    $aft_id = function_exists('tm_doctor_home_aft_id') ? tm_doctor_home_aft_id($user->ID) : (int) get_user_meta($user->ID, 'tm_home_aft', true);
    $aft = $aft_id ? get_post($aft_id) : null;
    $confirmed_at = (string) get_user_meta($user->ID, 'tm_profile_confirmed_at', true);

    return [
        'id'            => $user->ID,
        'first_name'    => $user->first_name,
        'last_name'     => $user->last_name,
        'gender'        => get_user_meta($user->ID, 'tm_gender', true),
        'qualification' => $qualification,
        'is_ca'         => strpos((string) $qualification, 'ca_') === 0,
        'is_mandatory'  => tm_doctor_is_mandatory($user->ID),
        'is_admin'      => current_user_can('edit_users'),
        'can_manage'    => tm_can_manage_scheduling(),
        'coordinated_aft' => tm_coordinated_aft_payload(),
        'coordinated_facility' => tm_coordinated_facility_payload(),
        'is_doctor'     => true,
        'patients'      => (int) get_user_meta($user->ID, 'tm_patients', true),
        'role_start'    => get_user_meta($user->ID, 'tm_role_start', true),
        'weekly_hours'  => tm_doctor_weekly_hours($user->ID),
        'aft'           => $aft ? [
            'id'   => $aft->ID,
            'name' => $aft->post_title,
            'logo' => get_the_post_thumbnail_url($aft->ID, 'medium') ?: '',
        ] : null,
        'profile_confirmed' => $confirmed_at !== '' && tm_doctor_professional_data_complete($user->ID),
        'profile_confirmed_at' => $confirmed_at,
        'require_profile_confirmation' => tm_require_profile_confirmation(),
        'preferred_facility' => tm_preferred_facility_payload($user->ID),
        'hours_bank'    => function_exists('tm_doctor_hours_bank') ? tm_doctor_hours_bank($user->ID) : null,
        'next_shift'    => function_exists('tm_doctor_next_shift') ? tm_doctor_next_shift($user->ID) : null,
    ];
}

function tm_doctor_turnazione($user) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT a.schedule_id, a.position, a.status, a.turn_started_at, CAST(pm.meta_value AS UNSIGNED) AS facility_id
         FROM ' . tm_table('assignments') . ' a
         INNER JOIN ' . $wpdb->posts . " p ON p.ID = a.schedule_id AND p.post_type = 'tm_schedule' AND p.post_status IN ('publish', 'draft')
         INNER JOIN " . $wpdb->postmeta . " pm ON pm.post_id = a.schedule_id AND pm.meta_key = 'tm_facility_id'
         LEFT JOIN " . $wpdb->postmeta . " mm ON mm.post_id = a.schedule_id AND mm.meta_key = 'tm_schedule_month'
         WHERE a.doctor_id = %d
         ORDER BY COALESCE(NULLIF(a.turn_started_at, ''), '0000-00-00 00:00:00') DESC, mm.meta_value ASC, a.position ASC, a.schedule_id ASC",
        $user->ID
    ));
    if (!$rows) {
        return ['status' => 'nessuna'];
    }

    $months = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    $waiting_payload = null;
    $has_completed = false;
    foreach ($rows as $row) {
        if (in_array($row->status, ['done', 'skipped'], true)) {
            $has_completed = true;
            continue;
        }
        if (get_post_meta($row->schedule_id, 'tm_schedule_closed', true)) continue;
        if (function_exists('tm_schedule_participants_confirmed') && !tm_schedule_participants_confirmed($row->schedule_id)) continue;
        $blocking = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . tm_table('assignments') . " WHERE schedule_id = %d AND position < %d AND (status IS NULL OR status = '' OR status NOT IN ('done', 'skipped'))",
            $row->schedule_id, $row->position
        ));
        $facility = get_post((int) $row->facility_id);
        $month = tm_schedule_month($row->schedule_id);
        $status = $blocking > 0 ? 'in_attesa' : 'tocca_a_te';
        $deadline = '';
        if ($status === 'tocca_a_te' && $row->turn_started_at && function_exists('tm_queue_expiry_rules')) {
            $rules = tm_queue_expiry_rules();
            if ($rules['enabled']) {
                $deadline = tm_format_local_datetime(tm_add_local_days($row->turn_started_at, $rules['days']), 'd/m/Y H:i');
            }
        }
        $payload = [
            'status'        => $status,
            'schedule_id'   => (int) $row->schedule_id,
            'facility_id'   => (int) $row->facility_id,
            'facility_name' => $facility ? $facility->post_title : '',
            'facility_slug' => $facility ? $facility->post_name : '',
            'month_label'   => trim(($months[$month['month']] ?? '') . ' ' . $month['year']),
            'deadline'      => $deadline,
        ];
        if ($status === 'tocca_a_te') return $payload;
        if (!$waiting_payload) $waiting_payload = $payload;
    }
    if ($waiting_payload) return $waiting_payload;
    return ['status' => $has_completed ? 'completo' : 'nessuna'];
}

function tm_manager_payload() {
    $u = wp_get_current_user();
    $coordinated = tm_coordinated_aft_payload();
    $facility = tm_coordinated_facility_payload();
    return [
        'id'            => (int) $u->ID,
        'first_name'    => $u->first_name ?: $u->display_name,
        'last_name'     => $u->last_name,
        'qualification' => '',
        'is_ca'         => false,
        'is_mandatory'  => false,
        'is_admin'      => current_user_can('edit_users'),
        'can_manage'    => true,
        'coordinated_aft' => $coordinated,
        'coordinated_facility' => $facility,
        'is_doctor'     => false,
        'patients'      => 0,
        'aft'           => $coordinated ? $coordinated + ['logo' => get_the_post_thumbnail_url($coordinated['id'], 'medium') ?: ''] : null,
        'profile_confirmed' => true,
        'profile_confirmed_at' => '',
        'preferred_facility' => tm_preferred_facility_payload($u->ID),
        'hours_bank'    => null,
        'next_shift'    => null,
        'turnazione'    => ['status' => 'nessuna'],
    ];
}

function tm_rest_me() {
    $doctor = tm_current_doctor();
    if (!$doctor) {
        if (tm_can_manage_scheduling()) {
            return rest_ensure_response(tm_manager_payload());
        }
        return new WP_Error('tm_unauthorized', 'Accesso riservato ai medici.', ['status' => 401]);
    }
    $payload = tm_doctor_payload($doctor);
    $payload['turnazione'] = tm_doctor_turnazione($doctor);
    return rest_ensure_response($payload);
}

function tm_rest_my_structures() {
    $doctor = tm_current_doctor();
    $is_admin = current_user_can('edit_users');
    $coordinated_aft = tm_coordinator_aft_id();
    $coordinated_facility = function_exists('tm_coordinator_facility_id') ? tm_coordinator_facility_id() : 0;
    if (!$doctor && !$is_admin && !$coordinated_aft) {
        return new WP_Error('tm_unauthorized', 'Accesso riservato.', ['status' => 401]);
    }
    global $wpdb;
    $doctor_id = $doctor ? (int) $doctor->ID : 0;
    if ($doctor_id && !$is_admin && !tm_can_manage_scheduling() && !tm_doctor_is_visible_in_frontend($doctor_id)) {
        return rest_ensure_response([]);
    }
    $all_facility_ids = function () {
        return array_map('intval', get_posts([
            'post_type'   => 'tm_facility',
            'numberposts' => -1,
            'post_status' => 'publish',
            'fields'      => 'ids',
        ]));
    };

    if ($is_admin) {
        $all_ids = $all_facility_ids();
    } elseif (tm_can_manage_scheduling()) {
        $all_ids = function_exists('tm_coordinator_facility_ids') ? tm_coordinator_facility_ids() : [];
    } else {
        $facility_ids = $coordinated_facility ? [$coordinated_facility] : ($coordinated_aft ? tm_aft_facility_ids($coordinated_aft) : []);

        if ($doctor_id) {
            if (tm_doctor_is_ca_temporaneo($doctor_id) && !$coordinated_facility) {
                $facility_ids = array_merge($facility_ids, $all_facility_ids());
            } else {
                $aft_id = function_exists('tm_doctor_home_aft_id') ? tm_doctor_home_aft_id($doctor_id) : (int) get_user_meta($doctor_id, 'tm_home_aft', true);
                if (!$coordinated_facility && $aft_id && $aft_id !== $coordinated_aft) {
                    $facility_ids = array_merge($facility_ids, tm_aft_facility_ids($aft_id));
                }
            }

            $invited_facility_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                'SELECT DISTINCT s.facility_id FROM (
                    SELECT a.schedule_id, CAST(pm.meta_value AS UNSIGNED) AS facility_id
                    FROM ' . tm_table('assignments') . ' a
                    INNER JOIN ' . $wpdb->posts . ' p ON p.ID = a.schedule_id AND p.post_type = "tm_schedule" AND p.post_status IN ("publish", "draft")
                    INNER JOIN ' . $wpdb->postmeta . ' pm ON pm.post_id = a.schedule_id AND pm.meta_key = "tm_facility_id"
                    WHERE a.doctor_id = %d
                ) s',
                $doctor_id
            )));
            $facility_ids = array_merge($facility_ids, $invited_facility_ids);
        }

        $all_ids = array_values(array_unique($facility_ids));
    }
    $all_ids = array_values(array_filter($all_ids, fn($id) => tm_facility_in_turnazione($id) && tm_facility_has_aft($id)));

    $months = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    $structures = [];
    foreach ($all_ids as $facility_id) {
        $facility = get_post($facility_id);
        if (!$facility || $facility->post_type !== 'tm_facility') continue;

        $schedule = tm_doctor_current_schedule($facility_id, $doctor_id);
        $invited = $schedule ? tm_doctor_is_invited($schedule->ID, $doctor_id) : false;

        $aft_id = (int) get_post_meta($facility_id, 'tm_aft_id', true);
        $aft = $aft_id ? get_post($aft_id) : null;
        if (!$aft) continue;
        $pin = get_post_meta($facility_id, 'tm_facility_pin', true);

        $turn = 'consultazione';
        $month_label = '';
        if ($schedule) {
            $month = tm_schedule_month($schedule->ID);
            $month_label = trim(($months[$month['month']] ?? '') . ' ' . $month['year']);
            if ($invited && function_exists('tm_schedule_participants_confirmed') && tm_schedule_participants_confirmed($schedule->ID)) {
                $assignment = $wpdb->get_row($wpdb->prepare('SELECT position, status FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d AND doctor_id = %d', $schedule->ID, $doctor_id));
                if ($assignment && in_array((string) $assignment->status, ['done', 'skipped'], true)) {
                    $turn = 'completo';
                } elseif ($assignment) {
                    $blocking = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . tm_table('assignments') . " WHERE schedule_id = %d AND position < %d AND (status IS NULL OR status = '' OR status NOT IN ('done', 'skipped'))", $schedule->ID, $assignment->position));
                    $turn = $blocking > 0 ? 'in_attesa' : 'tocca_a_te';
                }
            }
        }

        $structures[] = [
            'id'          => $facility_id,
            'name'        => $facility->post_title,
            'slug'        => $facility->post_name,
            'schedule_id' => $schedule ? (int) $schedule->ID : 0,
            'aft_name'    => $aft ? $aft->post_title : '',
            'address'     => is_array($pin) ? ($pin['formatted_address'] ?? '') : '',
            'invited'     => $invited,
            'turn'        => $turn,
            'month_label' => $month_label,
            'vetrina_url' => function_exists('tm_vetrina_url') ? tm_vetrina_url($facility->post_name) : '',
        ];
    }

    usort($structures, fn($a, $b) => ($b['invited'] <=> $a['invited']) ?: strcmp($a['name'], $b['name']));

    return rest_ensure_response($structures);
}

add_action('rest_api_init', function () {
    $auth = fn() => tm_can_access_turni();

    register_rest_route('turnimedici/v1', '/me', [
        'methods'             => 'GET',
        'callback'            => 'tm_rest_me',
        'permission_callback' => $auth,
    ]);
    register_rest_route('turnimedici/v1', '/me/structures', [
        'methods'             => 'GET',
        'callback'            => 'tm_rest_my_structures',
        'permission_callback' => $auth,
    ]);
});
