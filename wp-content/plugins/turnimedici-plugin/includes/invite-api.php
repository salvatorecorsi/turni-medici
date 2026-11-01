<?php

if (!defined('ABSPATH')) exit;

function tm_get_invite_request_ids($schedule_id) {
    $raw = get_post_meta($schedule_id, 'tm_invite_requests', true);
    return is_array($raw) ? array_values(array_unique(array_filter(array_map('intval', $raw)))) : [];
}

function tm_set_invite_request_ids($schedule_id, $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
    if ($ids) {
        update_post_meta($schedule_id, 'tm_invite_requests', $ids);
    } else {
        delete_post_meta($schedule_id, 'tm_invite_requests');
    }
}

function tm_remove_doctor_invite_requests($doctor_id) {
    $doctor_id = (int) $doctor_id;
    if (!$doctor_id) return;
    $schedule_ids = get_posts([
        'post_type'   => 'tm_schedule',
        'numberposts' => -1,
        'post_status' => ['publish', 'draft'],
        'fields'      => 'ids',
        'meta_key'    => 'tm_invite_requests',
    ]);
    foreach ($schedule_ids as $schedule_id) {
        $ids = tm_get_invite_request_ids($schedule_id);
        if (!in_array($doctor_id, $ids, true)) continue;
        tm_set_invite_request_ids($schedule_id, array_diff($ids, [$doctor_id]));
    }
}

function tm_count_invite_requests() {
    global $wpdb;
    $rows = $wpdb->get_col(
        "SELECT postmeta.meta_value
         FROM {$wpdb->postmeta} postmeta
         INNER JOIN {$wpdb->posts} schedules ON schedules.ID = postmeta.post_id
         WHERE postmeta.meta_key = 'tm_invite_requests'
           AND schedules.post_type = 'tm_schedule'
           AND schedules.post_status IN ('publish', 'draft')"
    );
    $count = 0;
    foreach ($rows as $raw) {
        $ids = maybe_unserialize($raw);
        if (is_array($ids)) $count += count(array_filter(array_map('intval', $ids)));
    }
    return $count;
}

function tm_notify_managers_invite_request($doctor, $schedule, $facility_id) {
    $name = trim($doctor->first_name . ' ' . $doctor->last_name) ?: $doctor->user_email;
    $facility = get_post($facility_id);
    $facility_name = $facility ? $facility->post_title : '';
    $month = tm_schedule_month($schedule->ID);
    $months = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    $period = trim(($months[$month['month']] ?? '') . ' ' . $month['year']);
    $subject = '[Turni Medici] Richiesta di invito: ' . $name;
    $board_url = $facility && function_exists('tm_turni_url')
        ? add_query_arg('schedule', $schedule->ID, tm_turni_url($facility->post_name))
        : home_url('/');
    $body = $name . " chiede di essere invitato a coprire i turni di $facility_name ($period).\n\n"
        . "Apri la lista del turno: " . $board_url;
    $emails = function_exists('tm_schedule_manager_emails') ? tm_schedule_manager_emails($schedule->ID) : [get_option('admin_email')];
    foreach ($emails as $email) wp_mail($email, $subject, $body);
}

function tm_rest_request_invite($request) {
    $doctor = tm_current_doctor();
    if (!$doctor) {
        return new WP_Error('tm_unauthorized', 'Accesso riservato ai medici.', ['status' => 401]);
    }
    $facility_id = (int) $request['id'];
    $schedule = function_exists('tm_requested_schedule') ? tm_requested_schedule($facility_id, $request) : tm_doctor_current_schedule($facility_id);
    if (!$schedule) {
        return new WP_Error('tm_no_schedule', 'Nessuna turnazione attiva per questa struttura.', ['status' => 404]);
    }
    if (get_post_meta($schedule->ID, 'tm_schedule_closed', true)) {
        return new WP_Error('tm_closed', 'La turnazione di questo mese è chiusa.', ['status' => 403]);
    }
    if (tm_doctor_is_invited($schedule->ID, $doctor->ID)) {
        return new WP_Error('tm_already_invited', 'Sei già in turnazione in questa struttura.', ['status' => 400]);
    }

    $added = tm_with_database_lock('invite_request_' . $schedule->ID, function () use ($schedule, $doctor) {
        if (tm_doctor_is_invited($schedule->ID, $doctor->ID)) {
            return new WP_Error('tm_already_invited', 'Sei già in turnazione in questa struttura.', ['status' => 400]);
        }
        $ids = tm_get_invite_request_ids($schedule->ID);
        if (in_array($doctor->ID, $ids, true)) return false;
        $ids[] = $doctor->ID;
        tm_set_invite_request_ids($schedule->ID, $ids);
        return true;
    });
    if (is_wp_error($added)) return $added;
    if ($added) {
        if (function_exists('tm_queue_log_add')) tm_queue_log_add($schedule->ID, $doctor->ID, 'invite_request', $doctor->ID);
        tm_notify_managers_invite_request($doctor, $schedule, $facility_id);
    }

    return rest_ensure_response(tm_board($facility_id, $doctor, $schedule));
}

add_action('rest_api_init', function () {
    register_rest_route('turnimedici/v1', '/me/structures/(?P<id>\d+)/invite-request', [
        ['methods' => 'POST', 'callback' => 'tm_rest_request_invite', 'permission_callback' => fn() => tm_current_doctor() !== null],
    ]);
});

add_action('admin_menu', 'tm_schedule_menu_bubble', 999);
function tm_schedule_menu_bubble() {
    global $menu;
    $count = tm_count_invite_requests();
    if (!$count) return;
    foreach ($menu as $key => $item) {
        if (isset($item[2]) && $item[2] === 'edit.php?post_type=tm_schedule') {
            $menu[$key][0] .= ' <span class="awaiting-mod"><span class="pending-count">' . $count . '</span></span>';
            break;
        }
    }
}
