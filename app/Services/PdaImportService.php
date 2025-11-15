<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class PdaImportService
{
    private const PDA_UPLOAD_DIR = __DIR__ . '/../../storage/uploads/pda';

    /**
     * @var array<string, array<string, array<int, string>>>
     */
    private const FIELD_ALIASES = [
        'iccid' => ['ICCID', 'Seriale SIM', 'Serial Number', 'Codice SIM', 'Sim'],
        'msisdn' => ['MSISDN', 'Numero', 'Linea', 'Numero linea', 'Telefono linea'],
        'plan' => ['Offerta', 'Piano', 'Prodotto', 'Profilo', 'Canvass'],
        'price' => ['Prezzo', 'Canone', 'Totale', 'Costo', 'Importo'],
        'customer_fullname' => ['Intestatario', 'Cliente', 'Nominativo', 'Titolare'],
        'customer_tax_code' => ['Codice Fiscale', 'CF', 'Cod.Fisc.', 'Partita IVA', 'P.IVA'],
        'customer_email' => ['Email', 'E-mail', 'Mail'],
        'customer_phone' => ['Telefono', 'Cellulare', 'Contatto', 'Recapito'],
        'customer_address' => ['Indirizzo', 'Address', 'Residenza'],
    ];

    public function __construct(
        private PDO $pdo,
        private CustomerService $customerService
    ) {
    }

    /**
     * @param array<string, mixed>|null $file
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $currentUser
     * @return array{success:bool,message:string,warnings?:array<int,string>,errors?:array<int,string>,prefill?:array<string,mixed>}
     */
    public function processUpload(?array $file, array $input, ?array $currentUser = null): array
    {
        if ($file === null) {
            return [
                'success' => false,
                'message' => 'Nessun file ricevuto. Seleziona una PDA prima di procedere.',
                'errors' => ['File PDA mancante.'],
            ];
        }

        $providerId = isset($input['pda_provider_id']) ? (int) $input['pda_provider_id'] : 0;
        if ($providerId <= 0) {
            return [
                'success' => false,
                'message' => 'Seleziona il gestore prima di importare la PDA.',
                'errors' => ['Gestore non valido.'],
            ];
        }

        $provider = $this->fetchProvider($providerId);
        if ($provider === null) {
            return [
                'success' => false,
                'message' => 'Gestore non trovato. Aggiorna la pagina e riprova.',
                'errors' => ['Provider non presente a sistema.'],
            ];
        }

        if (strcasecmp((string) $provider['name'], 'iliad') === 0) {
            return [
                'success' => false,
                'message' => 'Il gestore selezionato non supporta l\'import automatico delle PDA.',
                'errors' => ['Iliad non supportato.'],
            ];
        }

        if (!isset($file['error']) || !is_int($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $message = $this->describeUploadError($file['error'] ?? UPLOAD_ERR_NO_FILE);
            return [
                'success' => false,
                'message' => 'Caricamento PDA non riuscito: ' . $message,
                'errors' => [$message],
            ];
        }

        $originalName = isset($file['name']) ? (string) $file['name'] : 'pda_upload';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = 'bin';
        }

        $tmpPath = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return [
                'success' => false,
                'message' => 'Impossibile accedere al file caricato. Riprova.',
                'errors' => ['File temporaneo mancante o non valido.'],
            ];
        }

        $storagePath = $this->storeUploadedFile($tmpPath, $extension, (string) $provider['name']);

        $extraction = $this->extractTextFromFile($storagePath, $extension);
        if (!($extraction['success'] ?? false)) {
            $this->recordImport([
                'status' => 'Failed',
                'provider_id' => (int) $provider['id'],
                'provider_name' => (string) $provider['name'],
                'source_filename' => $originalName,
                'stored_path' => $storagePath,
                'raw_text' => $extraction['raw_text'] ?? null,
                'error_message' => $extraction['error'] ?? 'Estrazione testo non riuscita.',
                'user_id' => isset($currentUser['id']) ? (int) $currentUser['id'] : null,
            ]);

            return [
                'success' => false,
                'message' => 'Impossibile leggere il contenuto della PDA: ' . ($extraction['error'] ?? 'estrazione testo non riuscita'),
                'errors' => [$extraction['error'] ?? 'Estrazione testo non riuscita.'],
            ];
        }

        $text = (string) $extraction['text'];
        $parsed = $this->parsePayload($text, (string) $provider['name']);
        if (!($parsed['success'] ?? false)) {
            $this->recordImport([
                'status' => 'Failed',
                'provider_id' => (int) $provider['id'],
                'provider_name' => (string) $provider['name'],
                'source_filename' => $originalName,
                'stored_path' => $storagePath,
                'raw_text' => $text,
                'error_message' => $parsed['error'] ?? 'Parsing PDA non riuscito.',
                'user_id' => isset($currentUser['id']) ? (int) $currentUser['id'] : null,
            ]);

            return [
                'success' => false,
                'message' => 'PDA non riconosciuta: ' . ($parsed['error'] ?? 'impossibile estrarre i dati richiesti'),
                'errors' => [$parsed['error'] ?? 'Dati insufficienti nella PDA.'],
            ];
        }

        $warnings = $parsed['warnings'] ?? [];
        $customerProfile = $parsed['customer'] ?? [];
        $items = $parsed['items'] ?? [];

        $customerResult = $this->ensureCustomer($customerProfile);
        $warnings = array_merge($warnings, $customerResult['warnings'] ?? []);

        $resolvedItems = $this->resolveItems($items, (int) $provider['id']);
        $warnings = array_merge($warnings, $resolvedItems['warnings']);

        if ($resolvedItems['items'] === []) {
            $this->recordImport([
                'status' => 'Failed',
                'provider_id' => (int) $provider['id'],
                'provider_name' => (string) $provider['name'],
                'source_filename' => $originalName,
                'stored_path' => $storagePath,
                'raw_text' => $text,
                'error_message' => 'Nessun ICCID valido trovato nella PDA.',
                'user_id' => isset($currentUser['id']) ? (int) $currentUser['id'] : null,
                'customer_id' => $customerResult['id'] ?? null,
                'customer_payload' => $customerProfile,
            ]);

            return [
                'success' => false,
                'message' => 'Nessun ICCID valido trovato nella PDA. Verifica il file caricato.',
                'errors' => ['ICCID non presente o non riconosciuto nella PDA.'],
            ];
        }

        $prefill = [
            'provider' => [
                'id' => (int) $provider['id'],
                'name' => (string) $provider['name'],
            ],
            'customer_id' => $customerResult['id'] ?? null,
            'customer_name' => $customerResult['fullname'] ?? ($customerProfile['fullname'] ?? null),
            'customer_email' => $customerResult['email'] ?? ($customerProfile['email'] ?? null),
            'customer_phone' => $customerResult['phone'] ?? ($customerProfile['phone'] ?? null),
            'customer_tax_code' => $customerResult['tax_code'] ?? ($customerProfile['tax_code'] ?? null),
            'customer_note' => $customerResult['note'] ?? ($customerProfile['note'] ?? null),
            'items' => $resolvedItems['items'],
        ];

        $recordId = $this->recordImport([
            'status' => 'Processed',
            'provider_id' => (int) $provider['id'],
            'provider_name' => (string) $provider['name'],
            'source_filename' => $originalName,
            'stored_path' => $storagePath,
            'raw_text' => $text,
            'customer_id' => $customerResult['id'] ?? null,
            'customer_payload' => $customerProfile,
            'sale_payload' => $resolvedItems['items'],
            'user_id' => isset($currentUser['id']) ? (int) $currentUser['id'] : null,
            'notes' => $parsed['summary'] ?? null,
        ]);

        $messageParts = ['PDA importata con successo.'];
        if (($customerResult['status'] ?? '') === 'created') {
            $messageParts[] = 'Nuovo cliente registrato automaticamente.';
        } elseif (($customerResult['status'] ?? '') === 'updated') {
            $messageParts[] = 'Dati cliente aggiornati.';
        }
        $messageParts[] = 'Riferimento import: #' . $recordId;

        return [
            'success' => true,
            'message' => implode(' ', $messageParts),
            'warnings' => $warnings,
            'prefill' => $prefill,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProvider(int $providerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name FROM providers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $providerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    private function describeUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File troppo grande: riduci le dimensioni o comprimi la PDA.',
            UPLOAD_ERR_PARTIAL => 'Caricamento interrotto: riprova.',
            UPLOAD_ERR_NO_FILE => 'Nessun file selezionato.',
            UPLOAD_ERR_NO_TMP_DIR => 'Directory temporanea mancante sul server.',
            UPLOAD_ERR_CANT_WRITE => 'Impossibile scrivere il file sul disco.',
            UPLOAD_ERR_EXTENSION => 'Caricamento bloccato da un\'estensione PHP.',
            default => 'Errore sconosciuto durante il caricamento (codice ' . $code . ').',
        };
    }

    private function storeUploadedFile(string $tmpPath, string $extension, string $providerName): string
    {
        if (!is_dir(self::PDA_UPLOAD_DIR)) {
            if (!mkdir(self::PDA_UPLOAD_DIR, 0775, true) && !is_dir(self::PDA_UPLOAD_DIR)) {
                throw new RuntimeException('Impossibile creare la cartella di upload PDA.');
            }
        }

        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($providerName));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'gestore';
        }

        $filename = date('Ymd_His') . '_' . $slug . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = self::PDA_UPLOAD_DIR . '/' . $filename;

        if (!move_uploaded_file($tmpPath, $destination)) {
            throw new RuntimeException('Impossibile spostare il file caricato.');
        }

        return $destination;
    }

    /**
     * @return array{success:bool,text?:string,error?:string,raw_text?:string}
     */
    private function extractTextFromFile(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        if (in_array($extension, ['txt', 'csv', 'json', 'xml', 'tsv'], true)) {
            $content = file_get_contents($path);
            if ($content === false) {
                return ['success' => false, 'error' => 'Impossibile leggere il file caricato.'];
            }

            return ['success' => true, 'text' => $this->sanitizeText($content)];
        }

        if ($extension === 'pdf') {
            $text = $this->convertPdfToText($path);
            if ($text !== null && trim($text) !== '') {
                return ['success' => true, 'text' => $this->sanitizeText($text)];
            }

            return [
                'success' => false,
                'error' => 'Installa la libreria "smalot/pdfparser" oppure carica l\'estratto della PDA in formato .txt/.csv.',
                'raw_text' => null,
            ];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return ['success' => false, 'error' => 'Formato file non supportato.'];
        }

        return ['success' => true, 'text' => $this->sanitizeText($content)];
    }

    private function convertPdfToText(string $path): ?string
    {
        if (!class_exists('Smalot\\PdfParser\\Parser')) {
            return null;
        }

        try {
            $parserClass = 'Smalot\\PdfParser\\Parser';
            $parser = new $parserClass();
            $pdf = $parser->parseFile($path);
            $text = $pdf->getText();
            if (!is_string($text)) {
                return null;
            }

            return $text !== '' ? $text : null;
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function sanitizeText(string $text): string
    {
        $encoding = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'WINDOWS-1252'], true);
        if ($encoding && strtoupper($encoding) !== 'UTF-8') {
            $text = mb_convert_encoding($text, 'UTF-8', $encoding);
        }

        $text = str_replace("\r", "\n", $text);
        $text = preg_replace("/\n+/", "\n", $text) ?? $text;

        return $text;
    }

    /**
     * @return array{success:bool,customer?:array<string,mixed>,items?:array<int,array<string,mixed>>,warnings?:array<int,string>,error?:string,summary?:string}
     */
    private function parsePayload(string $text, string $providerName): array
    {
        $normalizedText = trim($text);
        if ($normalizedText === '') {
            return ['success' => false, 'error' => 'Il file è vuoto.'];
        }

        $jsonCandidate = ltrim($normalizedText);
        if ($jsonCandidate !== '' && ($jsonCandidate[0] === '{' || $jsonCandidate[0] === '[')) {
            try {
                $decoded = json_decode($jsonCandidate, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $this->parseFromArray($decoded, $providerName);
                }
            } catch (\JsonException) {
                // Ignora: verrà eseguito il parsing testuale.
            }
        }

        return $this->parseFromText($normalizedText, $providerName);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{success:bool,customer?:array<string,mixed>,items?:array<int,array<string,mixed>>,warnings?:array<int,string>,error?:string,summary?:string}
     */
    private function parseFromArray(array $payload, string $providerName): array
    {
        $warnings = [];
        $customer = [
            'fullname' => $this->stringOrNull($payload['customer_name'] ?? $payload['fullname'] ?? null),
            'email' => $this->stringOrNull($payload['customer_email'] ?? null),
            'phone' => $this->stringOrNull($payload['customer_phone'] ?? null),
            'tax_code' => $this->normalizeTaxCode($payload['customer_tax_code'] ?? null),
            'note' => $this->stringOrNull($payload['customer_note'] ?? null),
        ];

        $itemsRaw = $payload['items'] ?? null;
        $items = [];
        if (is_array($itemsRaw)) {
            foreach ($itemsRaw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $iccid = $this->normalizeIccid($row['iccid'] ?? $row['sim'] ?? null);
                $plan = $this->stringOrNull($row['plan'] ?? $row['offer'] ?? null);
                $msisdn = $this->normalizeMsisdn($row['msisdn'] ?? $row['line'] ?? null);
                $price = $this->normalizePrice($row['price'] ?? $row['amount'] ?? null);
                if ($iccid === null && $plan === null) {
                    continue;
                }
                $items[] = [
                    'iccid' => $iccid,
                    'plan' => $plan,
                    'msisdn' => $msisdn,
                    'price' => $price,
                ];
            }
        }

        if ($items === []) {
            $warnings[] = 'Nessuna riga articoli trovata nella PDA JSON.';
        }

        return [
            'success' => true,
            'customer' => $customer,
            'items' => $items,
            'warnings' => $warnings,
            'summary' => 'Import automatico JSON per ' . $providerName,
        ];
    }

    /**
     * @return array{success:bool,customer?:array<string,mixed>,items?:array<int,array<string,mixed>>,warnings?:array<int,string>,error?:string,summary?:string}
     */
    private function parseFromText(string $text, string $providerName): array
    {
        $warnings = [];

        $isFastweb = $this->isFastwebProvider($providerName);

        $customer = [
            'fullname' => $this->matchFirst($text, self::FIELD_ALIASES['customer_fullname']),
            'email' => $this->matchFirst($text, self::FIELD_ALIASES['customer_email']),
            'phone' => $this->normalizeMsisdn($this->matchFirst($text, self::FIELD_ALIASES['customer_phone'])),
            'tax_code' => $this->normalizeTaxCode($this->matchFirst($text, self::FIELD_ALIASES['customer_tax_code'])),
            'note' => null,
        ];

        if ($isFastweb) {
            $fastwebName = $this->extractFastwebCustomerName($text);
            if ($fastwebName !== null) {
                $customer['fullname'] = $fastwebName;
            }
        }

        $address = $this->matchFirst($text, self::FIELD_ALIASES['customer_address']);
        if ($address !== null) {
            $customer['note'] = 'Indirizzo PDA: ' . $address;
        }

        $iccids = $this->matchMultiple($text, self::FIELD_ALIASES['iccid']);
        $plans = $this->matchMultiple($text, self::FIELD_ALIASES['plan']);
        $msisdnList = array_map(fn (?string $value) => $this->normalizeMsisdn($value), $this->matchMultiple($text, self::FIELD_ALIASES['msisdn']));
        $prices = array_map(fn (?string $value) => $this->normalizePrice($value), $this->matchMultiple($text, self::FIELD_ALIASES['price']));

        $items = [];
        $fastwebDuplicates = 0;

        if ($isFastweb) {
            $fastwebOffer = $this->extractFastwebOfferDetails($text);
            $uniqueIccids = [];

            foreach ($iccids as $rawIccid) {
                $normalized = $this->normalizeIccid($rawIccid);
                if ($normalized === null) {
                    continue;
                }
                if (isset($uniqueIccids[$normalized])) {
                    $fastwebDuplicates++;
                    continue;
                }
                $uniqueIccids[$normalized] = $normalized;
            }

            $preferredPlan = $this->resolveFastwebPlan($fastwebOffer['plan'], $plans);
            if ($preferredPlan === null && isset($fastwebOffer['plan'])) {
                $rawPlan = is_string($fastwebOffer['plan']) ? trim((string) $fastwebOffer['plan']) : '';
                if ($rawPlan !== '') {
                    $preferredPlan = $this->cleanFastwebPlanCandidate($rawPlan) ?? $rawPlan;
                }
            }
            $preferredPrice = $this->resolveFastwebPrice($fastwebOffer['price'], $prices);
            $preferredMsisdn = $this->firstNonNull($msisdnList);

            foreach (array_values($uniqueIccids) as $normalizedIccid) {
                $items[] = [
                    'iccid' => $normalizedIccid,
                    'plan' => $preferredPlan,
                    'msisdn' => $preferredMsisdn,
                    'price' => $preferredPrice,
                    'offer_hint' => $fastwebOffer['plan'] ?? null,
                    'offer_price_hint' => $fastwebOffer['price'] ?? null,
                ];
            }
        } else {
            $rowCount = max(count($iccids), count($plans), count($msisdnList));
            for ($i = 0; $i < $rowCount; $i++) {
                $iccid = $iccids[$i] ?? $iccids[0] ?? null;
                $plan = $plans[$i] ?? $plans[0] ?? null;
                $msisdn = $msisdnList[$i] ?? $msisdnList[0] ?? null;
                $price = $prices[$i] ?? $prices[0] ?? null;

                if ($iccid === null && $plan === null && $msisdn === null) {
                    continue;
                }

                $items[] = [
                    'iccid' => $this->normalizeIccid($iccid),
                    'plan' => $plan,
                    'msisdn' => $msisdn,
                    'price' => $price,
                    'offer_hint' => $plan,
                    'offer_price_hint' => $price,
                ];
            }
        }

        if ($items === []) {
            return ['success' => false, 'error' => 'Impossibile individuare ICCID o offerta nella PDA.'];
        }

        if ($isFastweb) {
            if ($fastwebDuplicates > 0) {
                $warnings[] = 'Rilevati ' . $fastwebDuplicates . ' riferimenti duplicati agli stessi ICCID: mantenute solo le SIM uniche.';
            }
        } elseif (count($iccids) > 1) {
            $warnings[] = 'Sono stati rilevati ' . count($iccids) . ' ICCID: verifica le righe prima di salvare la vendita.';
        }
        if ($customer['fullname'] === null) {
            $warnings[] = 'Nome cliente non presente nella PDA.';
        }

        return [
            'success' => true,
            'customer' => $customer,
            'items' => $items,
            'warnings' => $warnings,
            'summary' => 'Import testo per ' . $providerName,
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array{status:string,id?:int,fullname?:string,email?:string|null,phone?:string|null,tax_code?:string|null,note?:string|null,warnings?:array<int,string>}
     */
    private function ensureCustomer(array $profile): array
    {
        $warnings = [];

        $fullname = $this->stringOrNull($profile['fullname'] ?? null);
        $email = $this->stringOrNull($profile['email'] ?? null);
        $phone = $this->normalizeMsisdn($profile['phone'] ?? null);
        $taxCode = $this->normalizeTaxCode($profile['tax_code'] ?? null);
        $note = $this->stringOrNull($profile['note'] ?? null);

        $existing = null;
        if ($taxCode !== null) {
            $stmt = $this->pdo->prepare('SELECT id, fullname, email, phone, tax_code, note FROM customers WHERE tax_code = :tax LIMIT 1');
            $stmt->execute([':tax' => $taxCode]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($existing === null && $email !== null) {
            $stmt = $this->pdo->prepare('SELECT id, fullname, email, phone, tax_code, note FROM customers WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($existing !== null) {
            $existingSnapshot = [
                'fullname' => $this->stringOrNull($existing['fullname'] ?? null) ?? '',
                'email' => $this->stringOrNull($existing['email'] ?? null),
                'phone' => $this->normalizeMsisdn($existing['phone'] ?? null),
                'tax_code' => $this->normalizeTaxCode($existing['tax_code'] ?? null),
                'note' => $this->stringOrNull($existing['note'] ?? null),
            ];

            $payload = [
                'fullname' => $fullname ?? $existingSnapshot['fullname'],
                'email' => $email ?? $existingSnapshot['email'],
                'phone' => $phone ?? $existingSnapshot['phone'],
                'tax_code' => $taxCode ?? $existingSnapshot['tax_code'],
                'note' => $this->mergeNotes($existingSnapshot['note'], $note),
            ];

            $requiresUpdate = false;
            foreach ($payload as $field => $value) {
                if (($existingSnapshot[$field] ?? null) !== $value) {
                    $requiresUpdate = true;
                    break;
                }
            }

            if ($requiresUpdate) {
                $result = $this->customerService->update((int) $existing['id'], $payload);
                if (!($result['success'] ?? false)) {
                    $warnings[] = 'Aggiornamento cliente non riuscito: ' . ($result['message'] ?? 'errore sconosciuto');
                }

                return [
                    'status' => ($result['success'] ?? false) ? 'updated' : 'skipped',
                    'id' => (int) $existing['id'],
                    'fullname' => $payload['fullname'],
                    'email' => $payload['email'],
                    'phone' => $payload['phone'],
                    'tax_code' => $payload['tax_code'],
                    'note' => $payload['note'],
                    'warnings' => $warnings,
                ];
            }

            return [
                'status' => 'unchanged',
                'id' => (int) $existing['id'],
                'fullname' => $payload['fullname'],
                'email' => $payload['email'],
                'phone' => $payload['phone'],
                'tax_code' => $payload['tax_code'],
                'note' => $payload['note'],
                'warnings' => $warnings,
            ];
        }

        if ($fullname === null) {
            $fullname = 'Cliente PDA ' . date('d/m/Y H:i');
            $warnings[] = 'Nome cliente non presente nella PDA: usato un segnaposto.';
        }

        $result = $this->customerService->create([
            'fullname' => $fullname,
            'email' => $email,
            'phone' => $phone,
            'tax_code' => $taxCode,
            'note' => $note,
        ]);

        if (!($result['success'] ?? false)) {
            $warnings[] = 'Creazione cliente non riuscita: ' . ($result['message'] ?? 'errore sconosciuto');
            return [
                'status' => 'skipped',
                'warnings' => $warnings,
            ];
        }

        return [
            'status' => 'created',
            'id' => (int) ($result['id'] ?? 0),
            'fullname' => $fullname,
            'email' => $email,
            'phone' => $phone,
            'tax_code' => $taxCode,
            'note' => $note,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{items:array<int,array<string,mixed>>,warnings:array<int,string>}
     */
    private function resolveItems(array $items, int $providerId): array
    {
        $resolved = [];
        $warnings = [];

        $iccidStmt = $this->pdo->prepare(
            "SELECT id, provider_id, status FROM iccid_stock WHERE iccid = :iccid ORDER BY FIELD(status, 'InStock', 'Reserved', 'Sold') LIMIT 1"
        );

        foreach ($items as $item) {
            $iccid = $this->normalizeIccid($item['iccid'] ?? null);
            $plan = $this->stringOrNull($item['plan'] ?? null);
            $offerHint = $this->stringOrNull($item['offer_hint'] ?? null);
            $msisdn = $this->normalizeMsisdn($item['msisdn'] ?? null);
            $price = $this->normalizePrice($item['price'] ?? null);

            if ($price === null && isset($item['offer_price_hint'])) {
                $hintPrice = $this->normalizePrice($item['offer_price_hint']);
                if ($hintPrice !== null) {
                    $price = $hintPrice;
                }
            }

            $offerMatch = $this->matchActiveOffer($providerId, [$plan, $offerHint], $price);
            $offerId = null;
            if ($offerMatch !== null) {
                if (isset($offerMatch['title']) && is_string($offerMatch['title']) && $offerMatch['title'] !== '') {
                    $plan = $offerMatch['title'];
                }
                if (($price === null || $price <= 0.0) && isset($offerMatch['price'])) {
                    $matchedPrice = $this->normalizePrice($offerMatch['price']);
                    if ($matchedPrice !== null) {
                        $price = $matchedPrice;
                    }
                }
                if (isset($offerMatch['id'])) {
                    $offerId = (int) $offerMatch['id'];
                }
            }

            $descriptionParts = [];
            if ($plan !== null) {
                $descriptionParts[] = $plan;
            }
            if ($msisdn !== null) {
                $descriptionParts[] = 'MSISDN ' . $msisdn;
            }
            $description = $descriptionParts === [] ? 'Attivazione SIM' : implode(' • ', $descriptionParts);

            $iccidId = null;
            if ($iccid !== null) {
                $iccidStmt->execute([':iccid' => $iccid]);
                $row = $iccidStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($row !== null) {
                    if ((int) $row['provider_id'] !== $providerId) {
                        $warnings[] = 'La SIM ' . $iccid . ' appartiene a un altro gestore: verifica lo stock.';
                    }
                    if ((string) $row['status'] !== 'InStock') {
                        $warnings[] = 'La SIM ' . $iccid . ' risulta già ' . $row['status'] . '.';
                    }
                    $iccidId = (int) $row['id'];
                } else {
                    $warnings[] = 'SIM ' . $iccid . ' non trovata in magazzino: aggiungi manualmente l\'ICCID.';
                }
            }

            $resolved[] = [
                'iccid_id' => $iccidId,
                'iccid_code' => $iccid,
                'description' => $description,
                'price' => $price,
                'quantity' => 1,
                'offer_id' => $offerId,
                'offer_title' => $plan,
            ];
        }

        return [
            'items' => $resolved,
            'warnings' => $warnings,
        ];
    }

    private function isFastwebProvider(string $providerName): bool
    {
        return stripos($providerName, 'fastweb') !== false;
    }

    private function extractFastwebCustomerName(string $text): ?string
    {
        $patterns = [
            '/Proposta\s+di\s+Abbonamento[\s\S]{0,120}?Cliente\s*[:\-]\s*([^\r\n]+)/i',
            '/Cliente\s*[:\-]\s*([^\r\n]+)/i',
            '/Proposta\s+di\s+Abbonamento\s*(?:\r?\n){1,2}([^\r\n]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $candidate = $this->cleanFastwebNameCandidate($matches[1] ?? '');
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @return array{plan:?string,price:?float}
     */
    private function extractFastwebOfferDetails(string $text): array
    {
        $plan = null;
        $price = null;

        $planPatterns = [
            '/Dettaglio\s+costi\s*[:\-]?\s*([A-Z0-9][^\r\n]+)/i',
            '/Dettaglio\s+costi\s*(?:\r?\n)+\s*([A-Z0-9][^\r\n]+)/i',
            '/\bFastweb\s+Mobile\s+[A-Za-z0-9 ]{3,60}/i',
        ];

        foreach ($planPatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $candidate = $this->cleanFastwebPlanCandidate($matches[1] ?? $matches[0] ?? '');
                if ($candidate !== null) {
                    $plan = $candidate;
                    break;
                }
            }
        }

        $pricePatterns = [
            '/Contributo\s+SIM(?:\/eSIM)?\s+([0-9]+(?:[\.,][0-9]{1,2})?)/i',
            '/Prima\s+ricarica[^\r\n]*?([0-9]+(?:[\.,][0-9]{1,2})?)\s*€/i',
        ];

        foreach ($pricePatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $normalized = $this->normalizePrice($matches[1] ?? null);
                if ($normalized !== null) {
                    $price = $normalized;
                    break;
                }
            }
        }

        if ($price === null && $plan !== null) {
            $planPos = stripos($text, $plan);
            if ($planPos !== false) {
                $snippet = substr($text, $planPos, 200);
                if (is_string($snippet) && preg_match('/([0-9]+(?:[\.,][0-9]{1,2})?)\s*€/', $snippet, $m)) {
                    $normalized = $this->normalizePrice($m[1]);
                    if ($normalized !== null) {
                        $price = $normalized;
                    }
                }
            }
        }

        return [
            'plan' => $plan,
            'price' => $price,
        ];
    }

    private function cleanFastwebNameCandidate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $candidate = trim($value);
        if ($candidate === '') {
            return null;
        }

        $candidate = preg_split('/\b(?:Offerta|Codice\s+Preventivo|Codice\s+Venditore|Numero\s+Cliente|Mobile\s+Number\s+Portability)\b/i', $candidate)[0] ?? $candidate;
        $candidate = preg_replace('/\b(?:Cliente|Intestatario)\b\s*[:\-]?/i', '', $candidate) ?? $candidate;
        $candidate = trim($candidate, " \t.:,;-");

        return $candidate !== '' ? $candidate : null;
    }

    private function cleanFastwebPlanCandidate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $candidate = trim($value);
        $candidate = preg_replace('/\b(?:Prima\s+ricarica|Contributo\s+SIM\/eSIM|Contributo\s+SIM)\b.*$/i', '', $candidate) ?? $candidate;
        $candidate = trim($candidate, " \t.:,;-");

        if ($candidate === '' || strlen($candidate) < 4) {
            return null;
        }

        return preg_replace('/\s{2,}/', ' ', $candidate);
    }

    private function resolveFastwebPlan(?string $offerPlan, array $plans): ?string
    {
        $candidates = [];

        if ($offerPlan !== null) {
            $clean = $this->cleanFastwebPlanCandidate($offerPlan);
            if ($clean !== null) {
                if (!$this->isFastwebGenericPlan($clean)) {
                    return $clean;
                }
                $candidates[] = $clean;
            }
        }

        foreach ($plans as $plan) {
            if (!is_string($plan)) {
                continue;
            }
            $clean = $this->cleanFastwebPlanCandidate($plan);
            if ($clean === null) {
                continue;
            }
            if (!$this->isFastwebGenericPlan($clean)) {
                return $clean;
            }
            $candidates[] = $clean;
        }

        return $candidates[0] ?? null;
    }

    private function resolveFastwebPrice(?float $offerPrice, array $prices): ?float
    {
        if ($offerPrice !== null && $offerPrice > 0) {
            return round($offerPrice, 2);
        }

        foreach ($prices as $price) {
            if (is_float($price) && $price > 0) {
                return round($price, 2);
            }
        }

        return null;
    }

    private function firstNonNull(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function isFastwebGenericPlan(string $plan): bool
    {
        $normalized = strtolower($plan);
        $genericTokens = [
            'offerta residenziale',
            'offerta business',
            'termini e condizioni',
            'condizioni generali',
            'scheda cliente',
            'modulo',
            'costi mensili',
        ];

        foreach ($genericTokens as $token) {
            if (str_contains($normalized, $token)) {
                return true;
            }
        }

        return false;
    }

    private function matchActiveOffer(int $providerId, array $candidatePlans, ?float $price): ?array
    {
        $normalizedCandidates = [];
        foreach ($candidatePlans as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $trimmed = trim($candidate);
            if ($trimmed === '') {
                continue;
            }
            $normalized = $this->normalizeOfferTitle($trimmed);
            if ($normalized !== '') {
                $normalizedCandidates[$normalized] = $trimmed;
            }
        }

        $needsPriceMatch = $price !== null && $price > 0.0;
        if ($normalizedCandidates === [] && !$needsPriceMatch) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, title, price FROM operator_offers
             WHERE provider_id = :provider_id
               AND status = "Active"
               AND (valid_from IS NULL OR valid_from <= CURRENT_DATE())
               AND (valid_to IS NULL OR valid_to >= CURRENT_DATE())'
        );
        $stmt->execute([':provider_id' => $providerId]);
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $bestOffer = null;
        $bestScore = 0.0;
        $priceMatch = $price !== null ? (float) $price : null;

        foreach ($offers as $offer) {
            $title = isset($offer['title']) ? (string) $offer['title'] : '';
            if ($title === '') {
                continue;
            }
            $normalizedTitle = $this->normalizeOfferTitle($title);
            if ($normalizedTitle === '') {
                continue;
            }

            foreach ($normalizedCandidates as $candidate => $original) {
                if ($candidate === '') {
                    continue;
                }
                if ($normalizedTitle === $candidate) {
                    return [
                        'id' => (int) ($offer['id'] ?? 0),
                        'title' => $title,
                        'price' => isset($offer['price']) ? (float) $offer['price'] : null,
                    ];
                }
                if (str_contains($normalizedTitle, $candidate) || str_contains($candidate, $normalizedTitle)) {
                    if ($bestScore < 0.9) {
                        $bestOffer = $offer;
                        $bestScore = 0.9;
                    }
                }
            }

            if ($priceMatch !== null && isset($offer['price'])) {
                $offerPrice = $this->normalizePrice($offer['price']);
                if ($offerPrice !== null && abs($offerPrice - $priceMatch) < 0.01 && $bestScore < 0.6) {
                    $bestOffer = $offer;
                    $bestScore = 0.6;
                }
            }
        }

        if ($bestOffer !== null) {
            return [
                'id' => (int) ($bestOffer['id'] ?? 0),
                'title' => (string) ($bestOffer['title'] ?? ''),
                'price' => isset($bestOffer['price']) ? (float) $bestOffer['price'] : null,
            ];
        }

        if ($normalizedCandidates === []) {
            return null;
        }

        $firstOriginal = reset($normalizedCandidates);
        if (!is_string($firstOriginal) || $firstOriginal === '') {
            return null;
        }

        try {
            $created = $this->createOperatorOffer($providerId, $firstOriginal, $price);
            if ($created !== null) {
                return $created;
            }
        } catch (\Throwable $exception) {
            // Ignore offer creation failures; fallback to null match.
        }

        return null;
    }

    private function normalizeOfferTitle(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/u', '', $normalized) ?? $normalized;

        return $normalized;
    }

    private function createOperatorOffer(int $providerId, string $title, ?float $price): ?array
    {
        $trimmedTitle = trim($title);
        if ($trimmedTitle === '') {
            return null;
        }

        $amount = $price !== null && $price > 0 ? round($price, 2) : 0.00;

        $insert = $this->pdo->prepare(
            'INSERT INTO operator_offers (provider_id, title, description, price, status, valid_from, valid_to)
             VALUES (:provider_id, :title, NULL, :price, "Active", NULL, NULL)'
        );
        $insert->execute([
            ':provider_id' => $providerId,
            ':title' => $trimmedTitle,
            ':price' => $amount,
        ]);

        $offerId = (int) $this->pdo->lastInsertId();
        if ($offerId <= 0) {
            return null;
        }

        return [
            'id' => $offerId,
            'title' => $trimmedTitle,
            'price' => $amount,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function recordImport(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pda_imports (
                user_id, provider_id, provider_name, source_filename, stored_path, status,
                customer_id, customer_payload, sale_payload, raw_text, notes, error_message
            ) VALUES (
                :user_id, :provider_id, :provider_name, :source_filename, :stored_path, :status,
                :customer_id, :customer_payload, :sale_payload, :raw_text, :notes, :error_message
            )'
        );

        $stmt->execute([
            ':user_id' => $data['user_id'] ?? null,
            ':provider_id' => $data['provider_id'] ?? null,
            ':provider_name' => $data['provider_name'] ?? '',
            ':source_filename' => $data['source_filename'] ?? '',
            ':stored_path' => $this->relativeStoragePath($data['stored_path'] ?? ''),
            ':status' => $data['status'] ?? 'Processed',
            ':customer_id' => $data['customer_id'] ?? null,
            ':customer_payload' => $this->encodeJson($data['customer_payload'] ?? null),
            ':sale_payload' => $this->encodeJson($data['sale_payload'] ?? null),
            ':raw_text' => $data['raw_text'] ?? null,
            ':notes' => $data['notes'] ?? null,
            ':error_message' => $data['error_message'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function relativeStoragePath(string $absolutePath): string
    {
        if ($absolutePath === '') {
            return '';
        }

        $base = realpath(__DIR__ . '/../../');
        $absolute = realpath($absolutePath);
        if ($base === false || $absolute === false) {
            return $absolutePath;
        }

        if (str_starts_with($absolute, $base)) {
            return ltrim(str_replace($base, '', $absolute), DIRECTORY_SEPARATOR);
        }

        return $absolutePath;
    }

    private function encodeJson(mixed $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return null;
        }

        return $encoded;
    }

    /**
     * @param array<int, string> $labels
     */
    private function matchFirst(string $text, array $labels): ?string
    {
        foreach ($labels as $label) {
            $pattern = '/(?:^|\n)\s*' . preg_quote($label, '/') . '\s*[:\-]?\s*(.+)$/mi';
            if (preg_match($pattern, $text, $matches)) {
                $value = trim($matches[1]);
                if ($value !== '') {
                    return $this->stripTrailingLabelArtifacts($value);
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $labels
     * @return array<int, string|null>
     */
    private function matchMultiple(string $text, array $labels): array
    {
        $results = [];
        foreach ($labels as $label) {
            $pattern = '/(?:^|\n)\s*' . preg_quote($label, '/') . '\s*[:\-]?\s*(.+)$/mi';
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[1] as $value) {
                    $trimmed = $this->stripTrailingLabelArtifacts(trim((string) $value));
                    if ($trimmed !== '') {
                        $results[] = $trimmed;
                    }
                }
            }
        }

        return $results;
    }

    private function stripTrailingLabelArtifacts(string $value): string
    {
        return preg_replace('/\s+(?:Cod\.\s*Fisc\.|MSISDN|ICCID)\b.*$/i', '', $value) ?? $value;
    }

    private function normalizeIccid(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', $value);
        if ($digits === null || $digits === '' || strlen($digits) < 18) {
            return null;
        }

        return substr($digits, 0, 22);
    }

    private function normalizeMsisdn(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', $value);
        if ($digits === null || $digits === '') {
            return null;
        }
        if (strlen($digits) >= 9 && !str_starts_with($digits, '39')) {
            $digits = '39' . $digits;
        }

        return '+' . $digits;
    }

    private function normalizePrice(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }
        if (!is_string($value)) {
            return null;
        }
        $clean = preg_replace('/[^0-9,\.]/', '', $value);
        if ($clean === null || $clean === '') {
            return null;
        }
        $clean = trim($clean);
        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                // Formato europeo: usa la virgola come separatore decimale.
                $clean = str_replace('.', '', $clean);
                $clean = str_replace(',', '.', $clean);
            } else {
                // Formato anglosassone.
                $clean = str_replace(',', '', $clean);
            }
        } elseif ($lastComma !== false) {
            $clean = str_replace(',', '.', $clean);
        }

        if (!is_numeric($clean)) {
            return null;
        }

        return round((float) $clean, 2);
    }

    private function normalizeTaxCode(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $normalized = strtoupper(trim($value));
        $normalized = preg_replace('/[^A-Z0-9]/', '', $normalized) ?? $normalized;
        if ($normalized === '') {
            return null;
        }
        return $normalized;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function mergeNotes(?string $existing, ?string $note): ?string
    {
        if ($existing === null || $existing === '') {
            return $note;
        }
        if ($note === null || $note === '') {
            return $existing;
        }
        if (str_contains($existing, $note)) {
            return $existing;
        }

        return trim($existing . '\n' . $note);
    }
}
