<?php
/**
 * @var string $view
 * @var array<string, mixed> $data
 * @var array<string, mixed> $account
 * @var array<string, mixed> $profile
 */

$partialBase = __DIR__;

switch ($view) {
    case 'orders':
        $file = $partialBase . '/orders.php';
        break;
    case 'sales':
        $file = $partialBase . '/sales.php';
        break;
    case 'sale_detail':
        $file = $partialBase . '/sale_detail.php';
        break;
    case 'payments':
        $file = $partialBase . '/payments.php';
        break;
    case 'support':
        $file = $partialBase . '/support.php';
        break;
    case 'support_detail':
        $file = $partialBase . '/support_detail.php';
        break;
    case 'settings':
        $file = $partialBase . '/settings.php';
        break;
    case 'dashboard':
    default:
        $file = $partialBase . '/dashboard.php';
        break;
}

if (!file_exists($file)) {
    throw new \RuntimeException('Partial non trovato: ' . $file);
}

require $file;
