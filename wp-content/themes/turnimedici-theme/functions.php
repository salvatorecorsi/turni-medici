<?php

if (!defined('ABSPATH')) exit;

define('TM_THEME_VERSION', '0.1.0');

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets']);
    add_theme_support('automatic-feed-links');
    register_nav_menus(['primary' => 'Menu principale']);
});

add_action('wp_enqueue_scripts', function () {
    $style_path = get_stylesheet_directory() . '/style.css';
    $version = file_exists($style_path) ? filemtime($style_path) : TM_THEME_VERSION;
    wp_enqueue_style('turnimedici-theme', get_stylesheet_uri(), [], $version);
});

function tm_clock_logo($class = '') {
    static $script_printed = false;
    ?>
    <svg class="tm-clock<?php echo $class ? ' ' . esc_attr($class) : ''; ?>" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Orologio">
        <circle cx="24" cy="24" r="20" stroke="#16293a" stroke-width="3.5"/>
        <line x1="24" y1="8.5" x2="24" y2="12.5" stroke="#16293a" stroke-width="3" stroke-linecap="round"/>
        <line x1="24" y1="35.5" x2="24" y2="39.5" stroke="#16293a" stroke-width="3" stroke-linecap="round"/>
        <line x1="8.5" y1="24" x2="12.5" y2="24" stroke="#16293a" stroke-width="3" stroke-linecap="round"/>
        <line x1="35.5" y1="24" x2="39.5" y2="24" stroke="#16293a" stroke-width="3" stroke-linecap="round"/>
        <line class="tm-clock-hour" x1="24" y1="24" x2="24" y2="16.5" stroke="#2b6fe0" stroke-width="3.5" stroke-linecap="round" transform="rotate(240 24 24)"/>
        <line class="tm-clock-minute" x1="24" y1="24" x2="24" y2="13" stroke="#2b6fe0" stroke-width="3.5" stroke-linecap="round"/>
        <circle cx="24" cy="24" r="2.4" fill="#1f9d5c"/>
    </svg>
    <?php
    if ($script_printed) return;
    $script_printed = true;
    ?>
    <script>
    (function () {
        var update = function () {
            var now = new Date();
            var minutes = now.getMinutes() + now.getSeconds() / 60;
            var hours = now.getHours() % 12 + minutes / 60;
            document.querySelectorAll('.tm-clock-hour').forEach(function (hand) {
                hand.setAttribute('transform', 'rotate(' + hours * 30 + ' 24 24)');
            });
            document.querySelectorAll('.tm-clock-minute').forEach(function (hand) {
                hand.setAttribute('transform', 'rotate(' + minutes * 6 + ' 24 24)');
            });
        };
        update();
        setInterval(update, 10000);
    })();
    </script>
    <?php
}
