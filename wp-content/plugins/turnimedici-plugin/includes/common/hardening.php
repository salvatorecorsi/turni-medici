<?php

if (!defined('ABSPATH')) exit;

add_filter('xmlrpc_enabled', '__return_false');

remove_action('wp_head', 'wp_generator');
add_filter('the_generator', '__return_empty_string');

add_filter('login_errors', function () {
    return 'Credenziali non valide.';
});

add_filter('rest_endpoints', function ($endpoints) {
    if (is_user_logged_in()) return $endpoints;
    unset($endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)']);
    return $endpoints;
});

add_action('template_redirect', function () {
    if (is_author() && !is_user_logged_in()) {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
});

add_filter('redirect_canonical', function ($redirect) {
    if (!is_user_logged_in() && !empty($_GET['author'])) return false;
    return $redirect;
});
