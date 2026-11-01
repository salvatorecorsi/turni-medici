<?php

if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) exit;

if (!defined('TM_DEMO_MODE') || TM_DEMO_MODE !== true || !in_array(wp_get_environment_type(), ['local', 'development'], true)) {
    WP_CLI::error('Il seed richiede TM_DEMO_MODE e un ambiente local o development.');
}

if (is_multisite() || !function_exists('tm_table') || !function_exists('define_block_type')) {
    WP_CLI::error('Serve una installazione singola con Turni Medici e Define Blocks attivi.');
}

function tm_demo_seed_require($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

function tm_demo_seed_default_content() {
    if (!class_exists('WP_Privacy_Policy_Content')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
    }
    $paragraph = fn($text) => "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";
    $quote = fn($text) => "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">\n" . $paragraph($text) . "\n</blockquote>\n<!-- /wp:quote -->";
    return [
        1 => ['post', __('Hello world!'), $paragraph(__('Welcome to WordPress. This is your first post. Edit or delete it, then start writing!'))],
        2 => ['page', __('Sample Page'), implode("\n\n", [
            $paragraph(__("This is an example page. It's different from a blog post because it will stay in one place and will show up in your site navigation (in most themes). Most people start with an About page that introduces them to potential site visitors. It might say something like this:")),
            $quote(__("Hi there! I'm a bike messenger by day, aspiring actor by night, and this is my website. I live in Los Angeles, have a great dog named Jack, and I like pi&#241;a coladas. (And gettin' caught in the rain.)")),
            $paragraph(__('...or something like this:')),
            $quote(__('The XYZ Doohickey Company was founded in 1971, and has been providing quality doohickeys to the public ever since. Located in Gotham City, XYZ employs over 2,000 people and does all kinds of awesome things for the Gotham community.')),
            $paragraph(sprintf(__('As a new WordPress user, you should go to <a href="%s">your dashboard</a> to delete this page and create new pages for your content. Have fun!'), admin_url())),
        ])],
        3 => ['page', __('Privacy Policy'), WP_Privacy_Policy_Content::get_default_content()],
    ];
}

function tm_demo_seed_guard() {
    global $wpdb;
    $administrators = [];
    $demo_users = [];
    foreach (get_users() as $user) {
        if (get_user_meta($user->ID, '_tm_demo_seed', true) === '1') {
            tm_demo_seed_require((bool) preg_match('/@[^@]+\.invalid$/', $user->user_email), 'Un utente demo contiene una email non dimostrativa.');
            $demo_users[] = (int) $user->ID;
            continue;
        }
        tm_demo_seed_require(in_array('administrator', $user->roles, true), 'Il database contiene utenti estranei alla demo.');
        tm_demo_seed_require(!get_user_meta($user->ID, 'tm_doctor_enabled', true) && !get_user_meta($user->ID, 'tm_home_aft', true), 'L’amministratore contiene un profilo medico esistente.');
        $administrators[] = (int) $user->ID;
    }
    tm_demo_seed_require(count($administrators) === 1, 'Serve un solo amministratore installatore in un database dedicato.');

    $defaults = tm_demo_seed_default_content();
    $demo_schedules = [];
    $posts = $wpdb->get_results($wpdb->prepare("SELECT ID, post_type, post_status, post_title, post_content, post_excerpt, post_author, post_parent FROM {$wpdb->posts} WHERE ID > %d", 0));
    tm_demo_seed_require(is_array($posts), 'Lettura dei contenuti non riuscita.');
    foreach ($posts as $post) {
        if (get_post_meta($post->ID, '_tm_demo_seed', true) === '1') {
            if ($post->post_type === 'tm_schedule') $demo_schedules[] = (int) $post->ID;
            continue;
        }
        if ($post->post_type === 'revision' && get_post_meta($post->post_parent, '_tm_demo_seed', true) === '1') continue;
        if ($post->post_status === 'auto-draft' && in_array($post->post_type, ['post', 'page'], true)
            && (int) $post->post_author === $administrators[0] && $post->post_title === __('Auto Draft')
            && $post->post_content === '' && $post->post_excerpt === '') continue;
        $expected = $defaults[(int) $post->ID] ?? null;
        tm_demo_seed_require($expected && (int) $post->post_author === $administrators[0]
            && $post->post_type === $expected[0] && $post->post_title === $expected[1]
            && trim($post->post_content) === trim($expected[2]), 'Il database contiene contenuti non demo (ID ' . (int) $post->ID . '): usa una installazione WordPress appena creata.');
    }

    foreach (get_comments(['status' => 'all']) as $comment) {
        tm_demo_seed_require((int) $comment->comment_ID === 1 && (int) $comment->comment_post_ID === 1
            && $comment->comment_author === __('A WordPress Commenter')
            && $comment->comment_author_email === 'wapuu@wordpress.example'
            && $comment->comment_content === sprintf(__("Hi, this is a comment.\nTo get started with moderating, editing, and deleting comments, please visit the Comments screen in the dashboard.\nCommenter avatars come from <a href=\"%s\">Gravatar</a>."), esc_url(__('https://gravatar.com/'))), 'Il database contiene commenti non demo.');
    }

    foreach (['assignments', 'shifts', 'queue_log'] as $suffix) {
        $table = tm_table($suffix);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT schedule_id, doctor_id FROM {$table} WHERE id > %d", 0));
        tm_demo_seed_require(is_array($rows), 'Tabelle Turni Medici mancanti o non leggibili.');
        foreach ($rows as $row) {
            tm_demo_seed_require(in_array((int) $row->schedule_id, $demo_schedules, true)
                && in_array((int) $row->doctor_id, $demo_users, true), 'Il database contiene turnazioni non demo.');
        }
    }
    $table = tm_table('estar_dump');
    $external_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE id > %d", 0));
    tm_demo_seed_require($external_count !== null && (int) $external_count === 0, 'Il database contiene una importazione esterna.');
    return $administrators[0];
}

function tm_demo_seed_post($slug, $type, $title, $content, $metadata = []) {
    $existing = get_page_by_path($slug, OBJECT, $type);
    if ($existing) {
        tm_demo_seed_require(get_post_meta($existing->ID, '_tm_demo_seed', true) === '1', 'Uno slug demo è già occupato da contenuto estraneo.');
        return (int) $existing->ID;
    }
    $post_id = wp_insert_post([
        'post_type' => $type,
        'post_status' => 'publish',
        'post_author' => get_current_user_id(),
        'post_name' => $slug,
        'post_title' => $title,
        'post_content' => wp_slash($content),
        'comment_status' => 'closed',
        'ping_status' => 'closed',
        'meta_input' => array_merge($metadata, ['_tm_demo_seed' => '1']),
    ], true);
    tm_demo_seed_require(!is_wp_error($post_id), is_wp_error($post_id) ? $post_id->get_error_message() : 'Creazione contenuto non riuscita.');
    return (int) $post_id;
}

function tm_demo_seed_user($login, $name, $role, $metadata) {
    $existing = get_user_by('login', $login);
    if ($existing) {
        tm_demo_seed_require(get_user_meta($existing->ID, '_tm_demo_seed', true) === '1', 'Un nome utente demo è già occupato.');
        return (int) $existing->ID;
    }
    $user_id = wp_insert_user([
        'user_login' => $login,
        'user_email' => $login . '@demo.invalid',
        'display_name' => $name,
        'first_name' => $name,
        'user_pass' => getenv('TM_DEMO_PASSWORD') ?: wp_generate_password(32, true, true),
        'role' => $role,
        'meta_input' => array_merge($metadata, ['_tm_demo_seed' => '1']),
    ]);
    tm_demo_seed_require(!is_wp_error($user_id), is_wp_error($user_id) ? $user_id->get_error_message() : 'Creazione utente non riuscita.');
    return (int) $user_id;
}

function tm_demo_seed_block($name, $values) {
    return serialize_block(['blockName' => $name, 'attrs' => ['values' => $values], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
}

function tm_demo_seed_request($route, $body, $query = []) {
    $request = new WP_REST_Request('POST', '/turnimedici/v1/' . $route);
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $request->set_query_params($query);
    $request->set_body(wp_json_encode($body));
    $response = rest_do_request($request);
    $payload = $response->get_data();
    tm_demo_seed_require($response->get_status() < 400, $payload['message'] ?? 'Richiesta demo non riuscita.');
    return $payload;
}

function tm_demo_seed_output() {
    WP_CLI::line(home_url('/'));
    WP_CLI::line(wp_login_url());
    foreach (get_posts(['post_type' => 'tm_facility', 'numberposts' => -1, 'meta_key' => '_tm_demo_seed', 'meta_value' => '1']) as $facility) {
        WP_CLI::line(tm_turni_url($facility->post_name));
    }
    foreach (get_users(['meta_key' => '_tm_demo_seed', 'meta_value' => '1', 'orderby' => 'login']) as $user) {
        WP_CLI::line($user->user_login);
    }
}

try {
    if (get_option('tm_demo_seed_version') === '1') {
        tm_demo_seed_output();
        return;
    }
    $installer_id = tm_demo_seed_guard();
    get_user_by('id', $installer_id)->add_cap('manage_corsivo_developer_tools');
    tm_demo_seed_require(!get_option('tm_demo_seed_version'), 'Versione seed non riconosciuta.');
    wp_set_current_user($installer_id);
    add_filter('pre_wp_mail', '__return_false', PHP_INT_MAX);
    add_action('save_post_tm_schedule', function ($post_id) {
        update_post_meta($post_id, '_tm_demo_seed', '1');
    });
    $month = get_option('tm_demo_seed_month') ?: wp_date('Y-m', null, new DateTimeZone('Europe/Rome'));
    update_option('tm_demo_seed_month', $month, false);
    update_option('blogname', 'Turni Medici Demo');
    update_option('blogdescription', 'Demo di Define Blocks e Core Editor con dati sintetici');
    update_option('timezone_string', 'Europe/Rome');
    update_option('users_can_register', 0);
    update_option('blog_public', 0);

    $aft_id = tm_demo_seed_post('aft-demo', 'tm_aft', 'AFT Demo',
        tm_demo_seed_block('turnimedici/callout', ['variant' => 'info', 'title' => 'Rete dimostrativa', 'text' => '<p>Questa AFT, le strutture e tutti i profili sono fittizi.</p>']),
        ['tm_aft_area' => 'Area Demo', 'tm_aft_manager' => 'Coordinatore Demo', 'tm_aft_email' => 'coordinatore-demo@demo.invalid']);

    $doctors = [];
    foreach (['uno', 'due', 'tre'] as $index => $label) {
        $doctors[] = tm_demo_seed_user('medico-demo-' . $label, 'Medico Demo ' . ucfirst($label), 'tm_doctor', [
            'tm_doctor_enabled' => 1,
            'tm_qualification' => ['mmg', 'ruap_vincolato', 'ruap_volontario'][$index],
            'tm_home_aft' => $aft_id,
            'tm_patients' => [800, 600, 400][$index],
            'tm_weekly_hours' => 6,
            'tm_profile_confirmed_at' => current_time('mysql'),
        ]);
    }
    tm_demo_seed_user('coordinatore-demo', 'Coordinatore Demo', TM_COORDINATOR_ROLE, ['tm_coordinated_aft' => $aft_id]);

    foreach (['nord', 'sud'] as $facility_index => $label) {
        $week = [
            'viewMonth' => $month,
            'defaults' => ['closedWeekdays' => ['sat', 'sun'], 'slots' => [['from' => '08:00', 'to' => '11:00'], ['from' => '11:00', 'to' => '14:00']]],
            'overrides' => [],
        ];
        $facility_id = tm_demo_seed_post('struttura-demo-' . $label, 'tm_facility', 'Struttura Demo ' . ucfirst($label),
            tm_demo_seed_block('turnimedici/callout', ['variant' => 'info', 'title' => 'Struttura dimostrativa', 'text' => '<p>Calendario di esempio con fasce e assegnazioni sintetiche.</p>']) . "\n\n"
                . tm_demo_seed_block('turnimedici/weekly-schedule', ['week' => $week]),
            ['tm_aft_id' => $aft_id, 'tm_facility_short' => strtoupper($label), 'tm_facility_modes' => [$facility_index === 0 ? 'cdc' : 'spoke']]);

        $schedule = tm_facility_schedule_for_month($facility_id, $month);
        if (!$schedule) {
            $created = tm_demo_seed_request('structures/' . $facility_id . '/schedules', ['month' => $month, 'set_default' => true]);
            $schedule_id = (int) $created['schedule']['id'];
            update_post_meta($schedule_id, '_tm_demo_seed', '1');
        } else {
            $schedule_id = (int) $schedule->ID;
            tm_demo_seed_require(get_post_meta($schedule_id, '_tm_demo_seed', true) === '1', 'Calendario non appartenente al seed.');
        }
        tm_demo_seed_request('structures/' . $facility_id . '/calendar', ['week' => $week], ['schedule' => $schedule_id]);
        $days = (int) (new DateTimeImmutable($month . '-01', wp_timezone()))->format('t');
        $open_day_index = 0;
        for ($day = 1; $day <= $days; $day++) {
            $date = sprintf('%s-%02d', $month, $day);
            $effective = tm_board_effective_day($week, $date);
            if (!$effective['open']) continue;
            foreach ($effective['slots'] as $slot_index => $slot) {
                if (($open_day_index + $slot_index + $facility_index) % 5 === 0) continue;
                $slot_key = tm_slot_key($slot);
                $doctor_id = $doctors[($open_day_index + $slot_index + $facility_index) % count($doctors)];
                global $wpdb;
                $existing_doctor = $wpdb->get_var($wpdb->prepare('SELECT doctor_id FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND shift_date = %s AND slot = %s', $schedule_id, $date, $slot_key));
                if ($existing_doctor !== null) {
                    tm_demo_seed_require((int) $existing_doctor === $doctor_id, 'Una cella demo è stata modificata: seed interrotto senza sovrascriverla.');
                    continue;
                }
                tm_demo_seed_request('structures/' . $facility_id . '/cell', ['date' => $date, 'slot' => $slot_key, 'doctor_id' => $doctor_id], ['schedule' => $schedule_id]);
            }
            $open_day_index++;
        }
    }

    tm_demo_seed_post('guida-demo', 'page', 'Come usare la demo', implode("\n\n", [
        tm_demo_seed_block('turnimedici/callout', ['variant' => 'info', 'title' => 'Solo dati sintetici', 'text' => '<p>La demo mostra il funzionamento dei calendari e dei blocchi editoriali. Tutti i nomi, le email e i turni sono inventati.</p>']),
        tm_demo_seed_block('turnimedici/key-stats', ['intro' => 'Scenario dimostrativo', 'items' => [['value' => '1', 'label' => 'AFT demo'], ['value' => '2', 'label' => 'Strutture demo'], ['value' => '3', 'label' => 'Medici demo']]]),
        tm_demo_seed_block('turnimedici/compare', ['left_label' => 'Area medici', 'left' => '<p>Consulta i turni, il profilo e le informazioni della demo.</p>', 'right_label' => 'Coordinamento', 'right' => '<p>Gestisci i calendari e assegna i medici alle fasce disponibili.</p>']),
        tm_demo_seed_block('turnimedici/timeline', ['items' => [['date' => tm_month_label($month), 'title' => 'Calendari di esempio', 'text' => '<p>Le fasce vuote permettono di provare nuove assegnazioni.</p>']]]),
    ]), ['tm_show_in_app' => 1]);

    tm_refresh_facility_rewrite_map();
    flush_rewrite_rules(false);
    tm_demo_seed_require(update_option('tm_demo_seed_version', '1', false), 'Impossibile confermare il completamento del seed.');
    tm_demo_seed_output();
} catch (Throwable $exception) {
    WP_CLI::error($exception->getMessage());
}
