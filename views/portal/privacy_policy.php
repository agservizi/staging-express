<?php
$policy = $policy ?? null;
$feedbackPolicy = $feedbackPolicy ?? null;
$account = $account ?? null;
$requiresAcceptance = $requiresAcceptance ?? false;
$hasAccepted = $hasAccepted ?? false;
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Informativa privacy</title>
    <link rel="stylesheet" href="../assets/css/portal.css?v=1">
</head>
<body class="portal-auth">
<div class="portal-auth__card portal-auth__card--wide">
    <header class="portal-auth__header">
        <h1>Informativa privacy</h1>
        <?php if ($policy !== null): ?>
            <p>Versione <?= htmlspecialchars((string) ($policy['version'] ?? '')) ?><?= !empty($policy['updated_at']) ? ' · aggiornata al ' . htmlspecialchars(date('d/m/Y', strtotime((string) $policy['updated_at']))) : '' ?></p>
        <?php else: ?>
            <p>Documento non disponibile</p>
        <?php endif; ?>
    </header>

    <?php if ($feedbackPolicy !== null): ?>
        <div class="portal-alert portal-alert--<?= ($feedbackPolicy['success'] ?? false) ? 'success' : 'error' ?>">
            <p><?= htmlspecialchars($feedbackPolicy['message'] ?? '') ?></p>
            <?php foreach ($feedbackPolicy['errors'] ?? [] as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($policy === null): ?>
        <p class="portal-muted">Nessuna informativa attiva. Contatta l'assistenza per maggiori dettagli.</p>
    <?php else: ?>
        <div class="policy-layout">
            <article class="policy-layout__document">
                <div class="policy-content">
                    <?= nl2br(htmlspecialchars((string) ($policy['content'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
                </div>
            </article>

            <aside class="policy-layout__aside">
                <?php if ($account === null): ?>
                    <h2 class="policy-layout__title">Accesso richiesto</h2>
                    <p class="portal-muted">Accedi al portale per registrare il consenso.</p>
                    <p><a class="portal-link" href="index.php?view=login">Torna al login</a></p>
                <?php elseif ($requiresAcceptance): ?>
                    <h2 class="policy-layout__title">Conferma necessaria</h2>
                    <p class="portal-muted">Per proseguire è necessario confermare di aver letto e compreso l'informativa.</p>
                    <form method="post" class="portal-form policy-form">
                        <input type="hidden" name="action" value="accept_policy">
                        <input type="hidden" name="policy_id" value="<?= (int) ($policy['id'] ?? 0) ?>">
                        <label class="portal-checkbox">
                            <input type="checkbox" name="confirm_ack" value="1" required> Dichiaro di aver letto e accettato l'informativa privacy.
                        </label>
                        <button type="submit" class="portal-button portal-button--primary">Accetto e continuo</button>
                    </form>
                <?php else: ?>
                    <h2 class="policy-layout__title">Consenso registrato</h2>
                    <p class="portal-muted">Consenso registrato. <a class="portal-link" href="index.php">Vai al portale</a></p>
                <?php endif; ?>
            </aside>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
