<?php

if (!defined('ABSPATH')) exit;

$tm_doctor  = function_exists('tm_current_doctor') ? tm_current_doctor() : null;
$tm_manager = !$tm_doctor && current_user_can('edit_users');

if (!$tm_doctor && !$tm_manager) {
    $vetrina = function_exists('tm_vetrina_url') ? tm_vetrina_url() : home_url('/');
    if (untrailingslashit($vetrina) !== untrailingslashit(home_url('/'))) {
        wp_safe_redirect($vetrina);
        exit;
    }
    if (!is_user_logged_in()) auth_redirect();
    wp_die('Area medici. In locale imposta TM_LOCAL_FACE su "vetrina" per vedere il sito pubblico.');
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php wp_head(); ?>
</head>
<body <?php body_class('tm-app'); ?>>
<?php wp_body_open(); ?>
<div id="tm-dashboard"><div class="tmd-loading">Caricamento…</div></div>
<?php wp_footer(); ?>
</body>
</html>
