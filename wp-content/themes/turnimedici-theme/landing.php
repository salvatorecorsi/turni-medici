<?php

if (!defined('ABSPATH')) exit;
$tm_demo = function_exists('tm_demo_mode') && tm_demo_mode();
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php wp_head(); ?>
    <style>
        .tm-landing {
            --tm-blue: #2b6fe0;
            --tm-blue-dark: #1f55b8;
            --tm-blue-50: #eaf1fd;
            --tm-green: #1f9d5c;
            --tm-ink: #16293a;
            --tm-muted: #5b6b7b;
            --tm-line: #e6edf4;
            --tm-bg: #f6faff;
            --tm-radius-lg: 22px;
            --tm-shadow: 0 4px 18px rgba(28, 70, 140, 0.07);
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: var(--tm-ink);
            background: var(--tm-bg);
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }
        .tm-landing * { box-sizing: border-box; margin: 0; padding: 0; }
        .tm-landing-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 28px clamp(24px, 6vw, 64px);
        }
        .tm-landing-header .tm-clock { width: 40px; height: 40px; display: block; }
        .tm-landing-header strong { font-size: 18px; letter-spacing: -0.02em; }
        .tm-landing-main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .tm-landing-card {
            max-width: 520px;
            width: 100%;
            background: #fff;
            border: 1px solid var(--tm-line);
            border-radius: var(--tm-radius-lg);
            box-shadow: var(--tm-shadow);
            padding: clamp(32px, 6vw, 56px);
            text-align: center;
        }
        .tm-landing-badge {
            display: inline-block;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--tm-blue);
            background: var(--tm-blue-50);
            border-radius: 999px;
            padding: 6px 14px;
            margin-bottom: 20px;
        }
        .tm-landing-card h1 {
            font-size: clamp(26px, 5vw, 34px);
            letter-spacing: -0.03em;
            line-height: 1.15;
            margin-bottom: 14px;
        }
        .tm-landing-card p {
            color: var(--tm-muted);
            margin-bottom: 28px;
        }
        .tm-landing-card p a { color: var(--tm-blue); text-decoration: none; font-weight: 600; }
        .tm-landing-cta {
            display: inline-block;
            background: var(--tm-blue);
            color: #fff;
            font-weight: 600;
            text-decoration: none;
            border-radius: 999px;
            padding: 14px 30px;
            transition: background 0.15s ease;
        }
        .tm-landing-cta:hover { background: var(--tm-blue-dark); }
        .tm-landing-resend {
            margin-top: 30px;
            padding-top: 24px;
            border-top: 1px solid var(--tm-line);
        }
        .tm-landing-resend > p {
            font-size: 14px;
            margin-bottom: 12px;
        }
        .tm-landing-resend form {
            display: flex;
            gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .tm-landing-resend input[type="email"] {
            flex: 1;
            min-width: 200px;
            font: inherit;
            font-size: 14px;
            color: var(--tm-ink);
            background: var(--tm-bg);
            border: 1px solid var(--tm-line);
            border-radius: 999px;
            padding: 10px 18px;
            outline: none;
        }
        .tm-landing-resend input[type="email"]:focus { border-color: var(--tm-blue); }
        .tm-landing-resend input[name="website"] { position: absolute; left: -9999px; }
        .tm-landing-resend button {
            font: inherit;
            font-size: 14px;
            font-weight: 600;
            color: var(--tm-blue);
            background: var(--tm-blue-50);
            border: none;
            border-radius: 999px;
            padding: 10px 20px;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .tm-landing-resend button:hover { background: var(--tm-blue-100, #d4e4fb); }
        .tm-landing-resend button:disabled { opacity: 0.6; cursor: default; }
        .tm-landing-feedback {
            min-height: 20px;
            margin-top: 12px;
            font-size: 13px;
            color: var(--tm-green);
        }
        .tm-landing-contact {
            width: min(620px, calc(100vw - 32px));
            margin: 0 auto 48px;
            padding: 28px;
            background: #fff;
            border: 1px solid var(--tm-line);
            border-radius: 24px;
            box-shadow: 0 16px 40px rgba(28, 54, 78, 0.08);
        }
        .tm-landing-contact .tm-contact { max-width: none; }
        .tm-landing-footer {
            text-align: center;
            font-size: 13px;
            color: var(--tm-muted);
            padding: 24px;
        }
    </style>
</head>
<body <?php body_class('tm-landing'); ?>>
<?php wp_body_open(); ?>
<header class="tm-landing-header">
    <?php tm_clock_logo(); ?>
    <strong>Turni Medici</strong>
</header>
<main class="tm-landing-main">
    <div class="tm-landing-card">
        <span class="tm-landing-badge"><?php echo $tm_demo ? 'Demo con dati fittizi' : 'Accesso su invito'; ?></span>
        <h1>Turni Medici</h1>
        <p>Questa demo mostra la gestione dei turni con dati interamente fittizi. Accedi con un account locale di prova per esplorare l'applicazione.</p>
        <?php if ($tm_demo) : ?>
        <a class="tm-landing-cta" href="<?php echo esc_url(wp_login_url(home_url('/'))); ?>">Accedi alla demo</a>
        <p>Le email e le importazioni dai servizi esterni sono disabilitate.</p>
        <?php else : ?>
        <a class="tm-landing-cta" href="#richiedi-accesso">Richiedi l'accesso</a>
        <div class="tm-landing-resend">
            <p>Sei già dei nostri ma hai perso il link di accesso?</p>
            <form id="tm-resend-form">
                <input type="email" name="email" placeholder="La tua email" required autocomplete="email">
                <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
                <button type="submit">Reinvia il link</button>
            </form>
            <p class="tm-landing-feedback" role="status" aria-live="polite"></p>
        </div>
        <?php endif; ?>
    </div>
    <?php if (function_exists('tm_render_contact_form')) : ?>
        <section id="richiedi-accesso" class="tm-landing-contact">
            <?php
            echo tm_render_contact_form([
                'values' => [
                    'title' => $tm_demo ? 'Esempio di modulo contatti' : 'Richiedi l\'accesso',
                    'intro' => $tm_demo ? 'Blocco personalizzato costruito con Define Blocks.' : 'Scrivici per attivare Turni Medici nella tua struttura o nella tua AFT.',
                ],
            ], '', null);
            ?>
        </section>
    <?php endif; ?>
</main>
<footer class="tm-landing-footer">&copy; <?php echo esc_html(wp_date('Y')); ?> Turni Medici</footer>
<script>
(function () {
    var form = document.getElementById('tm-resend-form');
    if (!form) return;
    var feedback = document.querySelector('.tm-landing-feedback');
    var button = form.querySelector('button');
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        button.disabled = true;
        fetch('<?php echo esc_url_raw(rest_url('turnimedici/v1/access-link')); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                email: form.email.value,
                website: form.website.value
            })
        }).finally(function () {
            feedback.textContent = 'Se l\'indirizzo corrisponde a un account, riceverai il link via email entro qualche minuto.';
            form.reset();
            button.disabled = false;
        });
    });
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
