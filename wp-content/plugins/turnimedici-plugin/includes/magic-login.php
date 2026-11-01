<?php

if (!defined('ABSPATH')) exit;

const TM_MAGIC_MAX_ATTEMPTS = 10;
const TM_MAGIC_ATTEMPTS_WINDOW = 15 * MINUTE_IN_SECONDS;
const TM_DOCTOR_SESSION_LENGTH = 365 * DAY_IN_SECONDS;

add_filter('auth_cookie_expiration', function ($length, $user_id, $remember) {
    if ($remember && user_can($user_id, 'read') && tm_user_is_turnazione_doctor($user_id)) {
        return TM_DOCTOR_SESSION_LENGTH;
    }
    return $length;
}, 10, 3);

add_action('init', 'tm_consume_magic_login');
function tm_consume_magic_login() {
    if (empty($_GET['tm_login'])) return;

    $already_in = is_user_logged_in()
        && ((function_exists('tm_current_doctor') && tm_current_doctor()) || tm_can_manage_scheduling());

    if (tm_magic_login_is_throttled()) {
        wp_safe_redirect(home_url($already_in ? '/' : '/?tm_login_failed=1'));
        exit;
    }

    $token = sanitize_text_field(wp_unslash($_GET['tm_login']));
    $user = strlen($token) >= 20 ? tm_find_doctor_by_valid_token($token) : null;

    if (!$user) {
        if ($already_in) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
        tm_magic_login_register_failure();
        wp_safe_redirect(home_url('/?tm_login_failed=1'));
        exit;
    }

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);
    do_action('wp_login', $user->user_login, $user);

    wp_safe_redirect(tm_magic_login_redirect($user));
    exit;
}

function tm_find_doctor_by_valid_token($token) {
    $users = get_users([
        'meta_key'   => 'tm_magic_token',
        'meta_value' => $token,
        'number'     => 1,
    ]);
    if (empty($users)) return null;
    if (!tm_user_is_turnazione_doctor($users[0]->ID)) return null;

    return $users[0];
}

function tm_magic_login_throttle_key() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    return 'tm_magic_fail_' . md5($ip);
}

function tm_magic_login_is_throttled() {
    return (int) get_transient(tm_magic_login_throttle_key()) >= TM_MAGIC_MAX_ATTEMPTS;
}

function tm_magic_login_register_failure() {
    $key = tm_magic_login_throttle_key();
    $failures = (int) get_transient($key);
    set_transient($key, $failures + 1, TM_MAGIC_ATTEMPTS_WINDOW);
}

function tm_magic_login_redirect($user) {
    $url = function_exists('tm_turni_url') ? tm_turni_url('') : home_url('/');
    if (tm_magic_login_profile_requested()) {
        $url = add_query_arg('tm_profile', '1', $url);
    } elseif ((string) get_user_meta($user->ID, 'tm_profile_confirmed_at', true) !== '' && function_exists('tm_preferred_facility_payload')) {
        $preferred = tm_preferred_facility_payload($user->ID);
        if (!empty($preferred['slug'])) {
            $url = function_exists('tm_turni_url') ? tm_turni_url($preferred['slug']) : home_url('/' . ltrim($preferred['slug'], '/'));
        }
    }
    return $url;
}

function tm_magic_login_profile_requested() {
    foreach (['tm_profile', 'tm_edit_profile', 'profile'] as $key) {
        if (!isset($_GET[$key])) continue;
        $value = strtolower(sanitize_text_field(wp_unslash($_GET[$key])));
        if (!in_array($value, ['0', 'false', 'no'], true)) return true;
    }
    return false;
}

const TM_ACCESS_LINK_MAX_REQUESTS = 5;
const TM_ACCESS_LINK_WINDOW = 15 * MINUTE_IN_SECONDS;
const TM_ACCESS_LINK_USER_COOLDOWN = 5 * MINUTE_IN_SECONDS;

add_action('rest_api_init', function () {
    register_rest_route('turnimedici/v1', '/access-link', [
        'methods'             => 'POST',
        'callback'            => 'tm_rest_send_access_link',
        'permission_callback' => '__return_true',
        'args'                => [
            'email' => ['required' => true, 'type' => 'string'],
        ],
    ]);
});

function tm_rest_send_access_link($request) {
    if (tm_demo_mode()) {
        return new WP_Error('tm_demo_mail_disabled', 'La demo non invia email: usa un account locale di prova.', ['status' => 403]);
    }
    $generic = rest_ensure_response(['sent' => true]);

    if (!empty($request['website'])) return $generic;

    $ip_key = 'tm_link_req_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $requests = (int) get_transient($ip_key);
    if ($requests >= TM_ACCESS_LINK_MAX_REQUESTS) return $generic;
    set_transient($ip_key, $requests + 1, TM_ACCESS_LINK_WINDOW);

    $email = sanitize_email((string) $request['email']);
    if (!$email || !is_email($email)) return $generic;

    $user = get_user_by('email', $email);
    if (!$user || !tm_user_is_turnazione_doctor($user->ID)) return $generic;

    if (get_transient('tm_link_sent_' . $user->ID)) return $generic;
    set_transient('tm_link_sent_' . $user->ID, 1, TM_ACCESS_LINK_USER_COOLDOWN);

    $token = get_user_meta($user->ID, 'tm_magic_token', true);
    if (!$token) {
        update_user_meta($user->ID, 'tm_magic_token', bin2hex(random_bytes(20)));
        update_user_meta($user->ID, 'tm_magic_token_created', time());
    }

    $link = tm_doctor_magic_url($user->ID);
    if ($link) {
        wp_mail(
            $email,
            'Il tuo link di accesso — ' . get_bloginfo('name'),
            "Ciao,\n\necco il tuo link personale di accesso a " . get_bloginfo('name') . ":\n\n" . $link . "\n\nIl link è personale: non condividerlo.\nSe non hai richiesto tu questo messaggio, puoi ignorarlo.\n"
        );
    }

    return $generic;
}
