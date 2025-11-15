<?php
declare(strict_types=1);

/** @var string $content */
$currentUser = $currentUser ?? null;
$pageTitle = $pageTitle ?? 'Gestionale Telefonia';
$userDisplayName = null;
$userInitial = null;
$userRoleLabel = 'Operatore';
$appVersionLabel = 'v. 1.0';
$initialToasts = $initialToasts ?? [];
if (!is_array($initialToasts)) {
    $initialToasts = [];
}

if (is_array($currentUser)) {
    $displayCandidate = (string) ($currentUser['fullname'] ?? '');
    if ($displayCandidate === '') {
        $displayCandidate = (string) ($currentUser['username'] ?? '');
    }
    if ($displayCandidate === '') {
        $displayCandidate = 'Operatore';
    }
    $userDisplayName = $displayCandidate;

    if (function_exists('mb_substr')) {
        $initialChar = (string) mb_substr($userDisplayName, 0, 1, 'UTF-8');
        if (function_exists('mb_strtoupper')) {
            $userInitial = mb_strtoupper($initialChar, 'UTF-8');
        } else {
            $userInitial = strtoupper($initialChar);
        }
    } else {
        $userInitial = strtoupper((string) substr($userDisplayName, 0, 1));
    }

    $roleCandidate = $currentUser['role'] ?? null;
    if (is_string($roleCandidate) && $roleCandidate !== '') {
        $roleLabel = str_replace('_', ' ', strtolower($roleCandidate));
        $userRoleLabel = ucwords($roleLabel);
    }
}

if ($userInitial === null || $userInitial === '') {
    $userInitial = 'C';
}

$topbarNotifications = $topbarNotifications ?? ['items' => [], 'unread_count' => 0];
if (!is_array($topbarNotifications)) {
    $topbarNotifications = ['items' => [], 'unread_count' => 0];
}
if (!isset($topbarNotifications['items']) || !is_array($topbarNotifications['items'])) {
    $topbarNotifications['items'] = [];
}
$topbarNotifications['unread_count'] = (int) ($topbarNotifications['unread_count'] ?? 0);

$formatNotificationTime = static function (?string $timestamp): string {
    if ($timestamp === null || $timestamp === '') {
        return '';
    }

    try {
        $notificationTime = new \DateTimeImmutable($timestamp);
        $now = new \DateTimeImmutable('now');
    } catch (\Throwable) {
        return '';
    }

    $diff = $now->getTimestamp() - $notificationTime->getTimestamp();

    if ($diff < 60) {
        return 'Pochi secondi fa';
    }

    if ($diff < 3600) {
        $minutes = (int) floor($diff / 60);
        return $minutes . ' min fa';
    }

    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours . ' h fa';
    }

    return $notificationTime->format('d/m H:i');
};

$currentRoute = $_SERVER['REQUEST_URI'] ?? 'index.php';
$currentRoute = trim((string) $currentRoute);
if ($currentRoute === '' || $currentRoute === '/') {
    $currentRoute = 'index.php';
} else {
    if (str_starts_with($currentRoute, '/')) {
        $currentRoute = ltrim($currentRoute, '/');
    }
    if (!str_starts_with($currentRoute, 'index.php')) {
        $currentRoute = 'index.php';
    }
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
            <span class="sidebar__logo" aria-hidden="true">
                <img src="assets/img/logo-collapsed.svg" alt="">
            </span>
            <span class="sidebar__title">Coresuite Express</span>
        </div>
        <nav class="sidebar__nav">
            <a href="index.php?page=dashboard" class="sidebar__link" data-tooltip="Dashboard">🏠 <span>Dashboard</span></a>
            <a href="index.php?page=sim_stock" class="sidebar__link" data-tooltip="Magazzino SIM">📥 <span>Magazzino SIM</span></a>
            <a href="index.php?page=products" class="sidebar__link" data-tooltip="Prodotti">🛒 <span>Prodotti</span></a>
            <a href="index.php?page=products_list" class="sidebar__link" data-tooltip="Lista Prodotti">📋 <span>Lista prodotti</span></a>
            <a href="index.php?page=customers" class="sidebar__link" data-tooltip="Clienti">👥 <span>Clienti</span></a>
            <a href="index.php?page=offers" class="sidebar__link" data-tooltip="Listini">🗂️ <span>Listini</span></a>
            <a href="index.php?page=sales_create" class="sidebar__link" data-tooltip="Nuova vendita">🧾 <span>Nuova vendita</span></a>
            <a href="index.php?page=sales_list" class="sidebar__link" data-tooltip="Storico vendite">📊 <span>Storico vendite</span></a>
            <a href="index.php?page=product_requests" class="sidebar__link" data-tooltip="Ordini store">📦 <span>Ordini store</span></a>
            <a href="index.php?page=support_requests" class="sidebar__link" data-tooltip="Supporto clienti">💬 <span>Richieste supporto</span></a>
            <a href="index.php?page=reports" class="sidebar__link" data-tooltip="Report vendite">📈 <span>Report</span></a>
            <a href="index.php?page=settings" class="sidebar__link" data-tooltip="Impostazioni">⚙️ <span>Impostazioni</span></a>
        </nav>
    <div class="sidebar__footer">
        <div class="sidebar__user sidebar__user--minimal">
            <span class="sidebar__user-version"><?= htmlspecialchars($appVersionLabel) ?></span>
        </div>
    </div>
    </aside>
    <main class="main">
    <header class="topbar" role="banner">
            <div class="topbar__brand">
                <button class="sidebar__toggle topbar__toggle" type="button" aria-label="Comprimi menu" aria-expanded="true">
                    <span class="sidebar__toggle-icon" aria-hidden="true">
                        <span class="sidebar__chevron sidebar__chevron--primary"></span>
                        <span class="sidebar__chevron sidebar__chevron--secondary"></span>
                    </span>
                </button>
                <div class="topbar__brand-text">
                    <span class="topbar__brand-name">Coresuite Express</span>
                    <span class="topbar__page"><?= htmlspecialchars($pageTitle) ?></span>
                </div>
            </div>
            <div class="topbar__actions">
                <?php if ($currentUser): ?>
                    <div class="topbar__notifications" data-notification>
                        <button type="button" class="topbar__notification-toggle" data-notification-toggle aria-haspopup="true" aria-expanded="false">
                            <span class="topbar__notification-icon" aria-hidden="true">🔔</span>
                            <?php if ($topbarNotifications['unread_count'] > 0): ?>
                                <span class="topbar__notification-badge" data-notification-badge><?= (int) min($topbarNotifications['unread_count'], 99) ?></span>
                            <?php endif; ?>
                            <span class="sr-only">Mostra notifiche</span>
                        </button>
                        <div class="topbar__notification-panel" data-notification-panel data-open="false" role="region" aria-label="Notifiche" tabindex="-1">
                            <header class="topbar__notification-header">
                                <span>Notifiche</span>
                                <span class="topbar__notification-counter" data-notification-counter>
                                    <?= (int) $topbarNotifications['unread_count'] ?> non lett<?= (int) $topbarNotifications['unread_count'] === 1 ? 'a' : 'e' ?>
                                </span>
                            </header>
                            <ul class="topbar__notification-list" data-notification-list>
                                <?php if ($topbarNotifications['items'] === []): ?>
                                    <li class="topbar__notification-empty">Nessuna notifica recente.</li>
                                <?php else: ?>
                                    <?php foreach ($topbarNotifications['items'] as $notification): ?>
                                        <?php
                                            $title = (string) ($notification['title'] ?? '');
                                            $body = (string) ($notification['body'] ?? '');
                                            if ($title === '' && $body !== '') {
                                                $title = $body;
                                            }
                                            $time = $formatNotificationTime($notification['created_at'] ?? null);
                                            $isUnread = empty($notification['is_read']);
                                            $level = preg_replace('/[^a-z0-9_-]/i', '', (string) ($notification['level'] ?? 'info'));
                                            $channelKey = (string) ($notification['channel'] ?? 'system');
                                            $channelLabels = [
                                                'sales' => 'Vendite',
                                                'stock' => 'Scorte SIM',
                                                'product_stock' => 'Magazzino prodotti',
                                                'system' => 'Sistema',
                                            ];
                                            $channelLabel = $channelLabels[$channelKey] ?? ucfirst($channelKey);
                                            $link = isset($notification['link']) && $notification['link'] !== '' ? (string) $notification['link'] : null;
                                            $itemClasses = 'topbar__notification-item level-' . $level . ($isUnread ? ' is-unread' : '');
                                        ?>
                                        <li class="<?= $itemClasses ?>">
                                            <?php if ($link !== null): ?>
                                                <a href="<?= htmlspecialchars($link) ?>" class="topbar__notification-link">
                                            <?php endif; ?>
                                                    <span class="topbar__notification-title"><?= htmlspecialchars($title) ?></span>
                                                    <?php if ($body !== '' && $body !== $title): ?>
                                                        <p class="topbar__notification-body"><?= htmlspecialchars($body) ?></p>
                                                    <?php endif; ?>
                                                    <span class="topbar__notification-meta">
                                                        <span class="topbar__notification-channel"><?= htmlspecialchars($channelLabel) ?></span>
                                                        <?php if ($time !== ''): ?>
                                                            <span class="topbar__notification-time"><?= htmlspecialchars($time) ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                            <?php if ($link !== null): ?>
                                                </a>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                            <footer class="topbar__notification-footer">
                                <form method="post" action="index.php?page=notifications_mark_all_read" data-notification-mark>
                                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($currentRoute) ?>">
                                    <button type="submit" class="topbar__notification-clear">Segna tutto come letto</button>
                                </form>
                            </footer>
                        </div>
                    </div>
                    <a class="topbar__action" href="index.php?page=sim_stock">
                        <span>Magazzino SIM</span>
                    </a>
                    <a class="topbar__action topbar__action--primary" href="index.php?page=sales_create">
                        <span>Nuova vendita</span>
                    </a>
                    <div class="topbar__user">
                        <div class="topbar__user-avatar" aria-hidden="true"><?= htmlspecialchars($userInitial) ?></div>
                        <div class="topbar__user-info">
                                <a class="topbar__user-role" href="index.php?page=profile" title="Apri il profilo utente"><?= htmlspecialchars($userRoleLabel) ?></a>
                            <span class="topbar__user-name"><?= htmlspecialchars($userDisplayName ?? '') ?></span>
                        </div>
                        <a class="topbar__logout" href="index.php?page=logout">
                            Esci
                        </a>
                    </div>
                <?php else: ?>
                    <a class="topbar__action topbar__action--primary" href="index.php?page=login">
                        <span>Accedi</span>
                    </a>
                <?php endif; ?>
            </div>
        </header>
        <div class="main__content">
            <?= $content ?>
        </div>
    </main>
</div>
<?php
$initialToastsPayload = json_encode($initialToasts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if ($initialToastsPayload === false) {
    $initialToastsPayload = '[]';
}
$notificationsPayload = json_encode($topbarNotifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if ($notificationsPayload === false) {
    $notificationsPayload = '{"items":[],"unread_count":0}';
}
?>
<div class="toast-stack" data-toast-stack aria-live="polite" aria-atomic="true"></div>
<div class="modal" data-receipt-modal aria-hidden="true">
    <div class="modal__backdrop" data-receipt-dismiss></div>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-label="Anteprima scontrino">
        <button type="button" class="modal__close" data-receipt-dismiss aria-label="Chiudi anteprima">×</button>
        <div class="modal__content">
            <div class="modal__loader" data-receipt-loader>
                <span class="modal__loader-spinner" aria-hidden="true"></span>
                <span class="modal__loader-label">Caricamento anteprima…</span>
            </div>
            <iframe data-receipt-frame title="Anteprima scontrino"></iframe>
        </div>
    </div>
</div>
<script>
    window.AppInitialToasts = <?= $initialToastsPayload ?>;
    window.AppNotifications = <?= $notificationsPayload ?>;
</script>
</body>
</html>
