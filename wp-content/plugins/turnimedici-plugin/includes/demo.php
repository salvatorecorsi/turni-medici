<?php

if (!defined('ABSPATH')) exit;

if (!defined('TM_DEMO_MODE')) define('TM_DEMO_MODE', true);

function tm_demo_mode() {
    return TM_DEMO_MODE !== false;
}

function tm_demo_integration_error() {
    return new WP_Error('tm_demo_integration_disabled', 'La demo usa dati fittizi: importazioni da servizi esterni disabilitate.', ['status' => 403]);
}

add_filter('pre_wp_mail', function ($result) {
    return tm_demo_mode() ? false : $result;
}, PHP_INT_MAX);

add_filter('pre_http_request', function ($result, $request, $url) {
    if (!tm_demo_mode()) return $result;
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    if ($host === 'servizi.estar.toscana.it') {
        return tm_demo_integration_error();
    }
    return $result;
}, PHP_INT_MAX, 3);

add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if (!tm_demo_mode()) return $result;
    $route = $request->get_route();
    if (str_starts_with($route, '/turnimedici/v1/estar-')) {
        return tm_demo_integration_error();
    }
    return $result;
}, 10, 3);

add_action('admin_notices', function () {
    if (!tm_demo_mode() || !current_user_can('tm_coordinate_hours')) return;
    echo '<div class="notice notice-info"><p><strong>Turni Medici Demo:</strong> usa solo dati fittizi. Le email non vengono inviate e il servizio ESTAR è disabilitato.</p></div>';
});
