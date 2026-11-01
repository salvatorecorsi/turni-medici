<?php

if (!defined('ABSPATH')) exit;

add_action('init', 'tm_register_blocks');
function tm_register_blocks() {
    if (!function_exists('define_block_type')) return;

    define_block_type('turnimedici/schedule-admin', [
        'title'    => 'Gestione turno',
        'icon'     => 'calendar-alt',
        'category' => 'widgets',
        'settings' => ['hideTitle' => true],
        'schema'   => [
            [
                'scope'  => 'content',
                'fields' => [
                    'panel' => [
                        'type'    => 'schedule-admin',
                        'label'   => 'Gestione turno',
                        'default' => ['revision' => 0],
                    ],
                ],
            ],
        ],
        'render' => '__return_empty_string',
    ]);

    define_block_type('turnimedici/facility-ranking', [
        'title'    => 'Graduatoria struttura',
        'icon'     => 'editor-ol',
        'category' => 'widgets',
        'settings' => ['hideTitle' => true],
        'schema'   => [
            [
                'scope'  => 'content',
                'fields' => [
                    'ranking' => [
                        'type'       => 'facility-ranking',
                        'label'      => 'Graduatoria struttura',
                        'saveInMeta' => 'tm_facility_ranking',
                        'default'    => [],
                    ],
                ],
            ],
        ],
        'render' => '__return_empty_string',
    ]);

    define_block_type('turnimedici/weekly-schedule', [
        'title'    => 'Orari settimanali',
        'icon'     => 'clock',
        'category' => 'widgets',
        'schema'   => [
            [
                'scope'  => 'content',
                'fields' => [
                    'week' => [
                        'type'    => 'weekly-schedule',
                        'label'   => 'Orari di apertura',
                        'default' => [],
                    ],
                ],
            ],
        ],
        'render' => 'tm_render_weekly_schedule',
    ]);

    define_block_type('turnimedici/facility-pin', [
        'title'    => 'Posizione struttura',
        'icon'     => 'location',
        'category' => 'widgets',
        'schema'   => [
            [
                'scope'  => 'content',
                'fields' => [
                    'pin' => [
                        'type'       => 'osm-map',
                        'label'      => 'Posizione su mappa',
                        'saveInMeta' => 'tm_facility_pin',
                    ],
                ],
            ],
        ],
        'render' => '__return_empty_string',
    ]);

    define_block_type('turnimedici/callout', [
        'title'    => 'Riquadro evidenziato',
        'icon'     => 'info',
        'category' => 'design',
        'keywords' => ['callout', 'nota', 'avviso', 'attenzione'],
        'schema'   => [
            'variant' => [
                'type'    => 'select',
                'label'   => 'Tipo',
                'default' => 'info',
                'options' => [
                    ['label' => 'Informazione', 'value' => 'info'],
                    ['label' => 'Punto chiave', 'value' => 'key'],
                    ['label' => 'Attenzione',   'value' => 'warn'],
                    ['label' => 'Da ricordare', 'value' => 'ok'],
                ],
            ],
            'title' => ['type' => 'text', 'label' => 'Titolo'],
            'text'  => ['type' => 'richtext', 'label' => 'Testo'],
        ],
        'render' => 'tm_render_callout',
    ]);

    define_block_type('turnimedici/key-stats', [
        'title'    => 'Numeri chiave',
        'icon'     => 'chart-bar',
        'category' => 'design',
        'keywords' => ['stat', 'numeri', 'compensi', 'quote'],
        'schema'   => [
            'intro' => ['type' => 'text', 'label' => 'Didascalia (opzionale)'],
            'items' => [
                'type'       => 'repeater',
                'label'      => 'Voci',
                'previewKey' => 'value',
                'fields'     => [
                    'value' => ['type' => 'text', 'label' => 'Valore', 'placeholder' => 'es. 24,25 €'],
                    'label' => ['type' => 'text', 'label' => 'Etichetta'],
                    'note'  => ['type' => 'text', 'label' => 'Nota (opzionale)'],
                ],
            ],
        ],
        'render' => 'tm_render_key_stats',
    ]);

    define_block_type('turnimedici/compare', [
        'title'    => 'Confronto due colonne',
        'icon'     => 'columns',
        'category' => 'design',
        'keywords' => ['confronto', 'vs', 'differenza'],
        'schema'   => [
            'left_label'  => ['type' => 'text', 'label' => 'Titolo colonna sinistra'],
            'left'        => ['type' => 'richtext', 'label' => 'Contenuto sinistra'],
            'right_label' => ['type' => 'text', 'label' => 'Titolo colonna destra'],
            'right'       => ['type' => 'richtext', 'label' => 'Contenuto destra'],
        ],
        'render' => 'tm_render_compare',
    ]);

    define_block_type('turnimedici/timeline', [
        'title'    => 'Scadenze',
        'icon'     => 'calendar-alt',
        'category' => 'design',
        'keywords' => ['scadenze', 'timeline', 'date', 'cronologia'],
        'schema'   => [
            'items' => [
                'type'       => 'repeater',
                'label'      => 'Scadenze',
                'previewKey' => 'title',
                'fields'     => [
                    'date'  => ['type' => 'text', 'label' => 'Data', 'placeholder' => 'es. 31/12/2025'],
                    'title' => ['type' => 'text', 'label' => 'Cosa'],
                    'text'  => ['type' => 'richtext', 'label' => 'Dettaglio (opzionale)'],
                ],
            ],
        ],
        'render' => 'tm_render_timeline',
    ]);

    define_block_type('turnimedici/documents', [
        'title'    => 'Documenti',
        'icon'     => 'media-document',
        'category' => 'design',
        'keywords' => ['documenti', 'trasparenza', 'pdf', 'fonti', 'delibere', 'normativa'],
        'schema'   => [
            'documents' => [
                'type'       => 'repeater',
                'label'      => 'Documenti',
                'previewKey' => 'name',
                'fields'     => [
                    'name'        => ['type' => 'text', 'label' => 'Nome del documento'],
                    'description' => ['type' => 'textarea', 'label' => 'Descrizione'],
                    'file'        => ['type' => 'file', 'label' => 'File', 'allowedTypes' => ['application/pdf']],
                ],
            ],
        ],
        'render' => 'tm_render_documents',
    ]);

}

function tm_render_callout($attributes, $content, $block) {
    $values  = $attributes['values'] ?? [];
    $variant = in_array($values['variant'] ?? '', ['info', 'key', 'warn', 'ok'], true) ? $values['variant'] : 'info';
    $title   = trim($values['title'] ?? '');
    $text    = trim($values['text'] ?? '');
    if ($title === '' && $text === '') return '';

    $head = $title !== '' ? '<p class="tm-callout__title">' . esc_html($title) . '</p>' : '';
    $body = $text !== '' ? '<div class="tm-callout__text">' . wp_kses_post($text) . '</div>' : '';

    return sprintf('<div class="tm-callout tm-callout--%s">%s%s</div>', esc_attr($variant), $head, $body);
}

function tm_render_key_stats($attributes, $content, $block) {
    $values = $attributes['values'] ?? [];
    $items  = is_array($values['items'] ?? null) ? $values['items'] : [];
    $intro  = trim($values['intro'] ?? '');

    $cells = '';
    foreach ($items as $item) {
        $value = trim($item['value'] ?? '');
        if ($value === '') continue;
        $label = trim($item['label'] ?? '');
        $note  = trim($item['note'] ?? '');
        $cells .= '<div class="tm-keystat">'
            . '<span class="tm-keystat__value">' . esc_html($value) . '</span>'
            . ($label !== '' ? '<span class="tm-keystat__label">' . esc_html($label) . '</span>' : '')
            . ($note !== '' ? '<span class="tm-keystat__note">' . esc_html($note) . '</span>' : '')
            . '</div>';
    }
    if ($cells === '') return '';

    $caption = $intro !== '' ? '<p class="tm-keystats__intro">' . esc_html($intro) . '</p>' : '';
    return $caption . '<div class="tm-keystats">' . $cells . '</div>';
}

function tm_render_compare($attributes, $content, $block) {
    $values = $attributes['values'] ?? [];
    $column = function ($label, $body) {
        $label = trim($label);
        $body  = trim($body);
        if ($label === '' && $body === '') return '';
        return '<div class="tm-compare__col">'
            . ($label !== '' ? '<h4 class="tm-compare__title">' . esc_html($label) . '</h4>' : '')
            . ($body !== '' ? '<div class="tm-compare__body">' . wp_kses_post($body) . '</div>' : '')
            . '</div>';
    };

    $left  = $column($values['left_label'] ?? '', $values['left'] ?? '');
    $right = $column($values['right_label'] ?? '', $values['right'] ?? '');
    if ($left === '' && $right === '') return '';

    return '<div class="tm-compare">' . $left . $right . '</div>';
}

function tm_render_timeline($attributes, $content, $block) {
    $values = $attributes['values'] ?? [];
    $items  = is_array($values['items'] ?? null) ? $values['items'] : [];

    $rows = '';
    foreach ($items as $item) {
        $title = trim($item['title'] ?? '');
        $date  = trim($item['date'] ?? '');
        if ($title === '' && $date === '') continue;
        $text  = trim($item['text'] ?? '');
        $rows .= '<li class="tm-timeline__item">'
            . ($date !== '' ? '<span class="tm-timeline__date">' . esc_html($date) . '</span>' : '')
            . '<div class="tm-timeline__body">'
            . ($title !== '' ? '<span class="tm-timeline__title">' . esc_html($title) . '</span>' : '')
            . ($text !== '' ? '<div class="tm-timeline__text">' . wp_kses_post($text) . '</div>' : '')
            . '</div></li>';
    }
    if ($rows === '') return '';

    return '<ol class="tm-timeline">' . $rows . '</ol>';
}

function tm_render_documents($attributes, $content, $block) {
    $values = $attributes['values'] ?? [];
    $documents = is_array($values['documents'] ?? null) ? $values['documents'] : [];

    $file_icon = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><polyline points="14 2 14 8 20 8"/></svg>';
    $download_icon = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';

    $rows = '';
    foreach ($documents as $document) {
        $file = is_array($document['file'] ?? null) ? $document['file'] : [];
        $url = esc_url($file['url'] ?? '');
        if ($url === '') continue;
        $name = trim($document['name'] ?? '') ?: trim($file['name'] ?? '');
        if ($name === '') continue;
        $description = trim($document['description'] ?? '');
        $extension = strtoupper(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $meta = implode(' · ', array_filter([$extension, trim($file['size'] ?? '')]));

        $rows .= '<li class="tm-document">'
            . '<a class="tm-document__link" href="' . $url . '" target="_blank" rel="noopener">'
            . '<span class="tm-document__icon" aria-hidden="true">' . $file_icon . '</span>'
            . '<span class="tm-document__body">'
            . '<span class="tm-document__name">' . esc_html($name) . '</span>'
            . ($description !== '' ? '<span class="tm-document__description">' . nl2br(esc_html($description)) . '</span>' : '')
            . ($meta !== '' ? '<span class="tm-document__meta">' . esc_html($meta) . '</span>' : '')
            . '</span>'
            . '<span class="tm-document__download" aria-hidden="true">' . $download_icon . '</span>'
            . '</a></li>';
    }
    if ($rows === '') return '';

    return '<ul class="tm-documents">' . $rows . '</ul>';
}

function tm_render_weekly_schedule($attributes, $content, $block) {
    return tm_weekly_schedule_html($attributes['values']['week'] ?? []);
}

function tm_weekly_schedule_html($data) {
    if (!is_array($data)) return '';

    $defaults = is_array($data['defaults'] ?? null) ? $data['defaults'] : [];
    $closed   = is_array($defaults['closedWeekdays'] ?? null) ? $defaults['closedWeekdays'] : [];
    $slots    = is_array($defaults['slots'] ?? null) ? $defaults['slots'] : [];

    $ranges = [];
    foreach ($slots as $slot) {
        $from = trim($slot['from'] ?? '');
        $to   = trim($slot['to'] ?? '');
        if ($from !== '' && $to !== '') {
            $ranges[] = esc_html($from . '–' . $to);
        }
    }
    $open_hours = implode(', ', $ranges);

    $labels = [
        'mon' => 'Lunedì',
        'tue' => 'Martedì',
        'wed' => 'Mercoledì',
        'thu' => 'Giovedì',
        'fri' => 'Venerdì',
        'sat' => 'Sabato',
        'sun' => 'Domenica',
    ];

    $rows = '';
    foreach ($labels as $key => $label) {
        $is_closed = in_array($key, $closed, true) || $open_hours === '';
        $hours = $is_closed
            ? '<span class="tm-schedule__closed">Chiuso</span>'
            : $open_hours;

        $rows .= sprintf(
            '<div class="tm-schedule__row"><span class="tm-schedule__day">%s</span><span class="tm-schedule__hours">%s</span></div>',
            esc_html($label),
            $hours
        );
    }

    return '<div class="tm-schedule">' . $rows . '</div>';
}
