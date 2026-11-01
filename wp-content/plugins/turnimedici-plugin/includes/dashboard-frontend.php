<?php

if (!defined('ABSPATH')) exit;

const TM_FACILITY_REWRITE_MAP_OPTION = 'tm_facility_rewrite_map';
const TM_FACILITY_REWRITE_MAP_VERSION = 1;
const TM_FACILITY_REWRITE_DIRTY_OPTION = 'tm_facility_rewrite_dirty';

add_action('init', 'tm_facility_rewrites');
function tm_facility_rewrites() {
    foreach (tm_facility_rewrite_map() as $slug => $id) {
        add_rewrite_rule('^' . preg_quote($slug, '#') . '/?$', 'index.php?tm_facility_view=' . (int) $id, 'top');
    }
    if (get_option(TM_FACILITY_REWRITE_DIRTY_OPTION)) {
        delete_option(TM_FACILITY_REWRITE_DIRTY_OPTION);
        flush_rewrite_rules(false);
    }
}

function tm_facility_rewrite_map() {
    $cached = get_option(TM_FACILITY_REWRITE_MAP_OPTION);
    if (
        is_array($cached)
        && (int) ($cached['version'] ?? 0) === TM_FACILITY_REWRITE_MAP_VERSION
        && is_array($cached['map'] ?? null)
    ) {
        return $cached['map'];
    }

    return tm_refresh_facility_rewrite_map();
}

function tm_refresh_facility_rewrite_map() {
    $map = [];
    $ids = get_posts([
        'post_type'   => 'tm_facility',
        'numberposts' => -1,
        'post_status' => 'publish',
        'fields'      => 'ids',
        'meta_query'  => [
            ['key' => 'tm_aft_id', 'compare' => 'EXISTS'],
        ],
    ]);

    foreach ($ids as $id) {
        if (!tm_facility_has_aft($id)) continue;
        $slug = get_post_field('post_name', $id);
        if ($slug) $map[$slug] = (int) $id;
    }

    update_option(TM_FACILITY_REWRITE_MAP_OPTION, [
        'version' => TM_FACILITY_REWRITE_MAP_VERSION,
        'map'     => $map,
    ], true);

    return $map;
}

add_filter('query_vars', function ($vars) {
    $vars[] = 'tm_facility_view';
    return $vars;
});

/* Il flush avviene all'init successivo: flushare qui congelerebbe anche le
   regole del vecchio slug, ancora registrate da inizio richiesta. */
function tm_refresh_facility_rewrites() {
    tm_refresh_facility_rewrite_map();
    update_option(TM_FACILITY_REWRITE_DIRTY_OPTION, 1, false);
}

function tm_facility_slug_taken_by_other_content($slug, $facility_id) {
    global $wpdb;
    $public_types = array_values(array_diff(get_post_types(['public' => true]), ['tm_facility']));
    if (!$public_types) return false;
    $placeholders = implode(',', array_fill(0, count($public_types), '%s'));
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM $wpdb->posts WHERE post_name = %s AND ID <> %d AND post_status IN ('publish', 'future', 'private') AND post_type IN ($placeholders) LIMIT 1",
        array_merge([(string) $slug, (int) $facility_id], $public_types)
    ));
}

add_action('save_post_tm_facility', 'tm_facility_ensure_unique_slug', 5, 2);
function tm_facility_ensure_unique_slug($post_id, $post) {
    if (!$post->post_name || in_array($post->post_status, ['auto-draft', 'trash'], true)) return;
    if (!tm_facility_slug_taken_by_other_content($post->post_name, $post_id)) return;
    $suffix = 2;
    do {
        $candidate = $post->post_name . '-' . $suffix;
        $suffix++;
    } while (tm_facility_slug_taken_by_other_content($candidate, $post_id));
    remove_action('save_post_tm_facility', 'tm_facility_ensure_unique_slug', 5);
    wp_update_post(['ID' => (int) $post_id, 'post_name' => $candidate]);
    add_action('save_post_tm_facility', 'tm_facility_ensure_unique_slug', 5, 2);
}

add_action('save_post_tm_facility', 'tm_refresh_facility_rewrites', 10, 0);
add_action('save_post_tm_aft', 'tm_refresh_facility_rewrites', 10, 0);

add_action('deleted_post', 'tm_refresh_facility_rewrites_on_post_change');
add_action('trashed_post', 'tm_refresh_facility_rewrites_on_post_change');
add_action('untrashed_post', 'tm_refresh_facility_rewrites_on_post_change');
function tm_refresh_facility_rewrites_on_post_change($post_id) {
    $type = get_post_type($post_id);
    if (!in_array($type, ['tm_facility', 'tm_aft'], true)) return;
    tm_refresh_facility_rewrites();
}

/**
 * La richiesta deve essere servita dall'app turni?
 * Sulla faccia turni: home (qualunque sia show_on_front) o l'apertura diretta di una struttura.
 * NON dipende da is_front_page(), che cambia se la home WP viene riconfigurata.
 */
function tm_app_facility_id() {
    $facility_id = (int) get_query_var('tm_facility_view');
    if (!$facility_id && is_singular('tm_facility')) {
        $facility_id = (int) get_queried_object_id();
    }
    return $facility_id;
}

function tm_is_app_request() {
    $facility_id = tm_app_facility_id();
    if ($facility_id > 0 && !tm_facility_has_aft($facility_id)) return false;
    if ($facility_id > 0) return true;
    if (function_exists('tm_is_turni_face') && !tm_is_turni_face()) return false;
    return is_front_page() || is_home();
}

add_filter('template_include', 'tm_app_template', 99);
function tm_app_template($template) {
    if (!tm_is_app_request()) return $template;
    $app = TM_DIR . 'templates/app.php';
    return file_exists($app) ? $app : $template;
}

add_action('wp_enqueue_scripts', 'tm_enqueue_dashboard');
function tm_enqueue_dashboard() {
    if (!tm_is_app_request()) return;
    if (!tm_can_access_turni()) return;

    $base = TM_DIR . 'app/build/';
    $url = TM_URL . 'app/build/';
    if (!file_exists($base . 'dashboard.asset.php')) {
        return;
    }

    $asset = include $base . 'dashboard.asset.php';
    wp_enqueue_script('tm-dashboard', $url . 'dashboard.js', $asset['dependencies'], (string) filemtime($base . 'dashboard.js'), true);

    foreach (['style-dashboard.css', 'dashboard.css'] as $css) {
        if (file_exists($base . $css)) {
            wp_enqueue_style('tm-dashboard', $url . $css, [], (string) filemtime($base . $css));
            break;
        }
    }

    $doctor = tm_current_doctor();
    wp_localize_script('tm-dashboard', 'tmDashboard', [
        'restUrl'    => rest_url('turnimedici/v1/'),
        'apiRoot'    => rest_url(),
        'nonce'      => wp_create_nonce('wp_rest'),
        'homeUrl'    => home_url('/'),
        'logoutUrl'  => wp_logout_url(home_url('/')),
        'adminBase'  => admin_url('post.php'),
        'facilityId' => tm_app_facility_id(),
    ]);
}

// L'app turni non deve ereditare il CSS della vetrina: stesso prefisso .tm-, collisioni garantite.
// Stacca style.css/tm-vetrina.css e carica solo le fondamenta (reset, token, font).
add_action('wp_enqueue_scripts', 'tm_isolate_app_styles', 100);
function tm_isolate_app_styles() {
    if (!tm_is_app_request()) return;
    if (!tm_can_access_turni()) return;

    wp_dequeue_style('turnimedici-theme');
    wp_dequeue_style('tm-vetrina');

    $base = TM_DIR . 'assets/app-base.css';
    if (file_exists($base)) {
        wp_enqueue_style('tm-app-base', TM_URL . 'assets/app-base.css', [], (string) filemtime($base));
    }
}
