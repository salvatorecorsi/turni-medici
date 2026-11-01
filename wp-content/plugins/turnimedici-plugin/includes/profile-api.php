<?php

if (!defined('ABSPATH')) exit;

const TM_PROFILE_SENSITIVE = [
    'patients'      => ['tm_patients', 'Assistiti'],
    'qualification' => ['tm_qualification', 'Qualifica'],
    'home_aft'      => ['tm_home_aft', 'AFT'],
];

function tm_qualification_label($value) {
    $map = [
        'mmg'             => 'MMG',
        'ruap_volontario' => 'RUAP volontario',
        'ruap_vincolato'  => 'RUAP vincolato',
        'ruap_corsista'   => 'Corsista RUAP',
        'ca_titolare'     => 'CA Titolare',
        'ca_temporaneo'   => 'CA Temporaneo',
    ];
    return $map[$value] ?? $value;
}

function tm_get_pending_profile($user_id) {
    $raw = get_user_meta($user_id, 'tm_pending_profile', true);
    return is_array($raw) ? $raw : [];
}

function tm_set_pending_profile($user_id, $pending) {
    if ($pending) {
        update_user_meta($user_id, 'tm_pending_profile', $pending);
    } else {
        delete_user_meta($user_id, 'tm_pending_profile');
    }
}

function tm_display_field_value($field, $value) {
    if ($field === 'home_aft') {
        $post = $value ? get_post((int) $value) : null;
        return $post ? $post->post_title : '—';
    }
    if ($field === 'qualification') {
        return $value ? tm_qualification_label($value) : '—';
    }
    return ($value === '' || $value === null) ? '—' : (string) $value;
}

function tm_format_pending_fields($user_id, $pending) {
    $fields = [];
    foreach (TM_PROFILE_SENSITIVE as $field => [$meta_key, $label]) {
        if (!isset($pending[$field])) continue;
        $current = get_user_meta($user_id, $meta_key, true);
        $requested = $pending[$field]['value'] ?? '';
        $fields[] = [
            'field'        => $field,
            'label'        => $label,
            'current'      => tm_display_field_value($field, $current),
            'requested'    => tm_display_field_value($field, $requested),
            'value'        => $requested,
            'requested_at' => $pending[$field]['requested_at'] ?? '',
        ];
    }
    return $fields;
}

function tm_my_profile_payload($user_id) {
    $user = get_user_by('id', $user_id);
    $confirmed_at = (string) get_user_meta($user_id, 'tm_profile_confirmed_at', true);
    $preferred_facility = function_exists('tm_preferred_facility_payload') ? tm_preferred_facility_payload($user_id) : null;
    return [
        'first_name'    => $user->first_name,
        'last_name'     => $user->last_name,
        'email'         => $user->user_email,
        'phone'         => get_user_meta($user_id, 'tm_phone', true),
        'gender'        => get_user_meta($user_id, 'tm_gender', true),
        'patients'      => (int) get_user_meta($user_id, 'tm_patients', true),
        'qualification' => get_user_meta($user_id, 'tm_qualification', true),
        'home_aft'      => function_exists('tm_doctor_home_aft_id') ? tm_doctor_home_aft_id($user_id) : (int) get_user_meta($user_id, 'tm_home_aft', true),
        'ca_rank'       => function_exists('tm_ca_rank_value') ? tm_ca_rank_value($user_id) : null,
        'ca_zone'       => get_user_meta($user_id, 'tm_ca_zone', true),
        'ca_cot'        => (bool) get_user_meta($user_id, 'tm_ca_cot', true),
        'preferred_facility' => $preferred_facility ? (int) $preferred_facility['id'] : 0,
        'pending'       => tm_format_pending_fields($user_id, tm_get_pending_profile($user_id)),
        'confirmed_at'  => $confirmed_at,
        'requires_confirmation' => $confirmed_at === '' || !tm_doctor_professional_data_complete($user_id),
    ];
}

function tm_afts_for_select() {
    $afts = get_posts([
        'post_type'   => 'tm_aft',
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => ['publish', 'draft'],
    ]);
    return array_map(fn($post) => ['id' => $post->ID, 'name' => $post->post_title], $afts);
}

function tm_rest_get_my_profile() {
    $doctor = tm_current_doctor();
    if (!$doctor) {
        return new WP_Error('tm_unauthorized', 'Accesso riservato ai medici.', ['status' => 401]);
    }
    return rest_ensure_response([
        'profile'    => tm_my_profile_payload($doctor->ID),
        'afts'       => tm_afts_for_select(),
        'facilities' => function_exists('tm_app_facilities_for_select') ? tm_app_facilities_for_select() : [],
    ]);
}

function tm_rest_update_my_profile($request) {
    $doctor = tm_current_doctor();
    if (!$doctor) {
        return new WP_Error('tm_unauthorized', 'Accesso riservato ai medici.', ['status' => 401]);
    }
    $payload = $request->get_json_params();
    $qualification = sanitize_key($payload['qualification'] ?? get_user_meta($doctor->ID, 'tm_qualification', true));
    if (!in_array($qualification, tm_allowed_doctor_qualifications(), true)) {
        return new WP_Error('tm_qualification_required', 'Scegli una qualifica.', ['status' => 400]);
    }
    $home_aft = absint($payload['home_aft'] ?? get_user_meta($doctor->ID, 'tm_home_aft', true));
    if ($qualification !== 'ca_temporaneo' && !tm_valid_doctor_aft($home_aft)) {
        return new WP_Error('tm_home_aft_required', 'Scegli l’AFT di appartenenza.', ['status' => 400]);
    }
    if ($qualification === 'ca_temporaneo') $home_aft = 0;

    $current_complete = tm_doctor_professional_data_complete($doctor->ID);
    $confirmed_at = (string) get_user_meta($doctor->ID, 'tm_profile_confirmed_at', true);
    $first_confirmation = !empty($payload['confirm_profile']) && (!$confirmed_at || !$current_complete);

    $update = ['ID' => $doctor->ID];
    if (isset($payload['first_name']) || isset($payload['last_name'])) {
        $first = sanitize_text_field($payload['first_name'] ?? $doctor->first_name);
        $last  = sanitize_text_field($payload['last_name'] ?? $doctor->last_name);
        $update['first_name']   = $first;
        $update['last_name']    = $last;
        $update['display_name'] = trim("$first $last") ?: $doctor->user_email;
    }
    if (isset($payload['email'])) {
        $email = sanitize_email($payload['email']);
        if (!$email || !is_email($email)) {
            return new WP_Error('tm_invalid_email', 'Email non valida.', ['status' => 400]);
        }
        $existing = email_exists($email);
        if ($existing && (int) $existing !== $doctor->ID) {
            return new WP_Error('tm_email_unavailable', 'Questa email non può essere utilizzata: scegline un’altra o contatta la segreteria.', ['status' => 400]);
        }
        $update['user_email'] = $email;
    }
    $updated_user = wp_update_user($update);
    if (is_wp_error($updated_user)) {
        return new WP_Error('tm_profile_update_failed', $updated_user->get_error_message(), ['status' => 400]);
    }

    if (isset($payload['phone'])) {
        update_user_meta($doctor->ID, 'tm_phone', sanitize_text_field($payload['phone']));
    }
    if (isset($payload['gender'])) {
        update_user_meta($doctor->ID, 'tm_gender', tm_sanitize_gender($payload['gender']));
    }
    if (array_key_exists('preferred_facility', $payload)) {
        $preferred_facility = absint($payload['preferred_facility']);
        if ($preferred_facility && function_exists('tm_facility_app_payload') && tm_facility_app_payload($preferred_facility)) {
            update_user_meta($doctor->ID, 'tm_preferred_facility', $preferred_facility);
        } else {
            delete_user_meta($doctor->ID, 'tm_preferred_facility');
        }
    }
    $pending = tm_get_pending_profile($doctor->ID);
    $new_requests = [];
    $now = current_time('mysql');

    if ($first_confirmation && !$current_complete) {
        update_user_meta($doctor->ID, 'tm_qualification', $qualification);
        if ($home_aft) update_user_meta($doctor->ID, 'tm_home_aft', $home_aft);
        else delete_user_meta($doctor->ID, 'tm_home_aft');
        update_user_meta($doctor->ID, 'tm_patients', absint($payload['patients'] ?? 0));
        foreach (array_keys(TM_PROFILE_SENSITIVE) as $field) unset($pending[$field]);
    } else {
        $payload['qualification'] = $qualification;
        $payload['home_aft'] = $home_aft;
        foreach (TM_PROFILE_SENSITIVE as $field => [$meta_key, $label]) {
            if (!array_key_exists($field, $payload)) continue;

            $current = get_user_meta($doctor->ID, $meta_key, true);
            if ($field === 'qualification') {
                $requested = sanitize_key($payload[$field]);
                $same = (string) $requested === (string) $current;
            } else {
                $requested = (int) $payload[$field];
                $same = $requested === (int) $current;
            }

            if ($same) {
                unset($pending[$field]);
                continue;
            }
            $pending[$field] = ['value' => $requested, 'requested_at' => $now];
            $new_requests[$field] = $requested;
        }
    }
    tm_set_pending_profile($doctor->ID, $pending);

    if ($first_confirmation) {
        update_user_meta($doctor->ID, 'tm_profile_confirmed_at', current_time('mysql'));
    }

    if ($new_requests) {
        tm_notify_admin_profile_request($doctor, $new_requests);
    }

    return rest_ensure_response(tm_my_profile_payload($doctor->ID));
}

function tm_notify_admin_profile_request($doctor, $requests) {
    $lines = [];
    foreach ($requests as $field => $value) {
        $label = TM_PROFILE_SENSITIVE[$field][1] ?? $field;
        $lines[] = $label . ': ' . tm_display_field_value($field, $value);
    }
    $name = trim($doctor->first_name . ' ' . $doctor->last_name) ?: $doctor->user_email;
    $subject = '[Turni Medici] Richiesta modifica dati: ' . $name;
    $body = $name . " ha richiesto la modifica dei seguenti dati, in attesa di approvazione:\n\n"
        . implode("\n", $lines)
        . "\n\nApprova o rifiuta da: " . admin_url('users.php?page=tm-doctors');
    wp_mail(get_option('admin_email'), $subject, $body);
}

function tm_count_pending_requests() {
    $users = get_users([
        'role'     => 'tm_doctor',
        'meta_key' => 'tm_pending_profile',
        'fields'   => 'ID',
    ]);
    $count = 0;
    foreach ($users as $user_id) {
        if (tm_get_pending_profile($user_id)) $count++;
    }
    return $count;
}

function tm_rest_list_change_requests() {
    $users = get_users([
        'role'     => 'tm_doctor',
        'meta_key' => 'tm_pending_profile',
        'orderby'  => 'display_name',
        'order'    => 'ASC',
    ]);
    $out = [];
    foreach ($users as $user) {
        $fields = tm_format_pending_fields($user->ID, tm_get_pending_profile($user->ID));
        if (!$fields) continue;
        $out[] = [
            'doctor_id' => $user->ID,
            'name'      => trim($user->first_name . ' ' . $user->last_name) ?: $user->user_email,
            'fields'    => $fields,
        ];
    }
    return rest_ensure_response($out);
}

function tm_rest_resolve_change_request($request) {
    $user = tm_get_doctor_or_error($request['id']);
    if (is_wp_error($user)) {
        return $user;
    }
    $body = $request->get_json_params();
    $field = sanitize_key($body['field'] ?? '');
    $action = sanitize_key($body['action'] ?? '');

    $pending = tm_get_pending_profile($user->ID);
    if (!isset($pending[$field]) || !isset(TM_PROFILE_SENSITIVE[$field])) {
        return new WP_Error('tm_not_found', 'Richiesta non trovata.', ['status' => 404]);
    }
    if ($action === 'approve') {
        tm_save_doctor_meta($user->ID, [$field => $pending[$field]['value']]);
    }
    unset($pending[$field]);
    tm_set_pending_profile($user->ID, $pending);

    return rest_ensure_response([
        'doctor_id' => $user->ID,
        'fields'    => tm_format_pending_fields($user->ID, $pending),
    ]);
}

add_action('rest_api_init', function () {
    $doctor_auth = fn() => tm_current_doctor() !== null;
    $admin_auth  = fn() => current_user_can('edit_users');

    register_rest_route('turnimedici/v1', '/me/profile', [
        ['methods' => 'GET',  'callback' => 'tm_rest_get_my_profile',    'permission_callback' => $doctor_auth],
        ['methods' => 'POST', 'callback' => 'tm_rest_update_my_profile', 'permission_callback' => $doctor_auth],
    ]);
    register_rest_route('turnimedici/v1', '/change-requests', [
        ['methods' => 'GET', 'callback' => 'tm_rest_list_change_requests', 'permission_callback' => $admin_auth],
    ]);
    register_rest_route('turnimedici/v1', '/change-requests/(?P<id>\d+)/resolve', [
        ['methods' => 'POST', 'callback' => 'tm_rest_resolve_change_request', 'permission_callback' => $admin_auth],
    ]);
});
