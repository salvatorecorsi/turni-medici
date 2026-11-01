<?php

if (!defined('ABSPATH')) exit;

const TM_AFT_PALETTE = [
    '#3f6cb5', '#2f8f84', '#7d5fc6', '#c14a86', '#d98a2b', '#2f8fb0', '#4f9e54',
    '#b35cc0', '#5b6b7d', '#8a6d3b', '#6a8a3a', '#6b6bbf', '#a8527f', '#a66433',
];

const TM_AFT_DAYS = [
    ['id' => 'lun', 'label' => 'Lunedì'],
    ['id' => 'mar', 'label' => 'Martedì'],
    ['id' => 'mer', 'label' => 'Mercoledì'],
    ['id' => 'gio', 'label' => 'Giovedì'],
    ['id' => 'ven', 'label' => 'Venerdì'],
];

function tm_aft_board_defaults() {
    return ['dayStart' => '08:00', 'dayEnd' => '19:00', 'bands' => [['09:00', '13:00'], ['14:00', '19:00']]];
}

function tm_aft_clean_time($t) {
    $t = trim((string) $t);
    return preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m) ? sprintf('%02d:%02d', (int) $m[1], (int) $m[2]) : '';
}

function tm_aft_clean_bands($bands) {
    $out = [];
    foreach ((array) $bands as $b) {
        $a = tm_aft_clean_time($b[0] ?? '');
        $c = tm_aft_clean_time($b[1] ?? '');
        if ($a && $c) $out[] = [$a, $c];
    }
    return $out ?: tm_aft_board_defaults()['bands'];
}

function tm_aft_config($aft_id) {
    $c = get_post_meta($aft_id, 'tm_aft_board_config', true);
    return array_merge(tm_aft_board_defaults(), is_array($c) ? $c : []);
}

function tm_aft_doctors($aft_id) {
    $aft_id = (int) $aft_id;
    $users = array_values(array_filter(
        get_users(['role' => 'tm_doctor', 'fields' => ['ID', 'display_name']]),
        fn($user) => (function_exists('tm_doctor_home_aft_id') ? tm_doctor_home_aft_id($user->ID) : (int) get_user_meta($user->ID, 'tm_home_aft', true)) === $aft_id
    ));
    $order = get_post_meta($aft_id, 'tm_aft_doctor_order', true);
    $position = is_array($order) ? array_flip(array_map('intval', $order)) : [];
    usort($users, function ($a, $b) use ($position) {
        $pa = $position[$a->ID] ?? PHP_INT_MAX;
        $pb = $position[$b->ID] ?? PHP_INT_MAX;
        if ($pa !== $pb) return $pa <=> $pb;
        return strcasecmp(
            get_user_meta($a->ID, 'last_name', true) ?: $a->display_name,
            get_user_meta($b->ID, 'last_name', true) ?: $b->display_name
        );
    });
    $doctors = [];
    $i = 0;
    foreach ($users as $u) {
        $last  = get_user_meta($u->ID, 'last_name', true);
        $first = get_user_meta($u->ID, 'first_name', true);
        $custom = get_user_meta($u->ID, 'tm_doctor_color', true);
        $color = ($custom && preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $custom)) ? $custom : TM_AFT_PALETTE[$i % count(TM_AFT_PALETTE)];
        $doctors[] = ['id' => (int) $u->ID, 'name' => $last ?: $u->display_name, 'full' => trim($first . ' ' . $last) ?: $u->display_name, 'color' => $color];
        $i++;
    }
    return $doctors;
}

/* Ambulatori di un'AFT = strutture con modalità "ambulatorio" */
function tm_aft_ambulatori_list($aft_id) {
    $out = [];
    $posts = get_posts([
        'post_type'   => 'tm_facility',
        'numberposts' => -1,
        'post_status' => ['publish', 'draft'],
        'orderby'     => 'title',
        'order'       => 'ASC',
        'meta_query'  => [
            ['key' => 'tm_aft_id', 'value' => (int) $aft_id],
            ['key' => 'tm_facility_modes', 'value' => '"ambulatorio"', 'compare' => 'LIKE'],
        ],
    ]);
    foreach ($posts as $p) {
        $short = get_post_meta($p->ID, 'tm_facility_short', true);
        $pin = get_post_meta($p->ID, 'tm_facility_pin', true);
        $address = is_array($pin) ? ($pin['formatted_address'] ?? '') : '';
        $out[] = [
            'id'      => (int) $p->ID,
            'label'   => $p->post_title,
            'short'   => $short ?: mb_strtoupper(mb_substr($p->post_title, 0, 3)),
            'address' => $address,
        ];
    }
    return $out;
}

/* Medici membri con i loro orari NEGLI AMBULATORI DI QUESTA AFT (subset editabile) */
function tm_aft_member_doctors($aft_id) {
    $amb_ids = array_column(tm_aft_ambulatori_list($aft_id), 'id');
    $doctors = tm_aft_doctors($aft_id);
    foreach ($doctors as &$d) {
        $sched = get_user_meta($d['id'], 'tm_ambulatory_schedule', true);
        $mine = [];
        if (is_array($sched)) {
            foreach ($sched as $s) {
                if (in_array((int) ($s['amb'] ?? 0), $amb_ids, true)) {
                    $mine[] = ['amb' => (int) $s['amb'], 'day' => $s['day'] ?? 'lun', 'start' => tm_aft_clean_time($s['start'] ?? ''), 'end' => tm_aft_clean_time($s['end'] ?? ''), 'booking' => ($s['booking'] ?? '') === 'libero' ? 'libero' : 'appuntamento'];
                }
            }
        }
        $d['schedule'] = $mine;
    }
    return $doctors;
}

/* Payload prospetto costruito da dati LIVE (ambulatori CPT + orari sui medici) */
function tm_aft_live_payload($aft_id, $config = null) {
    $config = $config ?: tm_aft_config($aft_id);
    $locations = array_map(fn($a) => ['id' => (string) $a['id'], 'label' => $a['label'], 'short' => $a['short'], 'address' => $a['address'] ?? ''], tm_aft_ambulatori_list($aft_id));
    $doctors = tm_aft_member_doctors($aft_id);

    $used = [];
    $shifts = [];
    foreach ($doctors as $d) {
        foreach ($d['schedule'] as $s) {
            if (!$s['start'] || !$s['end']) continue;
            $shifts[] = ['doctor' => (string) $d['id'], 'loc' => (string) $s['amb'], 'day' => $s['day'], 'start' => $s['start'], 'end' => $s['end'], 'booking' => $s['booking']];
            $used[$d['id']] = true;
        }
    }
    $json_doctors = [];
    foreach ($doctors as $d) {
        if (empty($used[$d['id']])) continue;
        $json_doctors[] = ['id' => (string) $d['id'], 'name' => $d['name'], 'color' => $d['color']];
    }
    $cfg = array_merge(tm_aft_board_defaults(), is_array($config) ? $config : []);
    return [
        'meta'      => ['dayStart' => tm_aft_clean_time($cfg['dayStart']) ?: '08:00', 'dayEnd' => tm_aft_clean_time($cfg['dayEnd']) ?: '19:00', 'bands' => tm_aft_clean_bands($cfg['bands'] ?? [])],
        'locations' => $locations,
        'doctors'   => $json_doctors,
        'shifts'    => $shifts,
    ];
}

function tm_aft_prospetto_url($aft_id) {
    $t = get_post_meta($aft_id, 'tm_aft_prospetto_token', true);
    if (!$t) { $t = wp_generate_password(24, false); update_post_meta($aft_id, 'tm_aft_prospetto_token', $t); }
    return home_url('/?tm_aft_prospetto=' . $t);
}

/* Motore + CSS del board: nell'editor (anche iframe canvas) e sul front */
function tm_aft_board_enqueue() {
    wp_enqueue_style('tm-aft-board', TM_URL . 'assets/aft-board.css', [], filemtime(TM_DIR . 'assets/aft-board.css'));
    wp_enqueue_script('tm-aft-board-engine', TM_URL . 'assets/aft-board-engine.js', [], filemtime(TM_DIR . 'assets/aft-board-engine.js'), true);
}
add_action('enqueue_block_assets', 'tm_aft_board_enqueue');
add_action('enqueue_block_editor_assets', 'tm_aft_board_enqueue');

/* Scrive lo schedule ambulatoriale di un medico limitandolo a $amb_ids,
   preservando gli orari negli ambulatori fuori da quel set (altre AFT). */
function tm_write_ambulatory_schedule($doctor_id, $amb_ids, $raw) {
    $valid_days = array_column(TM_AFT_DAYS, 'id');
    $posted = [];
    foreach ((array) $raw as $s) {
        $amb = (int) ($s['amb'] ?? 0);
        $day = sanitize_key($s['day'] ?? '');
        if (!in_array($amb, $amb_ids, true) || !in_array($day, $valid_days, true)) continue;
        $posted[] = ['amb' => $amb, 'day' => $day, 'start' => tm_aft_clean_time($s['start'] ?? ''), 'end' => tm_aft_clean_time($s['end'] ?? ''), 'booking' => ($s['booking'] ?? '') === 'libero' ? 'libero' : 'appuntamento'];
    }
    $existing = get_user_meta($doctor_id, 'tm_ambulatory_schedule', true);
    $keep = [];
    if (is_array($existing)) {
        foreach ($existing as $s) {
            if (!in_array((int) ($s['amb'] ?? 0), $amb_ids, true)) $keep[] = $s;
        }
    }
    update_user_meta($doctor_id, 'tm_ambulatory_schedule', array_merge($keep, $posted));
    return $posted;
}

function tm_doctor_ambulatory_payload($doctor_id) {
    $aft = function_exists('tm_doctor_home_aft_id') ? tm_doctor_home_aft_id($doctor_id) : (int) get_user_meta($doctor_id, 'tm_home_aft', true);
    $ambulatori = $aft ? tm_aft_ambulatori_list($aft) : [];
    $amb_ids = array_column($ambulatori, 'id');
    $sched = get_user_meta($doctor_id, 'tm_ambulatory_schedule', true);
    $mine = [];
    if (is_array($sched)) {
        foreach ($sched as $s) {
            if (!in_array((int) ($s['amb'] ?? 0), $amb_ids, true)) continue;
            $mine[] = ['amb' => (int) $s['amb'], 'day' => $s['day'] ?? 'lun', 'start' => tm_aft_clean_time($s['start'] ?? ''), 'end' => tm_aft_clean_time($s['end'] ?? ''), 'booking' => ($s['booking'] ?? '') === 'libero' ? 'libero' : 'appuntamento'];
        }
    }
    return ['homeAft' => $aft, 'ambulatori' => $ambulatori, 'schedule' => $mine, 'days' => TM_AFT_DAYS];
}

function tm_save_doctor_ambulatory_schedule($doctor_id, $raw) {
    $aft = function_exists('tm_doctor_home_aft_id') ? tm_doctor_home_aft_id($doctor_id) : (int) get_user_meta($doctor_id, 'tm_home_aft', true);
    $amb_ids = $aft ? array_column(tm_aft_ambulatori_list($aft), 'id') : [];
    return tm_write_ambulatory_schedule($doctor_id, $amb_ids, $raw);
}

/* ============================================================
   REST
   ============================================================ */
add_action('rest_api_init', function () {
    $can = fn() => current_user_can('edit_users');
    $is_doctor = fn() => function_exists('tm_current_doctor') && tm_current_doctor() !== null;

    register_rest_route('turnimedici/v1', '/me/ambulatory-schedule', [
        [
            'methods' => 'GET', 'permission_callback' => $is_doctor,
            'callback' => fn() => tm_doctor_ambulatory_payload(tm_current_doctor()->ID),
        ],
        [
            'methods' => 'POST', 'permission_callback' => $is_doctor,
            'callback' => function ($r) {
                $posted = tm_save_doctor_ambulatory_schedule(tm_current_doctor()->ID, $r->get_json_params()['schedule'] ?? []);
                return ['ok' => true, 'schedule' => $posted];
            },
        ],
    ]);

    register_rest_route('turnimedici/v1', '/aft/(?P<id>\d+)/board-context', [
        'methods' => 'GET', 'permission_callback' => $can,
        'callback' => function ($r) {
            $id = (int) $r['id'];
            $all = [];
            foreach (get_users(['role' => 'tm_doctor', 'fields' => ['ID', 'display_name']]) as $u) {
                $last = get_user_meta($u->ID, 'last_name', true);
                $first = get_user_meta($u->ID, 'first_name', true);
                $home_aft = function_exists('tm_doctor_home_aft_id') ? tm_doctor_home_aft_id($u->ID) : (int) get_user_meta($u->ID, 'tm_home_aft', true);
                $all[] = ['id' => (int) $u->ID, 'name' => trim($first . ' ' . $last) ?: $u->display_name, 'homeAft' => $home_aft];
            }
            usort($all, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            return [
                'aftId'       => $id,
                'ambulatori'  => tm_aft_ambulatori_list($id),
                'doctors'     => tm_aft_member_doctors($id),
                'allDoctors'  => $all,
                'config'      => tm_aft_config($id),
                'days'        => TM_AFT_DAYS,
                'defaults'    => tm_aft_board_defaults(),
                'printUrl'    => admin_url('admin-post.php'),
                'printNonce'  => wp_create_nonce('tm_aft_board_print'),
                'demoMode'    => tm_demo_mode(),
                'prospettoUrl' => tm_aft_prospetto_url($id),
                'ambNewUrl'   => admin_url('post-new.php?post_type=tm_facility'),
            ];
        },
    ]);

    register_rest_route('turnimedici/v1', '/aft/(?P<id>\d+)/config', [
        'methods' => 'POST', 'permission_callback' => $can,
        'callback' => function ($r) {
            if (get_post_type((int) $r['id']) !== 'tm_aft') return new WP_Error('tm_not_aft', 'AFT non valida.', ['status' => 404]);
            $cfg = $r->get_json_params()['config'] ?? [];
            $clean = [
                'dayStart' => tm_aft_clean_time($cfg['dayStart'] ?? '') ?: '08:00',
                'dayEnd'   => tm_aft_clean_time($cfg['dayEnd'] ?? '') ?: '19:00',
                'bands'    => tm_aft_clean_bands($cfg['bands'] ?? []),
            ];
            update_post_meta((int) $r['id'], 'tm_aft_board_config', $clean);
            return ['config' => $clean];
        },
    ]);

    register_rest_route('turnimedici/v1', '/aft/(?P<id>\d+)/doctor/(?P<did>\d+)/schedule', [
        'methods' => 'POST', 'permission_callback' => $can,
        'callback' => function ($r) {
            $aft = (int) $r['id'];
            $did = (int) $r['did'];
            $u = get_userdata($did);
            if (!$u || !in_array('tm_doctor', (array) $u->roles, true)) return new WP_Error('tm_not_doctor', 'Non è un medico.', ['status' => 400]);

            $amb_ids = array_column(tm_aft_ambulatori_list($aft), 'id');
            $posted = tm_write_ambulatory_schedule($did, $amb_ids, $r->get_json_params()['schedule'] ?? []);
            return ['ok' => true, 'schedule' => $posted];
        },
    ]);

    register_rest_route('turnimedici/v1', '/doctors/(?P<id>\d+)/ambulatory-schedule', [
        [
            'methods' => 'GET', 'permission_callback' => $can,
            'callback' => function ($r) {
                $did = (int) $r['id'];
                $u = get_userdata($did);
                if (!$u || !in_array('tm_doctor', (array) $u->roles, true)) return new WP_Error('tm_not_doctor', 'Non è un medico.', ['status' => 400]);
                return tm_doctor_ambulatory_payload($did);
            },
        ],
        [
            'methods' => 'POST', 'permission_callback' => $can,
            'callback' => function ($r) {
                $did = (int) $r['id'];
                $u = get_userdata($did);
                if (!$u || !in_array('tm_doctor', (array) $u->roles, true)) return new WP_Error('tm_not_doctor', 'Non è un medico.', ['status' => 400]);
                $posted = tm_save_doctor_ambulatory_schedule($did, $r->get_json_params()['schedule'] ?? []);
                return ['ok' => true, 'schedule' => $posted];
            },
        ],
    ]);

    register_rest_route('turnimedici/v1', '/aft/(?P<id>\d+)/order', [
        'methods' => 'POST', 'permission_callback' => $can,
        'callback' => function ($r) {
            if (get_post_type((int) $r['id']) !== 'tm_aft') return new WP_Error('tm_not_aft', 'AFT non valida.', ['status' => 404]);
            $ids = array_values(array_filter(array_map('intval', (array) ($r->get_json_params()['order'] ?? []))));
            update_post_meta((int) $r['id'], 'tm_aft_doctor_order', $ids);
            return ['ok' => true, 'order' => $ids];
        },
    ]);

    register_rest_route('turnimedici/v1', '/doctors/(?P<id>\d+)/color', [
        'methods' => 'POST', 'permission_callback' => $can,
        'callback' => function ($r) {
            $did = (int) $r['id'];
            $u = get_userdata($did);
            if (!$u || !in_array('tm_doctor', (array) $u->roles, true)) return new WP_Error('tm_not_doctor', 'Non è un medico.', ['status' => 400]);
            $color = sanitize_text_field($r->get_json_params()['color'] ?? '');
            if (!preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $color)) return new WP_Error('tm_bad_color', 'Colore non valido.', ['status' => 400]);
            update_user_meta($did, 'tm_doctor_color', $color);
            return ['ok' => true, 'color' => $color];
        },
    ]);

    register_rest_route('turnimedici/v1', '/aft/(?P<id>\d+)/doctor/(?P<did>\d+)/member', [
        'methods' => 'POST', 'permission_callback' => $can,
        'callback' => function ($r) {
            $aft = (int) $r['id'];
            $did = (int) $r['did'];
            $u = get_userdata($did);
            if (!$u || !in_array('tm_doctor', (array) $u->roles, true)) return new WP_Error('tm_not_doctor', 'Non è un medico.', ['status' => 400]);
            $member = !empty($r->get_json_params()['member']);
            update_user_meta($did, 'tm_home_aft', $member ? $aft : 0);
            $doctors = tm_aft_member_doctors($aft);
            foreach ($doctors as $d) if ($d['id'] === $did) return $d;
            return ['id' => $did, 'removed' => true];
        },
    ]);

    register_rest_route('turnimedici/v1', '/aft/(?P<id>\d+)/email', [
        'methods' => 'POST', 'permission_callback' => $can,
        'callback' => function ($r) {
            if (tm_demo_mode()) {
                return new WP_Error('tm_demo_mail_disabled', 'La demo non invia email: usa il link diretto al prospetto.', ['status' => 403]);
            }
            $aft = (int) $r['id'];
            $email = sanitize_email($r->get_json_params()['email'] ?? '');
            if (!$email || !is_email($email)) return new WP_Error('tm_bad_email', 'Email non valida.', ['status' => 400]);
            $post = get_post($aft);
            $url = tm_aft_prospetto_url($aft);
            $sent = wp_mail(
                $email,
                'Prospetto ambulatori — ' . $post->post_title,
                "Ecco il prospetto di copertura ambulatori dell'AFT " . $post->post_title . ".\n\nApri il link (sempre aggiornato, stampabile in PDF):\n" . $url . "\n"
            );
            if (!$sent) return new WP_Error('tm_send', 'Invio non riuscito.', ['status' => 500]);
            return ['sent' => true];
        },
    ]);
});

/* ============================================================
   Prospetto pubblico tokenizzato (per l'email-link)
   ============================================================ */
add_action('init', function () {
    if (empty($_GET['tm_aft_prospetto'])) return;
    $token = sanitize_text_field(wp_unslash($_GET['tm_aft_prospetto']));
    $q = get_posts(['post_type' => 'tm_aft', 'numberposts' => 1, 'meta_key' => 'tm_aft_prospetto_token', 'meta_value' => $token]);
    if (!$q) { status_header(404); exit; }
    $aft = $q[0];
    $payload = tm_aft_live_payload($aft->ID);
    $title = $aft->post_title;
    $json = wp_json_encode($payload);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    include TM_DIR . 'templates/aft-board.php';
    exit;
}, 0);

/* ============================================================
   Blocco AFT
   ============================================================ */
add_action('init', 'tm_register_aft_orari_block', 25);
function tm_register_aft_orari_block() {
    if (!function_exists('define_block_type')) return;
    define_block_type('turnimedici/aft-orari', [
        'title'    => 'Orari ambulatori AFT',
        'icon'     => 'calendar-alt',
        'category' => 'design',
        'keywords' => ['aft', 'ambulatori', 'orari', 'copertura', 'prospetto'],
        'schema'   => [[
            'scope'  => 'content',
            'fields' => [
                'board' => ['type' => 'aft-orari', 'label' => 'Orari ambulatori', 'default' => []],
            ],
        ]],
        'render' => 'tm_render_aft_orari',
    ]);
}

function tm_render_aft_orari($attributes, $content, $block) {
    $aft_id = get_the_ID();
    $payload = tm_aft_live_payload($aft_id);
    if (empty($payload['shifts'])) {
        return '<div class="tm-aftboard-block"><p style="color:#6b7280">Nessun orario ambulatorio inserito.</p></div>';
    }
    tm_aft_board_enqueue();
    static $init = false;
    if (!$init) {
        $init = true;
        wp_add_inline_script('tm-aft-board-engine', 'document.querySelectorAll(".tm-board-mount[data-board]").forEach(function(m){if(m.dataset.done)return;m.dataset.done="1";try{window.TMBoard.render(m,JSON.parse(m.dataset.board),{fit:"inline"});}catch(e){}});');
    }
    return '<div class="tm-aftboard-block"><div class="tm-board-mount" data-board="' . esc_attr(wp_json_encode($payload)) . '"></div></div>';
}

/* ============================================================
   Stampa diretta (A4) — usa payload live + config eventuale
   ============================================================ */
add_action('admin_post_tm_aft_board_print', 'tm_aft_board_print');
function tm_aft_board_print() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'tm_aft_board_print')) wp_die('Nonce non valido.');
    $aft_id = (int) ($_POST['aft'] ?? 0);
    if (!$aft_id || !current_user_can('edit_post', $aft_id)) wp_die('Non autorizzato.');

    $config = isset($_POST['config']) ? json_decode(wp_unslash($_POST['config']), true) : null;
    $payload = tm_aft_live_payload($aft_id, is_array($config) ? $config : null);
    $aft = get_post($aft_id);
    $title = $aft->post_title;
    $json = wp_json_encode($payload);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    include TM_DIR . 'templates/aft-board.php';
    exit;
}
