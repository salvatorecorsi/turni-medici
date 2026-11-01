<?php

if (!defined('ABSPATH')) exit;

add_action('add_meta_boxes', function () {
    add_meta_box('tm_aft_details', 'Dettagli AFT', 'tm_aft_details_box', 'tm_aft', 'normal', 'high');
});

function tm_aft_details_box($post) {
    wp_nonce_field('tm_aft_details', 'tm_aft_details_nonce');
    $fields = [
        'tm_aft_area'    => ['Area / zona', 'text', 'es. Zona Nord'],
        'tm_aft_manager' => ['Responsabile', 'text', 'es. Dott. Mario Rossi'],
        'tm_aft_phone'   => ['Telefono', 'text', 'es. 0583 123456'],
        'tm_aft_email'   => ['Email', 'email', 'es. aft@example.invalid'],
    ];
    echo '<div style="display:grid;gap:14px;max-width:560px">';
    foreach ($fields as $key => $field) {
        $val = esc_attr(get_post_meta($post->ID, $key, true));
        printf(
            '<label style="display:block"><strong>%s</strong><br><input type="%s" name="%s" value="%s" placeholder="%s" class="widefat"></label>',
            esc_html($field[0]),
            esc_attr($field[1]),
            esc_attr($key),
            $val,
            esc_attr($field[2])
        );
    }
    echo '</div>';
}

add_action('save_post_tm_aft', function ($post_id) {
    if (!isset($_POST['tm_aft_details_nonce']) || !wp_verify_nonce($_POST['tm_aft_details_nonce'], 'tm_aft_details')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    foreach (['tm_aft_area', 'tm_aft_manager', 'tm_aft_phone', 'tm_aft_email'] as $key) {
        if (isset($_POST[$key])) {
            $value = $key === 'tm_aft_email'
                ? sanitize_email(wp_unslash($_POST[$key]))
                : sanitize_text_field(wp_unslash($_POST[$key]));
            update_post_meta($post_id, $key, $value);
        }
    }
});
