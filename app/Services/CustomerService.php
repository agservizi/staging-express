<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\CustomerPortalAuthService;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Gestione anagrafiche clienti con validazioni e controlli di utilizzo.
 */
final class CustomerService
{
	public function __construct(
		private PDO $pdo,
		private ?string $resendApiKey = null,
		private ?string $resendFrom = null,
		private ?string $appName = null,
		private ?string $portalUrl = null,
		private ?string $resendFromName = null
	) {
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listAll(): array
	{
		$stmt = $this->pdo->query(
			'SELECT id, fullname, email, phone, tax_code, note, created_at, updated_at
			 FROM customers
			 ORDER BY fullname ASC'
		);

		return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	/**
	 * @return array{rows: array<int, array<string, mixed>>, pagination: array<string, int|bool>}
	 */
	public function listPaginated(int $page, int $perPage = 10, ?string $search = null): array
	{
		$page = max(1, $page);
		$perPage = max(1, min($perPage, 50));

		$searchTerm = trim((string) ($search ?? ''));
		$params = [];
		$where = '';
		if ($searchTerm !== '') {
			$where = 'WHERE fullname LIKE :term OR email LIKE :term OR phone LIKE :term OR tax_code LIKE :term';
			$params[':term'] = '%' . $searchTerm . '%';
		}

		$countSql = 'SELECT COUNT(*) FROM customers ' . $where;
		$countStmt = $this->pdo->prepare($countSql);
		foreach ($params as $key => $value) {
			$countStmt->bindValue($key, $value);
		}
		$countStmt->execute();
		$total = (int) ($countStmt->fetchColumn() ?: 0);

		$totalPages = max(1, (int) ceil($total / $perPage));
		if ($page > $totalPages) {
			$page = $totalPages;
		}
		$offset = ($page - 1) * $perPage;

		$query = 'SELECT id, fullname, email, phone, tax_code, note, created_at, updated_at
				  FROM customers ' . $where . ' ORDER BY fullname ASC LIMIT :limit OFFSET :offset';
		$stmt = $this->pdo->prepare($query);
		foreach ($params as $key => $value) {
			$stmt->bindValue($key, $value);
		}
		$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
		$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

		return [
			'rows' => $rows,
			'pagination' => [
				'page' => $page,
				'per_page' => $perPage,
				'total' => $total,
				'total_pages' => $totalPages,
				'has_prev' => $page > 1,
				'has_next' => $page < $totalPages,
			],
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{success:bool, id?:int, message:string, errors?:array<int, string>, portal_account?:array<string, mixed>}
	 */
	public function create(array $payload): array
	{
		$normalised = $this->normalisePayload($payload);
		if ($normalised['errors'] !== []) {
			return [
				'success' => false,
				'message' => 'Impossibile creare il cliente. Verifica i dati inseriti.',
				'errors' => $normalised['errors'],
			];
		}

		$uniqueErrors = $this->ensureUniqueConstraints($normalised['values']);
		if ($uniqueErrors !== []) {
			return [
				'success' => false,
				'message' => 'Impossibile creare il cliente. Verifica i dati inseriti.',
				'errors' => $uniqueErrors,
			];
		}

		$portalAccount = null;

		try {
			$this->pdo->beginTransaction();
			$stmt = $this->pdo->prepare(
				'INSERT INTO customers (fullname, email, phone, tax_code, note)
				 VALUES (:fullname, :email, :phone, :tax_code, :note)'
			);
			$stmt->execute([
				':fullname' => $normalised['values']['fullname'],
				':email' => $normalised['values']['email'],
				':phone' => $normalised['values']['phone'],
				':tax_code' => $normalised['values']['tax_code'],
				':note' => $normalised['values']['note'],
			]);

			$customerId = (int) $this->pdo->lastInsertId();
			if ($normalised['values']['email'] !== null) {
				$portalAccount = $this->provisionPortalAccount($customerId, $normalised['values']['email']);
			}

			$this->pdo->commit();
		} catch (\Throwable $exception) {
			if ($this->pdo->inTransaction()) {
				$this->pdo->rollBack();
			}

			return [
				'success' => false,
				'message' => 'Errore durante il salvataggio del cliente.',
				'errors' => ['Dettaglio: ' . $exception->getMessage()],
			];
		}

		$message = 'Cliente creato con successo.';
		if (is_array($portalAccount) && ($portalAccount['status'] ?? '') === 'created') {
			$message .= ' Credenziali area clienti generate automaticamente.';
			$this->sendPortalWelcomeEmail(
				(string) ($portalAccount['email'] ?? ''),
				(string) ($portalAccount['password'] ?? ''),
				$normalised['values']['fullname']
			);
		}

		return [
			'success' => true,
			'id' => $customerId,
			'message' => $message,
			'portal_account' => $portalAccount,
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{success:bool, message:string, errors?:array<int, string>, portal_account?:array<string, mixed>}
	 */
	public function update(int $customerId, array $payload): array
	{
		$existing = $this->find($customerId);
		if ($existing === null) {
			return [
				'success' => false,
				'message' => 'Cliente non trovato.',
				'errors' => ['Il cliente selezionato non è più disponibile.'],
			];
		}

		$normalised = $this->normalisePayload($payload);
		if ($normalised['errors'] !== []) {
			return [
				'success' => false,
				'message' => 'Impossibile aggiornare il cliente.',
				'errors' => $normalised['errors'],
			];
		}

		$uniqueErrors = $this->ensureUniqueConstraints($normalised['values'], $customerId);
		if ($uniqueErrors !== []) {
			return [
				'success' => false,
				'message' => 'Impossibile aggiornare il cliente.',
				'errors' => $uniqueErrors,
			];
		}

		$portalAccount = null;

		try {
			$this->pdo->beginTransaction();
			$stmt = $this->pdo->prepare(
				'UPDATE customers
				 SET fullname = :fullname,
					 email = :email,
					 phone = :phone,
					 tax_code = :tax_code,
					 note = :note
				 WHERE id = :id'
			);
			$stmt->execute([
				':fullname' => $normalised['values']['fullname'],
				':email' => $normalised['values']['email'],
				':phone' => $normalised['values']['phone'],
				':tax_code' => $normalised['values']['tax_code'],
				':note' => $normalised['values']['note'],
				':id' => $customerId,
			]);

			$portalAccount = $this->syncPortalAccount($customerId, $normalised['values']['email']);

			$this->pdo->commit();
		} catch (\Throwable $exception) {
			if ($this->pdo->inTransaction()) {
				$this->pdo->rollBack();
			}

			return [
				'success' => false,
				'message' => 'Errore durante l\'aggiornamento del cliente.',
				'errors' => ['Dettaglio: ' . $exception->getMessage()],
			];
		}

		$message = 'Cliente aggiornato correttamente.';
		if (is_array($portalAccount)) {
			$status = $portalAccount['status'] ?? '';
			if ($status === 'created') {
				$message .= ' Account area clienti creato automaticamente.';
				$this->sendPortalWelcomeEmail(
					(string) ($portalAccount['email'] ?? ''),
					(string) ($portalAccount['password'] ?? ''),
					$normalised['values']['fullname']
				);
			} elseif ($status === 'updated') {
				$message .= ' Email area clienti aggiornata.';
			}
		}

		return [
			'success' => true,
			'message' => $message,
			'portal_account' => $portalAccount,
		];
	}

	/**
	 * @return array{success:bool, message:string, errors?:array<int, string>}
	 */
	public function delete(int $customerId): array
	{
		$existing = $this->find($customerId);
		if ($existing === null) {
			return [
				'success' => false,
				'message' => 'Cliente non trovato.',
				'errors' => ['Il cliente selezionato non è più disponibile.'],
			];
		}

		$usageStmt = $this->pdo->prepare('SELECT COUNT(*) FROM sales WHERE customer_id = :id');
		$usageStmt->execute([':id' => $customerId]);
		$usage = (int) ($usageStmt->fetchColumn() ?: 0);
		if ($usage > 0) {
			return [
				'success' => false,
				'message' => 'Impossibile eliminare il cliente.',
				'errors' => ['Sono presenti vendite associate: scollega o annulla gli scontrini prima di procedere.'],
			];
		}

		try {
			$stmt = $this->pdo->prepare('DELETE FROM customers WHERE id = :id');
			$stmt->execute([':id' => $customerId]);
		} catch (PDOException $exception) {
			return [
				'success' => false,
				'message' => 'Errore durante l\'eliminazione del cliente.',
				'errors' => ['Database: ' . $exception->getMessage()],
			];
		}

		return [
			'success' => true,
			'message' => 'Cliente eliminato correttamente.',
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(int $customerId): ?array
	{
		$stmt = $this->pdo->prepare(
			'SELECT id, fullname, email, phone, tax_code, note, created_at, updated_at
			 FROM customers
			 WHERE id = :id'
		);
		$stmt->execute([':id' => $customerId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row !== false ? $row : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function search(string $term): array
	{
		$term = trim($term);
		if ($term === '') {
			return [];
		}

		$stmt = $this->pdo->prepare(
			'SELECT id, fullname, email, phone, tax_code
			 FROM customers
			 WHERE fullname LIKE :term OR email LIKE :term OR phone LIKE :term OR tax_code LIKE :term
			 ORDER BY fullname ASC
			 LIMIT 10'
		);
		$stmt->execute([':term' => '%' . $term . '%']);

		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	}

	/**
	 * @return array{success:bool, message:string, errors?:array<int, string>, portal_account?:array<string, mixed>}
	 */
	public function resendPortalCredentials(int $customerId): array
	{
		$customer = $this->find($customerId);
		if ($customer === null) {
			return [
				'success' => false,
				'message' => 'Cliente non trovato.',
				'errors' => ['Seleziona un cliente valido per reinviare le credenziali.'],
			];
		}

		$emailRaw = isset($customer['email']) ? trim((string) $customer['email']) : '';
		if ($emailRaw === '' || !filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
			return [
				'success' => false,
				'message' => 'Email cliente mancante o non valida.',
				'errors' => ['Imposta un indirizzo email valido prima di reinviare le credenziali.'],
			];
		}

		$normalizedEmail = trim(function_exists('mb_strtolower') ? mb_strtolower($emailRaw) : strtolower($emailRaw));
		$portalAccount = null;

		try {
			$this->pdo->beginTransaction();
			$stmt = $this->pdo->prepare('SELECT id, email FROM customer_portal_accounts WHERE customer_id = :customer LIMIT 1');
			$stmt->execute([':customer' => $customerId]);
			$account = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

			if ($account === null) {
				$portalAccount = $this->provisionPortalAccount($customerId, $normalizedEmail);
				$portalAccount['status'] = 'resent';
			} else {
				if ((string) ($account['email'] ?? '') !== $normalizedEmail) {
					$updateEmail = $this->pdo->prepare('UPDATE customer_portal_accounts SET email = :email, updated_at = NOW() WHERE id = :id');
					$updateEmail->execute([
						':email' => $normalizedEmail,
						':id' => (int) $account['id'],
					]);
				}

				$newPassword = $this->generatePortalPassword();
				$hash = password_hash($newPassword, PASSWORD_DEFAULT);
				$updatePassword = $this->pdo->prepare(
					'UPDATE customer_portal_accounts
					 SET password_hash = :hash,
						 invite_token = NULL,
						 invite_sent_at = NOW(),
						 updated_at = NOW()
					 WHERE id = :id'
				);
				$updatePassword->execute([
					':hash' => $hash,
					':id' => (int) $account['id'],
				]);

				$portalAccount = [
					'email' => $normalizedEmail,
					'password' => $newPassword,
					'password_changed' => true,
					'account_id' => (int) $account['id'],
					'original_status' => 'existing',
					'status' => 'resent',
				];
			}

			$this->pdo->commit();
		} catch (\Throwable $exception) {
			if ($this->pdo->inTransaction()) {
				$this->pdo->rollBack();
			}

			return [
				'success' => false,
				'message' => 'Errore durante il reinvio delle credenziali.',
				'errors' => ['Dettaglio: ' . $exception->getMessage()],
			];
		}

		$sent = $this->deliverPortalWelcomeEmail(
			(string) ($portalAccount['email'] ?? $normalizedEmail),
			(string) ($portalAccount['password'] ?? ''),
			(string) ($customer['fullname'] ?? '')
		);
		$portalAccount['email_sent'] = $sent;

		$message = $sent
			? 'Nuove credenziali inviate a ' . ($portalAccount['email'] ?? $normalizedEmail) . '.'
			: 'Credenziali aggiornate. Condividile manualmente con il cliente.';

		return [
			'success' => true,
			'message' => $message,
			'portal_account' => $portalAccount,
		];
	}

	/**
	 * @return array{success:bool, message:string, errors?:array<int, string>, invitation?:array<string, mixed>}
	 */
	public function sendPortalInvitation(int $customerId): array
	{
		$customer = $this->find($customerId);
		if ($customer === null) {
			return [
				'success' => false,
				'message' => 'Cliente non trovato.',
				'errors' => ['Seleziona un cliente valido per inviare un invito.'],
			];
		}

		$emailRaw = isset($customer['email']) ? trim((string) $customer['email']) : '';
		if ($emailRaw === '' || !filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
			return [
				'success' => false,
				'message' => 'Email cliente mancante o non valida.',
				'errors' => ['Imposta un indirizzo email valido prima di inviare l\'invito.'],
			];
		}

		$normalizedEmail = trim(function_exists('mb_strtolower') ? mb_strtolower($emailRaw) : strtolower($emailRaw));
		$customerName = (string) ($customer['fullname'] ?? '');

		$authService = new CustomerPortalAuthService($this->pdo);
		$invitation = $authService->createInvitation($customerId, $normalizedEmail);
		if (!($invitation['success'] ?? false)) {
			return [
				'success' => false,
				'message' => $invitation['message'] ?? 'Impossibile inviare l\'invito.',
				'errors' => $invitation['errors'] ?? ['Riprovare più tardi.'],
			];
		}

		$token = (string) ($invitation['token'] ?? '');
		if ($token === '') {
			return [
				'success' => false,
				'message' => 'Token invito non disponibile.',
				'errors' => ['Non è stato possibile generare il link di attivazione.'],
			];
		}

		$activationLink = $this->buildPortalInvitationLink($token);
		$linkAvailable = $activationLink !== '';
		$appName = $this->appName !== null && $this->appName !== '' ? $this->appName : 'Coresuite';
		$subject = '[' . $appName . '] Attiva il tuo accesso area clienti';

		$textBody = $this->buildInvitationTextBody($customerName, $activationLink, $token, $normalizedEmail, $appName);
		$htmlBody = $this->buildInvitationHtmlBody($customerName, $activationLink, $token, $normalizedEmail, $appName);
		$fromEmail = $this->getFromEmail();
		$fromName = $this->getFromDisplayName();
		$formattedFrom = $this->formatEmailAddress($fromEmail, $fromName);

		$sent = false;
		if ($this->resendApiKey !== null && $htmlBody !== null && $this->sendEmailViaResend($normalizedEmail, $subject, $textBody, $htmlBody)) {
			$sent = true;
		}

		if (!$sent) {
			$headers = [
				'From: ' . $formattedFrom,
				'Reply-To: ' . $fromEmail,
				'MIME-Version: 1.0',
				'Content-Type: text/plain; charset=UTF-8',
			];
			$sent = @mail($normalizedEmail, $subject, $textBody, implode("\r\n", $headers));
		}

		$message = $sent
			? 'Invito inviato a ' . $normalizedEmail . '.'
			: 'Invito generato ma invio automatico non riuscito. Condividi manualmente il link di attivazione.';

		return [
			'success' => true,
			'message' => $message,
			'invitation' => [
				'email' => $normalizedEmail,
				'token' => $token,
				'activation_link' => $activationLink,
				'email_sent' => $sent,
				'app_name' => $appName,
			],
		];
	}

	/**
	 * @return array{status:string, email?:string, password?:string}
	 */
	private function provisionPortalAccount(int $customerId, string $email): array
	{
		$normalizedEmail = trim(function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email));
		if ($normalizedEmail === '' || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
			throw new RuntimeException('Email non valida per l\'area clienti.');
		}

		$password = $this->generatePortalPassword();
		$hash = password_hash($password, PASSWORD_DEFAULT);

		$stmt = $this->pdo->prepare(
			'INSERT INTO customer_portal_accounts (customer_id, email, password_hash)
			 VALUES (:customer_id, :email, :hash)'
		);
		$stmt->execute([
			':customer_id' => $customerId,
			':email' => $normalizedEmail,
			':hash' => $hash,
		]);

		return [
			'status' => 'created',
			'email' => $normalizedEmail,
			'password' => $password,
		];
	}

	/**
	 * @return array{status:string, email?:string, password?:string}
	 */
	private function syncPortalAccount(int $customerId, ?string $newEmail): array
	{
		if ($newEmail === null) {
			return ['status' => 'skipped'];
		}

		$normalizedEmail = trim(function_exists('mb_strtolower') ? mb_strtolower($newEmail) : strtolower($newEmail));
		if ($normalizedEmail === '' || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
			return ['status' => 'skipped'];
		}

		$stmt = $this->pdo->prepare(
			'SELECT id, email FROM customer_portal_accounts WHERE customer_id = :customer_id LIMIT 1'
		);
		$stmt->execute([':customer_id' => $customerId]);
		$account = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

		if ($account === null) {
			return $this->provisionPortalAccount($customerId, $normalizedEmail);
		}

		if ((string) $account['email'] === $normalizedEmail) {
			return [
				'status' => 'unchanged',
				'email' => $normalizedEmail,
			];
		}

		$update = $this->pdo->prepare(
			'UPDATE customer_portal_accounts SET email = :email, updated_at = NOW() WHERE id = :id'
		);
		$update->execute([
			':email' => $normalizedEmail,
			':id' => (int) $account['id'],
		]);

		return [
			'status' => 'updated',
			'email' => $normalizedEmail,
		];
	}

	private function generatePortalPassword(): string
	{
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!?@#$%';
		$length = 12;
		$password = '';

		$maxIndex = strlen($alphabet) - 1;
		for ($i = 0; $i < $length; $i++) {
			$password .= $alphabet[random_int(0, $maxIndex)];
		}

		return $password;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{values: array{fullname:string, email:?string, phone:?string, tax_code:?string, note:?string}, errors: array<int, string>}
	 */
	private function normalisePayload(array $payload): array
	{
		$fullname = isset($payload['fullname']) ? trim((string) $payload['fullname']) : '';
		$email = isset($payload['email']) ? trim((string) $payload['email']) : '';
		$phone = isset($payload['phone']) ? trim((string) $payload['phone']) : '';
		$taxCode = isset($payload['tax_code']) ? strtoupper(trim((string) $payload['tax_code'])) : '';
		$note = isset($payload['note']) ? trim((string) $payload['note']) : '';

		$errors = [];
		if ($fullname === '') {
			$errors[] = 'Il nome completo è obbligatorio.';
		}

		if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$errors[] = 'Email non valida.';
		}

		if ($taxCode !== '' && !preg_match('/^[A-Za-z0-9]{11,16}$/', $taxCode)) {
			$errors[] = 'Codice fiscale o partita IVA non valido.';
		}

		return [
			'values' => [
				'fullname' => $fullname,
				'email' => $email !== '' ? $email : null,
				'phone' => $phone !== '' ? $phone : null,
				'tax_code' => $taxCode !== '' ? $taxCode : null,
				'note' => $note !== '' ? $note : null,
			],
			'errors' => $errors,
		];
	}

	/**
	 * @param array{fullname:string, email:?string, phone:?string, tax_code:?string, note:?string} $values
	 * @return array<int, string>
	 */
	private function ensureUniqueConstraints(array $values, int $ignoreId = 0): array
	{
		$errors = [];

		if ($values['email'] !== null) {
			$sql = 'SELECT id FROM customers WHERE email = :email';
			$params = [':email' => $values['email']];
			if ($ignoreId > 0) {
				$sql .= ' AND id <> :ignore';
				$params[':ignore'] = $ignoreId;
			}
			$sql .= ' LIMIT 1';
			$stmt = $this->pdo->prepare($sql);
			$stmt->execute($params);
			if ($stmt->fetch(PDO::FETCH_ASSOC)) {
				$errors[] = 'Email già associata a un altro cliente.';
			}
		}

		if ($values['tax_code'] !== null) {
			$sql = 'SELECT id FROM customers WHERE tax_code = :tax_code';
			$params = [':tax_code' => $values['tax_code']];
			if ($ignoreId > 0) {
				$sql .= ' AND id <> :ignore';
				$params[':ignore'] = $ignoreId;
			}
			$sql .= ' LIMIT 1';
			$stmt = $this->pdo->prepare($sql);
			$stmt->execute($params);
			if ($stmt->fetch(PDO::FETCH_ASSOC)) {
				$errors[] = 'Codice fiscale/partita IVA già presente.';
			}
		}

		return $errors;
	}

	private function sendPortalWelcomeEmail(string $email, string $password, string $customerName): void
	{
		$this->deliverPortalWelcomeEmail($email, $password, $customerName);
	}

	private function deliverPortalWelcomeEmail(string $email, string $password, string $customerName): bool
	{
		$email = trim($email);
		$password = trim($password);
		if ($email === '' || $password === '') {
			return false;
		}

		$appName = $this->appName !== null && $this->appName !== '' ? $this->appName : 'Coresuite';
		$subject = '[' . $appName . '] Accesso area clienti';
		$portalLink = $this->portalUrl !== null && $this->portalUrl !== '' ? $this->portalUrl : $this->guessPortalUrl();
		$displayName = trim($customerName);
		$greeting = $displayName !== '' ? 'Ciao ' . $displayName . ',' : 'Ciao,';
		$accessLink = null;
		if ($portalLink !== null) {
			$link = $this->buildPortalAccessLink($portalLink, $email, $password);
			$accessLink = $link !== '' ? $link : null;
		}
		$ctaLink = $accessLink ?? $portalLink;
		$textLines = [
			$greeting,
			'',
			'abbiamo attivato il tuo accesso all\'area clienti. Di seguito le nuove credenziali temporanee:',
			'Email: ' . $email,
			'Password: ' . $password,
			'',
			$ctaLink !== null
				? 'Accedi subito: ' . $ctaLink
				: 'Accedi all\'area clienti tramite il link fornito dal tuo punto vendita.',
		];
		if ($ctaLink !== null) {
			$textLines[] = 'Il link apre la pagina di login con le credenziali già compilate.';
		}
		$textLines[] = 'Ricordati di aggiornare la password dal profilo al primo accesso.';
		$textLines[] = '';
		$textLines[] = 'Squadra ' . $appName;
		$textBody = implode(PHP_EOL, $textLines);
		$htmlBody = $this->buildPortalEmailHtmlTemplate($greeting, $email, $password, $ctaLink, $appName);
		$fromEmail = $this->getFromEmail();
		$fromName = $this->getFromDisplayName();
		$formattedFrom = $this->formatEmailAddress($fromEmail, $fromName);

		if ($this->resendApiKey !== null && $this->sendEmailViaResend($email, $subject, $textBody, $htmlBody)) {
			return true;
		}

		$headers = [
			'From: ' . $formattedFrom,
			'Reply-To: ' . $fromEmail,
			'MIME-Version: 1.0',
			'Content-Type: text/html; charset=UTF-8',
		];
		$mailBody = $htmlBody ?? nl2br(htmlspecialchars($textBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
		return @mail($email, $subject, $mailBody, implode("\r\n", $headers));
	}

	private function buildPortalEmailHtmlTemplate(string $greeting, string $email, string $password, ?string $ctaLink, string $appName): string
	{
		$emailEscaped = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$passwordEscaped = htmlspecialchars($password, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$appNameEscaped = htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$greetingEscaped = htmlspecialchars($greeting, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$cta = $ctaLink !== null
			? '<a href="' . htmlspecialchars($ctaLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="display:inline-block;padding:12px 24px;background-color:#1f75fe;color:#ffffff;border-radius:4px;font-weight:600;text-decoration:none;">Accedi all\'area clienti</a>'
			: '<p style="margin:16px 0 0 0;color:#1f1f1f;font-size:14px;">Accedi all\'area clienti tramite il link fornito dal tuo punto vendita.</p>';
		$prefillNotice = $ctaLink !== null
			? '<p style="margin:16px 0 0 0;font-size:14px;line-height:1.6;color:#475569;">Il pulsante aprirà il portale con email e password già compilate per il primo accesso.</p>'
			: '';
		$ctaSection = '<div style="margin:32px 0 0 0;text-align:center;">' . $cta . $prefillNotice . '</div>';

		return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Benvenuto in {$appNameEscaped}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6fb;font-family:'Segoe UI',Arial,sans-serif;color:#1f1f1f;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6fb;padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 12px 40px rgba(31,117,254,0.12);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#1f75fe 0%,#4c9dff 100%);padding:32px;">
                            <h1 style="margin:0;font-size:24px;color:#ffffff;font-weight:600;">{$appNameEscaped}</h1>
                            <p style="margin:12px 0 0 0;font-size:16px;color:#e6f0ff;">Accesso area clienti attivo</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 16px 0;font-size:16px;">{$greetingEscaped}</p>
                            <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;">Abbiamo attivato le tue credenziali per accedere all'area clienti <strong>{$appNameEscaped}</strong>. Utilizza i dati qui sotto per effettuare il primo accesso.</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;border-collapse:separate;border-spacing:0 8px;">
                                <tr>
                                    <td width="120" style="font-size:14px;color:#6b7280;padding:8px 0;">Email</td>
                                    <td style="font-size:15px;color:#1f1f1f;padding:8px 0;"><strong>{$emailEscaped}</strong></td>
                                </tr>
                                <tr>
                                    <td width="120" style="font-size:14px;color:#6b7280;padding:8px 0;">Password</td>
                                    <td style="font-size:15px;color:#1f1f1f;padding:8px 0;"><strong>{$passwordEscaped}</strong></td>
                                </tr>
                            </table>
                            <p style="margin:0 0 8px 0;font-size:15px;line-height:1.6;">Per garantire la massima sicurezza, ti chiediamo di aggiornare la password dal tuo profilo al primo accesso.</p>
                            {$ctaSection}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px;background-color:#f8fafc;border-top:1px solid #e2e8f0;">
                            <p style="margin:0;font-size:14px;color:#6b7280;">Hai bisogno di assistenza? Rispondi a questa email o contatta il tuo referente commerciale.</p>
                            <p style="margin:12px 0 0 0;font-size:14px;color:#6b7280;">Grazie per aver scelto {$appNameEscaped}.<br>Il team {$appNameEscaped}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
	}

	private function sendEmailViaResend(string $recipient, string $subject, string $textBody, ?string $htmlBody = null): bool
	{
		if (!function_exists('curl_init') || $this->resendApiKey === null) {
			return false;
		}

		$fromEmail = $this->getFromEmail();
		$fromName = $this->getFromDisplayName();
		$from = $this->formatEmailAddress($fromEmail, $fromName);
		$payloadData = [
			'from' => $from,
			'to' => [$recipient],
			'subject' => $subject,
			'text' => $textBody,
		];
		$payloadData['reply_to'] = [$fromEmail];
		if ($htmlBody !== null) {
			$payloadData['html'] = $htmlBody;
		}
		$payload = json_encode($payloadData);
		if ($payload === false) {
			return false;
		}

		$ch = curl_init('https://api.resend.com/emails');
		if ($ch === false) {
			return false;
		}

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $this->resendApiKey,
		]);

		$response = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($response === false) {
			curl_close($ch);
			return false;
		}
		curl_close($ch);

		return $status >= 200 && $status < 300;
	}

	private function getFromEmail(): string
	{
		$email = trim((string) ($this->resendFrom ?? ''));
		return $email !== '' ? $email : 'alerts@coresuite.test';
	}

	private function getFromDisplayName(): ?string
	{
		$name = trim((string) ($this->resendFromName ?? ''));
		if ($name !== '') {
			return str_replace(["\r", "\n"], '', $name);
		}
		$fallback = trim((string) ($this->appName ?? ''));
		if ($fallback !== '') {
			return str_replace(["\r", "\n"], '', $fallback);
		}
		return null;
	}

	private function formatEmailAddress(string $email, ?string $name = null): string
	{
		$cleanEmail = trim(str_replace(["\r", "\n"], '', $email));
		if ($name === null || trim($name) === '') {
			return $cleanEmail;
		}
		$cleanName = trim(str_replace(["\r", "\n"], '', $name));
		if ($cleanName === '') {
			return $cleanEmail;
		}
		$needsQuotes = strpbrk($cleanName, ',;"') !== false;
		$encodedName = $needsQuotes ? '"' . addcslashes($cleanName, '"\\') . '"' : $cleanName;
		return $encodedName . ' <' . $cleanEmail . '>';
	}

	private function buildPortalAccessLink(string $baseUrl, string $email, string $password): string
	{
		$target = trim($baseUrl);
		if ($target === '') {
			$fallback = $this->guessPortalUrl();
			if ($fallback === null) {
				return '';
			}
			$target = $fallback;
		}

		$normalized = $target;
		if (!str_contains($normalized, '?') && !preg_match('~/index\.php$~i', $normalized)) {
			$normalized = rtrim($normalized, '/') . '/index.php';
		}
		$hasQuery = str_contains($normalized, '?');
		$normalized = rtrim($normalized, '?&');
		$query = http_build_query([
			'view' => 'login',
			'prefill_email' => $email,
			'prefill_password' => $password,
		]);
		$separator = $hasQuery ? '&' : '?';
		return $normalized . $separator . $query;
	}

	private function buildPortalInvitationLink(string $token): string
	{
		$base = $this->portalUrl !== null && $this->portalUrl !== '' ? $this->portalUrl : $this->guessPortalUrl();
		if ($base === null || $base === '') {
			return '';
		}

		$normalized = trim($base);
		$normalized = str_replace(['\r', '\n'], '', $normalized);
		$basePart = $normalized;
		$queryPart = '';
		if (str_contains($normalized, '?')) {
			[$basePart, $queryPart] = explode('?', $normalized, 2);
			$queryPart = '?' . $queryPart;
		}
		$basePart = rtrim($basePart, '/');
		if (!preg_match('~/index\.php$~i', $basePart)) {
			$basePart .= '/index.php';
		}
		$normalized = $basePart . $queryPart;
		$separator = str_contains($normalized, '?') ? '&' : '?';
		return $normalized . $separator . http_build_query([
			'view' => 'activate',
			'token' => $token,
		]);
	}

	private function buildInvitationTextBody(string $customerName, string $activationLink, string $token, string $email, string $appName): string
	{
		$greeting = trim($customerName) !== '' ? 'Ciao ' . trim($customerName) . ',' : 'Ciao,';
		$lines = [
			$greeting,
			'',
			'per completare l\'attivazione dell\'area clienti ' . $appName . ' segui questi passaggi:',
			'1) Apri il link di attivazione: ' . ($activationLink !== '' ? $activationLink : 'accedi al portale e inserisci il codice qui sotto'),
			'2) Inserisci il codice invito (se richiesto): ' . $token,
			'3) Imposta una nuova password per iniziare a usare i servizi.',
			'',
			'Email registrata: ' . $email,
			'',
			'Il link resta valido finché non completi l\'attivazione. Se hai bisogno di aiuto rispondi a questa email.',
			'',
			'Squadra ' . $appName,
		];

		return implode(PHP_EOL, $lines);
	}

	private function buildInvitationHtmlBody(string $customerName, string $activationLink, string $token, string $email, string $appName): string
	{
		$greeting = trim($customerName) !== '' ? 'Ciao ' . trim($customerName) . ',' : 'Ciao,';
		$activationLinkEscaped = $activationLink !== '' ? htmlspecialchars($activationLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
		$emailEscaped = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$tokenEscaped = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$appNameEscaped = htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$greetingEscaped = htmlspecialchars($greeting, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		$ctaBlock = $activationLink !== ''
			? '<a href="' . $activationLinkEscaped . '" style="display:inline-block;padding:12px 24px;background-color:#1f75fe;color:#ffffff;border-radius:4px;font-weight:600;text-decoration:none;">Completa l\'attivazione</a>'
			: '<p style="margin:0;font-size:14px;color:#475569;">Accedi al portale e inserisci il codice invito indicato di seguito.</p>';
		$copyNotice = $activationLink !== ''
			? '<p style="margin:0 0 8px 0;font-size:14px;line-height:1.6;color:#475569;">Se il pulsante non funziona copia questo link nel browser:<br><span style="word-break:break-all;color:#1f75fe;">' . $activationLinkEscaped . '</span></p>'
			: '';

		return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attiva il tuo accesso {$appNameEscaped}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6fb;font-family:'Segoe UI',Arial,sans-serif;color:#1f1f1f;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6fb;padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 12px 40px rgba(31,117,254,0.12);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#1f75fe 0%,#4c9dff 100%);padding:32px;">
                            <h1 style="margin:0;font-size:24px;color:#ffffff;font-weight:600;">{$appNameEscaped}</h1>
                            <p style="margin:12px 0 0 0;font-size:16px;color:#e6f0ff;">Completa l'attivazione dell'area clienti</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 16px 0;font-size:16px;">{$greetingEscaped}</p>
                            <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;">Segui questi passaggi per concludere l'attivazione del tuo accesso:</p>
                            <ol style="margin:0 0 24px 18px;color:#1f2937;font-size:15px;line-height:1.6;">
                                <li style="margin-bottom:12px;">Apri il link di attivazione qui sotto.</li>
                                <li style="margin-bottom:12px;">Inserisci (se richiesto) il codice invito: <strong>{$tokenEscaped}</strong>.</li>
                                <li>Imposta una nuova password e accedi al portale.</li>
                            </ol>
                            <div style="margin:32px 0 24px 0;text-align:center;">
                                {$ctaBlock}
                            </div>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;border-collapse:separate;border-spacing:0 8px;">
                                <tr>
                                    <td width="140" style="font-size:14px;color:#6b7280;padding:8px 0;">Email registrata</td>
                                    <td style="font-size:15px;color:#1f1f1f;padding:8px 0;"><strong>{$emailEscaped}</strong></td>
                                </tr>
                                <tr>
                                    <td width="140" style="font-size:14px;color:#6b7280;padding:8px 0;">Codice invito</td>
                                    <td style="font-size:15px;color:#1f1f1f;padding:8px 0;"><strong>{$tokenEscaped}</strong></td>
                                </tr>
                            </table>
							{$copyNotice}
                            <p style="margin:16px 0 0 0;font-size:14px;line-height:1.6;color:#475569;">Hai bisogno di assistenza? Rispondi a questa email, siamo qui per aiutarti.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px;background-color:#f8fafc;border-top:1px solid #e2e8f0;">
                            <p style="margin:0;font-size:14px;color:#6b7280;">Grazie per aver scelto {$appNameEscaped}.<br>Il team {$appNameEscaped}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
	}

	private function guessPortalUrl(): ?string
	{
		$host = $_SERVER['HTTP_HOST'] ?? '';
		if ($host === '') {
			return null;
		}

		$https = $_SERVER['HTTPS'] ?? null;
		$scheme = (is_string($https) && strtolower($https) !== 'off' && $https !== '') ? 'https' : 'http';
		return $scheme . '://' . $host . '/public/portal/';
	}
}



