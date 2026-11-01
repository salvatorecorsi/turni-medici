<?php

if (!defined('ABSPATH')) exit;

if (function_exists('defb_register_custom_field')) {
    defb_register_custom_field(__FILE__, ['weekly-schedule', 'schedule-admin', 'facility-ranking', 'aft-orari']);
}

function tm_weekly_schedule_style() {
    $css = __DIR__ . '/build/style-index.css';
    if (!file_exists($css)) return;

    wp_enqueue_style(
        'tm-weekly-schedule',
        plugins_url('build/style-index.css', __FILE__),
        [],
        (string) filemtime($css)
    );
}

add_action('enqueue_block_assets', function () {
    if (is_admin()) tm_weekly_schedule_style();
});
add_action('enqueue_block_editor_assets', 'tm_weekly_schedule_style');
