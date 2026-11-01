<?php

if (!defined('ABSPATH')) exit;

function tm_register_doctor_role() {
    add_role('tm_doctor', 'Medico', ['read' => true]);
}

function tm_allowed_doctor_qualifications() {
    return ['mmg', 'ruap_volontario', 'ruap_vincolato', 'ruap_corsista', 'ca_titolare', 'ca_temporaneo'];
}

/* Massimali ACN: 1.500 ordinario, 1.000 per i corsisti con incarico convenzionale temporaneo */
function tm_doctor_max_patients($qualification) {
    return $qualification === 'ruap_corsista' ? 1000 : 1500;
}

function tm_valid_doctor_aft($aft_id) {
    $aft = $aft_id ? get_post((int) $aft_id) : null;
    return $aft && $aft->post_type === 'tm_aft' && in_array($aft->post_status, ['publish', 'draft'], true);
}

function tm_doctor_professional_data_complete($user_id) {
    $qualification = (string) get_user_meta((int) $user_id, 'tm_qualification', true);
    if (!in_array($qualification, tm_allowed_doctor_qualifications(), true)) return false;
    if ($qualification === 'ca_temporaneo') return true;
    return tm_valid_doctor_aft((int) get_user_meta((int) $user_id, 'tm_home_aft', true));
}

function tm_user_is_turnazione_doctor($user_id) {
    $user_id = (int) $user_id;
    $user = $user_id ? get_userdata($user_id) : null;
    if (!$user) return false;
    if (in_array('tm_doctor', (array) $user->roles, true)) return true;
    if (get_user_meta($user_id, 'tm_doctor_enabled', true)) return true;
    return false;
}

function tm_turnazione_doctor_ids() {
    $ids = array_map('intval', get_users(['role' => 'tm_doctor', 'fields' => 'ID']));
    $ids = array_merge($ids, array_map('intval', get_users(['meta_key' => 'tm_doctor_enabled', 'fields' => 'ID'])));
    return array_values(array_unique(array_filter($ids)));
}

add_action('admin_init', 'tm_block_doctor_admin_access');
function tm_block_doctor_admin_access() {
    if (wp_doing_ajax()) return;
    $user = wp_get_current_user();
    if (function_exists('tm_user_is_coordinator') && tm_user_is_coordinator()) return;
    if (in_array('tm_doctor', (array) $user->roles, true) && !current_user_can('edit_users')) {
        wp_safe_redirect(home_url('/'));
        exit;
    }
}

add_action('show_user_profile', 'tm_render_doctor_role_field');
add_action('edit_user_profile', 'tm_render_doctor_role_field');
function tm_render_doctor_role_field($user) {
    if (!current_user_can('edit_users')) return;
    $is_doctor = in_array('tm_doctor', (array) $user->roles, true) || (bool) get_user_meta($user->ID, 'tm_doctor_enabled', true);
    $qualification = get_user_meta($user->ID, 'tm_qualification', true);
    $home_aft = function_exists('tm_doctor_home_aft_id') ? tm_doctor_home_aft_id($user->ID) : (int) get_user_meta($user->ID, 'tm_home_aft', true);
    $afts = get_posts(['post_type' => 'tm_aft', 'numberposts' => -1, 'post_status' => ['publish', 'draft'], 'orderby' => 'title', 'order' => 'ASC']);
    $qualifications = [
        'mmg'             => 'MMG',
        'ruap_volontario' => 'RUAP volontario',
        'ruap_vincolato'  => 'RUAP vincolato',
        'ruap_corsista'   => 'Corsista RUAP',
        'ca_titolare'     => 'CA Titolare',
        'ca_temporaneo'   => 'CA Temporaneo',
    ];
    wp_nonce_field('tm_is_doctor', 'tm_is_doctor_nonce');
    ?>
    <h2>Turnazione medici</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="tm_is_doctor">Medico in turnazione</label></th>
            <td>
                <label>
                    <input type="checkbox" name="tm_is_doctor" id="tm_is_doctor" value="1" <?php checked($is_doctor); ?>>
                    Abilita questo utente come medico in turnazione (ruolo aggiuntivo, non sostituisce quello principale).
                </label>
                <p class="description">Permette a un amministratore di essere anche medico. Qualifica, AFT e assistiti sono dati del medico, distinti dal coordinamento orario.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="tm_qualification">Qualifica medico</label></th>
            <td>
                <select name="tm_qualification" id="tm_qualification">
                    <option value="">— Da configurare —</option>
                    <?php foreach ($qualifications as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($qualification, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="tm_home_aft">AFT medico</label></th>
            <td>
                <select name="tm_home_aft" id="tm_home_aft">
                    <option value="">— Nessuna AFT —</option>
                    <?php foreach ($afts as $aft) : ?>
                        <option value="<?php echo (int) $aft->ID; ?>" <?php selected($home_aft, $aft->ID); ?>><?php echo esc_html($aft->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description">È l'AFT di appartenenza del medico. È diversa dalla AFT coordinata.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="tm_phone">Telefono medico</label></th>
            <td><input type="text" name="tm_phone" id="tm_phone" value="<?php echo esc_attr(get_user_meta($user->ID, 'tm_phone', true)); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th scope="row"><label for="tm_patients">Assistiti</label></th>
            <td><input type="number" name="tm_patients" id="tm_patients" value="<?php echo (int) get_user_meta($user->ID, 'tm_patients', true); ?>" class="small-text" min="0"></td>
        </tr>
        <tr>
            <th scope="row"><label for="tm_weekly_hours">Ore settimanali da coprire</label></th>
            <td>
                <input type="number" name="tm_weekly_hours" id="tm_weekly_hours" value="<?php echo esc_attr(tm_doctor_weekly_hours($user->ID)); ?>" class="small-text" min="0" step="0.5">
                <p class="description">Nel mese in coda vale il quadruplo (es. 12h/settimana &rarr; 48h nel mese).</p>
            </td>
        </tr>
    </table>
    <?php
}

/* Su profile_update, non su edit_user_profile_update: quegli hook girano PRIMA di
   wp_update_user, il cui set_role($_POST['role']) azzera i ruoli aggiuntivi appena messi. */
add_action('profile_update', 'tm_save_doctor_role_field');
add_action('user_profile_update_errors', 'tm_validate_doctor_role_fields', 10, 3);
function tm_validate_doctor_role_fields($errors, $update, $user) {
    if (!current_user_can('edit_users') || empty($_POST['tm_is_doctor'])) return;
    if (!isset($_POST['tm_is_doctor_nonce']) || !wp_verify_nonce($_POST['tm_is_doctor_nonce'], 'tm_is_doctor')) return;

    $qualification = sanitize_key(wp_unslash($_POST['tm_qualification'] ?? ''));
    if (!in_array($qualification, tm_allowed_doctor_qualifications(), true)) {
        $errors->add('tm_qualification_required', 'Scegli una qualifica valida per il medico.');
    }
    $home_aft = absint($_POST['tm_home_aft'] ?? 0);
    if ($qualification !== 'ca_temporaneo' && !tm_valid_doctor_aft($home_aft)) {
        $errors->add('tm_home_aft_required', 'Scegli l’AFT di appartenenza del medico.');
    }
}

function tm_save_doctor_role_field($user_id) {
    if (!current_user_can('edit_users')) return;
    if (!isset($_POST['tm_is_doctor_nonce']) || !wp_verify_nonce($_POST['tm_is_doctor_nonce'], 'tm_is_doctor')) return;
    $user = get_userdata($user_id);
    if (!$user) return;
    $want = !empty($_POST['tm_is_doctor']);
    $has = in_array('tm_doctor', (array) $user->roles, true);
    if ($want) {
        update_user_meta($user_id, 'tm_doctor_enabled', 1);
        $qualification = sanitize_key($_POST['tm_qualification'] ?? '');
        $allowed = tm_allowed_doctor_qualifications();
        if (!in_array($qualification, $allowed, true)) $qualification = '';

        $home_aft = absint($_POST['tm_home_aft'] ?? 0);
        if ($qualification === 'ca_temporaneo' || !tm_valid_doctor_aft($home_aft)) $home_aft = 0;

        update_user_meta($user_id, 'tm_qualification', $qualification);
        update_user_meta($user_id, 'tm_phone', sanitize_text_field(wp_unslash($_POST['tm_phone'] ?? '')));
        update_user_meta($user_id, 'tm_patients', absint($_POST['tm_patients'] ?? 0));
        update_user_meta($user_id, 'tm_weekly_hours', tm_sanitize_weekly_hours($_POST['tm_weekly_hours'] ?? 0));
        if ($home_aft) update_user_meta($user_id, 'tm_home_aft', $home_aft);
        else delete_user_meta($user_id, 'tm_home_aft');
    }
    if ($want && !$has) {
        $user->add_role('tm_doctor');
    } elseif (!$want && $has) {
        delete_user_meta($user_id, 'tm_doctor_enabled');
        $user->remove_role('tm_doctor');
        $fresh = get_userdata($user_id);
        if ($fresh && empty($fresh->roles)) $fresh->add_role('subscriber');
    } elseif (!$want) {
        delete_user_meta($user_id, 'tm_doctor_enabled');
    }
}
