<?php
/**
 * Plugin Name: Turni Medici
 * Description: Demo di gestione strutture e turnazione con Define Blocks e Core Editor.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.6
 * Requires Plugins: define-blocks
 * Author: Salvatore Corsi
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: turnimedici
 */

if (!defined('ABSPATH')) exit;

define('TM_VERSION', '0.1.0');
define('TM_DIR', plugin_dir_path(__FILE__));
define('TM_URL', plugin_dir_url(__FILE__));
show_admin_bar(false);

require_once TM_DIR . 'includes/demo.php';
require_once TM_DIR . 'includes/multidomain.php';
require_once TM_DIR . 'includes/post-types.php';
require_once TM_DIR . 'includes/taxonomies.php';
require_once TM_DIR . 'includes/meta.php';
require_once TM_DIR . 'includes/roles.php';
require_once TM_DIR . 'includes/coordinator.php';
require_once TM_DIR . 'includes/doctors.php';
require_once TM_DIR . 'includes/doctors-rest.php';
require_once TM_DIR . 'includes/graduatoria-import.php';
require_once TM_DIR . 'includes/estar-rest.php';
require_once TM_DIR . 'includes/magic-login.php';
require_once TM_DIR . 'includes/contact-form.php';
require_once TM_DIR . 'includes/pwa.php';
require_once TM_DIR . 'includes/doctor-api.php';
require_once TM_DIR . 'includes/profile-api.php';
require_once TM_DIR . 'includes/board-api.php';
require_once TM_DIR . 'includes/invite-api.php';
require_once TM_DIR . 'includes/aft-admin.php';
require_once TM_DIR . 'includes/ambulatori-cpt.php';
require_once TM_DIR . 'includes/aft-ambulatori.php';
require_once TM_DIR . 'includes/settings-page.php';
require_once TM_DIR . 'includes/dashboard-frontend.php';
require_once TM_DIR . 'includes/app-content.php';
require_once TM_DIR . 'includes/public-sections.php';
require_once TM_DIR . 'includes/notula.php';
require_once TM_DIR . 'includes/public-slots.php';
require_once TM_DIR . 'includes/schedules.php';
require_once TM_DIR . 'includes/data-transfer.php';
require_once TM_DIR . 'includes/structures.php';
require_once TM_DIR . 'includes/ranking.php';
require_once TM_DIR . 'includes/scheduling.php';
require_once TM_DIR . 'includes/scheduling-rest.php';
require_once TM_DIR . 'includes/schedule-admin.php';
require_once TM_DIR . 'includes/blocks.php';

$weekly_schedule_bootstrap = TM_DIR . 'custom-fields/weekly-schedule/weekly-schedule.php';
if (is_readable($weekly_schedule_bootstrap)) {
    require_once $weekly_schedule_bootstrap;
} else {
    add_action('admin_notices', function () {
        if (!current_user_can('manage_options')) return;
        echo '<div class="notice notice-error"><p><strong>Turni Medici:</strong> manca il componente <code>custom-fields/weekly-schedule/weekly-schedule.php</code>. Ricarica la directory completa del plugin per ripristinare l’editor settimanale delle strutture.</p></div>';
    });
}

require_once TM_DIR . 'includes/ssl/index.php';
require_once TM_DIR . 'includes/common/config.php';
require_once TM_DIR . 'includes/common/disabled-comments.php';
require_once TM_DIR . 'includes/common/valid-svg.php';
require_once TM_DIR . 'includes/common/utilities.php';
require_once TM_DIR . 'includes/common/hardening.php';

add_action('init', 'tm_register_facility');
add_action('init', 'tm_register_aft');
add_action('init', 'tm_register_schedule');
add_action('init', 'tm_register_service');
add_action('init', 'tm_register_facility_meta');
add_action('init', 'tm_register_schedule_meta');
add_action('init', 'tm_register_aft_meta');
add_action('init', 'tm_register_doctor_meta');

register_activation_hook(__FILE__, function () {
    tm_register_facility();
    tm_register_aft();
    tm_register_schedule();
    tm_register_service();
    tm_register_facility_meta();
    tm_register_schedule_meta();
    tm_register_doctor_role();
    tm_sync_coordinator_caps();
    tm_create_scheduling_tables();
    tm_refresh_facility_rewrite_map();
    tm_facility_rewrites();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    delete_option(TM_FACILITY_REWRITE_MAP_OPTION);
    flush_rewrite_rules();
});
