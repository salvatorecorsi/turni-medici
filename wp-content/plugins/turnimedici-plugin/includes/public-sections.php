<?php

if (!defined('ABSPATH')) exit;

add_action('template_redirect', function () {
    if (function_exists('tm_is_turni_face') && tm_is_turni_face()) return;
    if (is_front_page() || is_home()) return;
    if (is_page(apply_filters('tm_public_page_slugs', ['privacy-policy']))) return;
    if (is_page() && has_block('turnimedici/contact-form')) return;
    if (is_page() && has_block('turnimedici/documents')) return;

    nocache_headers();
    wp_safe_redirect(home_url('/'));
    exit;
});
