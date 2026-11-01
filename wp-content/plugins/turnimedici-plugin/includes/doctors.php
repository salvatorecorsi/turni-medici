<?php

if (!defined('ABSPATH')) exit;

function tm_sanitize_weekly_hours($value) {
    return min(999, max(0, round(((float) $value) * 2) / 2));
}

function tm_doctor_weekly_hours($user_id) {
    return (float) get_user_meta($user_id, 'tm_weekly_hours', true);
}

/* Il monte ore si esprime in ore settimanali; l'obbligo del mese in coda è settimanali × 4 */
function tm_doctor_monthly_target($user_id) {
    return tm_doctor_weekly_hours($user_id) * 4;
}

/* Quote corsista: fino a 650 assistiti 6h/sett obbligatorie (fino a 18 facoltative), oltre nessun obbligo */
function tm_corsista_mandatory_weekly_hours($patients) {
    return ((int) $patients) <= 650 ? 6.0 : 0.0;
}

function tm_doctor_is_mandatory($doctor_id) {
    $qualification = (string) get_user_meta((int) $doctor_id, 'tm_qualification', true);
    if ($qualification === 'ruap_vincolato') return true;
    if ($qualification === 'ruap_corsista') {
        return tm_corsista_mandatory_weekly_hours(get_user_meta((int) $doctor_id, 'tm_patients', true)) > 0;
    }
    return false;
}

const TM_WEEKLY_HOURS_VERSION = 1;
add_action('init', 'tm_migrate_weekly_hours', 30);
function tm_migrate_weekly_hours() {
    if ((int) get_option('tm_weekly_hours_version') === TM_WEEKLY_HOURS_VERSION) return;
    foreach (get_users(['meta_key' => 'tm_monthly_hours', 'fields' => 'ID']) as $user_id) {
        if (metadata_exists('user', $user_id, 'tm_weekly_hours')) continue;
        $monthly = (float) get_user_meta($user_id, 'tm_monthly_hours', true);
        update_user_meta($user_id, 'tm_weekly_hours', tm_sanitize_weekly_hours($monthly / 4));
    }
    update_option('tm_weekly_hours_version', TM_WEEKLY_HOURS_VERSION, false);
}

function tm_register_doctor_meta() {
    $fields = [
        'tm_qualification' => 'sanitize_key',
        'tm_home_aft'      => 'absint',
        'tm_patients'      => 'absint',
        'tm_role_start'    => 'sanitize_text_field',
        'tm_phone'         => 'sanitize_text_field',
        'tm_gender'        => 'tm_sanitize_gender',
        'tm_weekly_hours'  => 'tm_sanitize_weekly_hours',
        'tm_preferred_facility' => 'absint',
    ];

    $integer_fields = ['tm_home_aft', 'tm_patients', 'tm_preferred_facility'];

    foreach ($fields as $key => $sanitize) {
        register_meta('user', $key, [
            'type'              => $key === 'tm_weekly_hours' ? 'number' : (in_array($key, $integer_fields, true) ? 'integer' : 'string'),
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => $sanitize,
            'auth_callback'     => fn() => tm_current_user_can_manage_doctors(),
        ]);
    }
}

add_action('admin_menu', 'tm_doctors_menu', 30);
function tm_doctors_menu() {
    $label = 'Medici';
    $count = function_exists('tm_count_pending_requests') ? tm_count_pending_requests() : 0;
    if ($count > 0) {
        $label .= ' <span class="awaiting-mod"><span class="pending-count">' . $count . '</span></span>';
    }
    add_submenu_page('users.php', 'Medici', $label, 'tm_coordinate_hours', 'tm-doctors', 'tm_doctors_page');
    if (!current_user_can('edit_users')) {
        add_submenu_page('tm-schedules', 'Medici', $label, 'tm_coordinate_hours', 'tm-doctors', 'tm_doctors_page');
    }
}

add_action('admin_menu', 'tm_users_menu_bubble', 999);
function tm_users_menu_bubble() {
    global $menu;
    $count = function_exists('tm_count_pending_requests') ? tm_count_pending_requests() : 0;
    if (!$count) return;
    foreach ($menu as $key => $item) {
        if (isset($item[2]) && $item[2] === 'users.php') {
            $menu[$key][0] .= ' <span class="awaiting-mod"><span class="pending-count">' . $count . '</span></span>';
            break;
        }
    }
}

function tm_doctors_page() {
    $sync = current_user_can('edit_users') ? '<button type="button" id="tm-estar-sync-imported" class="button"' . disabled(tm_demo_mode(), true, false) . '>Allinea con ESTAR</button>' : '';
    echo '<div class="wrap"><div class="tm-doctors-head"><h1 class="wp-heading-inline">Medici</h1><div class="tm-doctors-head__actions">' . $sync . '<div id="tm-estar-toggle"></div></div></div><div id="tm-doctors-app"></div></div>';
}

function tm_doctors_estar_sync_script() {
    return <<<'JS'
(() => {
    const button = document.getElementById('tm-estar-sync-imported');
    const config = window.tmDoctors || {};
    if (!button || !config.restUrl || !config.nonce || config.demoMode) return;

    const idleLabel = button.textContent;
    const setBusy = (busy) => {
        button.disabled = busy;
        button.classList.toggle('is-busy', busy);
        button.textContent = busy ? 'Allineamento...' : idleLabel;
    };

    button.addEventListener('click', async () => {
        if (!window.confirm('Allineare nome, cognome, telefono, assistiti e AFT dei medici con i dati ESTAR? Senza ID ESTAR userò solo match univoci nome+cognome.')) return;

        setBusy(true);
        try {
            const response = await fetch(config.restUrl + 'estar-sync-imported', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
            });
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data?.message || 'Allineamento non riuscito');
            }

            const matchedByName = data.matched_by_name ? ` Match per nome: ${data.matched_by_name}.` : '';
            const missing = data.missing ? ` Non trovati nel dump: ${data.missing}.` : '';
            const ambiguous = data.ambiguous ? ` Match ambigui saltati: ${data.ambiguous}.` : '';
            const unmatchedAfts = data.unmatched_afts ? ` AFT non abbinate: ${data.unmatched_afts}.` : '';
            window.alert(`Controllati ${data.checked} medici. Aggiornati ${data.updated}, già allineati ${data.unchanged}.${matchedByName}${missing}${ambiguous}${unmatchedAfts}`);
            window.location.reload();
        } catch (error) {
            window.alert(error.message || 'Allineamento non riuscito');
        } finally {
            setBusy(false);
        }
    });
})();
JS;
}

add_action('admin_enqueue_scripts', 'tm_doctors_enqueue');
function tm_doctors_enqueue($hook) {
    if (sanitize_key($_GET['page'] ?? '') !== 'tm-doctors') return;

    $asset_path = TM_DIR . 'app/build/doctors.asset.php';
    if (!file_exists($asset_path)) return;

    $asset = include $asset_path;
    $js_path = TM_DIR . 'app/build/doctors.js';
    wp_enqueue_script(
        'tm-doctors',
        TM_URL . 'app/build/doctors.js',
        $asset['dependencies'],
        file_exists($js_path) ? (string) filemtime($js_path) : $asset['version'],
        true
    );
    wp_enqueue_style('wp-components');
    wp_enqueue_style('dashicons');
    $css = TM_DIR . 'assets/doctors.css';
    if (file_exists($css)) {
        wp_enqueue_style('tm-doctors', TM_URL . 'assets/doctors.css', ['wp-components'], filemtime($css));
    }
    $sched_css = TM_DIR . 'app/build/doctors.css';
    if (file_exists($sched_css)) {
        wp_enqueue_style('tm-ambulatory-schedule', TM_URL . 'app/build/doctors.css', ['wp-components'], filemtime($sched_css));
    }
    wp_localize_script('tm-doctors', 'tmDoctors', [
        'restUrl'        => rest_url('turnimedici/v1/'),
        'nonce'          => wp_create_nonce('wp_rest'),
        'faviconUrl'     => TM_URL . 'assets/estar-favicon.ico',
        'canEditAll'     => current_user_can('edit_users'),
        'demoMode'       => tm_demo_mode(),
        'coordinatedAft' => function_exists('tm_coordinator_aft_id') ? tm_coordinator_aft_id() : 0,
    ]);
    wp_add_inline_script('tm-doctors', tm_doctors_estar_sync_script(), 'after');
}
