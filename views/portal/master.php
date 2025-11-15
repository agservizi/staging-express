<?php
/** @var array{email:string, customer_id:int, id:int} $account */
/** @var array<string, mixed> $profile */
/** @var string $content */
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Area Clienti</title>
    <link rel="stylesheet" href="../assets/css/portal.css?v=1">
</head>
<body>
<div class="portal-shell">
    <aside class="portal-sidebar" aria-label="Navigazione">
        <div class="portal-brand">
            <span class="portal-brand__logo" aria-hidden="true">C</span>
            <div class="portal-brand__text">
                <strong>Coresuite Express</strong>
                <small>Self-service clienti</small>
            </div>
        </div>
        <nav class="portal-menu">
            <a class="portal-menu__link<?= ($_GET['view'] ?? 'dashboard') === 'dashboard' ? ' is-active' : '' ?>" href="index.php" title="Dashboard" aria-label="Dashboard" data-tooltip="Dashboard">
                <span class="portal-menu__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false">
                        <path d="M12 3.1a1 1 0 0 1 .64.23l7 5.63a1 1 0 0 1-.64 1.77H18v9.02a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V10.73H4a1 1 0 0 1-.64-1.77l7-5.63A1 1 0 0 1 12 3.1Z" fill="currentColor"/>
                        <path d="M10 14h4v5h-4z" fill="#f8fafc" opacity=".5"/>
                    </svg>
                </span>
                <span class="portal-menu__text">Dashboard</span>
            </a>
            <a class="portal-menu__link<?= ($_GET['view'] ?? '') === 'sales' ? ' is-active' : '' ?>" href="index.php?view=sales" title="Acquisti" aria-label="Acquisti" data-tooltip="Acquisti">
                <span class="portal-menu__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false">
                        <path d="M6.2 4.5a1 1 0 0 1 .98-.8h1.12a3.7 3.7 0 0 1 7.4 0h1.12a1 1 0 0 1 .98.8l1.5 8a2 2 0 0 1-1.97 2.33H6.67A2 2 0 0 1 4.7 12.5z" fill="currentColor" opacity=".18"/>
                        <path d="M9.55 4.7a2.45 2.45 0 0 1 4.9 0v.3h1.77a1 1 0 0 1 .98.8l1.5 8a2 2 0 0 1-1.97 2.33H6.27A2 2 0 0 1 4.3 13.8l1.5-8a1 1 0 0 1 .98-.8h1.77zm1.45.3v-.3a.45.45 0 0 1 .9 0v.3zM7.05 7l-1.1 6h12.1l-1.1-6z" fill="currentColor"/>
                        <circle cx="9" cy="17.5" r="1.2" fill="currentColor"/>
                        <circle cx="15" cy="17.5" r="1.2" fill="currentColor"/>
                    </svg>
                </span>
                <span class="portal-menu__text">Acquisti</span>
            </a>
            <a class="portal-menu__link<?= ($_GET['view'] ?? '') === 'payments' ? ' is-active' : '' ?>" href="index.php?view=payments" title="Pagamenti" aria-label="Pagamenti" data-tooltip="Pagamenti">
                <span class="portal-menu__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false">
                        <rect x="3" y="6" width="18" height="12" rx="2" fill="currentColor" opacity=".2"/>
                        <path d="M5 5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2m14 2H5v2h14zm-3 8a1 1 0 1 0 0-2H8a1 1 0 0 0 0 2z" fill="currentColor"/>
                    </svg>
                </span>
                <span class="portal-menu__text">Pagamenti</span>
            </a>
            <a class="portal-menu__link<?= ($_GET['view'] ?? '') === 'support' ? ' is-active' : '' ?>" href="index.php?view=support" title="Supporto" aria-label="Supporto" data-tooltip="Supporto">
                <span class="portal-menu__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false">
                        <path d="M12 4a7 7 0 0 1 7 7v1.5a2.5 2.5 0 0 1-2.5 2.5H15l-.7 2.1a1.5 1.5 0 0 1-1.43 1H12a2 2 0 0 1-2-2v-1H9.5A2.5 2.5 0 0 1 7 12.5V11a7 7 0 0 1 7-7Z" fill="currentColor" opacity=".18"/>
                        <path d="M12 2a9 9 0 0 1 9 9v1a3.5 3.5 0 0 1-3.5 3.5H16l-.54 1.62A2.5 2.5 0 0 1 13.06 19H12a3 3 0 0 1-3-3v-1H8.5A3.5 3.5 0 0 1 5 11V11a7 7 0 0 1 7-7m0 2a5 5 0 0 0-5 5v2a1.5 1.5 0 0 0 1.5 1.5H11a1 1 0 0 1 1 1v2a1 1 0 0 0 1 1h1.06a.5.5 0 0 0 .47-.33l.7-2.1a1 1 0 0 1 .95-.67H17.5A1.5 1.5 0 0 0 19 12V11a5 5 0 0 0-5-5Z" fill="currentColor"/>
                        <circle cx="9.5" cy="10.5" r="1" fill="currentColor"/>
                        <circle cx="14.5" cy="10.5" r="1" fill="currentColor"/>
                        <path d="M12 14.5a3 3 0 0 0 2.8-1.86.75.75 0 0 0-1.37-.6 1.54 1.54 0 0 1-2.86 0 .75.75 0 0 0-1.37.6A3 3 0 0 0 12 14.5Z" fill="currentColor"/>
                    </svg>
                </span>
                <span class="portal-menu__text">Supporto</span>
            </a>
            <a class="portal-menu__link<?= ($_GET['view'] ?? '') === 'settings' ? ' is-active' : '' ?>" href="index.php?view=settings" title="Impostazioni" aria-label="Impostazioni" data-tooltip="Impostazioni">
                <span class="portal-menu__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20" focusable="false">
                        <path d="M12 6a6 6 0 0 1 6 6 6 6 0 0 1-6 6h-1.2A2.8 2.8 0 0 1 8 15.2c0-.21.03-.42.07-.62L8.5 13l-1.7-1.69A2.8 2.8 0 0 1 7.2 6.8L8.9 5.1A2.8 2.8 0 0 1 10.8 4H12Z" fill="currentColor" opacity=".18"/>
                        <path d="M12 2a3 3 0 0 1 2.83 2.05l.22.64 1.03.06a3 3 0 0 1 2.78 2.78l.06 1.03.64.22A3 3 0 0 1 21 12a3 3 0 0 1-1.44 2.63l-.64.22-.06 1.03a3 3 0 0 1-2.78 2.78l-1.03.06-.22.64A3 3 0 0 1 12 21a3 3 0 0 1-2.63-1.44l-.22-.64-1.03-.06a3 3 0 0 1-2.78-2.78l-.06-1.03-.64-.22A3 3 0 0 1 3 12a3 3 0 0 1 1.44-2.63l.64-.22.06-1.03a3 3 0 0 1 2.78-2.78l1.03-.06.22-.64A3 3 0 0 1 12 2m0 2a1 1 0 0 0-.92.62l-.45 1.33a1 1 0 0 1-.93.67l-1.42.08a1 1 0 0 0-.93.93l-.08 1.42a1 1 0 0 1-.67.93L6.2 10.8a1 1 0 0 0 0 1.85l1.35.48a1 1 0 0 1 .67.93l.08 1.42a1 1 0 0 0 .93.93l1.42.08a1 1 0 0 1 .93.67l.45 1.33a1 1 0 0 0 1.85 0l.45-1.33a1 1 0 0 1 .93-.67l1.42-.08a1 1 0 0 0 .93-.93l.08-1.42a1 1 0 0 1 .67-.93l1.33-.45a1 1 0 0 0 0-1.85l-1.33-.45a1 1 0 0 1-.67-.93l-.08-1.42a1 1 0 0 0-.93-.93l-1.42-.08a1 1 0 0 1-.93-.67l-.45-1.33A1 1 0 0 0 12 4m0 4.5A3.5 3.5 0 1 1 8.5 12 3.5 3.5 0 0 1 12 8.5m0 2a1.5 1.5 0 1 0 1.5 1.5A1.5 1.5 0 0 0 12 10.5" fill="currentColor"/>
                    </svg>
                </span>
                <span class="portal-menu__text">Impostazioni</span>
            </a>
        </nav>
        <div class="portal-sidebar__footer">
            <div class="portal-user">
                <?php
                $avatarSource = (string) ($profile['fullname'] ?? $account['email']);
                $avatarLetter = strtoupper(substr($avatarSource, 0, 1));
                if (function_exists('mb_substr')) {
                    $avatarLetter = function_exists('mb_strtoupper')
                        ? mb_strtoupper(mb_substr($avatarSource, 0, 1))
                        : mb_substr($avatarSource, 0, 1);
                }
                ?>
                <span class="portal-user__avatar" aria-hidden="true">
                    <?= htmlspecialchars($avatarLetter) ?>
                </span>
                <div class="portal-user__info">
                    <strong><?= htmlspecialchars((string) ($profile['fullname'] ?? 'Cliente')) ?></strong>
                    <small><?= htmlspecialchars($account['email']) ?></small>
                </div>
            </div>
            <form method="post" class="portal-logout">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="portal-logout__button" aria-label="Esci">
                    <span>Esci</span>
                </button>
            </form>
        </div>
    </aside>
    <main class="portal-main">
        <header class="portal-header">
            <button type="button" class="portal-sidebar-toggle" aria-label="Comprimi menu" aria-expanded="true">
                <span class="portal-sidebar-toggle__icon" aria-hidden="true"></span>
                <span class="portal-sidebar-toggle__label">Menu</span>
            </button>
            <div class="portal-header__title">
                <h1><?= htmlspecialchars((string) ($profile['fullname'] ?? 'Benvenuto')) ?></h1>
                <?php if (!empty($profile['customer_email'])): ?>
                    <p class="portal-header__subtitle">Cliente registrato: <?= htmlspecialchars((string) $profile['customer_email']) ?></p>
                <?php endif; ?>
            </div>
        </header>
        <div class="portal-content">
            <?= $content ?>
        </div>
        <footer class="portal-footer">
            <small>&copy; <?= date('Y') ?> Coresuite Express · Area clienti</small>
        </footer>
    </main>
</div>
<script src="../assets/js/portal.js?v=1" defer></script>
</body>
</html>
