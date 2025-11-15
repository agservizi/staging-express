<?php
declare(strict_types=1);

/** @var string $content */
$currentUser = $currentUser ?? null;
$pageTitle = $pageTitle ?? 'Gestionale Telefonia';
$initialToasts = $initialToasts ?? [];
if (!is_array($initialToasts)) {
    $initialToasts = [];
}
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script defer src="assets/js/app.js"></script>
</head>
<body>
<div class="layout">
    <aside class="sidebar" data-collapsed="false">
        <div class="sidebar__header">
            <button class="sidebar__toggle" type="button" aria-label="Espandi/comprimi">☰</button>
            <span class="sidebar__logo" aria-hidden="true">
                <img src="assets/img/logo-collapsed.svg" alt="">
            </span>
            <span class="sidebar__title">Coresuite</span>
        </div>
        <nav class="sidebar__nav">
            <a href="index.php?page=dashboard" class="sidebar__link" data-tooltip="Dashboard">🏠 <span>Dashboard</span></a>
            <a href="index.php?page=iccid_list" class="sidebar__link" data-tooltip="ICCID">📦 <span>ICCID</span></a>
            <a href="index.php?page=sim_stock" class="sidebar__link" data-tooltip="Magazzino SIM">📥 <span>Magazzino SIM</span></a>
            <a href="index.php?page=offers" class="sidebar__link" data-tooltip="Listini">🗂️ <span>Listini</span></a>
            <a href="index.php?page=sales_create" class="sidebar__link" data-tooltip="Nuova vendita">🧾 <span>Nuova vendita</span></a>
            <a href="index.php?page=sales_list" class="sidebar__link" data-tooltip="Storico vendite">📊 <span>Storico vendite</span></a>
            <a href="index.php?page=settings" class="sidebar__link" data-tooltip="Impostazioni">⚙️ <span>Impostazioni</span></a>
        </nav>
        <div class="sidebar__footer">
            <?php if ($currentUser): ?>
                <?php
                    $displayName = $currentUser['fullname'] ?: $currentUser['username'];
                    $initial = strtoupper(substr($displayName, 0, 1));
                    if (function_exists('mb_substr')) {
                        $initial = strtoupper((string) mb_substr($displayName, 0, 1, 'UTF-8'));
                    }
                ?>
                <div class="sidebar__user">
                    <div class="sidebar__user-avatar" aria-hidden="true"><?= htmlspecialchars($initial) ?></div>
                    <div class="sidebar__user-info">
                        <span class="sidebar__user-label">Operatore</span>
                        <strong class="sidebar__user-name"><?= htmlspecialchars($displayName) ?></strong>
                    </div>
                    <a class="sidebar__logout" href="index.php?page=logout" aria-label="Esci">
                        <span class="sidebar__logout-text">Esci</span>
                        <svg class="sidebar__logout-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M15.5 7.5V5.25A2.25 2.25 0 0 0 13.25 3H7.25A2.25 2.25 0 0 0 5 5.25v13.5A2.25 2.25 0 0 0 7.25 21h6A2.25 2.25 0 0 0 15.5 18.75V16.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M9.75 12h9m0 0-3-3m3 3-3 3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </aside>
    <main class="main">
        <?= $content ?>
    </main>
</div>
<?php
$initialToastsPayload = json_encode($initialToasts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if ($initialToastsPayload === false) {
    $initialToastsPayload = '[]';
}
?>
<div class="toast-stack" data-toast-stack aria-live="polite" aria-atomic="true"></div>
<script>
    window.AppInitialToasts = <?= $initialToastsPayload ?>;
</script>
</body>
</html>
