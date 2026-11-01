<?php

if (!defined('ABSPATH')) exit;

const TM_MONTH_NAMES = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

function tm_month_label($month) {
    if (function_exists('tm_valid_month_value') && tm_valid_month_value($month) && preg_match('/^(\d{4})-(\d{2})$/', (string) $month, $m)) {
        return trim((TM_MONTH_NAMES[(int) $m[2]] ?? '') . ' ' . $m[1]);
    }
    return (string) $month;
}

function tm_schedule_app_url($schedule_id) {
    $schedule_id = (int) $schedule_id;
    if (get_post_type($schedule_id) !== 'tm_schedule') return '';
    $facility_id = (int) get_post_meta($schedule_id, 'tm_facility_id', true);
    $facility = $facility_id ? get_post($facility_id) : null;
    if (!$facility || $facility->post_type !== 'tm_facility') return '';
    $base = function_exists('tm_turni_url') ? tm_turni_url($facility->post_name) : get_permalink($facility_id);
    return $base ? add_query_arg('schedule', $schedule_id, $base) : '';
}

add_action('admin_menu', 'tm_schedules_menu');
function tm_schedules_menu() {
    add_menu_page('Turni', 'Turni', 'manage_options', 'tm-schedules', 'tm_schedules_page', 'dashicons-clock', 26);
}

function tm_schedules_page() {
    echo '<div class="wrap"><div id="tm-schedules-app"><p>Caricamento…</p></div></div>';
}

add_action('admin_enqueue_scripts', 'tm_schedules_enqueue');
function tm_schedules_enqueue($hook) {
    if ($hook !== 'toplevel_page_tm-schedules') return;
    $base = TM_DIR . 'app/build/';
    $url = TM_URL . 'app/build/';
    if (file_exists($base . 'schedules.asset.php')) {
        $asset = include $base . 'schedules.asset.php';
        wp_enqueue_script('tm-schedules', $url . 'schedules.js', $asset['dependencies'], (string) filemtime($base . 'schedules.js'), true);
        wp_localize_script('tm-schedules', 'tmSchedules', [
            'restUrl' => rest_url('turnimedici/v1/'),
            'nonce'   => wp_create_nonce('wp_rest'),
            'listUrl' => admin_url('edit.php?post_type=tm_schedule'),
        ]);
        wp_enqueue_style('wp-components');
    }
    $css = TM_DIR . 'assets/schedules.css';
    if (file_exists($css)) {
        wp_enqueue_style('tm-schedules', TM_URL . 'assets/schedules.css', ['wp-components'], (string) filemtime($css));
    }
}

add_filter('post_row_actions', function ($actions, $post) {
    if ($post->post_type !== 'tm_schedule') return $actions;
    unset($actions['inline hide-if-no-js']);
    $edit_url = get_edit_post_link($post->ID, 'raw');
    $app_url = tm_schedule_app_url($post->ID);
    $links = [];
    if ($edit_url) $links['edit'] = '<a href="' . esc_url($edit_url) . '">Modifica e ripara</a>';
    if ($app_url) $links['tm_open_calendar'] = '<a href="' . esc_url($app_url) . '">Apri app</a>';
    if ($links) $actions = $links + $actions;
    return $actions;
}, 10, 2);

add_filter('bulk_actions-edit-tm_schedule', function ($actions) {
    unset($actions['edit']);
    return $actions;
});

add_action('load-post-new.php', function () {
    if (sanitize_key($_GET['post_type'] ?? '') !== 'tm_schedule') return;
    wp_safe_redirect(admin_url('admin.php?page=tm-schedules'));
    exit;
});

add_action('pre_get_posts', function ($query) {
    if (!is_admin() || !$query->is_main_query()) return;
    if ($query->get('post_type') !== 'tm_schedule') return;
    $fid = isset($_GET['tm_struttura']) ? (int) $_GET['tm_struttura'] : 0;
    if ($fid) {
        $meta_query = (array) $query->get('meta_query');
        $meta_query[] = [
            'key'   => 'tm_facility_id',
            'value' => tm_user_manages_facility($fid) ? $fid : 0,
        ];
        $query->set('meta_query', $meta_query);
    }
});

add_filter('manage_tm_schedule_posts_columns', function ($cols) {
    $new = [];
    if (isset($cols['cb'])) $new['cb'] = $cols['cb'];
    $new['title']      = 'Titolo';
    $new['tm_month']   = 'Mese';
    $new['tm_struct']  = 'Struttura';
    $new['tm_counts']  = 'Medici / Turni';
    $new['tm_closed']  = 'Turnazione';
    $new['tm_default'] = 'Mostra di default';
    if (isset($cols['date'])) $new['date'] = $cols['date'];
    return $new;
});

add_action('manage_tm_schedule_posts_custom_column', function ($col, $post_id) {
    global $wpdb;
    if ($col === 'tm_month') {
        echo esc_html(tm_month_label(get_post_meta($post_id, 'tm_schedule_month', true)));
    } elseif ($col === 'tm_struct') {
        $fid = (int) get_post_meta($post_id, 'tm_facility_id', true);
        $f = $fid ? get_post($fid) : null;
        echo $f ? esc_html($f->post_title) : '&mdash;';
    } elseif ($col === 'tm_counts') {
        $a = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d', $post_id));
        $s = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d', $post_id));
        echo (int) $a . ' medici &middot; ' . (int) $s . ' turni';
    } elseif ($col === 'tm_closed') {
        $closed = (bool) get_post_meta($post_id, 'tm_schedule_closed', true);
        printf(
            '<label class="tm-schedule-switch" title="Chiusa = i medici non possono più compilare i turni né chiedere inviti per questo mese."><input type="checkbox" data-post="%d"%s><span class="tm-schedule-switch__track"></span><span class="tm-schedule-switch__label">%s</span></label>',
            (int) $post_id,
            checked($closed, true, false),
            $closed ? 'Chiusa' : 'Aperta'
        );
    } elseif ($col === 'tm_default') {
        $fid = (int) get_post_meta($post_id, 'tm_facility_id', true);
        $def = (int) get_post_meta($fid, 'tm_default_schedule', true);
        $url = wp_nonce_url(admin_url('admin-post.php?action=tm_toggle_default&post=' . (int) $post_id), 'tm_toggle_default_' . $post_id);
        if ($def === (int) $post_id) {
            echo '<strong style="color:#1f6b45">&#9733; Predefinito</strong> &middot; <a href="' . esc_url($url) . '">annulla</a>';
        } else {
            echo '<a href="' . esc_url($url) . '">Imposta predefinito</a>';
        }
    }
}, 10, 2);

add_action('admin_post_tm_toggle_default', function () {
    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if (!tm_can_manage_scheduling() || !tm_user_manages_schedule($post_id) || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'tm_toggle_default_' . $post_id)) {
        wp_die('Non autorizzato.');
    }
    $fid = (int) get_post_meta($post_id, 'tm_facility_id', true);
    if ($fid) {
        $cur = (int) get_post_meta($fid, 'tm_default_schedule', true);
        if ($cur === $post_id) {
            delete_post_meta($fid, 'tm_default_schedule');
        } else {
            update_post_meta($fid, 'tm_default_schedule', $post_id);
        }
    }
    wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=tm_schedule'));
    exit;
});

add_action('admin_notices', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'edit-tm_schedule') return;
    $smistatore = admin_url('admin.php?page=tm-schedules');
    $fid = isset($_GET['tm_struttura']) ? (int) $_GET['tm_struttura'] : 0;
    if ($fid) {
        $f = get_post($fid);
        $aid = (int) get_post_meta($fid, 'tm_aft_id', true);
        $aft = $aid ? get_post($aid) : null;
        $aft_name = $aft ? preg_replace('/^AFT\s+/i', '', $aft->post_title) : '';
        echo '<div class="tm-sched-crumb" style="margin:10px 0 2px;font-size:14px;color:#1d2327">'
            . '<span style="font-weight:600">' . esc_html($aft_name) . ' / ' . esc_html($f ? $f->post_title : '') . '</span>'
            . ' &nbsp; <a href="' . esc_url($smistatore) . '" style="text-decoration:underline;color:#2271b1">&lsaquo; Cambia struttura</a>'
            . '</div>';
    } else {
        echo '<div class="tm-sched-crumb" style="margin:10px 0 2px;font-size:14px">'
            . '<a href="' . esc_url($smistatore) . '" style="text-decoration:underline;color:#2271b1">Scegli struttura</a>'
            . '</div>';
    }
});

/* Celle totali del mese (giorni aperti × fasce): denominatore del semaforo di copertura */
function tm_schedule_cells_total($schedule_id) {
    if (!function_exists('tm_board_calendar')) return 0;
    $week = tm_board_calendar($schedule_id);
    if (!$week) return 0;
    $month = tm_schedule_month($schedule_id);
    $days = (int) date('t', mktime(0, 0, 0, $month['month'], 1, $month['year']));
    $total = 0;
    for ($day = 1; $day <= $days; $day++) {
        $iso = sprintf('%04d-%02d-%02d', $month['year'], $month['month'], $day);
        $effective = tm_board_effective_day($week, $iso);
        if ($effective['open']) $total += count($effective['slots']);
    }
    return $total;
}

add_filter('manage_tm_schedule_posts_columns', function ($cols) {
    $new = [];
    foreach ($cols as $key => $label) {
        $new[$key] = $label;
        if ($key === 'title') $new['tm_coverage'] = 'Copertura';
    }
    return $new;
});

add_action('manage_tm_schedule_posts_custom_column', function ($col, $post_id) {
    if ($col !== 'tm_coverage') return;
    global $wpdb;
    $covered = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d', $post_id));
    $total = tm_schedule_cells_total($post_id);
    if (!$total) { echo '&mdash;'; return; }
    $pct = (int) round($covered / $total * 100);
    $color = $pct >= 100 ? '#1a7a3c' : ($pct >= 50 ? '#8a6a13' : '#b32d2e');
    $bg = $pct >= 100 ? '#e6f4ea' : ($pct >= 50 ? '#fdf6e0' : '#fdeaea');
    echo '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-weight:600;background:' . esc_attr($bg) . ';color:' . esc_attr($color) . '">' . $covered . '/' . $total . ' &middot; ' . $pct . '%</span>';
}, 10, 2);

function tm_schedules_overview() {
    global $wpdb;
    $out = [];
    $scope_aft = current_user_can('edit_users') ? 0 : tm_coordinator_aft_id();
    $afts = get_posts(['post_type' => 'tm_aft', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
    foreach ($afts as $aft) {
        if ($scope_aft && $aft->ID !== $scope_aft) continue;
        $facilities = get_posts([
            'post_type'   => 'tm_facility',
            'numberposts' => -1,
            'post_status' => ['publish', 'draft'],
            'orderby'     => 'title',
            'order'       => 'ASC',
            'meta_key'    => 'tm_aft_id',
            'meta_value'  => $aft->ID,
        ]);
        if (!$facilities) continue;

        $structures = [];
        foreach ($facilities as $f) {
            if (!tm_user_manages_facility($f->ID)) continue;
            if (!tm_facility_in_turnazione($f->ID)) continue;
            $default = (int) get_post_meta($f->ID, 'tm_default_schedule', true);
            $schedules = get_posts([
                'post_type'   => 'tm_schedule',
                'numberposts' => -1,
                'post_status' => ['publish', 'draft'],
                'meta_key'    => 'tm_facility_id',
                'meta_value'  => $f->ID,
            ]);
            usort($schedules, fn($a, $b) => strcmp((string) get_post_meta($b->ID, 'tm_schedule_month', true), (string) get_post_meta($a->ID, 'tm_schedule_month', true)));

            $list = [];
            foreach ($schedules as $s) {
                $month = get_post_meta($s->ID, 'tm_schedule_month', true);
                $list[] = [
                    'id'          => $s->ID,
                    'month'       => $month,
                    'label'       => tm_month_label($month),
                    'status'      => $s->post_status,
                    'is_default'  => $s->ID === $default,
                    'assignments' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . tm_table('assignments') . ' WHERE schedule_id = %d', $s->ID)),
                    'shifts'      => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d', $s->ID)),
                    'cells_total' => tm_schedule_cells_total($s->ID),
                    'edit_url'    => admin_url('post.php?post=' . $s->ID . '&action=edit'),
                ];
            }
            $structures[] = [
                'id'               => $f->ID,
                'name'             => $f->post_title,
                'app_url'          => function_exists('tm_turni_url') ? tm_turni_url($f->post_name) : get_permalink($f->ID),
                'default_schedule' => $default,
                'schedules'        => $list,
            ];
        }
        if (!$structures) continue;
        $out[] = [
            'aft_id'     => $aft->ID,
            'aft_name'   => preg_replace('/^AFT\s+/i', '', $aft->post_title),
            'structures' => $structures,
        ];
    }
    return rest_ensure_response($out);
}

function tm_schedule_set_default($request) {
    $sid = (int) $request['id'];
    $s = get_post($sid);
    if (!$s || $s->post_type !== 'tm_schedule') {
        return new WP_Error('tm_not_found', 'Orario non trovato.', ['status' => 404]);
    }
    if (!tm_user_manages_schedule($sid)) {
        return new WP_Error('tm_forbidden', 'Fuori dalla tua AFT.', ['status' => 403]);
    }
    $facility_id = (int) get_post_meta($sid, 'tm_facility_id', true);
    if (!$facility_id) {
        return new WP_Error('tm_no_facility', 'Orario senza struttura.', ['status' => 400]);
    }
    $on = !empty($request->get_json_params()['default']);
    if ($on) {
        update_post_meta($facility_id, 'tm_default_schedule', $sid);
    } elseif ((int) get_post_meta($facility_id, 'tm_default_schedule', true) === $sid) {
        delete_post_meta($facility_id, 'tm_default_schedule');
    }
    return rest_ensure_response(['facility' => $facility_id, 'default' => (int) get_post_meta($facility_id, 'tm_default_schedule', true)]);
}

add_action('rest_api_init', function () {
    $manage = fn() => tm_can_manage_scheduling();
    register_rest_route('turnimedici/v1', '/schedules-overview', [
        'methods'             => 'GET',
        'callback'            => 'tm_schedules_overview',
        'permission_callback' => $manage,
    ]);
    register_rest_route('turnimedici/v1', '/schedules/(?P<id>\d+)/default', [
        'methods'             => 'POST',
        'callback'            => 'tm_schedule_set_default',
        'permission_callback' => $manage,
    ]);
});

add_action('wp_ajax_tm_toggle_schedule_closed', function () {
    check_ajax_referer('tm_toggle_schedule_closed');
    $post_id = (int) ($_POST['post'] ?? 0);
    if (!$post_id || get_post_type($post_id) !== 'tm_schedule' || !tm_can_manage_scheduling() || !tm_user_manages_schedule($post_id)) {
        wp_send_json_error('Non autorizzato.', 403);
    }
    $closed = !empty($_POST['closed']);
    update_post_meta($post_id, 'tm_schedule_closed', $closed);
    wp_send_json_success(['post' => $post_id, 'closed' => $closed]);
});

add_action('admin_footer-edit.php', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'tm_schedule') return;
    $nonce = wp_create_nonce('tm_toggle_schedule_closed');
    ?>
    <style>
    .tm-schedule-switch { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; user-select: none; }
    .tm-schedule-switch input { display: none; }
    .tm-schedule-switch__track { width: 32px; height: 18px; border-radius: 999px; background: #1a7f37; position: relative; transition: background .15s ease; flex-shrink: 0; }
    .tm-schedule-switch__track::after { content: ''; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%; background: #fff; transition: left .15s ease; box-shadow: 0 1px 2px rgba(0,0,0,.25); }
    .tm-schedule-switch input:checked + .tm-schedule-switch__track { background: #b32d2e; }
    .tm-schedule-switch input:checked + .tm-schedule-switch__track::after { left: 16px; }
    .tm-schedule-switch__label { font-size: 12px; color: #50575e; min-width: 44px; }
    .tm-schedule-switch.is-busy { opacity: .5; pointer-events: none; }
    </style>
    <script>
    (function () {
        document.addEventListener('change', function (event) {
            var input = event.target;
            if (!input.matches('.tm-schedule-switch input')) return;
            var wrap = input.closest('.tm-schedule-switch');
            var label = wrap.querySelector('.tm-schedule-switch__label');
            wrap.classList.add('is-busy');
            var body = new URLSearchParams({
                action: 'tm_toggle_schedule_closed',
                _ajax_nonce: '<?php echo esc_js($nonce); ?>',
                post: input.dataset.post,
                closed: input.checked ? '1' : ''
            });
            fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                .then(function (response) { if (!response.ok) throw new Error(); return response.json(); })
                .then(function (data) {
                    if (!data || !data.success) throw new Error();
                    label.textContent = input.checked ? 'Chiusa' : 'Aperta';
                })
                .catch(function () { input.checked = !input.checked; alert('Salvataggio non riuscito, riprova.'); })
                .finally(function () { wrap.classList.remove('is-busy'); });
        });
    })();
    </script>
    <?php
});
