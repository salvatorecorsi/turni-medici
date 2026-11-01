<?php

if (!defined('ABSPATH')) exit;

function tm_app_content_types() {
    $types = get_post_types(['public' => true, 'show_ui' => true], 'names');
    unset($types['attachment'], $types['tm_schedule']);
    return array_values($types);
}

function tm_type_label($post_type) {
    $obj = get_post_type_object($post_type);
    return $obj ? $obj->labels->singular_name : $post_type;
}

add_action('add_meta_boxes', function () {
    foreach (tm_app_content_types() as $pt) {
        add_meta_box('tm_show_in_app', 'App turni', 'tm_show_in_app_box', $pt, 'side', 'high');
    }
});

function tm_show_in_app_box($post) {
    wp_nonce_field('tm_show_in_app', 'tm_show_in_app_nonce');
    $on = (bool) get_post_meta($post->ID, 'tm_show_in_app', true);
    echo '<label style="display:flex;gap:8px;align-items:flex-start;font-size:13px;line-height:1.45">';
    echo '<input type="checkbox" name="tm_show_in_app" value="1"' . checked($on, true, false) . ' style="margin-top:2px">';
    echo '<span><strong>Popola in app turni</strong><br><span style="color:#666">Mostra questo contenuto nel pannello Informazioni della dashboard medici.</span></span>';
    echo '</label>';
}

add_action('save_post', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['tm_show_in_app_nonce']) || !wp_verify_nonce($_POST['tm_show_in_app_nonce'], 'tm_show_in_app')) return;
    if (!current_user_can('edit_post', $post_id)) return;
    update_post_meta($post_id, 'tm_show_in_app', isset($_POST['tm_show_in_app']) ? 1 : 0);
});

function tm_rest_info_list() {
    $posts = get_posts([
        'post_type'   => tm_app_content_types(),
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_key'    => 'tm_show_in_app',
        'meta_value'  => '1',
        'orderby'     => 'date',
        'order'       => 'DESC',
    ]);
    return rest_ensure_response(array_map(function ($p) {
        return [
            'id'         => $p->ID,
            'title'      => get_the_title($p),
            'excerpt'    => wp_trim_words(wp_strip_all_tags($p->post_excerpt ?: $p->post_content), 26),
            'type_label' => tm_type_label($p->post_type),
            'date'       => mysql2date('j F Y', $p->post_date),
        ];
    }, $posts));
}

function tm_rest_info_single($req) {
    $id = (int) $req['id'];
    $post = get_post($id);
    if (!$post || $post->post_status !== 'publish' || !get_post_meta($id, 'tm_show_in_app', true)) {
        return new WP_Error('tm_not_found', 'Contenuto non trovato.', ['status' => 404]);
    }
    return rest_ensure_response([
        'id'         => $id,
        'title'      => get_the_title($post),
        'type_label' => tm_type_label($post->post_type),
        'date'       => mysql2date('j F Y', $post->post_date),
        'content'    => apply_filters('the_content', $post->post_content),
    ]);
}

add_action('rest_api_init', function () {
    $auth = fn() => tm_can_access_turni();

    register_rest_route('turnimedici/v1', '/me/info', [
        'methods'             => 'GET',
        'callback'            => 'tm_rest_info_list',
        'permission_callback' => $auth,
    ]);
    register_rest_route('turnimedici/v1', '/me/info/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'tm_rest_info_single',
        'permission_callback' => $auth,
    ]);
});
