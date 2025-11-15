<?php
declare(strict_types=1);

namespace App\Services;

final class SaleNotificationService
{
    public function __construct(
        private ?string $resendApiKey,
        private ?string $fromEmail,
        private ?string $fromName,
        private ?string $recipientEmail,
        private string $appName,
        private ?string $logFile = null,
        private ?SystemNotificationService $notificationService = null
    ) {
    }

    /**
     * @param array<string, mixed> $sale
     */
    public function sendSaleCompletedEmail(array $sale): bool
    {
        $saleId = (int) ($sale['id'] ?? 0);
        if ($saleId <= 0) {
            return false;
        }

        $createdAt = $sale['created_at'] ?? null;
        $formattedDate = $this->formatDateTime($createdAt);
        $operatorName = $this->resolveOperatorName($sale);
        $paymentMethod = $sale['payment_method'] ?? 'Contanti';
        $paymentStatus = $sale['payment_status'] ?? ($sale['status'] ?? 'Completed');
        $customerName = $this->resolveCustomerName($sale);
        $customerEmail = $sale['customer_email'] ?? null;
        $customerPhone = $sale['customer_phone'] ?? null;
        $customerTaxCode = $sale['customer_tax_code'] ?? null;
        $customerNote = $sale['customer_note'] ?? null;

        $recipients = [];
        $storeRecipient = $this->normalizeEmail($this->recipientEmail);
        if ($storeRecipient !== null) {
            $recipients[] = $storeRecipient;
        }

        $customerRecipient = $this->normalizeEmail($customerEmail);
        if ($customerRecipient !== null) {
            $recipients[] = $customerRecipient;
        }

        $recipients = array_values(array_unique($recipients));
        if ($recipients === []) {
            $this->log('Nessun destinatario valido per vendita #' . $saleId . '. Email non inviata.');
            return false;
        }

        $recipientsLabel = implode(', ', $recipients);

        $total = (float) ($sale['total'] ?? 0.0);
        $totalPaid = (float) ($sale['total_paid'] ?? $total);
        $balanceDue = (float) ($sale['balance_due'] ?? 0.0);
        $discount = (float) ($sale['discount'] ?? 0.0);
        $vatRate = (float) ($sale['vat'] ?? 0.0);
        $vatAmount = (float) ($sale['vat_amount'] ?? 0.0);

        $items = is_array($sale['items'] ?? null) ? $sale['items'] : [];
        $normalizedItems = $this->normalizeItems($items);
        $receiptUrl = $this->buildReceiptUrl($saleId);

        $subject = sprintf('[%s] Ordine evaso #%d', $this->appName, $saleId);
        $this->log('Invio email vendita #' . $saleId . ' a: ' . $recipientsLabel . ' (operatore ' . $operatorName . ').');
        $textBody = $this->buildTextBody(
            $saleId,
            $formattedDate,
            $operatorName,
            $paymentMethod,
            $paymentStatus,
            $customerName,
            $customerEmail,
            $customerPhone,
            $customerTaxCode,
            $customerNote,
            $normalizedItems,
            $total,
            $totalPaid,
            $balanceDue,
            $discount,
            $vatRate,
            $vatAmount,
            $receiptUrl
        );
        $htmlBody = $this->buildHtmlBody(
            $saleId,
            $formattedDate,
            $operatorName,
            $paymentMethod,
            $paymentStatus,
            $customerName,
            $customerEmail,
            $customerPhone,
            $customerTaxCode,
            $customerNote,
            $normalizedItems,
            $total,
            $totalPaid,
            $balanceDue,
            $discount,
            $vatRate,
            $vatAmount,
            $receiptUrl
        );

        $sent = false;
        $channelsUsed = [];

        if ($this->sendViaResend($recipients, $subject, $textBody, $htmlBody, $saleId)) {
            $sent = true;
            $channelsUsed[] = 'email:resend';
        } else {
            $fallback = $this->sendViaMailFallback($recipients, $subject, $textBody, $saleId);
            if ($fallback) {
                $sent = true;
                $channelsUsed[] = 'email:mail';
            }
        }

        if (!$sent) {
            $this->log('Sale #' . $saleId . ' email failed on both Resend and PHP mail().');
        }

        $this->emitSaleNotification($sale, $sent, $channelsUsed, $subject);

        return $sent;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $quantity = (int) ($item['quantity'] ?? 1);
            if ($quantity <= 0) {
                $quantity = 1;
            }

            $unitPrice = (float) ($item['price'] ?? 0.0);
            $lineTotal = $unitPrice * $quantity;
            $iccidValue = $item['iccid'] ?? null;
            $description = $item['description'] ?? null;
            if ($description === null || trim((string) $description) === '') {
                $description = $iccidValue !== null ? 'SIM Card' : 'Articolo';
            }

            $normalized[] = [
                'description' => trim((string) $description),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'iccid' => $iccidValue,
            ];
        }

        return $normalized;
    }

    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $trimmed = trim($email);
        if ($trimmed === '') {
            return null;
        }

        return filter_var($trimmed, FILTER_VALIDATE_EMAIL) ?: null;
    }

    private function formatDateTime(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            try {
                $date = new \DateTimeImmutable($value);
                return $date->format('d/m/Y H:i');
            } catch (\Throwable) {
                // Ignore parsing errors and fall through to now.
            }
        }

        $now = new \DateTimeImmutable('now');
        return $now->format('d/m/Y H:i');
    }

    /**
     * @param array<string, mixed> $sale
     */
    private function resolveOperatorName(array $sale): string
    {
        $fullname = $sale['fullname'] ?? null;
        if (is_string($fullname) && trim($fullname) !== '') {
            return trim($fullname);
        }

        $username = $sale['username'] ?? null;
        if (is_string($username) && trim($username) !== '') {
            return trim($username);
        }

        return 'Operatore non indicato';
    }

    /**
     * @param array<string, mixed> $sale
     */
    private function resolveCustomerName(array $sale): ?string
    {
        $priorities = ['customer_name', 'customer_fullname'];
        foreach ($priorities as $field) {
            if (!empty($sale[$field]) && is_string($sale[$field])) {
                $trimmed = trim((string) $sale[$field]);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return null;
    }

    private function buildReceiptUrl(int $saleId): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            $https = $_SERVER['HTTPS'] ?? null;
            $scheme = (is_string($https) && strtolower($https) !== 'off' && $https !== '') ? 'https' : 'http';
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            $baseDir = rtrim(str_replace('index.php', '', $script), '/\\');
            if ($baseDir !== '' && $baseDir !== '/') {
                if ($baseDir[0] !== '/') {
                    $baseDir = '/' . $baseDir;
                }
                $base = $scheme . '://' . $host . $baseDir;
            } else {
                $base = $scheme . '://' . $host;
            }

            return rtrim($base, '/\\') . '/print_receipt.php?sale_id=' . $saleId;
        }

        return 'print_receipt.php?sale_id=' . $saleId;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function buildTextBody(
        int $saleId,
        string $formattedDate,
        string $operatorName,
        string $paymentMethod,
        string $paymentStatus,
        ?string $customerName,
        ?string $customerEmail,
        ?string $customerPhone,
        ?string $customerTaxCode,
        mixed $customerNote,
        array $items,
        float $total,
        float $totalPaid,
        float $balanceDue,
        float $discount,
        float $vatRate,
        float $vatAmount,
        string $receiptUrl
    ): string {
        $lines = [];
        $lines[] = sprintf('%s - Ordine evaso #%d', $this->appName, $saleId);
        $lines[] = 'Data: ' . $formattedDate;
        $lines[] = 'Operatore: ' . $operatorName;
        $lines[] = 'Pagamento: ' . $paymentMethod . ' (' . $paymentStatus . ')';
        if ($customerName !== null) {
            $lines[] = 'Cliente: ' . $customerName;
        }
        if ($customerEmail !== null && $customerEmail !== '') {
            $lines[] = 'Email cliente: ' . $customerEmail;
        }
        if ($customerPhone !== null && $customerPhone !== '') {
            $lines[] = 'Telefono cliente: ' . $customerPhone;
        }
        if ($customerTaxCode !== null && $customerTaxCode !== '') {
            $lines[] = 'Codice fiscale: ' . $customerTaxCode;
        }
        if (is_string($customerNote) && trim($customerNote) !== '') {
            $lines[] = 'Nota cliente: ' . trim($customerNote);
        }

        $lines[] = '';
        $lines[] = 'Articoli:';
        foreach ($items as $item) {
            $desc = $item['description'];
            $qty = $item['quantity'];
            $totalLine = number_format($item['line_total'], 2, ',', '.');
            $iccid = $item['iccid'];
            $iccidLabel = ($iccid !== null && $iccid !== '') ? ' - ICCID ' . $iccid : '';
            $lines[] = sprintf(' - %s x%d - € %s%s', $desc, $qty, $totalLine, $iccidLabel);
        }

        $lines[] = '';
        if ($discount > 0.0) {
            $lines[] = 'Sconto: € ' . number_format($discount, 2, ',', '.');
        }
        if ($vatAmount > 0.0) {
            $lines[] = 'IVA (' . number_format($vatRate, 2, ',', '.') . '%): € ' . number_format($vatAmount, 2, ',', '.');
        }
        $lines[] = 'Totale: € ' . number_format($total, 2, ',', '.');
        $lines[] = 'Incassato: € ' . number_format($totalPaid, 2, ',', '.');
        if ($balanceDue > 0.0) {
            $lines[] = 'Residuo: € ' . number_format($balanceDue, 2, ',', '.');
        }
        $lines[] = '';
        $lines[] = 'Ricevuta: ' . $receiptUrl;

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function buildHtmlBody(
        int $saleId,
        string $formattedDate,
        string $operatorName,
        string $paymentMethod,
        string $paymentStatus,
        ?string $customerName,
        ?string $customerEmail,
        ?string $customerPhone,
        ?string $customerTaxCode,
        mixed $customerNote,
        array $items,
        float $total,
        float $totalPaid,
        float $balanceDue,
        float $discount,
        float $vatRate,
        float $vatAmount,
        string $receiptUrl
    ): string {
        $rows = '';
        foreach ($items as $item) {
            $desc = $this->escape($item['description']);
            $qty = (int) $item['quantity'];
            $unit = number_format((float) $item['unit_price'], 2, ',', '.');
            $line = number_format((float) $item['line_total'], 2, ',', '.');
            $iccid = $item['iccid'];
            $iccidBadge = '';
            if ($iccid !== null && $iccid !== '') {
                $iccidBadge = '<span class="badge badge--muted">ICCID ' . $this->escape((string) $iccid) . '</span>';
            }
            $rows .= '<tr>' .
                '<td class="item-desc">' . $desc . $iccidBadge . '</td>' .
                '<td class="item-qty">' . $qty . '</td>' .
                '<td class="item-price">€ ' . $unit . '</td>' .
                '<td class="item-total">€ ' . $line . '</td>' .
                '</tr>';
        }

        $customerBlock = '';
        if ($customerName !== null || $customerEmail !== null || $customerPhone !== null || $customerTaxCode !== null) {
            $customerLines = [];
            if ($customerName !== null) {
                $customerLines[] = '<strong>Cliente:</strong> ' . $this->escape($customerName);
            }
            if ($customerEmail !== null && $customerEmail !== '') {
                $customerLines[] = '<strong>Email:</strong> <a href="mailto:' . $this->escape($customerEmail) . '">' . $this->escape($customerEmail) . '</a>';
            }
            if ($customerPhone !== null && $customerPhone !== '') {
                $customerLines[] = '<strong>Telefono:</strong> <a href="tel:' . $this->escape($customerPhone) . '">' . $this->escape($customerPhone) . '</a>';
            }
            if ($customerTaxCode !== null && $customerTaxCode !== '') {
                $customerLines[] = '<strong>Codice fiscale:</strong> ' . $this->escape($customerTaxCode);
            }
            $customerBlock = '<div class="panel"><h3>Cliente</h3><p>' . implode('<br>', $customerLines) . '</p></div>';
        }

        $noteBlock = '';
        if (is_string($customerNote) && trim($customerNote) !== '') {
            $noteBlock = '<div class="panel"><h3>Note cliente</h3><p>' . nl2br($this->escape(trim($customerNote))) . '</p></div>';
        }

        $discountRow = $discount > 0.0
            ? '<div class="stat"><span class="stat-label">Sconto</span><span class="stat-value">€ ' . number_format($discount, 2, ',', '.') . '</span></div>'
            : '';
        $vatRow = $vatAmount > 0.0
            ? '<div class="stat"><span class="stat-label">IVA ' . number_format($vatRate, 2, ',', '.') . '%</span><span class="stat-value">€ ' . number_format($vatAmount, 2, ',', '.') . '</span></div>'
            : '';
        $balanceRow = $balanceDue > 0.0
            ? '<div class="stat"><span class="stat-label">Residuo</span><span class="stat-value warning">€ ' . number_format($balanceDue, 2, ',', '.') . '</span></div>'
            : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Ordine evaso #{$saleId}</title>
<style>
body { margin: 0; padding: 0; background: #0f172a; font-family: 'Segoe UI', Arial, sans-serif; color: #0f172a; }
a { color: #2563eb; text-decoration: none; }
.wrapper { width: 100%; padding: 32px 16px; background: linear-gradient(135deg, #0f172a, #312e81); }
.mail { max-width: 640px; margin: 0 auto; background: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 24px 60px rgba(15, 23, 42, 0.3); }
.hero { padding: 36px; background: radial-gradient(circle at top left, #2563eb, #1e3a8a); color: #f8fafc; }
.hero small { text-transform: uppercase; letter-spacing: 0.2em; font-weight: 600; opacity: 0.8; }
.hero h1 { margin: 16px 0 0; font-size: 28px; line-height: 1.2; }
.hero .meta { margin-top: 16px; font-size: 14px; opacity: 0.9; }
.content { padding: 32px; }
.section-title { margin-top: 0; font-size: 18px; color: #1e293b; }
.items { width: 100%; border-collapse: collapse; margin-top: 16px; }
.items th { text-align: left; font-size: 13px; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
.items td { padding: 14px 0; border-bottom: 1px solid #f1f5f9; font-size: 15px; color: #1e293b; }
.items tr:last-child td { border-bottom: none; }
.item-desc { font-weight: 600; }
.badge { display: inline-block; margin-left: 8px; padding: 2px 8px; font-size: 11px; border-radius: 999px; background: #eef2ff; color: #4338ca; }
.badge--muted { margin-left: 12px; background: #f1f5f9; color: #475569; font-weight: 500; }
.item-qty, .item-price, .item-total { text-align: right; font-variant-numeric: tabular-nums; }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin: 24px 0 8px; }
.stat { background: #f8fafc; border-radius: 16px; padding: 18px; box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.2); }
.stat-label { display: block; font-size: 13px; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-bottom: 8px; }
.stat-value { font-size: 20px; font-weight: 700; color: #0f172a; font-variant-numeric: tabular-nums; }
.stat-value.warning { color: #dc2626; }
.details { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-top: 32px; }
.panel { background: #f8fafc; border-radius: 16px; padding: 18px 20px; box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.18); }
.panel h3 { margin: 0 0 12px; font-size: 15px; letter-spacing: 0.06em; text-transform: uppercase; color: #475569; }
.panel p { margin: 0; font-size: 14px; line-height: 1.6; color: #1e293b; }
.cta { margin-top: 32px; text-align: center; }
.cta a { display: inline-block; padding: 14px 28px; background: linear-gradient(135deg, #22d3ee, #3b82f6); color: #0f172a; font-weight: 700; border-radius: 999px; box-shadow: 0 16px 30px rgba(34, 211, 238, 0.35); }
.footer { margin-top: 24px; font-size: 12px; color: #94a3b8; text-align: center; }
@media (max-width: 520px) {
    .hero, .content { padding: 24px; }
    .hero h1 { font-size: 24px; }
    .cta a { width: 100%; }
}
</style>
</head>
<body>
    <div class="wrapper">
        <div class="mail">
            <div class="hero">
                <small>Ordine evaso</small>
                <h1>Vendita #{$saleId} completata</h1>
                <div class="meta">{$this->escape($formattedDate)} &middot; Operatore {$this->escape($operatorName)} &middot; {$this->escape($paymentMethod)} ({$this->escape($paymentStatus)})</div>
            </div>
            <div class="content">
                <h2 class="section-title">Articoli evasi</h2>
                <table class="items" role="presentation" aria-label="Riepilogo articoli">
                    <thead>
                        <tr>
                            <th scope="col">Articolo</th>
                            <th scope="col" style="text-align:right;">Qt</th>
                            <th scope="col" style="text-align:right;">Prezzo</th>
                            <th scope="col" style="text-align:right;">Totale</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$rows}
                    </tbody>
                </table>
                <div class="stats">
                    <div class="stat"><span class="stat-label">Totale</span><span class="stat-value">€ {$this->escape(number_format($total, 2, ',', '.'))}</span></div>
                    <div class="stat"><span class="stat-label">Incassato</span><span class="stat-value">€ {$this->escape(number_format($totalPaid, 2, ',', '.'))}</span></div>
                    {$discountRow}
                    {$vatRow}
                    {$balanceRow}
                </div>
                <div class="details">
                    <div class="panel">
                        <h3>Dettagli pagamento</h3>
                        <p><strong>Metodo:</strong> {$this->escape($paymentMethod)}<br><strong>Stato:</strong> {$this->escape($paymentStatus)}<br><strong>Operatore:</strong> {$this->escape($operatorName)}<br><strong>Data:</strong> {$this->escape($formattedDate)}</p>
                    </div>
                    {$customerBlock}
                    {$noteBlock}
                </div>
                <div class="cta">
                    <a href="{$this->escape($receiptUrl)}" target="_blank" rel="noopener">Apri lo scontrino</a>
                </div>
                <div class="footer">Email generata automaticamente da {$this->escape($this->appName)}. Se non riconosci questa operazione, rispondi a questo messaggio.</div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * @param array<string, mixed> $sale
     * @param array<int, string> $channelsUsed
     */
    private function emitSaleNotification(array $sale, bool $emailSent, array $channelsUsed, string $subject): void
    {
        if ($this->notificationService === null) {
            return;
        }

        $saleId = (int) ($sale['id'] ?? 0);
        if ($saleId <= 0) {
            return;
        }

        $total = (float) ($sale['total'] ?? 0.0);
        $customerName = $this->resolveCustomerName($sale) ?? 'Cliente non registrato';
        $paymentStatus = (string) ($sale['payment_status'] ?? ($sale['status'] ?? 'Completed'));
        $body = sprintf(
            'Totale € %s · Pagamento: %s · %s',
            number_format($total, 2, ',', '.'),
            $paymentStatus,
            $emailSent ? 'Email inviata automaticamente.' : 'Verifica l\'invio dell\'email al cliente.'
        );

        $meta = [
            'sale_id' => $saleId,
            'customer_name' => $customerName,
            'total' => $total,
            'payment_status' => $paymentStatus,
            'email_sent' => $emailSent,
            'channels' => $channelsUsed,
        ];

        $this->notificationService->push(
            'sale_completed',
            $subject,
            $body,
            [
                'level' => $emailSent ? 'success' : 'warning',
                'channel' => 'sales',
                'source' => 'sale_notification_service',
                'link' => 'index.php?page=sales_list',
                'meta' => $meta,
            ]
        );
    }

    private function sendViaResend(array $recipients, string $subject, string $textBody, string $htmlBody, int $saleId): bool
    {
        if ($this->resendApiKey === null || $this->resendApiKey === '' || !function_exists('curl_init')) {
            $this->log('Resend skipped for sale #' . $saleId . ' (API key missing or cURL unavailable).');
            return false;
        }

        $from = $this->formatFromAddress();
        if ($from === null) {
            $this->log('Resend skipped for sale #' . $saleId . ' (invalid FROM address).');
            return false;
        }

        if ($recipients === []) {
            return false;
        }

        $payload = [
            'from' => $from,
            'to' => $recipients,
            'subject' => $subject,
            'text' => $textBody,
            'html' => $htmlBody,
        ];

        try {
            $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->log('Resend payload encoding failed for sale #' . $saleId . ': ' . $exception->getMessage());
            return false;
        }

        $ch = curl_init('https://api.resend.com/emails');
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->resendApiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $encodedPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $error = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorMessage = $error !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        if ($error !== 0) {
            $this->log('Resend cURL error for sale #' . $saleId . ': #' . $error . ' ' . ($errorMessage ?? ''));
            return false;
        }

        if ($status < 200 || $status >= 300) {
            $snippet = is_string($response) ? substr($response, 0, 500) : '[no body]';
            $this->log('Resend rejected sale #' . $saleId . ' with HTTP ' . $status . ' body: ' . $snippet);
            return false;
        }

        $this->log('Resend accepted sale #' . $saleId . ' email per ' . implode(', ', $recipients) . ' (HTTP ' . $status . ').');
        return true;
    }

    private function sendViaMailFallback(array $recipients, string $subject, string $textBody, int $saleId): bool
    {
        $from = $this->formatFromAddress();
        if ($from === null) {
            $this->log('mail() skipped for sale #' . $saleId . ' (invalid FROM address).');
            return false;
        }

        $headers = [
            'From: ' . $from,
            'Content-Type: text/plain; charset=UTF-8',
        ];

        $success = false;
        foreach ($recipients as $recipient) {
            $result = @mail($recipient, $subject, $textBody, implode("\r\n", $headers));
            if ($result) {
                $this->log('mail() sent sale #' . $saleId . ' email to ' . $recipient . '.');
                $success = true;
            } else {
                $this->log('mail() failed for sale #' . $saleId . ' to ' . $recipient . '.');
            }
        }

        return $success;
    }

    private function formatFromAddress(): ?string
    {
        $email = $this->normalizeEmail($this->fromEmail);
        if ($email === null) {
            return null;
        }

        $name = $this->fromName !== null ? trim($this->fromName) : '';
        if ($name !== '') {
            $safeName = str_replace(['"', '<', '>'], '', $name);
            return sprintf('%s <%s>', $safeName, $email);
        }

        return $email;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function log(string $message): void
    {
        if ($this->logFile === null) {
            return;
        }

        $timestamp = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $line = '[' . $timestamp . '] ' . $message . "\n";
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
