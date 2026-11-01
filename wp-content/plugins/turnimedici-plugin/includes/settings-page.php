<?php

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_options_page('Turni Medici', 'Turni Medici', 'manage_options', 'tm-settings', 'tm_render_settings_page');
});

function tm_require_profile_confirmation() {
    return (bool) get_option('tm_require_profile_confirmation', 1);
}

const TM_MAGIC_MESSAGE_DEFAULT = "Ciao {{first_name}}, questo è il tuo link personale per accedere a Turni Medici:\n{{magic_link}}\n\nIl link è personale: non condividerlo.";

function tm_magic_message_template() {
    $template = (string) get_option('tm_magic_message_template', '');
    return trim($template) !== '' ? $template : TM_MAGIC_MESSAGE_DEFAULT;
}

function tm_submit_watchers() {
    $raw = get_option('tm_submit_watchers', []);
    if (!is_array($raw)) return [];
    $watchers = [];
    foreach ($raw as $row) {
        $email = sanitize_email($row['email'] ?? '');
        if (!$email || !is_email($email)) continue;
        $watchers[] = ['email' => $email, 'facility_id' => (int) ($row['facility_id'] ?? 0)];
    }
    return $watchers;
}

function tm_render_settings_page() {
    $dump      = get_option('tm_estar_dump', []);
    $dump_rows = function_exists('tm_estar_dump_count') ? tm_estar_dump_count() : 0;
    $aft_count = count(get_posts(['post_type' => 'tm_aft', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids']));
    $notice    = tm_settings_notice_message();
    $annual_hours_reference = max(0, (int) get_option('tm_annual_hours_reference', 0));
    ?>
    <div class="wrap">
        <h1>Turni Medici</h1>
        <?php if ($notice) : ?>
        <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['text']); ?></p></div>
        <?php endif; ?>

        <div style="display:grid;gap:20px;max-width:680px;margin-top:16px">

            <div class="card" style="max-width:none;padding:4px 20px 20px">
                <h2>Graduatoria e monte ore</h2>
                <p>L'ordine delle categorie resta configurabile nella pagina Graduatoria. Le ore obbligatorie dei RUAP vincolati si assegnano invece per singolo turno dalla lista partecipanti nell'app.</p>
                <p><a class="button button-secondary" href="<?php echo esc_url(admin_url('users.php?page=tm-ranking')); ?>">Configura graduatoria</a></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('tm_save_hours_settings'); ?>
                    <input type="hidden" name="action" value="tm_save_hours_settings">
                    <label for="tm_annual_hours_reference"><strong>Riferimento annuale ore</strong></label>
                    <p><input type="number" min="0" step="1" id="tm_annual_hours_reference" name="annual_hours_reference" value="<?php echo (int) $annual_hours_reference; ?>" class="small-text"> ore</p>
                    <p class="description">È solo un riferimento visivo nella banca ore. Con 0 non viene mostrato alcun obiettivo o tetto.</p>
                    <?php submit_button('Salva impostazioni ore', 'primary', 'submit', false); ?>
                </form>
            </div>

            <div class="card" style="max-width:none;padding:4px 20px 20px">
                <h2>Scarica le AFT da ESTAR</h2>
                <?php if (tm_demo_mode()) : ?><p>Integrazione disabilitata nella demo: usa le AFT e i medici fittizi.</p><?php endif; ?>
                <p>Legge le AFT presenti nell'archivio ESTAR (lo stesso usato in Utenti → Medici) e crea le AFT mancanti, con il nome già ripulito. Le AFT esistenti non vengono toccate.</p>
                <p style="color:#646970;font-size:13px;">
                    Archivio ESTAR: <?php echo $dump_rows ? (int) $dump_rows . ' medici' : 'vuoto'; ?>
                    <?php if (!empty($dump['updated'])) : ?> — aggiornato il <?php echo esc_html(wp_date('d/m/Y H:i', $dump['updated'])); ?><?php endif; ?>
                    · AFT attuali: <?php echo (int) $aft_count; ?>
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('tm_download_afts'); ?>
                    <input type="hidden" name="action" value="tm_download_afts">
                    <?php submit_button('Scarica le AFT', 'primary', 'submit', false, tm_demo_mode() ? ['disabled' => 'disabled'] : null); ?>
                </form>
            </div>

            <div class="card" style="max-width:none;padding:4px 20px 20px">
                <h2>Ripulisci i nomi delle AFT</h2>
                <p>Normalizza i titoli delle AFT esistenti: rimuove le sigle burocratiche ("AFT", "PLS", codici provincia), i numeri iniziali e il TUTTO MAIUSCOLO. Esempio: <code>AFT DEMO CENTRO</code> → <code>Demo Centro</code>.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('tm_clean_aft_names'); ?>
                    <input type="hidden" name="action" value="tm_clean_aft_names">
                    <?php submit_button('Ripulisci i nomi', 'secondary', 'submit', false); ?>
                </form>
            </div>

            <div class="card" style="max-width:none;padding:4px 20px 20px">
                <h2>Medici debug</h2>
                <p>Aggiunge o rimuove cinque medici finti per testare ricerca, graduatoria e liste turno. Usa solo le email <code>user1@example.invalid</code> … <code>user5@example.invalid</code>.</p>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('tm_add_debug_doctors'); ?>
                        <input type="hidden" name="action" value="tm_add_debug_doctors">
                        <?php submit_button('Aggiungi medici debug', 'secondary', 'submit', false); ?>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Rimuovere i medici debug?');">
                        <?php wp_nonce_field('tm_remove_debug_doctors'); ?>
                        <input type="hidden" name="action" value="tm_remove_debug_doctors">
                        <?php submit_button('Rimuovi medici debug', 'delete', 'submit', false); ?>
                    </form>
                </div>
            </div>

            <div class="card" style="max-width:none;padding:4px 20px 20px">
                <h2>App e notifiche</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('tm_save_app_settings'); ?>
                    <input type="hidden" name="action" value="tm_save_app_settings">

                    <p>
                        <label>
                            <input type="checkbox" name="require_profile_confirmation" value="1" <?php checked(tm_require_profile_confirmation()); ?>>
                            <strong>Chiedi la conferma dei dati al primo accesso</strong>
                        </label>
                    </p>
                    <p class="description">Se disattivata, la finestra iniziale di conferma dei dati personali non compare e la compilazione dei turni non richiede il profilo completo.</p>

                    <hr>

                    <p><label for="tm_magic_message_template"><strong>Messaggio con il link di accesso</strong></label></p>
                    <textarea id="tm_magic_message_template" name="magic_message_template" class="large-text" rows="5"><?php echo esc_textarea(tm_magic_message_template()); ?></textarea>
                    <p class="description">Usato dalle voci "Copia messaggi" nell'app. Variabili: <code>{{first_name}}</code>, <code>{{name}}</code>, <code>{{magic_link}}</code>, <code>{{structure}}</code>, <code>{{month}}</code>.</p>

                    <hr>

                    <p><strong>Notifiche di compilazione turni</strong></p>
                    <p class="description">Ogni volta che un medico compila (o salta) il proprio turno, questi indirizzi ricevono un'email. Lascia vuota l'email per rimuovere una riga.</p>
                    <?php
                    $watchers = tm_submit_watchers();
                    $watchers[] = ['email' => '', 'facility_id' => 0];
                    $watchers[] = ['email' => '', 'facility_id' => 0];
                    $facilities = get_posts(['post_type' => 'tm_facility', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
                    foreach ($watchers as $index => $watcher) :
                    ?>
                    <p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <input type="email" name="watcher_email[]" value="<?php echo esc_attr($watcher['email']); ?>" placeholder="demo@example.invalid" class="regular-text">
                        <select name="watcher_facility[]">
                            <option value="0" <?php selected($watcher['facility_id'], 0); ?>>Tutte le strutture</option>
                            <?php foreach ($facilities as $facility) : ?>
                                <option value="<?php echo (int) $facility->ID; ?>" <?php selected($watcher['facility_id'], $facility->ID); ?>><?php echo esc_html($facility->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <?php endforeach; ?>

                    <?php submit_button('Salva app e notifiche', 'primary', 'submit', false); ?>
                </form>
            </div>

            <?php if (function_exists('tm_render_data_transfer_settings')) tm_render_data_transfer_settings(); ?>

        </div>
    </div>
    <?php
}

function tm_settings_notice_message() {
    if (empty($_GET['tm_notice'])) return null;
    $created = (int) ($_GET['created'] ?? 0);
    $skipped = (int) ($_GET['skipped'] ?? 0);
    $updated = (int) ($_GET['updated'] ?? 0);
    $deleted = (int) ($_GET['deleted'] ?? 0);
    $renamed = (int) ($_GET['renamed'] ?? 0);

    switch ($_GET['tm_notice']) {
        case 'afts_downloaded':
            return ['type' => 'success', 'text' => "AFT scaricate: {$created} create, {$skipped} già presenti."];
        case 'afts_cleaned':
            return ['type' => 'success', 'text' => $renamed ? "Nomi ripuliti: {$renamed} AFT rinominate." : 'Nomi già puliti: nessuna modifica necessaria.'];
        case 'debug_doctors_added':
            return ['type' => 'success', 'text' => "Medici debug: {$created} creati, {$updated} aggiornati, {$skipped} saltati."];
        case 'debug_doctors_removed':
            return ['type' => 'success', 'text' => $deleted ? "Medici debug rimossi: {$deleted}." : 'Nessun medico debug da rimuovere.'];
        case 'debug_doctors_no_aft':
            return ['type' => 'error', 'text' => 'Crea almeno una AFT prima di aggiungere i medici debug.'];
        case 'hours_saved':
            return ['type' => 'success', 'text' => 'Impostazioni ore salvate.'];
        case 'app_settings_saved':
            return ['type' => 'success', 'text' => 'Impostazioni app e notifiche salvate.'];
        case 'dump_failed':
            return ['type' => 'error', 'text' => 'Download dell\'archivio ESTAR non riuscito: riprova più tardi.'];
        case 'dump_empty':
            return ['type' => 'error', 'text' => 'Nessuna AFT trovata nell\'archivio ESTAR.'];
    }
    return null;
}

function tm_settings_redirect($args) {
    wp_safe_redirect(add_query_arg($args, admin_url('options-general.php?page=tm-settings')));
    exit;
}

add_action('admin_post_tm_download_afts', 'tm_handle_download_afts');
function tm_handle_download_afts() {
    check_admin_referer('tm_download_afts');
    if (!current_user_can('manage_options')) wp_die('Non autorizzato.');
    if (tm_demo_mode()) wp_die(esc_html(tm_demo_integration_error()->get_error_message()));

    if (tm_estar_dump_count() === 0 && !tm_download_estar_dump()) {
        tm_settings_redirect(['tm_notice' => 'dump_failed']);
    }

    global $wpdb;
    $names = $wpdb->get_col('SELECT DISTINCT aft FROM ' . tm_table('estar_dump') . " WHERE aft IS NOT NULL AND aft <> ''");
    if (!$names) {
        tm_settings_redirect(['tm_notice' => 'dump_empty']);
    }

    $created = 0;
    $skipped = 0;
    foreach ($names as $raw) {
        $raw = trim(preg_replace('/\s+/', ' ', (string) $raw));
        if ($raw === '') continue;

        if (tm_match_aft($raw)) {
            $skipped++;
            continue;
        }

        $post_id = wp_insert_post([
            'post_type'   => 'tm_aft',
            'post_status' => 'publish',
            'post_title'  => tm_aft_clean_title($raw),
        ]);
        if ($post_id && !is_wp_error($post_id)) {
            update_post_meta($post_id, 'tm_estar_aft_name', $raw);
            $created++;
        }
    }

    tm_settings_redirect(['tm_notice' => 'afts_downloaded', 'created' => $created, 'skipped' => $skipped]);
}

add_action('admin_post_tm_clean_aft_names', 'tm_handle_clean_aft_names');
function tm_handle_clean_aft_names() {
    check_admin_referer('tm_clean_aft_names');
    if (!current_user_can('manage_options')) wp_die('Non autorizzato.');

    $renamed = 0;
    foreach (get_posts(['post_type' => 'tm_aft', 'numberposts' => -1, 'post_status' => 'any']) as $aft) {
        $source = (string) get_post_meta($aft->ID, 'tm_estar_aft_name', true);
        if ($source === '') {
            $source = $aft->post_title;
        }
        $clean = tm_aft_clean_title($source);
        if ($clean !== '' && $clean !== $aft->post_title) {
            wp_update_post(['ID' => $aft->ID, 'post_title' => $clean]);
            $renamed++;
        }
    }

    tm_settings_redirect(['tm_notice' => 'afts_cleaned', 'renamed' => $renamed]);
}

add_action('admin_post_tm_save_hours_settings', 'tm_handle_save_hours_settings');
function tm_handle_save_hours_settings() {
    check_admin_referer('tm_save_hours_settings');
    if (!current_user_can('manage_options')) wp_die('Non autorizzato.');

    update_option('tm_annual_hours_reference', absint($_POST['annual_hours_reference'] ?? 0), false);
    tm_settings_redirect(['tm_notice' => 'hours_saved']);
}

add_action('admin_post_tm_save_app_settings', 'tm_handle_save_app_settings');
function tm_handle_save_app_settings() {
    check_admin_referer('tm_save_app_settings');
    if (!current_user_can('manage_options')) wp_die('Non autorizzato.');

    update_option('tm_require_profile_confirmation', empty($_POST['require_profile_confirmation']) ? 0 : 1, false);
    update_option('tm_magic_message_template', sanitize_textarea_field(wp_unslash($_POST['magic_message_template'] ?? '')), false);

    $emails = array_map('sanitize_email', (array) wp_unslash($_POST['watcher_email'] ?? []));
    $facility_ids = array_map('absint', (array) ($_POST['watcher_facility'] ?? []));
    $watchers = [];
    foreach ($emails as $index => $email) {
        if (!$email || !is_email($email)) continue;
        $facility_id = $facility_ids[$index] ?? 0;
        if ($facility_id && get_post_type($facility_id) !== 'tm_facility') $facility_id = 0;
        $watchers[] = ['email' => $email, 'facility_id' => $facility_id];
    }
    update_option('tm_submit_watchers', $watchers, false);

    tm_settings_redirect(['tm_notice' => 'app_settings_saved']);
}

function tm_debug_doctor_rows() {
    return [
        ['email' => 'user1@example.invalid', 'first_name' => 'Alba', 'last_name' => 'Demo', 'phone' => '', 'qualification' => 'ruap_vincolato', 'patients' => 920],
        ['email' => 'user2@example.invalid', 'first_name' => 'Bruno', 'last_name' => 'Demo', 'phone' => '', 'qualification' => 'ruap_volontario', 'patients' => 1280],
        ['email' => 'user3@example.invalid', 'first_name' => 'Carla', 'last_name' => 'Demo', 'phone' => '', 'qualification' => 'mmg', 'patients' => 1510],
        ['email' => 'user4@example.invalid', 'first_name' => 'Dario', 'last_name' => 'Demo', 'phone' => '', 'qualification' => 'ca_titolare', 'patients' => 0],
        ['email' => 'user5@example.invalid', 'first_name' => 'Elena', 'last_name' => 'Demo', 'phone' => '', 'qualification' => 'ca_temporaneo', 'patients' => 0, 'ca_zone' => 'Debug'],
    ];
}

function tm_debug_default_aft_id() {
    $ids = get_posts([
        'post_type'      => 'tm_aft',
        'post_status'    => ['publish', 'draft'],
        'fields'         => 'ids',
        'numberposts'    => 1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'suppress_filters' => false,
    ]);
    return $ids ? (int) $ids[0] : 0;
}

add_action('admin_post_tm_add_debug_doctors', 'tm_handle_add_debug_doctors');
function tm_handle_add_debug_doctors() {
    check_admin_referer('tm_add_debug_doctors');
    if (!current_user_can('manage_options')) wp_die('Non autorizzato.');

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $aft_id = tm_debug_default_aft_id();
    if (!$aft_id) {
        tm_settings_redirect(['tm_notice' => 'debug_doctors_no_aft']);
    }

    foreach (tm_debug_doctor_rows() as $row) {
        $email = sanitize_email($row['email']);
        $user = get_user_by('email', $email);
        if ($user && (int) get_user_meta($user->ID, 'tm_debug_doctor', true) !== 1) {
            $skipped++;
            continue;
        }
        if (!$user) {
            $user_id = tm_create_doctor_user($email, $row['first_name'], $row['last_name']);
            if (is_wp_error($user_id)) {
                $skipped++;
                continue;
            }
            $user = get_user_by('id', $user_id);
            $created++;
        } else {
            $updated_user = wp_update_user([
                'ID'           => $user->ID,
                'first_name'   => $row['first_name'],
                'last_name'    => $row['last_name'],
                'display_name' => trim($row['first_name'] . ' ' . $row['last_name']),
            ]);
            if (is_wp_error($updated_user)) {
                $skipped++;
                continue;
            }
            $updated++;
        }
        $user->set_role('tm_doctor');

        $payload = $row;
        if ($aft_id && $row['qualification'] !== 'ca_temporaneo') {
            $payload['home_aft'] = $aft_id;
        }
        tm_save_doctor_meta($user->ID, $payload);
        if ($row['qualification'] === 'ca_temporaneo') {
            tm_set_ca_periods($user->ID, [['start' => '2020-01-01', 'end' => '2099-12-31']]);
        }
        update_user_meta($user->ID, 'tm_debug_doctor', 1);
        if (!get_user_meta($user->ID, 'tm_magic_token', true)) {
            update_user_meta($user->ID, 'tm_magic_token', bin2hex(random_bytes(20)));
        }
    }

    tm_settings_redirect(['tm_notice' => 'debug_doctors_added', 'created' => $created, 'updated' => $updated, 'skipped' => $skipped]);
}

add_action('admin_post_tm_remove_debug_doctors', 'tm_handle_remove_debug_doctors');
function tm_handle_remove_debug_doctors() {
    check_admin_referer('tm_remove_debug_doctors');
    if (!current_user_can('manage_options')) wp_die('Non autorizzato.');

    require_once ABSPATH . 'wp-admin/includes/user.php';

    global $wpdb;
    $deleted = 0;
    foreach (tm_debug_doctor_rows() as $row) {
        $user = get_user_by('email', sanitize_email($row['email']));
        if (!$user || (int) get_user_meta($user->ID, 'tm_debug_doctor', true) !== 1) continue;

        $doctor_id = (int) $user->ID;
        $wpdb->delete(tm_table('shifts'), ['doctor_id' => $doctor_id]);
        $wpdb->delete(tm_table('assignments'), ['doctor_id' => $doctor_id]);
        $wpdb->query($wpdb->prepare('DELETE FROM ' . tm_table('queue_log') . ' WHERE doctor_id = %d OR actor_id = %d', $doctor_id, $doctor_id));
        wp_delete_user($doctor_id);
        $deleted++;
    }

    tm_settings_redirect(['tm_notice' => 'debug_doctors_removed', 'deleted' => $deleted]);
}
