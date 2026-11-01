<?php

if (!defined('ABSPATH')) exit;

/**
 * Generatore PDF minimale autonomo (nessuna dipendenza esterna).
 * Font base-14 Helvetica/Helvetica-Bold (non incorporati), encoding WinAnsi.
 * Coordinate in punti, origine in alto a sinistra (convertite internamente).
 */
class TM_SimplePDF {
    private $w = 595.28;
    private $h = 841.89;
    private $buf = '';

    public function pageWidth()  { return $this->w; }
    public function pageHeight() { return $this->h; }

    public function text($x, $yTop, $size, $s, $bold = false, $align = 'L', $boxw = 0) {
        $font = $bold ? 'F2' : 'F1';
        $s = $this->enc($s);
        if ($align !== 'L' && $boxw > 0) {
            $tw = $this->width($s, $size);
            if ($align === 'C') $x += ($boxw - $tw) / 2;
            elseif ($align === 'R') $x += ($boxw - $tw);
        }
        $y = $this->h - $yTop;
        $this->buf .= sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $font, $size, $x, $y, $this->escTxt($s));
    }

    public function line($x1, $y1, $x2, $y2, $lw = 0.4) {
        $this->buf .= sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $lw, $x1, $this->h - $y1, $x2, $this->h - $y2);
    }

    public function rect($x, $yTop, $w, $hh, $lw = 0.4) {
        $this->buf .= sprintf("%.2F w %.2F %.2F %.2F %.2F re S\n", $lw, $x, $this->h - $yTop - $hh, $w, $hh);
    }

    public function fill($x, $yTop, $w, $hh, $g = 0.9) {
        $this->buf .= sprintf("%.3F g %.2F %.2F %.2F %.2F re f 0 g\n", $g, $x, $this->h - $yTop - $hh, $w, $hh);
    }

    private function enc($s) {
        $r = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', (string) $s);
        return $r === false ? (string) $s : $r;
    }

    private function escTxt($s) {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s);
    }

    private function width($s, $size) {
        $tot = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) $tot += $this->cw(ord($s[$i]));
        return $tot * $size / 1000;
    }

    private function cw($o) {
        static $t = null;
        if ($t === null) {
            $ascii = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
            $t = [];
            foreach ($ascii as $i => $wv) $t[32 + $i] = $wv;
        }
        return isset($t[$o]) ? $t[$o] : 556;
    }

    public function output() {
        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objs[3] = sprintf("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>", $this->w, $this->h);
        $objs[4] = "<< /Length " . strlen($this->buf) . " >>\nstream\n" . $this->buf . "endstream";
        $objs[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objs[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        for ($i = 1; $i <= 6; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $objs[$i] . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 7\n0000000000 65535 f \n";
        for ($i = 1; $i <= 6; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        $pdf .= "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }
}

function tm_notula_slot_hours($slot) {
    $p = explode('-', $slot);
    if (count($p) < 2) return 0;
    $min = function ($t) {
        $x = explode(':', trim($t));
        return (int) $x[0] * 60 + (int) ($x[1] ?? 0);
    };
    return max(0, ($min($p[1]) - $min($p[0])) / 60);
}

function tm_notula_slot_label($slot) {
    $p = explode('-', $slot);
    $f = function ($t) { return preg_replace('/:00$/', '', trim($t)); };
    return count($p) >= 2 ? $f($p[0]) . '-' . $f($p[1]) : $slot;
}

function tm_notula_hours_fmt($h) {
    return rtrim(rtrim(number_format((float) $h, 1, ',', ''), '0'), ',');
}

function tm_render_notula($facility_id, $doctor, $schedule = null) {
    global $wpdb;

    $board = function_exists('tm_board') ? tm_board((int) $facility_id, $doctor, $schedule) : null;
    if (!$board || empty($board['schedule'])) return null;

    $schedule_id   = (int) $board['schedule']['id'];
    $month         = isset($board['schedule']['month']) ? $board['schedule']['month'] : [];
    $monthNum      = (int) ($month['month'] ?? current_time('n'));
    $year          = (int) ($month['year'] ?? current_time('Y'));
    $facility_name = (string) $board['structure']['name'];
    $months        = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    $monthLabel    = ($monthNum ? $months[$monthNum] : '') . ' ' . $year;
    $daysInMonth   = (int) date('t', mktime(0, 0, 0, $monthNum, 1, $year));
    $doctorName    = trim($doctor->first_name . ' ' . $doctor->last_name);
    if ($doctorName === '') $doctorName = $doctor->display_name;

    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT shift_date, slot FROM ' . tm_table('shifts') . ' WHERE schedule_id = %d AND doctor_id = %d ORDER BY shift_date ASC, slot ASC',
        $schedule_id,
        (int) $doctor->ID
    ));
    $byDay = [];
    foreach ((array) $rows as $r) {
        $d = (int) substr($r->shift_date, 8, 2);
        if ($d >= 1 && $d <= 31) $byDay[$d][] = $r->slot;
    }

    $pdf = new TM_SimplePDF();
    $L = 30; $R = 565; $cw = $R - $L;

    $pdf->text($L, 40, 11, 'AZIENDA USL TOSCANA NORD OVEST', true, 'C', $cw);
    $pdf->text($L, 58, 9, 'ZONA DISTRETTO  ____________________', false, 'L');
    $pdf->text($L, 58, 9, 'Presidio/Struttura: ' . $facility_name, false, 'R', $cw);
    $pdf->text($L, 76, 8.5, "FOGLIO PRESENZA - ATTESTAZIONE SVOLGIMENTO ATTIVITÀ ORARIA AVANZATA", true, 'C', $cw);
    $pdf->text($L, 96, 9.5, 'Dr./Dr.ssa: ' . $doctorName, false, 'L');
    $pdf->text($L, 96, 9.5, 'Mese e Anno: ' . $monthLabel, false, 'R', $cw);

    // colonne tabella
    $cols = [
        ['x' => 30,  'w' => 38,  'label' => 'DATA'],
        ['x' => 68,  'w' => 165, 'label' => "SEDE ATTIVITÀ"],
        ['x' => 233, 'w' => 120, 'label' => 'TURNO DI SERVIZIO'],
        ['x' => 353, 'w' => 70,  'label' => 'ORE TOTALI'],
        ['x' => 423, 'w' => 142, 'label' => 'NOTE'],
    ];
    $tableTop = 110;
    $headH    = 18;
    $rowH     = 15;
    $bodyTop  = $tableTop + $headH;
    $tableBottom = $bodyTop + $daysInMonth * $rowH;

    // header riga
    $pdf->fill($L, $tableTop, $cw, $headH, 0.88);
    foreach ($cols as $c) $pdf->text($c['x'], $tableTop + 12, 7.5, $c['label'], true, 'C', $c['w']);

    $total = 0;
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $y = $bodyTop + ($d - 1) * $rowH;
        $pdf->text($cols[0]['x'], $y + 10.5, 8, (string) $d, false, 'C', $cols[0]['w']);
        if (!empty($byDay[$d])) {
            $slots = $byDay[$d];
            $hours = 0;
            $labels = [];
            foreach ($slots as $s) { $hours += tm_notula_slot_hours($s); $labels[] = tm_notula_slot_label($s); }
            $total += $hours;
            $pdf->text($cols[1]['x'] + 4, $y + 10.5, 8, $facility_name, false, 'L');
            $pdf->text($cols[2]['x'], $y + 10.5, 8, implode('  ', $labels), false, 'C', $cols[2]['w']);
            $pdf->text($cols[3]['x'], $y + 10.5, 8, tm_notula_hours_fmt($hours), false, 'C', $cols[3]['w']);
        }
    }

    // griglia
    $pdf->rect($L, $tableTop, $cw, $tableBottom - $tableTop);
    $pdf->line($L, $bodyTop, $R, $bodyTop);
    for ($d = 1; $d < $daysInMonth; $d++) {
        $y = $bodyTop + $d * $rowH;
        $pdf->line($L, $y, $R, $y, 0.25);
    }
    foreach ($cols as $c) {
        if ($c['x'] > $L) $pdf->line($c['x'], $tableTop, $c['x'], $tableBottom);
    }

    // totale
    $totY = $tableBottom;
    $pdf->fill($L, $totY, $cw, 16, 0.94);
    $pdf->rect($L, $totY, $cw, 16);
    $pdf->line($cols[3]['x'], $totY, $cols[3]['x'], $totY + 16);
    $pdf->text($L + 6, $totY + 11, 8.5, 'TOTALE ORE (a carico del medico)', true, 'L');
    $pdf->text($cols[3]['x'], $totY + 11, 9, tm_notula_hours_fmt($total), true, 'C', $cols[3]['w'] + $cols[4]['w']);

    // note servizio
    $noteY = $totY + 30;
    $pdf->text($L, $noteY, 8.5, 'NOTE SERVIZIO', true, 'L');
    $pdf->rect($L, $noteY + 4, $cw, 46);

    // firme
    $signY = $noteY + 78;
    $pdf->text($L, $signY, 8.5, 'Data ____________________', false, 'L');
    $pdf->line($L, $signY + 40, $L + 200, $signY + 40);
    $pdf->line($R - 200, $signY + 40, $R, $signY + 40);
    $pdf->text($L, $signY + 52, 8, 'Firma e timbro del Responsabile', false, 'C', 200);
    $pdf->text($R - 200, $signY + 52, 8, 'Firma e timbro medico incaricato', false, 'C', 200);

    return $pdf->output();
}

add_action('rest_api_init', function () {
    register_rest_route('turnimedici/v1', '/structures/(?P<id>\d+)/notula', [
        'methods'             => 'GET',
        'callback'            => 'tm_rest_notula',
        'permission_callback' => function () {
            return (bool) tm_current_doctor() || current_user_can('edit_users');
        },
    ]);
});

function tm_rest_notula($request) {
    $doctor = tm_current_doctor();
    if (!$doctor) {
        return new WP_Error('tm_unauthorized', 'Solo i medici possono scaricare la notula.', ['status' => 403]);
    }

    $schedule = tm_requested_schedule((int) $request['id'], $request);
    if ($request->get_param('schedule') && !$schedule) {
        return new WP_Error('tm_not_found', 'Orario non disponibile.', ['status' => 404]);
    }

    $pdf = tm_render_notula((int) $request['id'], $doctor, $schedule);
    if ($pdf === null) {
        return new WP_Error('tm_not_found', 'Nessun orario disponibile per questo mese.', ['status' => 404]);
    }

    $fname = 'notula-' . sanitize_title($doctor->display_name ?: 'medico') . '.pdf';
    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
