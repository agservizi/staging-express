<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../app/';
    if (str_starts_with($class, $prefix)) {
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $path = $baseDir . $relative . '.php';
        if (file_exists($path)) {
            require $path;
        }
    }
});

use App\Services\SalesService;

$pdo = Database::getConnection();
$salesService = new SalesService($pdo);

$saleId = isset($_GET['sale_id']) ? (int) $_GET['sale_id'] : 0;
if ($saleId <= 0) {
    http_response_code(400);
    echo 'Parametro sale_id mancante.';
    exit;
}

$sale = $salesService->getSaleWithItems($saleId);
if ($sale === null) {
    http_response_code(404);
    echo 'Scontrino non trovato.';
    exit;
}

$operator = $sale['fullname'] !== null && $sale['fullname'] !== '' ? $sale['fullname'] : $sale['username'];
$timezoneId = $GLOBALS['config']['app']['timezone'] ?? (ini_get('date.timezone') ?: 'Europe/Rome');
try {
  $appTimezone = new DateTimeZone($timezoneId);
} catch (\Throwable $exception) {
  $fallbackTz = date_default_timezone_get();
  $appTimezone = new DateTimeZone($fallbackTz !== '' ? $fallbackTz : 'UTC');
}
$storageTimezoneId = @date_default_timezone_get();
if ($storageTimezoneId === false || $storageTimezoneId === '') {
  $storageTimezoneId = 'UTC';
}
try {
  $storageTimezone = new DateTimeZone($storageTimezoneId);
} catch (\Throwable $exception) {
  $storageTimezone = new DateTimeZone('UTC');
}

// Normalize timestamps to the configured app timezone.
$createdAt = (new DateTimeImmutable($sale['created_at'], $storageTimezone))->setTimezone($appTimezone);
$cancelledAt = !empty($sale['cancelled_at'])
    ? (new DateTimeImmutable($sale['cancelled_at'], $storageTimezone))->setTimezone($appTimezone)
    : null;
$refundedAt = !empty($sale['refunded_at'])
    ? (new DateTimeImmutable($sale['refunded_at'], $storageTimezone))->setTimezone($appTimezone)
    : null;
$vatRate = isset($sale['vat']) ? max((float) $sale['vat'], 0.0) : 0.0;
$configuredVatRate = isset($GLOBALS['config']['app']['tax_rate'])
  ? max((float) $GLOBALS['config']['app']['tax_rate'], 0.0)
  : 0.0;
$displayVatRate = $configuredVatRate > 0.0001 ? $vatRate : 0.0;
$taxNote = $GLOBALS['config']['app']['tax_note'] ?? null;
$vatAmount = isset($sale['vat_amount']) ? max((float) $sale['vat_amount'], 0.0) : 0.0;
$vatSummary = [];
if (!empty($sale['items']) && is_array($sale['items'])) {
  foreach ($sale['items'] as $lineItem) {
    if (!is_array($lineItem)) {
      continue;
    }
    $code = isset($lineItem['vat_code']) ? trim((string) $lineItem['vat_code']) : '';
    if ($code === '') {
      continue;
    }
    $rate = isset($lineItem['tax_rate']) ? (float) $lineItem['tax_rate'] : null;
    $key = $code . '|' . ($rate !== null ? number_format($rate, 4, '.', '') : '');
    if (!isset($vatSummary[$key])) {
      $vatSummary[$key] = [
        'code' => $code,
        'rate' => $rate,
      ];
    }
  }
}
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>DOCUMENTO GESTIONALE #<?= htmlspecialchars((string) $saleId) ?></title>
<style>
  :root {
    color-scheme: light;
  }
  * {
    box-sizing: border-box;
  }
  body {
    margin: 0;
    font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
    font-size: 15px;
    line-height: 1.5;
    color: #000;
    background: #f3f3f3;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
  }
  main {
    width: 100%;
    max-width: 960px;
    margin: 0 auto;
    padding: 32px 16px 48px;
    flex: 1;
  }
  .card {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08);
    padding: 28px;
  }
  .card h1 {
    font-size: 24px;
    margin: 0 0 12px;
  }
  .card p.lead {
    margin: 0 0 24px;
    color: #000;
  }
  .preview-wrapper {
    position: relative;
    border: 1px solid #d1d7de;
    border-radius: 12px;
    background: #f9fafb;
    min-height: 480px;
    overflow: hidden;
  }
  #pdf-preview {
    width: 100%;
    height: 70vh;
    min-height: 520px;
    border: none;
    background: #fff;
  }
  .preview-placeholder {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 12px;
    color: #4b5563;
    font-size: 16px;
    padding: 24px;
  }
  .preview-placeholder svg {
    width: 56px;
    height: 56px;
    color: #2563eb;
  }
  .actions {
    margin-top: 24px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
  }
  .actions button,
  .actions a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: none;
    border-radius: 10px;
    padding: 12px 18px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.15s ease;
    text-decoration: none;
  }
  .actions button.primary {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    box-shadow: 0 10px 25px rgba(37, 99, 235, 0.25);
  }
  .actions button.primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 32px rgba(37, 99, 235, 0.3);
  }
  .actions button.secondary,
  .actions a.secondary {
    background: #e5e7eb;
    color: #1f2937;
  }
  .actions button.secondary:hover,
  .actions a.secondary:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 22px rgba(148, 163, 184, 0.25);
  }
  footer {
    text-align: center;
    padding: 16px;
    font-size: 13px;
    color: #000;
  }
  .receipt-source {
    position: absolute;
    left: -9999px;
    top: 0;
    width: 80mm;
    pointer-events: none;
  }
  @page {
    size: 80mm auto;
    margin: 4mm 4mm 6mm;
  }
  .receipt {
    width: 72mm;
    margin: 0 auto;
    padding: 5mm 3mm 6mm;
    font-family: 'Helvetica', 'Arial', sans-serif;
    font-size: 12px;
    color: #000;
    background: #fff;
  }
  .receipt h3 {
    margin: 0 0 4px;
    font-size: 16px;
    font-weight: 700;
  }
  .center {
    text-align: center;
  }
  table {
    width: 100%;
    border-collapse: collapse;
  }
  td {
    padding: 4px 0;
    vertical-align: top;
  }
  .total {
    font-weight: bold;
    font-size: 14px;
    margin-top: 8px;
    border-top: 1px dashed #000;
    padding-top: 6px;
  }
  hr {
    border: none;
    border-top: 1px dashed #000;
    margin: 8px 0;
  }
  .muted {
    color: #000;
    font-size: 11px;
  }
  .banner {
    border: 1px solid #000;
    padding: 6px;
    margin: 6px 0;
    text-transform: uppercase;
    font-weight: bold;
    text-align: center;
  }
</style>
</head>
<body>
<main>
  <div class="card">
  <h1>Anteprima DOCUMENTO GESTIONALE</h1>
  <p class="lead">Il PDF viene generato automaticamente. Controlla i dettagli prima di procedere con la stampa.</p>
    <div class="preview-wrapper">
  <iframe id="pdf-preview" title="Anteprima PDF Documento Gestionale" hidden></iframe>
      <div class="preview-placeholder" id="pdf-placeholder">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M6 2a2 2 0 0 0-2 2v16.382a1 1 0 0 0 1.447.894L8 20.118l2.553 1.158a1 1 0 0 0 .894 0L14 20.118l2.553 1.158a1 1 0 0 0 1.447-.894V4a2 2 0 0 0-2-2H6zm2 3h8a1 1 0 1 1 0 2H8a1 1 0 1 1 0-2zm0 4h8a1 1 0 1 1 0 2H8a1 1 0 0 1 0-2zm0 4h5a1 1 0 1 1 0 2H8a1 1 0 0 1 0-2z" />
        </svg>
        <strong>Generazione PDF in corso...</strong>
        <span>Attendere qualche istante.</span>
      </div>
    </div>
    <div class="actions" id="pdf-actions" hidden>
      <button type="button" class="primary" id="btn-print-pdf">Stampa</button>
      <button type="button" class="secondary" id="btn-download-pdf">Scarica PDF</button>
      <a class="secondary" href="index.php?page=sales_create">Torna alla cassa</a>
    </div>
  </div>
</main>
<footer>
  Hai bisogno di stampare di nuovo? Puoi sempre recuperare questo DOCUMENTO GESTIONALE dalla sezione vendite.
</footer>
<div class="receipt-source" aria-hidden="true">
<div class="receipt" id="printable-receipt">
  <div class="center">
  <h3>AG SERVIZI VIA PLINIO 72 DI CAVALIERE CARMINE</h3>
  <div class="muted">DOCUMENTO GESTIONALE #<?= htmlspecialchars((string) $saleId) ?></div>
  </div>
  <?php if (($sale['status'] ?? 'Completed') !== 'Completed'): ?>
    <div class="banner">
        <?= $sale['status'] === 'Cancelled' ? 'ANNULLATO' : 'RESO' ?>
    </div>
  <?php endif; ?>
  <div>Data: <?= htmlspecialchars($createdAt->format('d/m/Y H:i')) ?></div>
  <div>Operatore: <?= htmlspecialchars($operator) ?></div>
  <?php if (!empty($sale['customer_name'])): ?>
    <div>Cliente: <?= htmlspecialchars((string) $sale['customer_name']) ?></div>
  <?php endif; ?>
  <hr>
  <table>
    <?php foreach ($sale['items'] as $item): ?>
      <tr>
        <td>
          <?= htmlspecialchars($item['description'] ?: ($item['iccid'] ?? '')) ?>
          <?php if (($item['quantity'] ?? 1) > 1): ?>
            <span class="muted">x<?= (int) $item['quantity'] ?></span>
          <?php endif; ?>
          <?php
              $itemVatCode = isset($item['vat_code']) ? trim((string) $item['vat_code']) : '';
              if ($itemVatCode !== ''):
                  $itemVatRate = isset($item['tax_rate']) ? (float) $item['tax_rate'] : 0.0;
          ?>
              <div class="muted">IVA <?= number_format($itemVatRate, 2, ',', '.') ?>% · Cod. <?= htmlspecialchars($itemVatCode) ?></div>
          <?php endif; ?>
        </td>
        <td style="text-align:right;">€ <?= number_format((float) $item['price'], 2, ',', '.') ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <hr>
  <?php if ($displayVatRate > 0.0001): ?>
    <div class="muted">IVA: <?= number_format($displayVatRate, 2, ',', '.') ?>%</div>
  <?php elseif ($taxNote): ?>
    <div class="muted"><?= htmlspecialchars($taxNote) ?></div>
  <?php endif; ?>
  <?php if ($vatAmount > 0.001): ?>
    <div class="muted">IVA compresa: € <?= number_format($vatAmount, 2, ',', '.') ?></div>
  <?php endif; ?>
  <?php if ($vatSummary !== []): ?>
    <?php
        $vatLabels = [];
        foreach ($vatSummary as $entry) {
            $parts = [];
            if ($entry['rate'] !== null) {
                $parts[] = number_format((float) $entry['rate'], 2, ',', '.') . '%';
            }
            $parts[] = 'Cod. ' . $entry['code'];
            $vatLabels[] = htmlspecialchars(implode(' • ', $parts));
        }
    ?>
    <div class="muted">Codici IVA applicati: <?= implode(' | ', $vatLabels) ?></div>
  <?php endif; ?>
  <?php if ((float) $sale['discount'] > 0): ?>
    <div class="muted">Sconto: € <?= number_format((float) $sale['discount'], 2, ',', '.') ?></div>
  <?php endif; ?>
  <div class="total">
    <?= ($sale['status'] ?? 'Completed') === 'Completed' ? 'Totale' : 'Totale originario' ?>:
    € <?= number_format((float) $sale['total'], 2, ',', '.') ?>
  </div>
  <div>Pagamento: <?= htmlspecialchars($sale['payment_method']) ?></div>
  <?php if ($sale['status'] === 'Refunded'): ?>
    <div class="total">Importo reso: € <?= number_format((float) ($sale['refunded_amount'] ?? $sale['total']), 2, ',', '.') ?></div>
  <?php endif; ?>
  <?php if ($sale['status'] === 'Cancelled'): ?>
    <?php if ($cancelledAt): ?>
      <div class="muted">Annullato il <?= htmlspecialchars($cancelledAt->format('d/m/Y H:i')) ?></div>
    <?php endif; ?>
    <?php if (!empty($sale['cancellation_note'])): ?>
      <div class="muted">Motivo annullo: <?= htmlspecialchars((string) $sale['cancellation_note']) ?></div>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($sale['status'] === 'Refunded'): ?>
    <?php if ($refundedAt): ?>
      <div class="muted">Reso registrato il <?= htmlspecialchars($refundedAt->format('d/m/Y H:i')) ?></div>
    <?php endif; ?>
    <?php if (!empty($sale['refund_note'])): ?>
      <div class="muted">Note reso: <?= htmlspecialchars((string) $sale['refund_note']) ?></div>
    <?php endif; ?>
  <?php endif; ?>
  <div class="center"><small>Grazie per il tuo acquisto!</small></div>
</div>
</div>
<noscript>
  <style>
    body { display: block; padding: 16px; background: #fff; }
    main, .card, .preview-wrapper, footer { display: none !important; }
    .receipt-source { position: static; pointer-events: auto; }
  </style>
  <div>
    <strong>JavaScript non attivo.</strong> Stampa questa pagina con le scorciatoie del browser.
  </div>
</noscript>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function() {
  const previewFrame = document.getElementById('pdf-preview');
  const placeholder = document.getElementById('pdf-placeholder');
  const actions = document.getElementById('pdf-actions');
  const printBtn = document.getElementById('btn-print-pdf');
  const downloadBtn = document.getElementById('btn-download-pdf');
  const receipt = document.getElementById('printable-receipt');
  const receiptContainer = document.querySelector('.receipt-source');
  if (!receipt || !receiptContainer) {
    if (placeholder) {
      placeholder.innerHTML = '<strong>Impossibile preparare lo scontrino.</strong>';
    }
    return;
  }
  if (typeof window.html2pdf !== 'function') {
    if (placeholder) {
      placeholder.innerHTML = '<strong>Impossibile generare il PDF.</strong><span>Stampa direttamente questa pagina.</span>';
    }
    return;
  }

  const pxToMm = (px) => px * 0.2645833333;
  const receiptHeightPx = receipt.scrollHeight || receipt.offsetHeight;
  const receiptHeightMm = Math.max(pxToMm(receiptHeightPx + 24), 100);
  const pdfOptions = {
    margin: [4, 4],
  filename: 'documento-gestionale-<?= (int) $saleId ?>.pdf',
    html2canvas: { scale: 2, useCORS: true, backgroundColor: '#FFFFFF', logging: false },
    jsPDF: { unit: 'mm', format: [80, receiptHeightMm], orientation: 'portrait' },
    pagebreak: { mode: ['css', 'legacy'] },
  };

  const worker = window.html2pdf().set(pdfOptions).from(receipt).toPdf();

  worker.get('pdf').then(function(pdf) {
    const blob = pdf.output('blob');
    const blobUrl = URL.createObjectURL(blob);
    if (previewFrame) {
      previewFrame.hidden = false;
      previewFrame.src = blobUrl;
      previewFrame.dataset.blobUrl = blobUrl;
    }
    if (placeholder) {
      placeholder.style.display = 'none';
    }
    if (actions) {
      actions.hidden = false;
    }

    if (printBtn) {
      printBtn.addEventListener('click', function() {
        const printWindow = window.open(blobUrl, '_blank', 'noopener');
        if (!printWindow) {
          alert('Abilita i pop-up per procedere con la stampa.');
          return;
        }
        printWindow.addEventListener('load', function() {
          printWindow.focus();
          printWindow.print();
        }, { once: true });
      });
    }

    if (downloadBtn) {
      downloadBtn.addEventListener('click', function() {
        pdf.save(pdfOptions.filename);
      });
    }

    window.addEventListener('beforeunload', function() {
      if (previewFrame && previewFrame.dataset.blobUrl) {
        URL.revokeObjectURL(previewFrame.dataset.blobUrl);
      }
    }, { once: true });
  }).catch(function() {
    if (placeholder) {
      placeholder.innerHTML = '<strong>Impossibile generare il PDF.</strong><span>Stampa direttamente questa pagina.</span>';
    }
  });
})();
</script>
</body>
</html>
