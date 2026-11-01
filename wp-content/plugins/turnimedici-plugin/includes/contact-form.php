<?php

if (!defined('ABSPATH')) exit;

const TM_CONTACT_MAX_REQUESTS = 5;
const TM_CONTACT_WINDOW = 15 * MINUTE_IN_SECONDS;

function tm_contact_recipient() {
    return apply_filters('tm_contact_recipient', get_option('admin_email', 'demo@example.invalid'));
}

function tm_contact_reasons() {
    return [
        'info'          => 'Richiesta di informazioni',
        'cancellazione' => 'Richiesta di cancellazione dei dati',
        'avviso'        => 'Avviso / segnalazione',
    ];
}

add_action('rest_api_init', function () {
    register_rest_route('turnimedici/v1', '/contact', [
        'methods'             => 'POST',
        'callback'            => 'tm_rest_contact',
        'permission_callback' => '__return_true',
    ]);
});

function tm_rest_contact($request) {
    if (tm_demo_mode()) {
        return new WP_Error('tm_demo_mail_disabled', 'Il modulo è dimostrativo: nessun messaggio viene inviato.', ['status' => 403]);
    }
    if (!empty($request['website'])) {
        return rest_ensure_response(['sent' => true]);
    }

    $ip_key = 'tm_contact_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $requests = (int) get_transient($ip_key);
    if ($requests >= TM_CONTACT_MAX_REQUESTS) {
        return new WP_Error('throttled', 'Troppe richieste: riprova tra qualche minuto.', ['status' => 429]);
    }
    set_transient($ip_key, $requests + 1, TM_CONTACT_WINDOW);

    $name    = sanitize_text_field((string) $request['name']);
    $email   = sanitize_email((string) $request['email']);
    $reason  = sanitize_key((string) $request['reason']);
    $message = sanitize_textarea_field((string) $request['message']);
    $privacy = !empty($request['privacy']);

    if (!$name || !$email || !is_email($email) || !$message) {
        return new WP_Error('invalid', 'Compila nome, email e messaggio.', ['status' => 400]);
    }
    if (mb_strlen($message) > 5000) {
        return new WP_Error('too_long', 'Il messaggio è troppo lungo (massimo 5000 caratteri).', ['status' => 400]);
    }
    if (!$privacy) {
        return new WP_Error('privacy', 'Devi accettare l\'informativa sulla privacy.', ['status' => 400]);
    }

    $reasons      = tm_contact_reasons();
    $reason_label = $reasons[$reason] ?? 'Segnalazione';

    $reply_to = ['Reply-To: ' . $name . ' <' . $email . '>'];
    $body = "Nuova segnalazione dal sito " . home_url('/') . "\n\n"
        . "Motivo: {$reason_label}\n"
        . "Nome: {$name}\n"
        . "Email: {$email}\n"
        . "Data: " . wp_date('d/m/Y H:i') . "\n"
        . "Consenso privacy: sì\n\n"
        . "Messaggio:\n{$message}\n";

    $sent = wp_mail(tm_contact_recipient(), $reason_label . ' — ' . $name, $body, $reply_to);

    if (!$sent) {
        return new WP_Error('send_failed', 'Invio non riuscito, riprova più tardi o scrivi a ' . tm_contact_recipient() . '.', ['status' => 500]);
    }

    return rest_ensure_response(['sent' => true]);
}

add_action('init', 'tm_register_contact_form_block', 20);
function tm_register_contact_form_block() {
    if (!function_exists('define_block_type')) return;

    define_block_type('turnimedici/contact-form', [
        'title'    => 'Modulo segnalazioni',
        'icon'     => 'email',
        'category' => 'design',
        'keywords' => ['contatto', 'segnalazione', 'form', 'cancellazione'],
        'schema'   => [
            [
                'scope'  => 'content',
                'fields' => [
                    'title' => ['type' => 'text', 'label' => 'Titolo', 'default' => 'Scrivici'],
                    'intro' => ['type' => 'textarea', 'label' => 'Introduzione', 'default' => 'Per avvisi, richieste di cancellazione dei dati o informazioni: compila il modulo, ti rispondiamo via email.'],
                ],
            ],
        ],
        'render'   => 'tm_render_contact_form',
    ]);
}

function tm_render_contact_form($attributes, $content, $block) {
    $values = $attributes['values'] ?? [];
    $title  = trim($values['title'] ?? '');
    $intro  = trim($values['intro'] ?? '');

    $privacy_url   = function_exists('get_privacy_policy_url') ? get_privacy_policy_url() : '';
    $privacy_label = $privacy_url
        ? sprintf('Ho letto l\'<a href="%s" target="_blank" rel="noopener">informativa sulla privacy</a> e acconsento al trattamento dei miei dati per rispondere alla richiesta.', esc_url($privacy_url))
        : 'Ho letto l\'informativa sulla privacy e acconsento al trattamento dei miei dati per rispondere alla richiesta.';

    $reason_options = '';
    foreach (tm_contact_reasons() as $value => $label) {
        $reason_options .= '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
    }

    ob_start();
    ?>
    <div class="tm-contact">
        <?php if ($title !== '') : ?><h2 class="tm-contact__title"><?php echo esc_html($title); ?></h2><?php endif; ?>
        <?php if ($intro !== '') : ?><p class="tm-contact__intro"><?php echo esc_html($intro); ?></p><?php endif; ?>
        <?php if (tm_demo_mode()) : ?><p>Modulo dimostrativo: nessun messaggio viene inviato.</p><?php endif; ?>
        <form class="tm-contact__form" novalidate>
            <label>Nome e cognome
                <input type="text" name="name" required autocomplete="name">
            </label>
            <label>Email
                <input type="email" name="email" required autocomplete="email">
            </label>
            <label>Motivo
                <select name="reason"><?php echo $reason_options; ?></select>
            </label>
            <label>Messaggio
                <textarea name="message" rows="6" required maxlength="5000"></textarea>
            </label>
            <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" class="tm-contact__hp">
            <label class="tm-contact__privacy">
                <input type="checkbox" name="privacy" required>
                <span><?php echo wp_kses($privacy_label, ['a' => ['href' => [], 'target' => [], 'rel' => []]]); ?></span>
            </label>
            <button type="submit" <?php disabled(tm_demo_mode()); ?>>Invia la segnalazione</button>
            <p class="tm-contact__feedback" role="status" aria-live="polite"></p>
        </form>
    </div>
    <?php
    $html = ob_get_clean();

    static $assets_printed = false;
    if (!$assets_printed) {
        $assets_printed = true;
        $html .= tm_contact_form_assets();
    }

    return $html;
}

function tm_contact_form_assets() {
    $endpoint = esc_url_raw(rest_url('turnimedici/v1/contact'));
    ob_start();
    ?>
    <style>
    .tm-contact { max-width: 560px; }
    .tm-contact__form { display: flex; flex-direction: column; gap: 16px; }
    .tm-contact__form label { display: flex; flex-direction: column; gap: 6px; font-weight: 600; font-size: 14px; }
    .tm-contact__form input[type="text"],
    .tm-contact__form input[type="email"],
    .tm-contact__form select,
    .tm-contact__form textarea {
        font: inherit;
        font-weight: 400;
        color: var(--tm-ink, #16293a);
        background: #fff;
        border: 1px solid var(--tm-line, #e6edf4);
        border-radius: 10px;
        padding: 10px 14px;
        outline: none;
    }
    .tm-contact__form input:focus,
    .tm-contact__form select:focus,
    .tm-contact__form textarea:focus { border-color: var(--tm-blue, #2b6fe0); }
    .tm-contact__hp { position: absolute; left: -9999px; }
    .tm-contact__privacy { flex-direction: row !important; align-items: flex-start; font-weight: 400 !important; font-size: 13px !important; gap: 10px !important; }
    .tm-contact__privacy input { margin-top: 3px; }
    .tm-contact__privacy a { color: var(--tm-blue, #2b6fe0); }
    .tm-contact__form button {
        align-self: flex-start;
        font: inherit;
        font-weight: 600;
        color: #fff;
        background: var(--tm-blue, #2b6fe0);
        border: none;
        border-radius: 999px;
        padding: 12px 28px;
        cursor: pointer;
    }
    .tm-contact__form button:hover { background: var(--tm-blue-dark, #1f55b8); }
    .tm-contact__form button:disabled { opacity: 0.6; cursor: default; }
    .tm-contact__feedback { min-height: 20px; margin: 0; font-size: 14px; }
    .tm-contact__feedback.is-error { color: var(--tm-red, #d64535); }
    .tm-contact__feedback.is-ok { color: var(--tm-green, #1f9d5c); }
    </style>
    <script>
    (function () {
        document.querySelectorAll('.tm-contact__form').forEach(function (form) {
            var feedback = form.querySelector('.tm-contact__feedback');
            var button = form.querySelector('button[type="submit"]');
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!form.reportValidity()) return;
                button.disabled = true;
                feedback.className = 'tm-contact__feedback';
                feedback.textContent = 'Invio in corso…';
                fetch('<?php echo $endpoint; ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name: form.name.value,
                        email: form.email.value,
                        reason: form.reason.value,
                        message: form.message.value,
                        privacy: form.privacy.checked,
                        website: form.website.value
                    })
                }).then(function (response) {
                    return response.json().then(function (data) { return { ok: response.ok, data: data }; });
                }).then(function (result) {
                    if (result.ok) {
                        form.reset();
                        feedback.classList.add('is-ok');
                        feedback.textContent = 'Segnalazione inviata: ti risponderemo via email. Grazie!';
                    } else {
                        feedback.classList.add('is-error');
                        feedback.textContent = (result.data && result.data.message) || 'Invio non riuscito, riprova.';
                    }
                }).catch(function () {
                    feedback.classList.add('is-error');
                    feedback.textContent = 'Invio non riuscito, riprova.';
                }).finally(function () {
                    button.disabled = false;
                });
            });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
