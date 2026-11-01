<?php

if (!defined('ABSPATH')) exit;

const TM_COORDINATOR_ROLE = 'tm_hours_coordinator';
const TM_COORDINATOR_CAPS_VERSION = 2;

function tm_schedule_capabilities() {
    return [
        'edit_tm_schedules',
        'edit_others_tm_schedules',
        'edit_published_tm_schedules',
        'edit_private_tm_schedules',
        'publish_tm_schedules',
        'read_private_tm_schedules',
        'delete_tm_schedules',
        'delete_others_tm_schedules',
        'delete_published_tm_schedules',
        'delete_private_tm_schedules',
    ];
}

add_action('init', 'tm_sync_coordinator_caps', 20);
function tm_sync_coordinator_caps() {
    if ((int) get_option('tm_coordinator_caps_version') === TM_COORDINATOR_CAPS_VERSION) return;

    if (!get_role(TM_COORDINATOR_ROLE)) {
        add_role(TM_COORDINATOR_ROLE, 'Coordinatore orario', ['read' => true]);
    }

    $delete_caps = ['delete_tm_schedules', 'delete_others_tm_schedules', 'delete_published_tm_schedules', 'delete_private_tm_schedules'];

    if ($coordinator = get_role(TM_COORDINATOR_ROLE)) {
        $coordinator->add_cap('tm_coordinate_hours');
        foreach (array_diff(tm_schedule_capabilities(), $delete_caps) as $cap) {
            $coordinator->add_cap($cap);
        }
    }

    if ($admin = get_role('administrator')) {
        $admin->add_cap('tm_coordinate_hours');
        foreach (tm_schedule_capabilities() as $cap) {
            $admin->add_cap($cap);
        }
    }

    update_option('tm_coordinator_caps_version', TM_COORDINATOR_CAPS_VERSION);
}

function tm_user_is_coordinator($user_id = 0) {
    $user = $user_id ? get_userdata($user_id) : wp_get_current_user();
    return $user && $user->ID && in_array(TM_COORDINATOR_ROLE, (array) $user->roles, true);
}

function tm_coordinator_aft_id($user_id = 0) {
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id || !tm_user_is_coordinator($user_id)) return 0;
    return (int) get_user_meta($user_id, 'tm_coordinated_aft', true);
}

function tm_coordinator_facility_id($user_id = 0) {
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id || !tm_user_is_coordinator($user_id)) return 0;

    $facility_id = (int) get_user_meta($user_id, 'tm_coordinated_facility', true);
    if (!$facility_id || get_post_type($facility_id) !== 'tm_facility') return 0;

    $aft_id = tm_coordinator_aft_id($user_id);
    if (!$aft_id || (int) get_post_meta($facility_id, 'tm_aft_id', true) !== $aft_id) return 0;

    return $facility_id;
}

function tm_doctor_home_aft_id($doctor_id) {
    $doctor_id = (int) $doctor_id;
    $home_aft = (int) get_user_meta($doctor_id, 'tm_home_aft', true);
    if ($home_aft > 0 && function_exists('tm_valid_doctor_aft') && tm_valid_doctor_aft($home_aft)) return $home_aft;
    return 0;
}

function tm_can_manage_scheduling() {
    return current_user_can('edit_users') || tm_coordinator_aft_id() > 0;
}

function tm_user_manages_facility($facility_id, $user_id = 0) {
    $facility_id = (int) $facility_id;
    if (!$facility_id) return false;

    if ($user_id ? user_can($user_id, 'edit_users') : current_user_can('edit_users')) return true;

    $aft_id = tm_coordinator_aft_id($user_id);
    if (!$aft_id || (int) get_post_meta($facility_id, 'tm_aft_id', true) !== $aft_id) return false;

    $scoped_facility = tm_coordinator_facility_id($user_id);
    return !$scoped_facility || $scoped_facility === $facility_id;
}

function tm_user_manages_schedule($schedule_id) {
    $facility_id = (int) get_post_meta((int) $schedule_id, 'tm_facility_id', true);
    if (!$facility_id) return current_user_can('edit_users');
    return tm_user_manages_facility($facility_id);
}

function tm_facility_has_aft($facility_id) {
    $aft_id = (int) get_post_meta((int) $facility_id, 'tm_aft_id', true);
    return $aft_id > 0 && get_post_type($aft_id) === 'tm_aft';
}

function tm_doctor_has_aft($doctor_id) {
    return tm_doctor_home_aft_id($doctor_id) > 0;
}

function tm_doctor_is_ca($doctor_id) {
    $qualification = get_user_meta((int) $doctor_id, 'tm_qualification', true);
    return strpos((string) $qualification, 'ca_') === 0;
}

function tm_doctor_is_ca_temporaneo($doctor_id) {
    return get_user_meta((int) $doctor_id, 'tm_qualification', true) === 'ca_temporaneo';
}

function tm_doctor_is_active_for_turnazione($doctor_id, $period = '') {
    $qualification = get_user_meta((int) $doctor_id, 'tm_qualification', true);
    if ($qualification !== 'ca_temporaneo') return true;
    if (!function_exists('tm_get_ca_periods')) return true;

    if (is_array($period)) {
        $start = sanitize_text_field($period['start'] ?? '');
        $end = sanitize_text_field($period['end'] ?? $start);
    } else {
        $start = sanitize_text_field($period ?: current_time('Y-m-d'));
        $end = $start;
    }

    if (!$start || !$end) return false;
    foreach (tm_get_ca_periods((int) $doctor_id) as $row) {
        $row_start = sanitize_text_field($row['start'] ?? '');
        $row_end = sanitize_text_field($row['end'] ?? '');
        if ($row_start && $row_end && $row_start <= $end && $row_end >= $start) return true;
    }
    return false;
}

function tm_doctor_is_visible_in_frontend($doctor_id, $period = '') {
    if (!tm_doctor_professional_data_complete($doctor_id)) return false;
    return tm_doctor_is_active_for_turnazione($doctor_id, $period);
}

function tm_aft_facility_ids($aft_id) {
    return array_map('intval', get_posts([
        'post_type'   => 'tm_facility',
        'numberposts' => -1,
        'post_status' => 'publish',
        'fields'      => 'ids',
        'meta_key'    => 'tm_aft_id',
        'meta_value'  => (int) $aft_id,
    ]));
}

function tm_coordinator_facility_ids($user_id = 0) {
    $facility_id = tm_coordinator_facility_id($user_id);
    if ($facility_id) return [$facility_id];

    $aft_id = tm_coordinator_aft_id($user_id);
    return $aft_id ? tm_aft_facility_ids($aft_id) : [];
}

add_action('show_user_profile', 'tm_render_coordinator_role_field');
add_action('edit_user_profile', 'tm_render_coordinator_role_field');
function tm_render_coordinator_role_field($user) {
    if (!current_user_can('edit_users')) return;
    $is_coordinator = in_array(TM_COORDINATOR_ROLE, (array) $user->roles, true);
    $current_aft = (int) get_user_meta($user->ID, 'tm_coordinated_aft', true);
    $current_facility = (int) get_user_meta($user->ID, 'tm_coordinated_facility', true);
    $afts = get_posts(['post_type' => 'tm_aft', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
    $facilities = get_posts(['post_type' => 'tm_facility', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
    wp_nonce_field('tm_is_coordinator', 'tm_is_coordinator_nonce');
    ?>
    <h2>Coordinamento orario</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="tm_is_coordinator">Coordinatore orario</label></th>
            <td>
                <label>
                    <input type="checkbox" name="tm_is_coordinator" id="tm_is_coordinator" value="1" <?php checked($is_coordinator); ?>>
                    Abilita questo utente come coordinatore orario (ruolo aggiuntivo, non sostituisce quello principale).
                </label>
                <p class="description">Gestisce turnazione, assegnazioni e link di accesso dei medici della sua AFT dall'app; nel backend accede alla sola sezione Turni.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="tm_coordinated_aft">AFT coordinata</label></th>
            <td>
                <select name="tm_coordinated_aft" id="tm_coordinated_aft">
                    <option value="">— Seleziona AFT —</option>
                    <?php foreach ($afts as $aft) : ?>
                        <option value="<?php echo (int) $aft->ID; ?>" <?php selected($current_aft, $aft->ID); ?>><?php echo esc_html($aft->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Obbligatoria per i coordinatori: delimita le strutture e i turni gestibili.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="tm_coordinated_facility">Struttura coordinata</label></th>
            <td>
                <select name="tm_coordinated_facility" id="tm_coordinated_facility">
                    <option value="">Tutte le strutture dell'AFT selezionata</option>
                    <?php foreach ($facilities as $facility) :
                        if (function_exists('tm_facility_in_turnazione') && !tm_facility_in_turnazione($facility->ID)) continue;
                        $facility_aft_id = (int) get_post_meta($facility->ID, 'tm_aft_id', true);
                        $facility_aft = $facility_aft_id ? get_post($facility_aft_id) : null;
                        $label = trim(($facility_aft ? preg_replace('/^AFT\s+/i', '', $facility_aft->post_title) . ' — ' : '') . $facility->post_title);
                        ?>
                        <option value="<?php echo (int) $facility->ID; ?>" <?php selected($current_facility, $facility->ID); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Opzionale: se impostata, il coordinatore potrà lavorare solo sui turni di questa struttura. Lascia vuoto per tutta l'AFT.</p>
            </td>
        </tr>
    </table>
    <?php
}

add_action('user_profile_update_errors', 'tm_validate_coordinator_fields', 10, 3);
function tm_validate_coordinator_fields($errors, $update, $user) {
    if (!current_user_can('edit_users')) return;
    if (!isset($_POST['tm_is_coordinator_nonce']) || !wp_verify_nonce($_POST['tm_is_coordinator_nonce'], 'tm_is_coordinator')) return;
    if (!empty($_POST['tm_is_coordinator']) && empty($_POST['tm_coordinated_aft'])) {
        $errors->add('tm_coordinated_aft', '<strong>Errore:</strong> seleziona l&rsquo;AFT coordinata per abilitare il Coordinatore orario.');
    }

    $aft_id = (int) ($_POST['tm_coordinated_aft'] ?? 0);
    $facility_id = (int) ($_POST['tm_coordinated_facility'] ?? 0);
    if (!empty($_POST['tm_is_coordinator']) && $facility_id) {
        if (get_post_type($facility_id) !== 'tm_facility') {
            $errors->add('tm_coordinated_facility', '<strong>Errore:</strong> seleziona una struttura valida.');
        } elseif (!$aft_id || (int) get_post_meta($facility_id, 'tm_aft_id', true) !== $aft_id) {
            $errors->add('tm_coordinated_facility', '<strong>Errore:</strong> la struttura scelta deve appartenere all&rsquo;AFT coordinata.');
        }
    }
}

/* Su profile_update come tm_save_doctor_role_field: dopo il set_role di wp_update_user */
add_action('profile_update', 'tm_save_coordinator_fields');
function tm_save_coordinator_fields($user_id) {
    if (!current_user_can('edit_users')) return;
    if (!isset($_POST['tm_is_coordinator_nonce']) || !wp_verify_nonce($_POST['tm_is_coordinator_nonce'], 'tm_is_coordinator')) return;
    $user = get_userdata($user_id);
    if (!$user) return;

    $aft_id = (int) ($_POST['tm_coordinated_aft'] ?? 0);
    if ($aft_id && get_post_type($aft_id) !== 'tm_aft') $aft_id = 0;

    $facility_id = (int) ($_POST['tm_coordinated_facility'] ?? 0);
    if ($facility_id && (get_post_type($facility_id) !== 'tm_facility' || !$aft_id || (int) get_post_meta($facility_id, 'tm_aft_id', true) !== $aft_id)) {
        $facility_id = 0;
    }

    if ($aft_id) {
        update_user_meta($user_id, 'tm_coordinated_aft', $aft_id);
    } else {
        delete_user_meta($user_id, 'tm_coordinated_aft');
    }

    if ($facility_id) {
        update_user_meta($user_id, 'tm_coordinated_facility', $facility_id);
    } else {
        delete_user_meta($user_id, 'tm_coordinated_facility');
    }

    $want = !empty($_POST['tm_is_coordinator']) && $aft_id > 0;
    $has = in_array(TM_COORDINATOR_ROLE, (array) $user->roles, true);
    if ($want && !$has) {
        $user->add_role(TM_COORDINATOR_ROLE);
    } elseif (!$want && $has) {
        $user->remove_role(TM_COORDINATOR_ROLE);
        $fresh = get_userdata($user_id);
        if ($fresh && empty($fresh->roles)) $fresh->add_role('subscriber');
    }
}

add_filter('map_meta_cap', 'tm_scope_coordinator_schedule_caps', 10, 4);
function tm_scope_coordinator_schedule_caps($caps, $cap, $user_id, $args) {
    if (!in_array($cap, ['edit_post', 'delete_post', 'read_post'], true) || empty($args[0])) return $caps;
    $post = get_post($args[0]);
    if (!$post || $post->post_type !== 'tm_schedule') return $caps;
    if (user_can($user_id, 'edit_users') || !tm_user_is_coordinator($user_id)) return $caps;

    $aft_id = (int) get_user_meta($user_id, 'tm_coordinated_aft', true);
    $facility_id = (int) get_post_meta($post->ID, 'tm_facility_id', true);
    $in_scope = !$facility_id || ($aft_id > 0 && tm_user_manages_facility($facility_id, $user_id));
    if (!$in_scope) $caps[] = 'do_not_allow';
    return $caps;
}

/* Il coordinatore può agganciare un turno solo a strutture della sua AFT */
function tm_guard_schedule_facility_meta($check, $object_id, $meta_key, $meta_value) {
    if ($meta_key !== 'tm_facility_id' || get_post_type($object_id) !== 'tm_schedule') return $check;
    if (!is_user_logged_in() || current_user_can('edit_users') || !tm_user_is_coordinator()) return $check;
    return tm_user_manages_facility((int) $meta_value) ? $check : false;
}
add_filter('update_post_metadata', 'tm_guard_schedule_facility_meta', 10, 4);
add_filter('add_post_metadata', 'tm_guard_schedule_facility_meta', 10, 4);

add_action('pre_get_posts', function ($query) {
    if (!is_admin() || !$query->is_main_query()) return;
    if ($query->get('post_type') !== 'tm_schedule') return;
    if (current_user_can('edit_users') || !tm_user_is_coordinator()) return;

    $facility_ids = tm_coordinator_facility_ids();
    $meta_query = (array) $query->get('meta_query');
    $meta_query[] = ['key' => 'tm_facility_id', 'value' => $facility_ids ?: [0], 'compare' => 'IN'];
    $query->set('meta_query', $meta_query);
});

function tm_coordinator_admin_request_allowed() {
    global $pagenow;
    if (in_array($pagenow, ['admin-post.php', 'profile.php'], true)) return true;
    return false;
}

add_action('admin_init', 'tm_limit_coordinator_admin_access', 5);
function tm_limit_coordinator_admin_access() {
    if (wp_doing_ajax()) return;
    if (current_user_can('edit_users') || !tm_user_is_coordinator()) return;
    if (tm_coordinator_admin_request_allowed()) return;
    wp_safe_redirect(function_exists('tm_turni_url') ? tm_turni_url('') : home_url('/'));
    exit;
}

add_action('admin_menu', function () {
    if (current_user_can('edit_users') || !tm_user_is_coordinator()) return;
    remove_menu_page('index.php');
}, 99);

/* Medici visibili a chi gestisce: tutti per gli admin, quelli della propria AFT per i coordinatori.
   Espone il magic link in sola copia; la generazione avviene solo se manca o è scaduto. */
function tm_managed_doctor_users() {
    $ids = function_exists('tm_turnazione_doctor_ids') ? tm_turnazione_doctor_ids() : get_users(['role' => 'tm_doctor', 'fields' => 'ID']);
    $users = array_values(array_filter(array_map('get_userdata', $ids)));
    usort($users, fn($a, $b) => strcasecmp($a->display_name, $b->display_name));
    $users = array_values(array_filter($users, fn($u) => tm_doctor_is_visible_in_frontend($u->ID)));
    if (current_user_can('edit_users')) return $users;
    $aft_id = tm_coordinator_aft_id();
    return array_values(array_filter($users, fn($u) => tm_doctor_is_ca_temporaneo($u->ID) || tm_doctor_home_aft_id($u->ID) === $aft_id));
}

function tm_rest_managed_doctors() {
    $doctors = array_map(function ($user) {
        $qualification = get_user_meta($user->ID, 'tm_qualification', true);
        return [
            'id'            => (int) $user->ID,
            'name'          => trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name,
            'qualification' => tm_qualification_label($qualification),
            'email'         => $user->user_email,
            'phone'         => get_user_meta($user->ID, 'tm_phone', true),
            'has_magic_link' => get_user_meta($user->ID, 'tm_magic_token', true) !== '',
        ];
    }, tm_managed_doctor_users());
    return rest_ensure_response($doctors);
}

/* Autorizzazione alla condivisione del link senza vincolo temporale: la graduatoria
   mensile mostra anche CA con contratti futuri, il loro link resta condivisibile */
function tm_can_share_doctor_link($doctor_id) {
    if (!tm_user_is_turnazione_doctor($doctor_id)) return false;
    if (current_user_can('edit_users')) return true;
    $aft_id = tm_coordinator_aft_id();
    return $aft_id > 0 && (tm_doctor_is_ca_temporaneo($doctor_id) || tm_doctor_home_aft_id($doctor_id) === $aft_id);
}

function tm_rest_managed_doctor_link($request) {
    $doctor_id = (int) $request['id'];
    if (!tm_can_share_doctor_link($doctor_id)) {
        return new WP_Error('tm_forbidden', 'Medico fuori dalla tua AFT.', ['status' => 403]);
    }
    $doctor = get_userdata($doctor_id);
    if (!$doctor) {
        return new WP_Error('tm_not_found', 'Medico non trovato.', ['status' => 404]);
    }
    tm_ensure_valid_magic_token($doctor_id);
    $facility_id = absint($request->get_param('facility'));
    $facility_name = ($facility_id && get_post_type($facility_id) === 'tm_facility') ? get_the_title($facility_id) : '';
    $month = tm_requested_ranking_month(absint($request->get_param('schedule')));
    $message = tm_render_magic_message(tm_magic_message_template(), $doctor, $facility_name, tm_month_label(tm_month_value($month)));
    return rest_ensure_response([
        'magic_url' => tm_doctor_magic_url($doctor_id),
        'message'   => $message,
    ]);
}

add_action('rest_api_init', function () {
    $manage = fn() => tm_can_manage_scheduling();
    register_rest_route('turnimedici/v1', '/managed-doctors', [
        'methods'             => 'GET',
        'callback'            => 'tm_rest_managed_doctors',
        'permission_callback' => $manage,
    ]);
    register_rest_route('turnimedici/v1', '/managed-doctors/(?P<id>\d+)/link', [
        'methods'             => 'POST',
        'callback'            => 'tm_rest_managed_doctor_link',
        'permission_callback' => $manage,
    ]);
});
