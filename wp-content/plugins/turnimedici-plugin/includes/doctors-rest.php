<?php

if (!defined('ABSPATH')) exit;

const TM_DOCTOR_META = [
    'qualification' => ['tm_qualification', 'sanitize_key'],
    'home_aft'      => ['tm_home_aft', 'absint'],
    'patients'      => ['tm_patients', 'absint'],
    'role_start'    => ['tm_role_start', 'sanitize_text_field'],
    'phone'         => ['tm_phone', 'sanitize_text_field'],
    'gender'        => ['tm_gender', 'tm_sanitize_gender'],
    'weekly_hours'  => ['tm_weekly_hours', 'tm_sanitize_weekly_hours'],
    'ca_zone'       => ['tm_ca_zone', 'sanitize_text_field'],
    'preferred_facility' => ['tm_preferred_facility', 'absint'],
];

function tm_sanitize_gender($value) {
    $value = sanitize_key((string) $value);
    return in_array($value, ['male', 'female', 'other'], true) ? $value : '';
}

function tm_gender_label($value) {
    return [
        'male'   => 'Maschio',
        'female' => 'Femmina',
        'other'  => 'Altro',
    ][$value] ?? '';
}

function tm_welcome_word($gender) {
    if ($gender === 'female') return 'Benvenuta';
    if ($gender === 'other') return 'Benvenutə';
    return 'Benvenuto';
}

function tm_current_user_can_manage_doctors() {
    return current_user_can('edit_users') || (function_exists('tm_coordinator_aft_id') && tm_coordinator_aft_id() > 0);
}

function tm_current_user_can_manage_doctor($user_id) {
    if (current_user_can('edit_users')) return true;
    $aft_id = function_exists('tm_coordinator_aft_id') ? tm_coordinator_aft_id() : 0;
    if (!$aft_id) return false;
    $qualification = get_user_meta($user_id, 'tm_qualification', true);
    if ($qualification === 'ca_temporaneo') return true;
    return (function_exists('tm_doctor_home_aft_id') ? tm_doctor_home_aft_id($user_id) : (int) get_user_meta($user_id, 'tm_home_aft', true)) === $aft_id;
}

function tm_prepare_doctor_payload_for_current_user($payload, $user_id = 0) {
    if (current_user_can('edit_users')) return $payload;
    $aft_id = function_exists('tm_coordinator_aft_id') ? tm_coordinator_aft_id() : 0;
    if (!$aft_id) return $payload;
    $qualification = sanitize_key($payload['qualification'] ?? ($user_id ? get_user_meta($user_id, 'tm_qualification', true) : ''));
    if ($qualification !== 'ca_temporaneo') {
        $payload['home_aft'] = $aft_id;
    }
    return $payload;
}

function tm_validate_doctor_payload($payload, $user_id = 0) {
    $qualification = sanitize_key($payload['qualification'] ?? ($user_id ? get_user_meta($user_id, 'tm_qualification', true) : ''));
    if (!in_array($qualification, tm_allowed_doctor_qualifications(), true)) {
        return new WP_Error('tm_qualification_required', 'Scegli una qualifica.', ['status' => 400]);
    }
    $home_aft = absint($payload['home_aft'] ?? ($user_id ? get_user_meta($user_id, 'tm_home_aft', true) : 0));
    if ($qualification !== 'ca_temporaneo' && !tm_valid_doctor_aft($home_aft)) {
        return new WP_Error('tm_home_aft_required', 'Scegli l’AFT di appartenenza.', ['status' => 400]);
    }
    $payload['qualification'] = $qualification;
    $payload['home_aft'] = $qualification === 'ca_temporaneo' ? 0 : $home_aft;
    if (array_key_exists('ca_periods', $payload)) {
        $periods = tm_validate_ca_periods($payload['ca_periods']);
        if (is_wp_error($periods)) return $periods;
        $payload['ca_periods'] = $periods;
    }
    return $payload;
}

function tm_validate_ca_periods($periods) {
    $clean = [];
    foreach ((array) $periods as $period) {
        $start = sanitize_text_field($period['start'] ?? '');
        $end = sanitize_text_field($period['end'] ?? '');
        if (!tm_valid_iso_date($start) || !tm_valid_iso_date($end) || $start > $end) {
            return new WP_Error('tm_invalid_ca_period', 'Il periodo CA contiene date non valide.', ['status' => 400]);
        }
        $clean[] = ['start' => $start, 'end' => $end];
    }
    return $clean;
}

function tm_get_ca_periods($user_id) {
    $raw = get_user_meta($user_id, 'tm_ca_periods', true);
    $periods = $raw ? json_decode($raw, true) : [];
    return is_array($periods) ? array_values($periods) : [];
}

function tm_set_ca_periods($user_id, $periods) {
    $clean = tm_validate_ca_periods($periods);
    if (is_wp_error($clean)) return false;
    update_user_meta($user_id, 'tm_ca_periods', wp_json_encode($clean));
    return true;
}

function tm_add_ca_period($user_id, $start, $end) {
    if (!tm_valid_iso_date($start) || !tm_valid_iso_date($end) || $start > $end) return false;
    $periods = tm_get_ca_periods($user_id);
    foreach ($periods as $period) {
        if ($period['start'] === $start && $period['end'] === $end) return true;
    }
    $periods[] = ['start' => sanitize_text_field($start), 'end' => sanitize_text_field($end)];
    update_user_meta($user_id, 'tm_ca_periods', wp_json_encode($periods));
    return true;
}

function tm_doctor_to_array($user) {
    $doctor = [
        'id'         => $user->ID,
        'first_name' => $user->first_name,
        'last_name'  => $user->last_name,
        'email'      => $user->user_email,
    ];
    foreach (TM_DOCTOR_META as $field => [$meta_key, $sanitize]) {
        $value = get_user_meta($user->ID, $meta_key, true);
        if (in_array($meta_key, ['tm_home_aft', 'tm_patients', 'tm_preferred_facility'], true)) {
            $doctor[$field] = (int) $value;
        } elseif ($meta_key === 'tm_weekly_hours') {
            $doctor[$field] = (float) $value;
        } else {
            $doctor[$field] = $value;
        }
    }
    if (function_exists('tm_doctor_home_aft_id')) {
        $doctor['home_aft'] = tm_doctor_home_aft_id($user->ID);
    }
    $doctor['profile_confirmed'] = (string) get_user_meta($user->ID, 'tm_profile_confirmed_at', true) !== '';
    $doctor['ca_periods'] = tm_get_ca_periods($user->ID);
    $doctor['ca_rank']    = function_exists('tm_ca_rank_value') ? tm_ca_rank_value($user->ID) : null;
    $doctor['ca_cot']     = (bool) get_user_meta($user->ID, 'tm_ca_cot', true);
    $doctor['has_magic_link'] = get_user_meta($user->ID, 'tm_magic_token', true) !== '';
    $invite_sent = (int) get_user_meta($user->ID, 'tm_invite_sent', true);
    $doctor['invite_sent_at']    = $invite_sent;
    $doctor['invite_sent_label'] = $invite_sent ? wp_date('d/m/Y H:i', $invite_sent) : '';
    return $doctor;
}

function tm_doctor_magic_url($user_id) {
    $token = get_user_meta($user_id, 'tm_magic_token', true);
    if (!$token) return '';
    $base = function_exists('tm_turni_url') ? tm_turni_url('') : home_url('/');
    return add_query_arg('tm_login', $token, $base);
}

function tm_doctor_profile_url($user_id) {
    $url = tm_doctor_magic_url($user_id);
    return $url ? add_query_arg('tm_profile', '1', $url) : '';
}

function tm_save_doctor_meta($user_id, $payload) {
    foreach (TM_DOCTOR_META as $field => [$meta_key, $sanitize]) {
        if (array_key_exists($field, $payload)) {
            $value = call_user_func($sanitize, $payload[$field]);
            if ($field === 'home_aft' && !$value) delete_user_meta($user_id, $meta_key);
            else update_user_meta($user_id, $meta_key, $value);
        }
    }
    if (array_key_exists('profile_confirmed', $payload)) {
        $already = (string) get_user_meta($user_id, 'tm_profile_confirmed_at', true) !== '';
        if (!empty($payload['profile_confirmed']) && !$already) {
            update_user_meta($user_id, 'tm_profile_confirmed_at', current_time('mysql'));
        } elseif (empty($payload['profile_confirmed']) && $already) {
            delete_user_meta($user_id, 'tm_profile_confirmed_at');
        }
    }
    if (array_key_exists('ca_periods', $payload)) {
        tm_set_ca_periods($user_id, (array) $payload['ca_periods']);
    }
    if (array_key_exists('ca_rank', $payload)) {
        $rank = $payload['ca_rank'];
        if ($rank === '' || $rank === null) delete_user_meta($user_id, 'tm_ca_rank');
        else update_user_meta($user_id, 'tm_ca_rank', absint($rank));
    }
    if (array_key_exists('ca_cot', $payload)) {
        if (!empty($payload['ca_cot'])) update_user_meta($user_id, 'tm_ca_cot', 1);
        else delete_user_meta($user_id, 'tm_ca_cot');
    }
}

function tm_unique_username_from_email($email) {
    $base = sanitize_user(current(explode('@', $email)), true) ?: 'medico';
    $username = $base;
    $suffix = 1;
    while (username_exists($username)) {
        $username = $base . $suffix++;
    }
    return $username;
}

function tm_create_doctor_user($email, $first, $last) {
    return wp_insert_user([
        'user_login'   => tm_unique_username_from_email($email),
        'user_email'   => $email,
        'first_name'   => $first,
        'last_name'    => $last,
        'display_name' => trim("$first $last") ?: $email,
        'user_pass'    => wp_generate_password(24),
        'role'         => 'tm_doctor',
    ]);
}

function tm_get_doctor_or_error($id) {
    $user = get_user_by('id', (int) $id);
    if (!$user || !tm_user_is_turnazione_doctor($user->ID)) {
        return new WP_Error('tm_not_found', 'Medico non trovato', ['status' => 404]);
    }
    return $user;
}

function tm_rest_list_doctors() {
    $user_ids = function_exists('tm_turnazione_doctor_ids') ? tm_turnazione_doctor_ids() : get_users(['role' => 'tm_doctor', 'fields' => 'ID']);
    $users = array_values(array_filter(array_map('get_userdata', $user_ids)));
    usort($users, fn($a, $b) => strcasecmp($a->display_name, $b->display_name));
    if (!current_user_can('edit_users')) {
        $users = array_values(array_filter($users, fn($user) => tm_current_user_can_manage_doctor($user->ID)));
    }
    $doctors = array_map('tm_doctor_to_array', $users);
    $ranking_rows = [];
    foreach (tm_global_ranking_list() as $row) $ranking_rows[$row['id']] = $row;
    foreach ($doctors as &$doctor) {
        $doctor['ranking_position'] = $ranking_rows[$doctor['id']]['position'] ?? null;
    }
    return rest_ensure_response($doctors);
}

function tm_rest_create_doctor($request) {
    $payload = tm_prepare_doctor_payload_for_current_user($request->get_json_params());
    $payload = tm_validate_doctor_payload($payload);
    if (is_wp_error($payload)) return $payload;
    $email = sanitize_email($payload['email'] ?? '');
    $first = sanitize_text_field($payload['first_name'] ?? '');
    $last  = sanitize_text_field($payload['last_name'] ?? '');

    if (!$email || !is_email($email)) {
        return new WP_Error('tm_invalid_email', 'Email non valida', ['status' => 400]);
    }
    if (email_exists($email)) {
        return new WP_Error('tm_email_exists', 'Email già in uso', ['status' => 400]);
    }

    $user_id = tm_create_doctor_user($email, $first, $last);
    if (is_wp_error($user_id)) {
        return $user_id;
    }

    tm_save_doctor_meta($user_id, $payload);
    return rest_ensure_response(tm_doctor_to_array(get_user_by('id', $user_id)));
}

function tm_rest_update_doctor($request) {
    $user = tm_get_doctor_or_error($request['id']);
    if (is_wp_error($user)) {
        return $user;
    }
    if (!tm_current_user_can_manage_doctor($user->ID)) {
        return new WP_Error('tm_forbidden', 'Medico fuori dalla tua AFT.', ['status' => 403]);
    }
    $requested_qualification = sanitize_key((string) ($request->get_json_params()['qualification'] ?? ''));
    if (
        !current_user_can('edit_users')
        && $requested_qualification
        && $requested_qualification !== 'ca_temporaneo'
        && get_user_meta($user->ID, 'tm_qualification', true) === 'ca_temporaneo'
    ) {
        return new WP_Error('tm_forbidden_qualification', 'Solo un amministratore può cambiare la qualifica di un medico CA temporaneo.', ['status' => 403]);
    }

    $payload = tm_prepare_doctor_payload_for_current_user($request->get_json_params(), $user->ID);
    $payload = tm_validate_doctor_payload($payload, $user->ID);
    if (is_wp_error($payload)) return $payload;
    $update = ['ID' => $user->ID];
    if (isset($payload['first_name']) || isset($payload['last_name'])) {
        $first = sanitize_text_field($payload['first_name'] ?? $user->first_name);
        $last  = sanitize_text_field($payload['last_name'] ?? $user->last_name);
        $update['first_name']   = $first;
        $update['last_name']    = $last;
        $update['display_name'] = trim("$first $last") ?: $user->user_email;
    }
    if (isset($payload['email']) && is_email($payload['email'])) {
        $update['user_email'] = sanitize_email($payload['email']);
    }
    $updated = wp_update_user($update);
    if (is_wp_error($updated)) {
        return new WP_Error('tm_doctor_update_failed', $updated->get_error_message(), ['status' => 400]);
    }

    tm_save_doctor_meta($user->ID, $payload);
    return rest_ensure_response(tm_doctor_to_array(get_user_by('id', $user->ID)));
}

function tm_reset_ca_ranking_meta() {
    global $wpdb;
    foreach (['tm_ca_rank', 'tm_ca_zone', 'tm_ca_cot'] as $meta_key) {
        $wpdb->delete($wpdb->usermeta, ['meta_key' => $meta_key]);
    }
}

function tm_rest_import_doctors($request) {
    if (!current_user_can('edit_users')) {
        return new WP_Error('tm_forbidden', 'Import riservato agli amministratori.', ['status' => 403]);
    }
    $payload = $request->get_json_params();
    if (!is_array($payload)) {
        return new WP_Error('tm_invalid_data', 'Dati non validi', ['status' => 400]);
    }
    $rows = (isset($payload['rows']) && is_array($payload['rows'])) ? $payload['rows'] : $payload;
    if (!empty($payload['reset_ca'])) {
        tm_reset_ca_ranking_meta();
    }
    return rest_ensure_response(tm_import_doctor_rows($rows));
}

function tm_import_doctor_rows($rows) {
    $created = 0;
    $updated = 0;
    $errors = [];

    foreach ($rows as $row) {
        $email = sanitize_email($row['email'] ?? '');
        $label = $row['last_name'] ?? ($email ?: 'riga');
        if (!$email || !is_email($email)) {
            $errors[] = "$label: email mancante o non valida";
            continue;
        }

        $start = sanitize_text_field($row['ca_start'] ?? '');
        $end   = sanitize_text_field($row['ca_end'] ?? '');
        $user  = get_user_by('email', $email);
        $row = tm_validate_doctor_payload($row, $user ? $user->ID : 0);
        if (is_wp_error($row)) {
            $errors[] = "$email: " . $row->get_error_message();
            continue;
        }

        if ($user) {
            if (!tm_user_is_turnazione_doctor($user->ID)) {
                $errors[] = "$email: l'utente esiste ma non è un medico";
                continue;
            }
            $first = sanitize_text_field($row['first_name'] ?? '');
            $last  = sanitize_text_field($row['last_name'] ?? '');
            $updated_user = wp_update_user([
                'ID'           => $user->ID,
                'first_name'   => $first,
                'last_name'    => $last,
                'display_name' => trim("$first $last") ?: $user->user_email,
            ]);
            if (is_wp_error($updated_user)) {
                $errors[] = "$email: " . $updated_user->get_error_message();
                continue;
            }
            tm_save_doctor_meta($user->ID, $row);
            tm_add_ca_period($user->ID, $start, $end);
            $updated++;
            continue;
        }

        $user_id = tm_create_doctor_user($email, sanitize_text_field($row['first_name'] ?? ''), sanitize_text_field($row['last_name'] ?? ''));
        if (is_wp_error($user_id)) {
            $errors[] = "$email: " . $user_id->get_error_message();
            continue;
        }
        tm_save_doctor_meta($user_id, $row);
        if ($start && $end) {
            tm_set_ca_periods($user_id, [['start' => $start, 'end' => $end]]);
        }
        $created++;
    }

    return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
}

function tm_rest_magic_link($request) {
    $user = tm_get_doctor_or_error($request['id']);
    if (is_wp_error($user)) {
        return $user;
    }
    if (!tm_current_user_can_manage_doctor($user->ID)) {
        return new WP_Error('tm_forbidden', 'Medico fuori dalla tua AFT.', ['status' => 403]);
    }
    update_user_meta($user->ID, 'tm_magic_token', bin2hex(random_bytes(20)));
    update_user_meta($user->ID, 'tm_magic_token_created', time());
    return rest_ensure_response(['magic_url' => tm_doctor_magic_url($user->ID)]);
}

function tm_rest_ensure_magic_link($request) {
    $user = tm_get_doctor_or_error($request['id']);
    if (is_wp_error($user)) {
        return $user;
    }
    if (!tm_current_user_can_manage_doctor($user->ID)) {
        return new WP_Error('tm_forbidden', 'Medico fuori dalla tua AFT.', ['status' => 403]);
    }
    tm_ensure_valid_magic_token($user->ID);
    return rest_ensure_response(['magic_url' => tm_doctor_magic_url($user->ID)]);
}

function tm_ensure_valid_magic_token($user_id) {
    $token = get_user_meta($user_id, 'tm_magic_token', true);
    if (!$token) {
        update_user_meta($user_id, 'tm_magic_token', bin2hex(random_bytes(20)));
        update_user_meta($user_id, 'tm_magic_token_created', time());
    }
}

function tm_rest_send_invite($request) {
    if (tm_demo_mode()) {
        return new WP_Error('tm_demo_mail_disabled', 'La demo non invia email: copia il link di accesso del medico per provarlo localmente.', ['status' => 403]);
    }
    $user = tm_get_doctor_or_error($request['id']);
    if (is_wp_error($user)) {
        return $user;
    }
    if (!tm_current_user_can_manage_doctor($user->ID)) {
        return new WP_Error('tm_forbidden', 'Medico fuori dalla tua AFT.', ['status' => 403]);
    }

    tm_ensure_valid_magic_token($user->ID);
    $link = tm_doctor_magic_url($user->ID);
    $profile_link = tm_doctor_profile_url($user->ID);
    $sent = wp_mail(
        $user->user_email,
        'Benvenuto su ' . get_bloginfo('name'),
        "Ciao " . $user->display_name . ",\n\nsei stato invitato su " . get_bloginfo('name') . ", la piattaforma per la gestione dei turni.\n\nQuesto è il tuo link personale di accesso:\n" . $profile_link . "\n\nIl link è personale: non condividerlo.\n"
    );

    if (!$sent) {
        return new WP_Error('tm_send_failed', 'Invio non riuscito: controlla la configurazione email.', ['status' => 500]);
    }

    $now = time();
    update_user_meta($user->ID, 'tm_invite_sent', $now);

    return rest_ensure_response([
        'sent'              => true,
        'invite_sent_at'    => $now,
        'invite_sent_label' => wp_date('d/m/Y H:i', $now),
        'magic_url'         => $link,
    ]);
}

function tm_doctor_activity_summary($user_id) {
    global $wpdb;
    $user_id = (int) $user_id;
    $assignments = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . tm_table('assignments') . ' WHERE doctor_id = %d',
        $user_id
    ));
    $shifts = $wpdb->get_row($wpdb->prepare(
        'SELECT COUNT(*) AS total, MIN(shift_date) AS first_date, MAX(shift_date) AS last_date FROM ' . tm_table('shifts') . ' WHERE doctor_id = %d',
        $user_id
    ));
    $queue_events = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . tm_table('queue_log') . ' WHERE doctor_id = %d',
        $user_id
    ));
    $schedule_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
        'SELECT DISTINCT schedule_id FROM ' . tm_table('shifts') . ' WHERE doctor_id = %d
         UNION SELECT DISTINCT schedule_id FROM ' . tm_table('assignments') . ' WHERE doctor_id = %d',
        $user_id,
        $user_id
    )));
    $facilities = [];
    foreach ($schedule_ids as $schedule_id) {
        $facility_id = (int) get_post_meta($schedule_id, 'tm_facility_id', true);
        if (!$facility_id || isset($facilities[$facility_id])) continue;
        $facility = get_post($facility_id);
        if ($facility) $facilities[$facility_id] = $facility->post_title;
    }
    $user = get_userdata($user_id);
    return [
        'doctor_id'    => $user_id,
        'assignments'  => $assignments,
        'shifts'       => (int) ($shifts->total ?? 0),
        'first_shift'  => (string) ($shifts->first_date ?? ''),
        'last_shift'   => (string) ($shifts->last_date ?? ''),
        'queue_events' => $queue_events,
        'facilities'   => array_values($facilities),
        'registered'   => $user ? substr($user->user_registered, 0, 10) : '',
        'has_activity' => $assignments > 0 || (int) ($shifts->total ?? 0) > 0 || $queue_events > 0,
    ];
}

function tm_rest_doctor_activity($request) {
    $user = tm_get_doctor_or_error($request['id']);
    if (is_wp_error($user)) {
        return $user;
    }
    if (!tm_current_user_can_manage_doctor($user->ID)) {
        return new WP_Error('tm_forbidden', 'Medico fuori dalla tua AFT.', ['status' => 403]);
    }
    return rest_ensure_response(tm_doctor_activity_summary($user->ID));
}

function tm_rest_resolve_duplicate_doctors($request) {
    if (!current_user_can('edit_users') || !current_user_can('delete_users')) {
        return new WP_Error('tm_forbidden', 'Operazione riservata agli amministratori.', ['status' => 403]);
    }
    $payload = $request->get_json_params();
    $keep_id = absint($payload['keep_id'] ?? 0);
    $remove_ids = array_values(array_unique(array_filter(array_map('absint', (array) ($payload['remove_ids'] ?? [])))));

    $keep = tm_get_doctor_or_error($keep_id);
    if (is_wp_error($keep)) {
        return $keep;
    }
    if (!$remove_ids) {
        return new WP_Error('tm_bad_request', 'Nessun medico da rimuovere.', ['status' => 400]);
    }
    foreach ($remove_ids as $remove_id) {
        if ($remove_id === $keep_id) {
            return new WP_Error('tm_bad_request', 'Il medico da tenere è anche tra quelli da rimuovere.', ['status' => 400]);
        }
        if ($remove_id === get_current_user_id()) {
            return new WP_Error('tm_bad_request', 'Non puoi eliminare il tuo stesso utente.', ['status' => 400]);
        }
        $user = tm_get_doctor_or_error($remove_id);
        if (is_wp_error($user)) {
            return $user;
        }
        if (user_can($remove_id, 'edit_users') || user_can($remove_id, 'tm_coordinate_hours')) {
            return new WP_Error('tm_bad_request', 'Uno dei profili da eliminare è un amministratore o un coordinatore: va gestito dal profilo utente, non da qui.', ['status' => 400]);
        }
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';
    global $wpdb;
    $removed = [];
    foreach ($remove_ids as $remove_id) {
        $wpdb->delete(tm_table('assignments'), ['doctor_id' => $remove_id]);
        $wpdb->delete(tm_table('shifts'), ['doctor_id' => $remove_id]);
        if (function_exists('tm_remove_doctor_invite_requests')) {
            tm_remove_doctor_invite_requests($remove_id);
        }
        if (wp_delete_user($remove_id, get_current_user_id())) {
            $removed[] = $remove_id;
        }
    }
    if ($removed && function_exists('tm_remove_doctors_from_facility_rankings')) {
        tm_remove_doctors_from_facility_rankings($removed);
    }
    return rest_ensure_response(['kept' => $keep_id, 'removed' => $removed]);
}

function tm_rest_list_afts() {
    $args = [
        'post_type'   => 'tm_aft',
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => ['publish', 'draft'],
    ];
    if (!current_user_can('edit_users')) {
        $aft_id = function_exists('tm_coordinator_aft_id') ? tm_coordinator_aft_id() : 0;
        $args['post__in'] = $aft_id ? [$aft_id] : [0];
    }
    $afts = get_posts($args);
    return rest_ensure_response(array_map(fn($post) => ['id' => $post->ID, 'name' => $post->post_title], $afts));
}

function tm_rest_list_facilities() {
    return rest_ensure_response(function_exists('tm_app_facilities_for_select') ? tm_app_facilities_for_select() : []);
}

add_action('rest_api_init', 'tm_register_doctor_routes');
function tm_register_doctor_routes() {
    $permission = fn() => tm_current_user_can_manage_doctors();

    register_rest_route('turnimedici/v1', '/doctors', [
        ['methods' => 'GET', 'callback' => 'tm_rest_list_doctors', 'permission_callback' => $permission],
        ['methods' => 'POST', 'callback' => 'tm_rest_create_doctor', 'permission_callback' => $permission],
    ]);
    register_rest_route('turnimedici/v1', '/doctors/import', [
        ['methods' => 'POST', 'callback' => 'tm_rest_import_doctors', 'permission_callback' => $permission],
    ]);
    register_rest_route('turnimedici/v1', '/doctors/(?P<id>\d+)', [
        ['methods' => 'PUT', 'callback' => 'tm_rest_update_doctor', 'permission_callback' => $permission],
    ]);
    register_rest_route('turnimedici/v1', '/doctors/(?P<id>\d+)/activity', [
        ['methods' => 'GET', 'callback' => 'tm_rest_doctor_activity', 'permission_callback' => $permission],
    ]);
    register_rest_route('turnimedici/v1', '/doctors/duplicates/resolve', [
        ['methods' => 'POST', 'callback' => 'tm_rest_resolve_duplicate_doctors', 'permission_callback' => fn() => current_user_can('edit_users')],
    ]);
    register_rest_route('turnimedici/v1', '/doctors/(?P<id>\d+)/magic-link', [
        ['methods' => 'GET', 'callback' => 'tm_rest_ensure_magic_link', 'permission_callback' => $permission],
        ['methods' => 'POST', 'callback' => 'tm_rest_magic_link', 'permission_callback' => $permission],
    ]);
    register_rest_route('turnimedici/v1', '/doctors/(?P<id>\d+)/invite', [
        ['methods' => 'POST', 'callback' => 'tm_rest_send_invite', 'permission_callback' => $permission],
    ]);
    register_rest_route('turnimedici/v1', '/afts', [
        ['methods' => 'GET', 'callback' => 'tm_rest_list_afts', 'permission_callback' => $permission],
    ]);
    register_rest_route('turnimedici/v1', '/facilities', [
        ['methods' => 'GET', 'callback' => 'tm_rest_list_facilities', 'permission_callback' => $permission],
    ]);
}
