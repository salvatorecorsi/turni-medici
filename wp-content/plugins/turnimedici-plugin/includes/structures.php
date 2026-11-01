<?php

if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'tm_structures_menu');
function tm_structures_menu() {
    add_menu_page('Strutture', 'Strutture', 'edit_users', 'tm-structures', 'tm_structures_page', 'dashicons-building', 25);
}

function tm_structures_page() {
    echo '<div class="wrap"><div id="tm-structures-app"><p>Caricamento…</p></div></div>';
}

function tm_structures_filter_aft_id() {
    $key = isset($_GET['tm_aft_filter']) ? 'tm_aft_filter' : 'tm_aft';
    return isset($_GET[$key]) ? absint(wp_unslash($_GET[$key])) : 0;
}

add_action('admin_enqueue_scripts', 'tm_structures_enqueue');
function tm_structures_enqueue($hook) {
    if ($hook !== 'toplevel_page_tm-structures') return;
    $base = TM_DIR . 'app/build/';
    $url  = TM_URL . 'app/build/';
    if (!file_exists($base . 'structures.asset.php')) return;
    $asset = include $base . 'structures.asset.php';
    wp_enqueue_script('tm-structures', $url . 'structures.js', $asset['dependencies'], (string) filemtime($base . 'structures.js'), true);
    wp_enqueue_style('wp-components');
    $css = TM_DIR . 'assets/schedules.css';
    if (file_exists($css)) {
        wp_enqueue_style('tm-structures', TM_URL . 'assets/schedules.css', ['wp-components'], (string) filemtime($css));
    }
    wp_localize_script('tm-structures', 'tmStructures', [
        'restUrl' => rest_url('turnimedici/v1/'),
        'nonce'   => wp_create_nonce('wp_rest'),
        'newUrl'  => admin_url('post-new.php?post_type=tm_facility'),
        'listUrl' => admin_url('edit.php?post_type=tm_facility'),
    ]);
}

add_action('pre_get_posts', function ($query) {
    if (!is_admin() || !$query->is_main_query()) return;
    if ($query->get('post_type') !== 'tm_facility') return;
    if (isset($_GET['tm_aft']) && !isset($_GET['tm_aft_filter'])) {
        $query->set('tm_aft', '');
        $query->set('name', '');
    }
    $aid = tm_structures_filter_aft_id();
    if ($aid) {
        $meta_query = (array) $query->get('meta_query');
        $meta_query[] = ['key' => 'tm_aft_id', 'value' => $aid, 'type' => 'NUMERIC'];
        $query->set('meta_query', $meta_query);
    }
});

add_filter('manage_tm_facility_posts_columns', function ($cols) {
    $new = [];
    if (isset($cols['cb'])) $new['cb'] = $cols['cb'];
    $new['title']     = 'Struttura';
    $new['tm_aft']    = 'AFT';
    $new['tm_months'] = 'Orari';
    if (isset($cols['date'])) $new['date'] = $cols['date'];
    return $new;
});

add_action('manage_tm_facility_posts_custom_column', function ($col, $post_id) {
    if ($col === 'tm_aft') {
        $aid = (int) get_post_meta($post_id, 'tm_aft_id', true);
        $a = $aid ? get_post($aid) : null;
        echo $a ? esc_html(preg_replace('/^AFT\s+/i', '', $a->post_title)) : '&mdash;';
    } elseif ($col === 'tm_months') {
        $n = count(get_posts([
            'post_type'   => 'tm_schedule',
            'numberposts' => -1,
            'post_status' => ['publish', 'draft'],
            'fields'      => 'ids',
            'meta_key'    => 'tm_facility_id',
            'meta_value'  => $post_id,
        ]));
        if ($n) {
            $url = admin_url('edit.php?post_type=tm_schedule&tm_struttura=' . (int) $post_id);
            echo '<a href="' . esc_url($url) . '">' . (int) $n . ' mes' . ($n === 1 ? 'e' : 'i') . '</a>';
        } else {
            echo '&mdash;';
        }
    }
}, 10, 2);

add_action('admin_notices', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'edit-tm_facility') return;
    $smistatore = admin_url('admin.php?page=tm-structures');
    $aid = tm_structures_filter_aft_id();
    if ($aid) {
        $a = get_post($aid);
        $aft_name = $a ? preg_replace('/^AFT\s+/i', '', $a->post_title) : '';
        echo '<div class="tm-sched-crumb" style="margin:10px 0 2px;font-size:14px;color:#1d2327">'
            . '<span style="font-weight:600">' . esc_html($aft_name) . '</span>'
            . ' &nbsp; <a href="' . esc_url($smistatore) . '" style="text-decoration:underline;color:#2271b1">&lsaquo; Cambia AFT</a>'
            . '</div>';
    } else {
        echo '<div class="tm-sched-crumb" style="margin:10px 0 2px;font-size:14px">'
            . '<a href="' . esc_url($smistatore) . '" style="text-decoration:underline;color:#2271b1">Filtra per AFT</a>'
            . '</div>';
    }
});
