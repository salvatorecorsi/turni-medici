<?php

if (!defined('ABSPATH')) exit;

function tm_pwa_bundle_version() {
    $bundle = TM_DIR . 'app/build/dashboard.js';
    return file_exists($bundle) ? (string) filemtime($bundle) : '1';
}

add_action('wp_head', 'tm_pwa_head_links');
function tm_pwa_head_links() {
    if (!function_exists('tm_is_app_request') || !tm_is_app_request()) return;

    /* Manifest solo su mobile: su desktop il sito non deve proporsi né aprirsi come app installata */
    if (wp_is_mobile()) {
        printf('<link rel="manifest" href="%s">' . "\n", esc_url(home_url('/?tm_manifest=1')));
    }
    printf('<link rel="apple-touch-icon" href="%s">' . "\n", esc_url(TM_URL . 'assets/apple-touch-icon.png'));
    echo '<meta name="theme-color" content="#2b6fe0">' . "\n";
}

add_action('init', 'tm_pwa_manifest_endpoint', 0);
function tm_pwa_manifest_endpoint() {
    if (!isset($_GET['tm_manifest'])) return;

    nocache_headers();
    header('Content-Type: application/manifest+json; charset=utf-8');
    $scope = trailingslashit(wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/');
    echo wp_json_encode([
        'name'             => 'Turni Medici',
        'short_name'       => 'Turni',
        'description'      => 'Gestione dei turni per medici e coordinatori.',
        'id'               => $scope,
        'start_url'        => $scope,
        'scope'            => $scope,
        /* Su desktop resta la scheda del browser anche per chi ha già installato l'app:
           Chrome riallinea il display al prossimo aggiornamento del manifest */
        'display'          => wp_is_mobile() ? 'standalone' : 'browser',
        'background_color' => '#f6faff',
        'theme_color'      => '#2b6fe0',
        'icons'            => [
            ['src' => TM_URL . 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => TM_URL . 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ],
    ]);
    exit;
}

add_action('init', 'tm_pwa_service_worker_endpoint', 0);
function tm_pwa_service_worker_endpoint() {
    if (!isset($_GET['tm_sw'])) return;

    nocache_headers();
    header('Content-Type: application/javascript; charset=utf-8');
    $version = tm_pwa_bundle_version();
    $cache_prefix = 'tm-app-' . substr(hash('sha256', home_url('/')), 0, 12) . '-';
    $assets_prefix = wp_parse_url(TM_URL, PHP_URL_PATH);
    ?>
const CACHE_PREFIX = '<?php echo esc_js($cache_prefix); ?>';
const CACHE = CACHE_PREFIX + '<?php echo esc_js($version); ?>';
const ASSETS_PREFIX = '<?php echo esc_js($assets_prefix); ?>';

self.addEventListener('install', () => {});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE).map((key) => caches.delete(key))
        )).then(() => self.clients.claim())
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => new Response(
                '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Offline</title><style>body{font-family:system-ui,sans-serif;background:#f6faff;color:#16293a;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:24px}</style></head><body><div><h1>Sei offline</h1><p>Controlla la connessione e riprova.</p></div></body></html>',
                { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
            ))
        );
        return;
    }

    if (url.origin === self.location.origin && url.pathname.startsWith(ASSETS_PREFIX)) {
        event.respondWith(
            caches.open(CACHE).then(async (cache) => {
                const cached = await cache.match(request);
                const network = fetch(request).then((response) => {
                    if (response.ok) cache.put(request, response.clone());
                    return response;
                }).catch(() => cached);
                return cached || network;
            })
        );
    }
});
    <?php
    exit;
}
