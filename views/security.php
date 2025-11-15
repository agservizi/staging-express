<?php
declare(strict_types=1);

/**
 * @var array<string, mixed>|null $currentUser
 * @var array{mfa_enabled:bool, mfa_enabled_at:?string} $state
 * @var array{success:bool, message:string}|null $feedback
 * @var array{success:bool, secret?:string, otpauth_url?:string, qr_url?:string}|null $setupData
 * @var array<int, string> $recoveryCodes
 * @var string $issuer
 */

$currentUser = $currentUser ?? [];
$state = $state ?? ['mfa_enabled' => false, 'mfa_enabled_at' => null];
$feedback = $feedback ?? null;
$setupData = isset($setupData) && is_array($setupData) ? $setupData : null;
$recoveryCodes = $recoveryCodes ?? [];
$issuer = $issuer ?? 'Coresuite Express';

$mfaEnabled = (bool) ($state['mfa_enabled'] ?? false);
$enabledAt = $state['mfa_enabled_at'] ?? null;

$formatDateTime = static function (?string $value): string {
    if ($value === null || $value === '') {
        return 'n/d';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return 'n/d';
    }
    return date('d/m/Y H:i', $timestamp);
};
?>
<section class="page page--security">
    <header class="page__header">
        <h2>Sicurezza account</h2>
        <p>Gestisci l'autenticazione a due fattori (MFA) e i codici di recupero per proteggere il tuo accesso.</p>
    </header>

    <?php if ($feedback !== null): ?>
        <section class="page__section">
            <div class="alert <?= ($feedback['success'] ?? false) ? 'alert--success' : 'alert--error' ?>">
                <p><?= htmlspecialchars((string) ($feedback['message'] ?? 'Operazione completata.')) ?></p>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($recoveryCodes !== []): ?>
        <section class="page__section">
            <article class="card card--highlight">
                <h3>Nuovi codici di recupero</h3>
                <p>Salva questi codici in un luogo sicuro. Ogni codice può essere usato una sola volta quando non hai accesso all'app di autenticazione.</p>
                <ul class="recovery-codes">
                    <?php foreach ($recoveryCodes as $code): ?>
                        <li class="recovery-codes__item"><?= htmlspecialchars($code) ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="muted">Consiglio: stampa o annota i codici. Dopo aver lasciato questa pagina non potremo più mostrarli.</p>
            </article>
        </section>
    <?php endif; ?>

    <section class="page__section">
        <article class="card">
            <h3>Stato attuale</h3>
            <?php if ($mfaEnabled): ?>
                <p>L'autenticazione a due fattori è <strong>attiva</strong> per questo account.</p>
                <p>Ultima attivazione: <strong><?= htmlspecialchars($formatDateTime(is_string($enabledAt) ? $enabledAt : null)) ?></strong>.</p>
            <?php else: ?>
                <p>L'autenticazione a due fattori non è ancora attiva. Abilitala per proteggere meglio i tuoi accessi.</p>
            <?php endif; ?>
        </article>
    </section>

    <?php if ($setupData !== null): ?>
        <section class="page__section">
            <div class="setup-grid">
                <article class="card" data-qr-container>
                    <h3>1. Scansiona il QR code</h3>
                    <p>Apri Google Authenticator (o un'app compatibile) e scansiona il codice qui sotto.</p>
                    <?php if (!empty($setupData['qr_url'])): ?>
                        <img
                            src="<?= htmlspecialchars((string) $setupData['qr_url']) ?>"
                            <?php if (!empty($setupData['qr_fallback_url'])): ?>
                                data-qr-fallback="<?= htmlspecialchars((string) $setupData['qr_fallback_url']) ?>"
                            <?php endif; ?>
                            data-qr-image
                            alt="QR code MFA"
                            class="setup-grid__qr"
                        >
                    <?php endif; ?>
                    <?php if (!empty($setupData['otpauth_url'])): ?>
                        <p class="muted">
                            Se il QR non viene caricato, <a href="<?= htmlspecialchars((string) $setupData['otpauth_url']) ?>" target="_blank" rel="noopener">apri il link otpauth</a> oppure inserisci il codice manualmente.
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($setupData['secret'])): ?>
                        <p>In alternativa inserisci manualmente questo codice segreto:</p>
                        <code class="setup-grid__secret"><?= htmlspecialchars((string) $setupData['secret']) ?></code>
                    <?php endif; ?>
                </article>
                <article class="card">
                    <h3>2. Conferma il codice</h3>
                    <p>Inserisci il codice a 6 cifre generato dall'app per completare l'attivazione.</p>
                    <form method="post" class="form">
                        <input type="hidden" name="action" value="confirm_setup">
                        <div class="form__group">
                            <label for="mfa_code">Codice MFA</label>
                            <input type="text" name="mfa_code" id="mfa_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="10" required>
                        </div>
                        <button type="submit" class="btn btn--primary">Conferma attivazione</button>
                    </form>
                    <form method="post" class="form form--inline">
                        <input type="hidden" name="action" value="cancel_setup">
                        <button type="submit" class="btn btn--secondary">Annulla configurazione</button>
                    </form>
                </article>
            </div>
        </section>
    <?php else: ?>
        <?php if (!$mfaEnabled): ?>
            <section class="page__section">
                <article class="card">
                    <h3>Attiva l'autenticazione a due fattori</h3>
                    <p>L'MFA riduce il rischio di accessi non autorizzati richiedendo un secondo codice legato al tuo dispositivo.</p>
                    <form method="post">
                        <input type="hidden" name="action" value="start_setup">
                        <button type="submit" class="btn btn--primary">Genera QR code e avvia configurazione</button>
                    </form>
                </article>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($mfaEnabled): ?>
        <section class="page__section">
            <div class="security-actions">
                <article class="card">
                    <h3>Disattiva MFA</h3>
                    <p>Per disattivare l'MFA inserisci un codice attivo dall'app o un codice di recupero.</p>
                    <form method="post" class="form">
                        <input type="hidden" name="action" value="disable_mfa">
                        <div class="form__group">
                            <label for="disable_code">Codice di verifica</label>
                            <input type="text" name="mfa_code" id="disable_code" inputmode="text" maxlength="32" placeholder="OTP oppure codice di recupero" required>
                        </div>
                        <button type="submit" class="btn btn--secondary">Disattiva MFA</button>
                    </form>
                </article>
                <article class="card">
                    <h3>Rigenera codici di recupero</h3>
                    <p>Usa questa opzione solo se hai smarrito i codici attuali: quelli esistenti verranno invalidati.</p>
                    <form method="post" class="form">
                        <input type="hidden" name="action" value="regenerate_codes">
                        <div class="form__group">
                            <label for="regen_code">Codice di verifica</label>
                            <input type="text" name="mfa_code" id="regen_code" inputmode="text" maxlength="32" placeholder="OTP oppure codice di recupero" required>
                        </div>
                        <button type="submit" class="btn btn--primary">Genera nuovi codici</button>
                    </form>
                </article>
            </div>
        </section>
    <?php endif; ?>
</section>
