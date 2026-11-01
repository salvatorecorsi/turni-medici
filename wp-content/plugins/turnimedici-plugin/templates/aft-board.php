<?php if (!defined('ABSPATH')) exit; ?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($title); ?> — Copertura ambulatori</title>
<link rel="stylesheet" href="<?php echo esc_url(TM_URL . 'assets/aft-board.css?v=' . filemtime(TM_DIR . 'assets/aft-board.css')); ?>">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: #fcfcfa; }
  .sheet { margin: 0 auto; padding: 12px 18px 14px; background: #fff; }
  h1 { font-size: 18px; font-weight: 800; letter-spacing: -0.01em; text-transform: uppercase; margin: 0; color: #1f2328; font-family: ui-sans-serif, system-ui, "Segoe UI", Roboto, Arial, sans-serif; }
  .subtitle { color: #6b7280; margin-top: 2px; font-size: 10px; letter-spacing: 0.02em; font-family: ui-sans-serif, system-ui, Arial, sans-serif; }
  header { margin-bottom: 5px; }
  .toolbar { position: fixed; top: 12px; right: 12px; z-index: 10; }
  .toolbar button { font: inherit; font-size: 13px; font-weight: 600; background: #2b6fe0; color: #fff; border: 0; border-radius: 999px; padding: 8px 18px; cursor: pointer; font-family: ui-sans-serif, system-ui, Arial, sans-serif; }
  @media print { @page { size: A4 landscape; margin: 6mm; } html, body { height: 197mm; overflow: hidden; } body { background: #fff; } .sheet { padding: 0; } .toolbar { display: none; } * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Stampa / Salva PDF</button></div>
<div class="sheet">
  <header>
    <h1><?php echo esc_html($title); ?></h1>
    <div class="subtitle">Copertura settimanale degli ambulatori</div>
  </header>
  <div id="tm-board-root"></div>
</div>
<script type="application/json" id="tm-board-data"><?php echo $json; ?></script>
<script src="<?php echo esc_url(TM_URL . 'assets/aft-board-engine.js?v=' . filemtime(TM_DIR . 'assets/aft-board-engine.js')); ?>"></script>
<script>
  (function () {
    var data = JSON.parse(document.getElementById('tm-board-data').textContent);
    window.TMBoard.render(document.getElementById('tm-board-root'), data, { fit: 'window' });
  })();
</script>
</body>
</html>
