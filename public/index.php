<?php
declare(strict_types=1);

session_start();

$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

/**
 * @param array<string, mixed>|string $toast
 */
function pushFlashToast($toast): void
{
    $normalized = normalizeToastPayload($toast);
    if ($normalized === null) {
        return;
    }
    if (!isset($_SESSION['flash_toasts']) || !is_array($_SESSION['flash_toasts'])) {
        $_SESSION['flash_toasts'] = [];
    }
    $_SESSION['flash_toasts'][] = $normalized;
}

/**
 * @return array<int, array<string, mixed>>
 */
function pullFlashToasts(): array
{
    $queued = $_SESSION['flash_toasts'] ?? [];
    unset($_SESSION['flash_toasts']);
    if (!is_array($queued)) {
        return [];
    }

    $result = [];
    foreach ($queued as $toast) {
        $normalized = normalizeToastPayload($toast);
        if ($normalized !== null) {
            $result[] = $normalized;
        }
    }
    return $result;
}

/**
 * @param array<string, mixed>|string $toast
 */
function normalizeToastPayload($toast): ?array
{
    if (is_string($toast)) {
        $message = trim($toast);
        if ($message === '') {
            return null;
        }
        return [
            'type' => 'info',
            'title' => '',
            'message' => $message,
            'duration' => 5000,
            'dismissible' => true,
        ];
    }

    if (!is_array($toast)) {
        return null;
    }

    $message = isset($toast['message']) ? trim((string) $toast['message']) : '';
    if ($message === '') {
        return null;
    }

    $normalized = [
        'type' => isset($toast['type']) ? (string) $toast['type'] : 'info',
        'title' => isset($toast['title']) ? trim((string) $toast['title']) : '',
        'message' => $message,
        'duration' => isset($toast['duration']) && is_numeric($toast['duration']) ? max(0, (int) $toast['duration']) : 5000,
        'dismissible' => !isset($toast['dismissible']) || (bool) $toast['dismissible'],
    ];

    foreach (['id', 'onDismiss', 'meta'] as $optionalKey) {
        if (array_key_exists($optionalKey, $toast)) {
            $normalized[$optionalKey] = $toast[$optionalKey];
        }
    }

    return $normalized;
}

/**
 * @param array<string, mixed> $feedback
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>|null
 */
function toastFromFeedback(array $feedback, array $overrides = []): ?array
{
    $isSuccess = (bool) ($feedback['success'] ?? false);

    $parts = [];
    if (isset($feedback['message'])) {
        $parts[] = trim((string) $feedback['message']);
    }

    if (!$isSuccess) {
        if (!empty($feedback['error'])) {
            $parts[] = 'Dettaglio: ' . trim((string) $feedback['error']);
        }
        if (!empty($feedback['errors']) && is_array($feedback['errors'])) {
            foreach ($feedback['errors'] as $error) {
                $errorText = trim((string) $error);
                if ($errorText !== '') {
                    $parts[] = $errorText;
                }
            }
        }
    }

    $message = trim(implode("\n", array_filter($parts, static fn (string $value): bool => $value !== '')));
    if ($message === '') {
        $message = $isSuccess ? 'Operazione completata.' : 'Impossibile completare l\'operazione.';
    }

    $toast = [
        'type' => $isSuccess ? 'success' : 'danger',
        'title' => $isSuccess ? 'Operazione completata' : 'Operazione non riuscita',
        'message' => $message,
        'duration' => $isSuccess ? 5000 : 0,
        'dismissible' => true,
    ];

    foreach ($overrides as $key => $value) {
        if ($key === 'message' && is_string($value)) {
            $toast[$key] = trim($value);
            continue;
        }
        $toast[$key] = $value;
    }

    return normalizeToastPayload($toast);
}

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

use App\Controllers\AuthController;
use App\Controllers\ICCIDController;
use App\Controllers\CustomerController;
use App\Controllers\OffersController;
use App\Controllers\ProductController;
use App\Controllers\ProductRequestController;
use App\Controllers\ReportsController;
use App\Controllers\SalesController;
use App\Controllers\SupportRequestController;
use App\Controllers\SsoController;
use App\Controllers\PdaImportController;
use App\Services\AuthService;
use App\Services\CustomerService;
use App\Services\DiscountCampaignService;
use App\Services\ICCIDService;
use App\Services\OffersService;
use App\Services\ProductService;
use App\Services\ProductRequestService;
use App\Services\ReportsService;
use App\Services\SaleNotificationService;
use App\Services\SalesService;
use App\Services\StockMonitorService;
use App\Services\SupportRequestService;
use App\Services\UserService;
use App\Services\NotificationDispatcher;
use App\Services\SystemNotificationService;
use App\Services\SsoService;
use App\Services\PdaImportService;

$pdo = Database::getConnection();

$alertsConfig = $GLOBALS['config']['alerts'] ?? [];
$alertEmail = $alertsConfig['email'] ?? null;
$resendApiKey = $alertsConfig['resend_api_key'] ?? null;
$resendFrom = $alertsConfig['resend_from'] ?? null;
$resendFromName = $alertsConfig['resend_from_name'] ?? null;
$saleFulfilmentEmail = $alertsConfig['sales_fulfilment_email'] ?? null;
$appConfig = $GLOBALS['config']['app'] ?? [];
$appName = is_array($appConfig) && isset($appConfig['name']) && is_string($appConfig['name']) && $appConfig['name'] !== ''
    ? (string) $appConfig['name']
    : 'Gestionale Telefonia';
$configuredPortalUrl = is_array($appConfig) && isset($appConfig['portal_url']) && is_string($appConfig['portal_url']) && $appConfig['portal_url'] !== ''
    ? trim($appConfig['portal_url'])
    : null;
$portalLoginUrl = $configuredPortalUrl !== null && $configuredPortalUrl !== '' ? $configuredPortalUrl : null;
if ($portalLoginUrl === null && !empty($_SERVER['HTTP_HOST'])) {
    $httpsValue = $_SERVER['HTTPS'] ?? null;
    $scheme = (is_string($httpsValue) && strtolower((string) $httpsValue) !== 'off' && $httpsValue !== '') ? 'https' : 'http';
    $portalLoginUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/public/portal/';
}
$logPath = __DIR__ . '/../storage/logs/stock_alerts.log';
$saleNotificationLog = __DIR__ . '/../storage/logs/sale_notifications.log';
$notificationsConfig = $GLOBALS['config']['notifications'] ?? [];
$notificationsLog = __DIR__ . '/../storage/logs/notifications.log';
$notificationDispatcher = new NotificationDispatcher(
    $notificationsConfig['webhook_url'] ?? null,
    is_array($notificationsConfig['webhook_headers'] ?? null) ? $notificationsConfig['webhook_headers'] : [],
    is_array($notificationsConfig['queue'] ?? null) ? $notificationsConfig['queue'] : null,
    $notificationsLog
);
$systemNotificationService = new SystemNotificationService($pdo, $notificationDispatcher, $notificationsLog);
$GLOBALS['systemNotificationService'] = $systemNotificationService;

if ($saleFulfilmentEmail === null || !filter_var($saleFulfilmentEmail, FILTER_VALIDATE_EMAIL)) {
    $saleFulfilmentEmail = $alertEmail;
}

$authService = new AuthService($pdo);
$iccidService = new ICCIDService($pdo);
$customerService = new CustomerService($pdo, $resendApiKey, $resendFrom, $appName, $portalLoginUrl, $resendFromName);
$offersService = new OffersService($pdo);
$reportsService = new ReportsService($pdo);
$productService = new ProductService($pdo);
$productRequestService = new ProductRequestService($pdo);
$salesService = new SalesService($pdo);
$discountCampaignService = new DiscountCampaignService($pdo);
$pdaImportService = new PdaImportService($pdo, $customerService);
$supportRequestService = new SupportRequestService($pdo);
$userService = new UserService($pdo);
$stockMonitorService = new StockMonitorService($pdo, $alertEmail, $logPath, $resendApiKey, $resendFrom, $systemNotificationService);
$saleNotificationService = new SaleNotificationService(
    $resendApiKey,
    $resendFrom,
    $resendFromName,
    $saleFulfilmentEmail,
    $appName,
    $saleNotificationLog,
    $systemNotificationService
);
$ssoConfig = $GLOBALS['config']['sso'] ?? [];
$ssoService = new SsoService($pdo, is_array($ssoConfig) ? $ssoConfig : []);

$authController = new AuthController($authService);
$iccidController = new ICCIDController($iccidService);
$customerController = new CustomerController($customerService);
$offersController = new OffersController($offersService);
$reportsController = new ReportsController($reportsService);
$productController = new ProductController($productService);
$productRequestController = new ProductRequestController($productRequestService);
$salesController = new SalesController($salesService, $discountCampaignService, $saleNotificationService);
$supportRequestController = new SupportRequestController($supportRequestService);
$ssoController = new SsoController($ssoService);
$pdaImportController = new PdaImportController($pdaImportService);

$page = $_GET['page'] ?? 'dashboard';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$currentUser = $authService->currentUser();

if ($page === 'sso_token') {
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'method_not_allowed']);
        exit;
    }

    $input = $_POST;
    if ($input === [] && isset($_SERVER['CONTENT_TYPE']) && str_contains((string) $_SERVER['CONTENT_TYPE'], 'application/json')) {
        $rawBody = file_get_contents('php://input');
        $decoded = json_decode($rawBody ?: '{}', true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }

    $response = $ssoController->token($input);
    http_response_code($response['status']);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response['body']);
    exit;
}

if ($page === 'sso_authorize') {
    if (!$ssoService->isEnabled()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'sso_disabled']);
        exit;
    }

    if ($currentUser === null) {
        $query = $_GET;
        $query['page'] = 'sso_authorize';
        $queryString = http_build_query($query);
        $_SESSION['login_redirect'] = 'index.php' . ($queryString !== '' ? '?' . $queryString : '');
        header('Location: index.php?page=login');
        exit;
    }

    $result = $ssoController->authorize($_GET, $currentUser);
    if (!($result['success'] ?? false)) {
        http_response_code($result['status'] ?? 400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => $result['error'] ?? 'Operazione non riuscita.',
        ]);
        exit;
    }

    header('Location: ' . $result['redirect']);
    exit;
}

if ($page === 'logout') {
    $authController->logout();
}

if ($page === 'login' && $method === 'POST') {
    $result = $authController->login($_POST);
    if ($result['success']) {
        $pending = $_SESSION['login_redirect'] ?? null;
        unset($_SESSION['login_redirect']);
        $target = is_string($pending) && $pending !== ''
            ? sanitizeInternalUrl($pending, 'index.php')
            : 'index.php';
        header('Location: ' . $target);
        exit;
    }

    if (!empty($result['mfa_required']) && isset($result['redirect'])) {
        header('Location: ' . $result['redirect']);
        exit;
    }

    render('login', [
        'errors' => $result['errors'] ?? [],
        'appName' => $GLOBALS['config']['app']['name'] ?? 'Gestionale Telefonia',
        'oldInput' => $result['old'] ?? ['username' => '', 'remember_me' => false],
    ], false);
    exit;
}

if ($currentUser === null && !in_array($page, ['login', 'login_mfa', 'sso_authorize', 'sso_token'], true)) {
    header('Location: index.php?page=login');
    exit;
}

if ($currentUser !== null) {
    $allowedWithoutMfa = ['security', 'logout', 'notifications_stream', 'notifications_mark_all_read'];
    $state = $authController->getSecurityState((int) $currentUser['id']);
    $hasMfa = is_array($state) && (($state['mfa_enabled'] ?? false) === true);

    if ($hasMfa) {
        unset($_SESSION['mfa_enforcement_prompted']);
    } elseif (!in_array($page, $allowedWithoutMfa, true)) {
        if (empty($_SESSION['mfa_enforcement_prompted'])) {
            pushFlashToast([
                'type' => 'warning',
                'title' => 'Proteggi il tuo account',
                'message' => 'Configura l’autenticazione a due fattori per continuare a usare la piattaforma.',
                'duration' => 0,
                'dismissible' => false,
            ]);
            $_SESSION['mfa_enforcement_prompted'] = true;
        }

        header('Location: index.php?page=security&setup=1');
        exit;
    }
}

if ($page === 'login_mfa') {
    if ($currentUser !== null) {
        header('Location: index.php');
        exit;
    }

    if (!$authController->hasPendingMfa()) {
        $authController->cancelPendingMfa();
        header('Location: index.php?page=login');
        exit;
    }

    $errors = [];
    if ($method === 'POST') {
        $action = isset($_POST['action']) ? (string) $_POST['action'] : 'verify';
        if ($action === 'cancel') {
            $authController->cancelPendingMfa();
            header('Location: index.php?page=login');
            exit;
        }

        $verification = $authController->verifyMfa($_POST);
        if ($verification['success'] ?? false) {
            header('Location: index.php');
            exit;
        }
        if (!empty($verification['error'])) {
            $errors[] = (string) $verification['error'];
        }
    }

    $pendingMfa = $authController->getPendingMfa();

    render('login_mfa', [
        'errors' => $errors,
        'pending' => $pendingMfa,
        'appName' => $GLOBALS['config']['app']['name'] ?? 'Gestionale Telefonia',
    ], false);
    exit;
}

if ($page === 'login') {
    if ($currentUser !== null) {
        header('Location: index.php');
        exit;
    }

    render('login', [
        'errors' => [],
        'appName' => $GLOBALS['config']['app']['name'] ?? 'Gestionale Telefonia',
        'oldInput' => ['username' => '', 'remember_me' => false],
    ], false);
    exit;
}

if ($page === 'notifications_mark_all_read') {
    if ($method !== 'POST') {
        http_response_code(405);
        exit;
    }

    $userId = null;
    if (is_array($currentUser) && isset($currentUser['id'])) {
        $userId = (int) $currentUser['id'];
    }

    if (isset($systemNotificationService)) {
        $systemNotificationService->markAllRead($userId);
    }

    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    $redirect = isset($_POST['redirect']) ? (string) $_POST['redirect'] : 'index.php';
    $redirect = sanitizeInternalUrl($redirect, 'index.php');
    header('Location: ' . $redirect);
    exit;
}

if ($page === 'notifications_stream') {
    if ($method !== 'GET') {
        http_response_code(405);
        exit;
    }

    if (!isset($systemNotificationService)) {
        http_response_code(503);
        exit;
    }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Connection: keep-alive');
    if (function_exists('header_remove')) {
        header_remove('Content-Length');
    }
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    set_time_limit(0);
    ignore_user_abort(true);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $userId = null;
    if (is_array($currentUser) && isset($currentUser['id'])) {
        $userId = (int) $currentUser['id'];
    }

    $lastId = isset($_GET['last_id']) ? (int) $_GET['last_id'] : 0;
    $sleepInterval = 5;
    $maxRuntime = 300; // seconds
    $startedAt = time();

    echo "retry: 8000\n\n";
    while (@ob_end_flush()) {
        // drain existing buffers
    }
    flush();

    while (!connection_aborted()) {
        if ((time() - $startedAt) >= $maxRuntime) {
            break;
        }

        $payload = $systemNotificationService->getStreamPayload($userId, $lastId);
        $items = $payload['items'] ?? [];

        if ($items !== []) {
            $lastId = (int) ($payload['last_id'] ?? $lastId);
            $eventData = json_encode([
                'items' => $items,
                'unread_count' => (int) ($payload['unread_count'] ?? 0),
                'last_id' => $lastId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($eventData !== false) {
                echo "event: notifications\n";
                echo 'data: ' . $eventData . "\n\n";
                flush();
            }
        }

        $heartbeat = json_encode(['time' => time()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($heartbeat !== false) {
            echo "event: heartbeat\n";
            echo 'data: ' . $heartbeat . "\n\n";
            flush();
        }

        sleep($sleepInterval);
    }

    exit;
}

switch ($page) {
    case 'dashboard':
        $period = $_GET['period'] ?? 'day';
        if (!in_array($period, ['day', 'month', 'year'], true)) {
            $period = 'day';
        }

        $metrics = getDashboardMetrics($pdo, $period);
        $providerInsights = $stockMonitorService->getProviderInsights();
        $stockAlerts = $stockMonitorService->getOpenAlerts();
        $productInsights = $stockMonitorService->getProductInsights();
        $productAlerts = $stockMonitorService->getOpenProductAlerts();
        $lowStockCount = 0;
        $lowStockNames = [];
        foreach ($providerInsights as $insight) {
            if (!empty($insight['below_threshold'])) {
                $lowStockCount++;
                if (!empty($insight['provider_name'])) {
                    $lowStockNames[] = (string) $insight['provider_name'];
                }
            }
        }
        $metrics['low_stock_providers'] = $lowStockCount;
        $lowStockProductNames = [];
        foreach ($productInsights as $productInfo) {
            if (!empty($productInfo['below_threshold']) && !empty($productInfo['product_name'])) {
                $lowStockProductNames[] = (string) $productInfo['product_name'];
            }
        }
        $metrics['low_stock_products'] = count($lowStockProductNames);
        $stockRiskSummary = buildStockRiskSummary($providerInsights);
        $productRiskSummary = buildProductStockRiskSummary($productInsights);
        $nextSteps = buildDashboardNextSteps(
            $metrics,
            $providerInsights,
            $productInsights,
            $metrics['campaign_performance'] ?? ['items' => []],
            $stockAlerts,
            $productAlerts
        );
        $operationalPulse = buildOperationalPulse(
            $metrics,
            $stockAlerts,
            $productAlerts,
            $providerInsights,
            $productInsights,
            $metrics['campaign_performance'] ?? ['items' => []],
            $metrics['support_summary'] ?? [],
            $metrics['billing'] ?? []
        );
        render('dashboard', [
            'metrics' => $metrics,
            'stockAlerts' => $stockAlerts,
            'providerInsights' => $providerInsights,
            'productInsights' => $productInsights,
            'productAlerts' => $productAlerts,
            'selectedPeriod' => $period,
            'currentUser' => $currentUser,
            'lowStockProviders' => $lowStockNames,
            'lowStockProducts' => $lowStockProductNames,
            'stockRiskSummary' => $stockRiskSummary,
            'productRiskSummary' => $productRiskSummary,
            'nextSteps' => $nextSteps,
            'operationalPulse' => $operationalPulse,
        ]);
        break;

    case 'reports':
        $reportData = $reportsController->summary($_GET['view'] ?? 'daily', $_GET);
        render('reports', [
            'report' => $reportData,
            'currentUser' => $currentUser,
            'filters' => $reportData['filters'] ?? [],
            'filterOptions' => $reportData['filter_options'] ?? [],
            'view' => $reportData['granularity'] ?? 'daily',
            'pageTitle' => 'Report vendite',
        ]);
        break;

    case 'sim_stock':
        $feedback = $_SESSION['sim_stock_feedback'] ?? null;
        unset($_SESSION['sim_stock_feedback']);
        $initialToasts = [];
        if (is_array($feedback)) {
            $toast = toastFromFeedback($feedback, [
                'type' => ($feedback['success'] ?? false) ? 'reorder' : 'danger',
                'title' => ($feedback['success'] ?? false) ? 'SIM registrata' : 'Errore magazzino SIM',
                'duration' => ($feedback['success'] ?? false) ? 6000 : 0,
            ]);
            if ($toast !== null) {
                $initialToasts[] = $toast;
            }
        }

        if ($method === 'GET' && ($_GET['action'] ?? '') === 'refresh') {
            $stockPage = isset($_GET['page_no']) ? max((int) $_GET['page_no'], 1) : 1;
            $stockPerPage = isset($_GET['per_page']) ? max(1, min((int) $_GET['per_page'], 50)) : 7;
            $stockList = $iccidController->listPaginated($stockPage, $stockPerPage);
            jsonResponse([
                'success' => true,
                'payload' => [
                    'rows' => $stockList['rows'],
                    'pagination' => $stockList['pagination'],
                ],
            ]);
        }

        if ($method === 'POST') {
            $result = $iccidController->create($_POST);

            if (isAjaxRequest()) {
                $status = $result['success'] ? 200 : 422;
                $stockPage = isset($_POST['page_no']) ? max((int) $_POST['page_no'], 1) : 1;
                $stockPerPage = isset($_POST['per_page']) ? max(1, min((int) $_POST['per_page'], 50)) : 7;
                $stockList = $iccidController->listPaginated($stockPage, $stockPerPage);
                jsonResponse([
                    'success' => $result['success'],
                    'message' => $result['message'],
                    'error' => $result['error'] ?? null,
                    'payload' => [
                        'rows' => $stockList['rows'],
                        'pagination' => $stockList['pagination'],
                    ],
                ], $status);
            }

            $_SESSION['sim_stock_feedback'] = $result;
            header('Location: index.php?page=sim_stock');
            exit;
        }
        $stockPage = isset($_GET['page_no']) ? max((int) $_GET['page_no'], 1) : 1;
        $stockPerPage = 7;
        $stockList = $iccidController->listPaginated($stockPage, $stockPerPage);
        render('sim_stock', [
            'providers' => $iccidController->providers(),
            'stock' => $stockList['rows'],
            'pagination' => $stockList['pagination'],
            'currentUser' => $currentUser,
            'initialToasts' => $initialToasts,
        ]);
        break;

    case 'products':
        $feedbackProducts = $_SESSION['products_feedback'] ?? null;
        unset($_SESSION['products_feedback']);
        $initialToasts = [];
        if (is_array($feedbackProducts)) {
            $toast = toastFromFeedback($feedbackProducts, [
                'type' => ($feedbackProducts['success'] ?? false) ? 'store' : 'danger',
                'title' => ($feedbackProducts['success'] ?? false) ? 'Catalogo aggiornato' : 'Errore catalogo prodotti',
                'duration' => ($feedbackProducts['success'] ?? false) ? 6000 : 0,
            ]);
            if ($toast !== null) {
                $initialToasts[] = $toast;
            }
        }
        $editId = isset($_GET['edit']) ? max((int) $_GET['edit'], 0) : 0;
        $productToEdit = null;

        if ($editId > 0) {
            $productToEdit = $productController->find($editId);
            if ($productToEdit === null) {
                $feedbackProducts = [
                    'success' => false,
                    'message' => 'Prodotto non trovato.',
                    'errors' => ['Il prodotto selezionato non è più presente a catalogo.'],
                ];
                $toast = toastFromFeedback($feedbackProducts, [
                    'type' => 'warning',
                    'title' => 'Prodotto non trovato',
                    'duration' => 0,
                ]);
                if ($toast !== null) {
                    $initialToasts[] = $toast;
                }
            }
        }

        if ($method === 'POST') {
            $currentUserId = null;
            if (is_array($currentUser) && isset($currentUser['id'])) {
                $candidate = (int) $currentUser['id'];
                if ($candidate > 0) {
                    $currentUserId = $candidate;
                }
            }
            $action = $_POST['action'] ?? 'create';
            if ($action === 'update') {
                $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
                $result = $productController->update($productId, $_POST, $currentUserId);
                if ($result['success'] ?? false) {
                    $_SESSION['products_list_feedback'] = $result;
                    header('Location: index.php?page=products_list');
                } else {
                    $_SESSION['products_feedback'] = $result;
                    header('Location: index.php?page=products&edit=' . max($productId, 0));
                }
                exit;
            }

            $result = $productController->create($_POST, $currentUserId);
            $_SESSION['products_feedback'] = $result;
            header('Location: index.php?page=products');
            exit;
        }

        render('products', [
            'currentUser' => $currentUser,
            'pageTitle' => 'Catalogo prodotti',
            'editingProduct' => $productToEdit,
            'initialToasts' => $initialToasts,
        ]);
        break;

    case 'products_list':
        $feedbackList = $_SESSION['products_list_feedback'] ?? null;
        unset($_SESSION['products_list_feedback']);
        $initialToasts = [];
        if (is_array($feedbackList)) {
            $toast = toastFromFeedback($feedbackList, [
                'type' => ($feedbackList['success'] ?? false) ? 'store' : 'danger',
                'title' => ($feedbackList['success'] ?? false) ? 'Catalogo aggiornato' : 'Errore lista prodotti',
                'duration' => ($feedbackList['success'] ?? false) ? 6000 : 0,
            ]);
            if ($toast !== null) {
                $initialToasts[] = $toast;
            }
        }

        if ($method === 'POST') {
            $action = $_POST['action'] ?? '';
            $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
            if ($action === 'delete_product') {
                $result = $productController->delete($productId);
            } elseif ($action === 'restock_product') {
                $result = $productController->restock($productId);
            } else {
                $result = [
                    'success' => false,
                    'message' => 'Azione non riconosciuta.',
                    'errors' => ['Richiesta non valida per la lista prodotti.'],
                ];
            }
            $_SESSION['products_list_feedback'] = $result;
            header('Location: index.php?page=products_list');
            exit;
        }

        $productsPage = isset($_GET['page_no']) ? max((int) $_GET['page_no'], 1) : 1;
        $productsPerPage = 7;
        if (isset($_GET['per_page'])) {
            $requested = max(1, min((int) $_GET['per_page'], 50));
            if ($requested !== $productsPerPage) {
                $productsPerPage = $requested;
            }
        }

        $productsList = $productController->listPaginated($productsPage, $productsPerPage);
        render('products_list', [
            'products' => $productsList['rows'],
            'pagination' => $productsList['pagination'],
            'currentUser' => $currentUser,
            'pageTitle' => 'Lista prodotti',
            'initialToasts' => $initialToasts,
        ]);
        break;

    case 'customers':
        $feedbackCustomers = $_SESSION['customers_feedback'] ?? null;
        unset($_SESSION['customers_feedback']);

        $initialToasts = [];
        if (is_array($feedbackCustomers)) {
            $toast = toastFromFeedback($feedbackCustomers);
            if ($toast !== null) {
                $detailLines = [];
                if (!empty($feedbackCustomers['success'])) {
                    $portalInfo = $feedbackCustomers['portal_account'] ?? null;
                    if (is_array($portalInfo)) {
                        $portalStatus = (string) ($portalInfo['status'] ?? '');
                        $portalEmail = isset($portalInfo['email']) ? trim((string) $portalInfo['email']) : '';
                        $portalPassword = isset($portalInfo['password']) ? trim((string) $portalInfo['password']) : '';

                        if ($portalStatus === 'created' && $portalPassword !== '') {
                            $detailLines[] = 'Credenziali area clienti generate:';
                            if ($portalEmail !== '') {
                                $detailLines[] = 'Email: ' . $portalEmail;
                            }
                            $detailLines[] = 'Password temporanea: ' . $portalPassword;
                            $detailLines[] = 'Condividi la password con il cliente e invita al cambio al primo accesso.';
                        } elseif ($portalStatus === 'updated' && $portalEmail !== '') {
                            $detailLines[] = 'Email di accesso area clienti aggiornata a ' . $portalEmail . '.';
                        } elseif ($portalStatus === 'resent') {
                            if ($portalEmail !== '') {
                                $detailLines[] = 'Nuove credenziali generate per ' . $portalEmail . '.';
                            } else {
                                $detailLines[] = 'Nuove credenziali generate.';
                            }
                            if ($portalPassword !== '') {
                                $detailLines[] = 'Password temporanea: ' . $portalPassword;
                            }
                            if (array_key_exists('email_sent', $portalInfo)) {
                                $detailLines[] = !empty($portalInfo['email_sent'])
                                    ? 'Email inviata automaticamente al cliente.'
                                    : 'Invio automatico non riuscito. Condividi le credenziali manualmente.';
                            }
                        }
                    }

                    $invitationInfo = $feedbackCustomers['invitation'] ?? null;
                    if (is_array($invitationInfo)) {
                        $invitationEmail = isset($invitationInfo['email']) ? trim((string) $invitationInfo['email']) : '';
                        if ($invitationEmail !== '') {
                            $detailLines[] = 'Link di attivazione generato per ' . $invitationEmail . '.';
                        }
                        if (!empty($invitationInfo['activation_link'])) {
                            $detailLines[] = 'URL attivazione: ' . trim((string) $invitationInfo['activation_link']);
                        }
                        if (!empty($invitationInfo['token'])) {
                            $detailLines[] = 'Codice invito: ' . trim((string) $invitationInfo['token']);
                        }
                        if (array_key_exists('email_sent', $invitationInfo)) {
                            $detailLines[] = !empty($invitationInfo['email_sent'])
                                ? 'Email inviata automaticamente.'
                                : 'Email non inviata, condividi il link manualmente.';
                        }
                    }
                }

                if ($detailLines !== []) {
                    $messageLines = [];
                    if (!empty($toast['message'])) {
                        $messageLines[] = (string) $toast['message'];
                    }
                    foreach ($detailLines as $line) {
                        $trimmedLine = trim((string) $line);
                        if ($trimmedLine !== '') {
                            $messageLines[] = $trimmedLine;
                        }
                    }
                    if ($messageLines !== []) {
                        $toast['message'] = implode("\n", $messageLines);
                    }
                    $toast['duration'] = 0;
                }

                $initialToasts[] = $toast;
            }
        }

        $searchTerm = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
        $perPage = 10;
        if (isset($_GET['per_page'])) {
            $requestedPerPage = max(1, min((int) $_GET['per_page'], 50));
            if ($requestedPerPage !== $perPage) {
                $perPage = $requestedPerPage;
            }
        }

        if ($method === 'POST') {
            $action = $_POST['action'] ?? '';
            $customerId = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
            $searchTerm = isset($_POST['search_term']) ? trim((string) $_POST['search_term']) : $searchTerm;
            $pageNo = isset($_POST['page_no']) ? max((int) $_POST['page_no'], 1) : null;
            $perPagePost = isset($_POST['per_page']) ? max((int) $_POST['per_page'], 1) : null;

            if ($action === 'create_customer') {
                $result = $customerController->create($_POST);
            } elseif ($action === 'update_customer') {
                $result = $customerController->update($customerId, $_POST);
            } elseif ($action === 'delete_customer') {
                $result = $customerController->delete($customerId);
            } elseif ($action === 'resend_portal_credentials') {
                $result = $customerController->resendPortalCredentials($customerId);
            } elseif ($action === 'send_portal_invitation') {
                $result = $customerController->sendPortalInvitation($customerId);
            } else {
                $result = [
                    'success' => false,
                    'message' => 'Azione non riconosciuta.',
                ];
            }

            $_SESSION['customers_feedback'] = $result;

            $redirectParams = ['page' => 'customers'];
            if ($searchTerm !== '') {
                $redirectParams['search'] = $searchTerm;
            }
            if ($pageNo !== null) {
                $redirectParams['page_no'] = $pageNo;
            }
            if ($perPagePost !== null) {
                $redirectParams['per_page'] = min($perPagePost, 50);
            }
            if ($action === 'update_customer' && !($result['success'] ?? false) && $customerId > 0) {
                $redirectParams['edit'] = $customerId;
            }

            header('Location: index.php?' . http_build_query($redirectParams));
            exit;
        }

        $editId = isset($_GET['edit']) ? max((int) $_GET['edit'], 0) : 0;
        $customerToEdit = null;
        if ($editId > 0) {
            $customerToEdit = $customerController->find($editId);
            if ($customerToEdit === null) {
                $notFoundToast = toastFromFeedback([
                    'success' => false,
                    'message' => 'Cliente non trovato.',
                    'errors' => ['Il cliente selezionato non è più disponibile.'],
                ], ['duration' => 0]);
                if ($notFoundToast !== null) {
                    $initialToasts[] = $notFoundToast;
                }
            }
        }

        $customersPage = isset($_GET['page_no']) ? max((int) $_GET['page_no'], 1) : 1;
        $customersList = $customerController->listPaginated($customersPage, $perPage, $searchTerm !== '' ? $searchTerm : null);

        $buildCustomersPageUrl = static function (int $pageNo, string $search, int $perPage) {
            $params = [
                'page' => 'customers',
                'page_no' => max(1, $pageNo),
            ];
            if ($search !== '') {
                $params['search'] = $search;
            }
            if ($perPage !== 10) {
                $params['per_page'] = $perPage;
            }

            return 'index.php?' . http_build_query($params);
        };

        render('customers', [
            'customers' => $customersList['rows'],
            'pagination' => $customersList['pagination'],
            'currentUser' => $currentUser,
            'pageTitle' => 'Clienti',
            'searchTerm' => $searchTerm,
            'editingCustomer' => $customerToEdit,
            'perPage' => $perPage,
            'buildPageUrl' => $buildCustomersPageUrl,
            'initialToasts' => $initialToasts,
        ]);
        break;

    case 'profile':
        if ($currentUser === null) {
            header('Location: index.php?page=login');
            exit;
        }

        $profileUser = $userService->findUser((int) ($currentUser['id'] ?? 0));
        if ($profileUser === null) {
            pushFlashToast([
                'type' => 'danger',
                'title' => 'Profilo non disponibile',
                'message' => 'Impossibile caricare il profilo utente corrente.',
                'duration' => 0,
            ]);
            header('Location: index.php?page=dashboard');
            exit;
        }

        $roleNameRaw = (string) ($profileUser['role_name'] ?? '');
        $roleLabel = formatRoleLabel($roleNameRaw);
        $roleKey = strtolower(str_replace(' ', '_', $roleNameRaw));
        $shortcuts = resolveProfileShortcuts($roleKey);
        $salesSummary = buildUserProfileSalesSummary($pdo, (int) $profileUser['id']);
        $roleSummary = resolveRoleSummary($roleKey);

        render('profile', [
            'currentUser' => $currentUser,
            'pageTitle' => 'Profilo utente',
            'profile' => $profileUser,
            'roleLabel' => $roleLabel,
            'roleSummary' => $roleSummary,
            'shortcuts' => $shortcuts,
            'salesSummary' => $salesSummary,
        ]);
        break;

    case 'settings':
        $feedback = $_SESSION['settings_feedback'] ?? null;
        unset($_SESSION['settings_feedback']);
        $ssoFeedback = $_SESSION['settings_sso_feedback'] ?? null;
        unset($_SESSION['settings_sso_feedback']);
        $ssoSecretPreview = $_SESSION['settings_sso_secret'] ?? null;
        unset($_SESSION['settings_sso_secret']);
        $ssoEnabled = $ssoService->isEnabled();
        $isAdmin = $authService->hasRole('admin');

        $operatorEdit = null;
        $operatorEditForm = null;
        $operatorsOpenOverride = null;
        $operatorEditId = 0;

        if ($isAdmin) {
            if (isset($_SESSION['settings_operator_form']) && is_array($_SESSION['settings_operator_form'])) {
                $storedOperatorForm = $_SESSION['settings_operator_form'];
                unset($_SESSION['settings_operator_form']);
                $operatorEditId = isset($storedOperatorForm['id']) ? (int) $storedOperatorForm['id'] : 0;
                $operatorEditForm = isset($storedOperatorForm['form']) && is_array($storedOperatorForm['form'])
                    ? $storedOperatorForm['form']
                    : null;
                $operatorsOpenOverride = true;
            }

            if (isset($_GET['operators_open'])) {
                $operatorsOpenOverride = true;
            }

            if (isset($_GET['edit_operator'])) {
                $operatorEditId = max((int) $_GET['edit_operator'], 0);
                if ($operatorEditId > 0) {
                    $operatorsOpenOverride = true;
                }
            }
        } else {
            unset($_SESSION['settings_operator_form']);
        }

        $fiscalOpen = isset($_GET['fiscal_open']);
        $ssoOpen = isset($_GET['sso_open']);
        $ssoClients = [];
        if ($ssoEnabled) {
            try {
                $ssoClients = $ssoService->listClients();
            } catch (\Throwable $exception) {
                if ($ssoFeedback === null) {
                    $ssoFeedback = [
                        'success' => false,
                        'message' => 'Impossibile caricare i client SSO: ' . $exception->getMessage(),
                    ];
                }
            }
        }

        if ($method === 'POST') {
            $action = $_POST['action'] ?? '';
            $redirectParams = [];

            if ($action === 'update_threshold') {
                if (!$isAdmin) {
                    $result = [
                        'success' => false,
                        'message' => 'Operazione non autorizzata.',
                        'error' => 'Solo gli amministratori possono aggiornare le soglie.',
                    ];
                } else {
                    $providerId = (int) ($_POST['provider_id'] ?? 0);
                    $threshold = (int) ($_POST['reorder_threshold'] ?? 0);
                    $result = $stockMonitorService->updateThreshold($providerId, $threshold);
                }
            } elseif ($action === 'create_operator') {
                if (!$isAdmin) {
                    $result = [
                        'success' => false,
                        'message' => 'Operazione non autorizzata.',
                        'error' => 'Solo gli amministratori possono creare operatori.',
                    ];
                } else {
                    $result = $userService->createOperator($_POST);
                }
                if (!($result['success'] ?? false)) {
                    $redirectParams['operators_open'] = 1;
                }
            } elseif ($action === 'update_operator') {
                if (!$isAdmin) {
                    $result = [
                        'success' => false,
                        'message' => 'Operazione non autorizzata.',
                        'error' => 'Solo gli amministratori possono aggiornare operatori.',
                    ];
                } else {
                    $operatorId = isset($_POST['operator_id']) ? (int) $_POST['operator_id'] : 0;
                    $formData = [
                        'fullname' => trim((string) ($_POST['operator_edit_fullname'] ?? '')),
                        'username' => trim((string) ($_POST['operator_edit_username'] ?? '')),
                        'role_id' => isset($_POST['operator_edit_role']) ? (int) $_POST['operator_edit_role'] : 0,
                    ];
                    $result = $userService->updateOperator($operatorId, $_POST);
                    if (!($result['success'] ?? false)) {
                        $_SESSION['settings_operator_form'] = [
                            'id' => $operatorId,
                            'form' => $formData,
                        ];
                        if ($operatorId > 0) {
                            $redirectParams['edit_operator'] = $operatorId;
                        }
                    } else {
                        unset($_SESSION['settings_operator_form']);
                    }
                }
                $redirectParams['operators_open'] = 1;
            } elseif ($action === 'delete_operator') {
                if (!$isAdmin) {
                    $result = [
                        'success' => false,
                        'message' => 'Operazione non autorizzata.',
                        'error' => 'Solo gli amministratori possono eliminare operatori.',
                    ];
                } else {
                    $operatorId = isset($_POST['operator_id']) ? (int) $_POST['operator_id'] : 0;
                    $actingUserId = isset($currentUser['id']) ? (int) $currentUser['id'] : 0;
                    $result = $userService->deleteOperator($operatorId, $actingUserId);
                    unset($_SESSION['settings_operator_form']);
                }
                $redirectParams['operators_open'] = 1;
            } elseif ($action === 'update_product_tax') {
                if (!$isAdmin) {
                    $result = [
                        'success' => false,
                        'message' => 'Operazione non autorizzata.',
                        'error' => 'Solo gli amministratori possono aggiornare le impostazioni fiscali.',
                    ];
                } else {
                    $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
                    $taxRateInput = isset($_POST['product_tax_rate']) ? (float) $_POST['product_tax_rate'] : 0.0;
                    $vatCodeInput = array_key_exists('product_vat_code', $_POST)
                        ? (string) $_POST['product_vat_code']
                        : null;
                    $result = $productService->updateTaxSettings($productId, $taxRateInput, $vatCodeInput);
                }
                $redirectParams['fiscal_open'] = 1;
            } elseif ($action === 'create_discount_campaign') {
                if (!$isAdmin) {
                    $result = [
                        'success' => false,
                        'message' => 'Operazione non autorizzata.',
                        'error' => 'Solo gli amministratori possono creare campagne.',
                    ];
                } else {
                    $result = $discountCampaignService->create($_POST);
                }
            } elseif ($action === 'toggle_discount_campaign') {
                if (!$isAdmin) {
                    $result = [
                        'success' => false,
                        'message' => 'Operazione non autorizzata.',
                        'error' => 'Solo gli amministratori possono aggiornare campagne.',
                    ];
                } else {
                    $campaignId = (int) ($_POST['campaign_id'] ?? 0);
                    $target = isset($_POST['target_status']) ? ((int) $_POST['target_status'] === 1) : true;
                    $result = $discountCampaignService->setStatus($campaignId, $target);
                }
            } elseif ($action === 'force_disable_mfa') {
                if (!$isAdmin) {
                    $result = [
                        'success' => false,
                        'message' => 'Operazione non autorizzata.',
                        'error' => 'Solo gli amministratori possono intervenire sull’MFA degli operatori.',
                    ];
                } else {
                    $operatorId = isset($_POST['operator_id']) ? (int) $_POST['operator_id'] : 0;
                    $result = $authController->disableMfa($operatorId, null, true);
                    $result['message'] = ($result['success'] ?? false)
                        ? 'MFA disattivata per l’operatore selezionato.'
                        : ($result['error'] ?? 'Impossibile disattivare l’MFA per l’operatore.');
                }
                $redirectParams['operators_open'] = 1;
            } elseif ($action === 'sso_create_client') {
                $redirectParams['sso_open'] = 1;
                if (!$isAdmin) {
                    $result = [
                        'success' => false,
                        'message' => 'Operazione non autorizzata.',
                        'error' => 'Solo gli amministratori possono creare client SSO.',
                    ];
                } elseif (!$ssoEnabled) {
                    $result = [
                        'success' => false,
                        'message' => 'SSO non configurato.',
                        'error' => 'Configura SSO_SHARED_SECRET per abilitare l\'SSO interno.',
                    ];
                } else {
                    $clientName = trim((string) ($_POST['sso_client_name'] ?? ''));
                    $clientRedirect = trim((string) ($_POST['sso_redirect_uri'] ?? ''));
                    $creation = $ssoService->createClient($clientName, $clientRedirect, true);
                    $result = $creation;
                    if (($creation['success'] ?? false) && isset($creation['client_secret'])) {
                        $_SESSION['settings_sso_secret'] = [
                            'client_id' => $creation['client_id'] ?? '',
                            'client_secret' => $creation['client_secret'],
                        ];
                        $result['message'] = 'Client SSO creato. Annota il secret generato: sarà mostrato una sola volta.';
                    }
                }
                $_SESSION['settings_sso_feedback'] = $result;
            } elseif ($action === 'sso_rotate_client_secret') {
                $redirectParams['sso_open'] = 1;
                if (!$isAdmin) {
                    $result = [
                        'success' => false,
                        'message' => 'Operazione non autorizzata.',
                        'error' => 'Solo gli amministratori possono rigenerare i secret SSO.',
                    ];
                } elseif (!$ssoEnabled) {
                    $result = [
                        'success' => false,
                        'message' => 'SSO non configurato.',
                        'error' => 'Configura SSO_SHARED_SECRET per usare il single sign-on interno.',
                    ];
                } else {
                    $clientRowId = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
                    if ($clientRowId <= 0) {
                        $result = [
                            'success' => false,
                            'message' => 'Client SSO non valido.',
                        ];
                    } else {
                        $label = trim((string) ($_POST['client_label'] ?? ''));
                        $identifier = trim((string) ($_POST['client_identifier'] ?? ''));
                        $rotation = $ssoService->rotateClientSecret($clientRowId);
                        $result = $rotation;
                        if (($rotation['success'] ?? false) && isset($rotation['client_secret'])) {
                            $_SESSION['settings_sso_secret'] = [
                                'client_id' => $identifier,
                                'client_secret' => $rotation['client_secret'],
                            ];
                            $name = $label !== '' ? $label : $identifier;
                            $result['message'] = 'Secret rigenerato per il client ' . ($name !== '' ? $name : 'selezionato') . '. Annota il nuovo valore.';
                        }
                    }
                }
                $_SESSION['settings_sso_feedback'] = $result;
            } elseif ($action === 'sso_toggle_client') {
                $redirectParams['sso_open'] = 1;
                if (!$isAdmin) {
                    $result = [
                        'success' => false,
                        'message' => 'Operazione non autorizzata.',
                        'error' => 'Solo gli amministratori possono modificare lo stato dei client SSO.',
                    ];
                } elseif (!$ssoEnabled) {
                    $result = [
                        'success' => false,
                        'message' => 'SSO non configurato.',
                        'error' => 'Configura SSO_SHARED_SECRET per usare il single sign-on interno.',
                    ];
                } else {
                    $clientRowId = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
                    $targetStatus = isset($_POST['target_status']) ? ((int) $_POST['target_status'] === 1) : false;
                    $toggle = $ssoService->setClientStatus($clientRowId, $targetStatus);
                    $result = $toggle;
                }
                $_SESSION['settings_sso_feedback'] = $result;
            } elseif ($action === 'sso_delete_client') {
                $redirectParams['sso_open'] = 1;
                if (!$isAdmin) {
                    $result = [
                        'success' => false,
                        'message' => 'Operazione non autorizzata.',
                        'error' => 'Solo gli amministratori possono eliminare client SSO.',
                    ];
                } elseif (!$ssoEnabled) {
                    $result = [
                        'success' => false,
                        'message' => 'SSO non configurato.',
                        'error' => 'Configura SSO_SHARED_SECRET per usare il single sign-on interno.',
                    ];
                } else {
                    $clientRowId = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
                    $result = $ssoService->deleteClient($clientRowId);
                }
                $_SESSION['settings_sso_feedback'] = $result;
            } else {
                $result = [
                    'success' => false,
                    'message' => 'Azione non riconosciuta.',
                ];
            }

            $_SESSION['settings_feedback'] = $result;

            $query = ['page' => 'settings'];
            foreach ($redirectParams as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $query[$key] = $value;
            }

            header('Location: index.php?' . http_build_query($query));
            exit;
        }

        if ($isAdmin && $operatorEditId > 0) {
            $operatorEdit = $userService->findUser($operatorEditId);
            if ($operatorEdit === null) {
                if ($feedback === null) {
                    $feedback = [
                        'success' => false,
                        'message' => 'Operatore non trovato.',
                        'error' => 'Seleziona un operatore valido da modificare.',
                    ];
                }
                $operatorEditId = 0;
                $operatorEditForm = null;
                $operatorsOpenOverride = true;
            }
        }

    $auditPage = isset($_GET['audit_page']) ? max((int) $_GET['audit_page'], 1) : 1;
        $auditPerPage = isset($_GET['audit_per_page']) ? max(5, min((int) $_GET['audit_per_page'], 25)) : 10;
        $auditLogsResult = paginateAuditLogs($pdo, $auditPage, $auditPerPage);
        $buildAuditPageUrl = static function (int $pageNo) use ($auditLogsResult): string {
            $target = max(1, $pageNo);
            $perPage = (int) ($auditLogsResult['pagination']['per_page'] ?? 10);
            $params = [
                'page' => 'settings',
                'audit_page' => $target,
            ];
            if ($perPage !== 10) {
                $params['audit_per_page'] = $perPage;
            }

            return 'index.php?' . http_build_query($params);
        };
        $auditOpen = isset($_GET['audit_page']) || isset($_GET['audit_per_page']) || isset($_GET['audit_open']);

        render('settings', [
            'providerInsights' => $stockMonitorService->getProviderInsights(),
            'stockAlerts' => $stockMonitorService->getOpenAlerts(),
            'feedback' => $feedback,
            'currentUser' => $currentUser,
            'pageTitle' => 'Impostazioni',
            'roles' => $isAdmin ? $userService->getRoles() : [],
            'operators' => $isAdmin ? $userService->listOperators() : [],
            'operatorEdit' => $operatorEdit,
            'operatorEditForm' => $operatorEditForm,
            'operatorsOpen' => $operatorsOpenOverride,
            'fiscalProducts' => $productService->listForFiscalSettings(),
            'fiscalOpen' => $fiscalOpen,
            'discountCampaigns' => $discountCampaignService->listAll(),
            'isAdmin' => $isAdmin,
            'auditLogs' => $auditLogsResult['rows'],
            'auditPagination' => $auditLogsResult['pagination'],
            'buildAuditPageUrl' => $buildAuditPageUrl,
            'auditOpen' => $auditOpen,
            'ssoEnabled' => $ssoEnabled,
            'ssoClients' => $ssoClients,
            'ssoFeedback' => $ssoFeedback,
            'ssoSecretPreview' => $ssoSecretPreview,
            'ssoOpen' => $ssoOpen,
            'ssoTokenTtl' => $ssoService->getTokenTtl(),
        ]);
        break;

    case 'security':
        $userId = isset($currentUser['id']) ? (int) $currentUser['id'] : 0;
        if ($userId <= 0) {
            header('Location: index.php?page=login');
            exit;
        }

        $issuer = $GLOBALS['config']['app']['name'] ?? 'Gestionale Telefonia';
        $securityFeedback = $_SESSION['security_feedback'] ?? null;
        unset($_SESSION['security_feedback']);
        $securityCodes = $_SESSION['security_recovery_codes'] ?? [];
        unset($_SESSION['security_recovery_codes']);

        if ($method === 'POST') {
            $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
            $redirectParams = [];
            $message = null;

            if ($action === 'start_setup') {
                $setupResult = $authController->beginMfaSetup($userId, $issuer);
                if ($setupResult['success'] ?? false) {
                    $message = [
                        'success' => true,
                        'message' => 'Scansiona il QR code e conferma il codice di verifica.',
                    ];
                    $redirectParams['setup'] = 1;
                } else {
                    $message = [
                        'success' => false,
                        'message' => $setupResult['error'] ?? 'Impossibile avviare la configurazione MFA.',
                    ];
                }
            } elseif ($action === 'cancel_setup') {
                $authController->cancelMfaSetup($userId);
                $message = [
                    'success' => true,
                    'message' => 'Configurazione MFA annullata.',
                ];
            } elseif ($action === 'confirm_setup') {
                $code = isset($_POST['mfa_code']) ? (string) $_POST['mfa_code'] : '';
                $setupResult = $authController->confirmMfaSetup($userId, $code);
                if ($setupResult['success'] ?? false) {
                    $_SESSION['security_recovery_codes'] = $setupResult['recovery_codes'] ?? [];
                    $message = [
                        'success' => true,
                        'message' => 'Autenticazione a due fattori attivata correttamente.',
                    ];
                } else {
                    $message = [
                        'success' => false,
                        'message' => $setupResult['error'] ?? 'Impossibile confermare il codice MFA.',
                    ];
                    $redirectParams['setup'] = 1;
                }
            } elseif ($action === 'disable_mfa') {
                $code = isset($_POST['mfa_code']) ? (string) $_POST['mfa_code'] : '';
                $disableResult = $authController->disableMfa($userId, $code, false);
                $message = [
                    'success' => $disableResult['success'] ?? false,
                    'message' => $disableResult['message'] ?? ($disableResult['error'] ?? 'Operazione completata.'),
                ];
            } elseif ($action === 'regenerate_codes') {
                $code = isset($_POST['mfa_code']) ? (string) $_POST['mfa_code'] : '';
                $regenResult = $authController->regenerateRecoveryCodes($userId, $code);
                if ($regenResult['success'] ?? false) {
                    $_SESSION['security_recovery_codes'] = $regenResult['recovery_codes'] ?? [];
                    $message = [
                        'success' => true,
                        'message' => 'Nuovi codici di recupero generati.',
                    ];
                } else {
                    $message = [
                        'success' => false,
                        'message' => $regenResult['error'] ?? 'Impossibile rigenerare i codici di recupero.',
                    ];
                }
            }

            if ($message !== null) {
                $_SESSION['security_feedback'] = $message;
            }

            $query = ['page' => 'security'];
            foreach ($redirectParams as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $query[$key] = $value;
            }

            header('Location: index.php?' . http_build_query($query));
            exit;
        }

        $state = $authController->getSecurityState($userId);
        if ($state === null) {
            $state = ['mfa_enabled' => false, 'mfa_enabled_at' => null];
        }

        $setupData = null;
        if (isset($_GET['setup'])) {
            $setupResult = $authController->getMfaSetupSecret($userId, $issuer);
            if ($setupResult['success'] ?? false) {
                $setupData = $setupResult;
            } else {
                if ($securityFeedback === null) {
                    $securityFeedback = [
                        'success' => false,
                        'message' => $setupResult['error'] ?? 'Impossibile recuperare i dati di configurazione.',
                    ];
                }
            }
        }

        render('security', [
            'currentUser' => $currentUser,
            'pageTitle' => 'Sicurezza account',
            'state' => $state,
            'setupData' => $setupData,
            'feedback' => $securityFeedback,
            'recoveryCodes' => $securityCodes,
            'issuer' => $issuer,
        ]);
        break;

    case 'iccid_list':
        $iccidPage = isset($_GET['page_no']) ? max((int) $_GET['page_no'], 1) : 1;
        $iccidPerPage = 7;
        if (isset($_GET['per_page'])) {
            $requested = max(1, min((int) $_GET['per_page'], 50));
            if ($requested !== $iccidPerPage) {
                $iccidPerPage = $requested;
            }
        }
        $iccidList = $iccidController->listPaginated($iccidPage, $iccidPerPage);

        if ($method === 'GET' && (($_GET['action'] ?? '') === 'refresh' || isAjaxRequest())) {
            jsonResponse([
                'success' => true,
                'payload' => [
                    'rows' => $iccidList['rows'],
                    'pagination' => $iccidList['pagination'],
                ],
            ]);
        }

        render('iccid_list', [
            'stock' => $iccidList['rows'],
            'pagination' => $iccidList['pagination'],
            'currentUser' => $currentUser,
        ]);
        break;

    case 'sales_create':
        $feedbackCreate = $_SESSION['sale_create_feedback'] ?? null;
        $feedbackCancel = $_SESSION['sale_cancel_feedback'] ?? null;
        $feedbackRefund = $_SESSION['sale_refund_feedback'] ?? null;
        $pdaFeedback = $_SESSION['sale_pda_feedback'] ?? null;
        $pdaPrefill = $_SESSION['sale_pda_prefill'] ?? null;
        unset(
            $_SESSION['sale_create_feedback'],
            $_SESSION['sale_cancel_feedback'],
            $_SESSION['sale_refund_feedback'],
            $_SESSION['sale_pda_feedback'],
            $_SESSION['sale_pda_prefill']
        );

        if ($method === 'POST' && ($_POST['action'] ?? '') === 'load_sale_details') {
            $saleId = isset($_POST['sale_id']) ? (int) $_POST['sale_id'] : 0;
            $payload = $salesController->loadSaleForRefund($saleId);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload);
            exit;
        }

        if ($method === 'POST') {
            $action = $_POST['action'] ?? 'create_sale';
            if ($action === 'upload_pda') {
                $result = $pdaImportController->upload($_FILES, $_POST, $currentUser);
                $_SESSION['sale_pda_feedback'] = $result;
                if (($result['success'] ?? false) && isset($result['prefill'])) {
                    $_SESSION['sale_pda_prefill'] = $result['prefill'];
                }
                header('Location: index.php?page=sales_create');
                exit;
            }
            if ($action === 'cancel_sale') {
                $result = $salesController->cancel($currentUser['id'], $_POST);
                $_SESSION['sale_cancel_feedback'] = $result;
                header('Location: index.php?page=sales_create');
                exit;
            }
            if ($action === 'refund_sale') {
                $result = $salesController->refund($currentUser['id'], $_POST);
                $_SESSION['sale_refund_feedback'] = $result;
                header('Location: index.php?page=sales_create');
                exit;
            }

            $feedback = $salesController->create($currentUser['id'], $_POST);
            if (($feedback['success'] ?? false) === true) {
                $_SESSION['sale_create_feedback'] = $feedback;
                header('Location: index.php?page=sales_create&print=' . (int) $feedback['sale_id']);
                exit;
            }

            $_SESSION['sale_create_feedback'] = $feedback;
            header('Location: index.php?page=sales_create');
            exit;
        }

        $availableProvidersRaw = $iccidController->providers();
        $availableProviders = array_values(array_filter(
            $availableProvidersRaw,
            static fn (array $provider): bool => strcasecmp((string) ($provider['name'] ?? ''), 'iliad') !== 0
        ));

        render('sales_create', [
            'availableIccid' => $iccidController->available(),
            'availableOffers' => $offersController->listActive(),
            'availableProducts' => $productController->listActive(),
            'availableCustomers' => $customerController->list(),
            'discountCampaigns' => $discountCampaignService->listActive(),
            'feedbackCreate' => $feedbackCreate,
            'feedbackCancel' => $feedbackCancel,
            'feedbackRefund' => $feedbackRefund,
            'pdaFeedback' => $pdaFeedback,
            'pdaPrefill' => $pdaPrefill,
            'availableProviders' => $availableProviders,
            'currentUser' => $currentUser,
        ]);
        break;

    case 'sales_list':
        $filters = [
            'q' => $_GET['q'] ?? null,
            'status' => $_GET['status'] ?? null,
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
            'payment' => $_GET['payment'] ?? null,
        ];
        $pageNumber = isset($_GET['page_no']) ? max((int) $_GET['page_no'], 1) : 1;
        $perPage = 7;
        if (isset($_GET['per_page'])) {
            $requested = max(1, min((int) $_GET['per_page'], 50));
            if ($requested !== $perPage) {
                $perPage = $requested;
            }
        }

        $list = $salesController->listSales($filters, $pageNumber, $perPage);

        if ($method === 'GET' && (($_GET['action'] ?? '') === 'refresh' || isAjaxRequest())) {
            jsonResponse([
                'success' => true,
                'payload' => [
                    'rows' => formatSalesRowsForJson($list['rows']),
                    'pagination' => $list['pagination'],
                    'filters' => $filters,
                ],
            ]);
        }

        render('sales_list', [
            'sales' => $list['rows'],
            'filters' => $filters,
            'pagination' => $list['pagination'],
            'currentUser' => $currentUser,
            'pageTitle' => 'Storico vendite',
        ]);
        break;

    case 'product_requests':
        $feedbackProductRequests = $_SESSION['product_requests_feedback'] ?? null;
        unset($_SESSION['product_requests_feedback']);

        $filters = [
            'status' => $_GET['status'] ?? null,
            'type' => $_GET['type'] ?? null,
            'payment' => $_GET['payment'] ?? null,
            'q' => $_GET['q'] ?? null,
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
        ];

        $pageNumber = isset($_GET['page_no']) ? max((int) $_GET['page_no'], 1) : 1;
        $perPage = 10;
        if (isset($_GET['per_page'])) {
            $requestedPerPage = max(1, min((int) $_GET['per_page'], 50));
            if ($requestedPerPage !== $perPage) {
                $perPage = $requestedPerPage;
            }
        }

        $requests = $productRequestController->list($filters, $pageNumber, $perPage);
        $summary = $productRequestController->summary();
        $statusOptions = $productRequestController->statusOptions();
        $typeOptions = $productRequestController->typeOptions();
        $paymentOptions = $productRequestController->paymentOptions();

        render('product_requests', [
            'requests' => $requests['rows'],
            'pagination' => $requests['pagination'],
            'filters' => $requests['filters'],
            'summary' => $summary,
            'statusOptions' => $statusOptions,
            'typeOptions' => $typeOptions,
            'paymentOptions' => $paymentOptions,
            'perPage' => $perPage,
            'feedback' => $feedbackProductRequests,
            'currentUser' => $currentUser,
            'pageTitle' => 'Richieste acquisto prodotti',
        ]);
        break;

    case 'product_request':
        if ($method === 'POST') {
            $requestId = isset($_POST['request_id']) ? max((int) $_POST['request_id'], 0) : 0;
            $result = $productRequestController->update($requestId, $_POST, $currentUser ?? []);
            $_SESSION['product_request_feedback'] = $result;
            if (($result['success'] ?? false) === true) {
                $_SESSION['product_requests_feedback'] = $result;
            }

            $backCandidate = isset($_POST['back']) ? (string) $_POST['back'] : '';
            $backUrl = sanitizeInternalUrl($backCandidate, 'index.php?page=product_requests');

            $redirect = 'index.php?page=product_request';
            if ($requestId > 0) {
                $redirect .= '&request_id=' . $requestId;
            }
            if ($backUrl !== 'index.php?page=product_requests') {
                $redirect .= '&back=' . rawurlencode($backUrl);
            }

            header('Location: ' . $redirect);
            exit;
        }

        $feedbackRequest = $_SESSION['product_request_feedback'] ?? null;
        unset($_SESSION['product_request_feedback']);

        $requestId = isset($_GET['request_id']) ? max((int) $_GET['request_id'], 0) : 0;
        if ($requestId <= 0) {
            $_SESSION['product_requests_feedback'] = [
                'success' => false,
                'message' => 'Richiesta non valida.',
                'errors' => ['Seleziona una richiesta esistente per continuare.'],
            ];
            header('Location: index.php?page=product_requests');
            exit;
        }

        $request = $productRequestController->get($requestId);
        if ($request === null) {
            $_SESSION['product_requests_feedback'] = [
                'success' => false,
                'message' => 'Richiesta non trovata.',
                'errors' => ['La richiesta indicata non è più disponibile.'],
            ];
            header('Location: index.php?page=product_requests');
            exit;
        }

        $backCandidate = isset($_GET['back']) ? (string) $_GET['back'] : '';
        $backUrl = sanitizeInternalUrl($backCandidate, 'index.php?page=product_requests');
        $backEncoded = $backUrl === 'index.php?page=product_requests'
            ? ''
            : rawurlencode($backUrl);

        render('product_request_detail', [
            'request' => $request,
            'statusOptions' => $productRequestController->statusOptions(),
            'typeOptions' => $productRequestController->typeOptions(),
            'paymentOptions' => $productRequestController->paymentOptions(),
            'feedback' => $feedbackRequest,
            'backUrl' => $backUrl,
            'backEncoded' => $backEncoded,
            'currentUser' => $currentUser,
            'pageTitle' => 'Richiesta acquisto #' . $requestId,
        ]);
        break;

    case 'support_requests':
        $feedbackSupport = $_SESSION['support_requests_feedback'] ?? null;
        unset($_SESSION['support_requests_feedback']);

        $filters = [
            'status' => $_GET['status'] ?? null,
            'type' => $_GET['type'] ?? null,
            'q' => $_GET['q'] ?? null,
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
        ];

        $pageNumber = isset($_GET['page_no']) ? max((int) $_GET['page_no'], 1) : 1;
        $perPage = 10;
        if (isset($_GET['per_page'])) {
            $requestedPerPage = max(1, min((int) $_GET['per_page'], 50));
            if ($requestedPerPage !== $perPage) {
                $perPage = $requestedPerPage;
            }
        }

        $requests = $supportRequestController->list($filters, $pageNumber, $perPage);
        $summary = $supportRequestController->statusSummary();
        $statusOptions = $supportRequestController->statusOptions();
        $typeOptions = $supportRequestController->typeOptions();

        render('support_requests', [
            'requests' => $requests['rows'],
            'pagination' => $requests['pagination'],
            'filters' => $requests['filters'],
            'summary' => $summary,
            'statusOptions' => $statusOptions,
            'typeOptions' => $typeOptions,
            'perPage' => $perPage,
            'feedback' => $feedbackSupport,
            'currentUser' => $currentUser,
            'pageTitle' => 'Richieste assistenza',
        ]);
        break;

    case 'support_request':
        if ($method === 'POST') {
            $result = $supportRequestController->update($_POST, $currentUser ?? []);
            $_SESSION['support_request_feedback'] = $result;
            if (($result['success'] ?? false) === true) {
                $_SESSION['support_requests_feedback'] = $result;
            }

            $targetId = isset($_POST['request_id']) ? max((int) $_POST['request_id'], 0) : 0;
            $backCandidate = isset($_POST['back']) ? (string) $_POST['back'] : '';
            $backUrl = sanitizeInternalUrl($backCandidate, 'index.php?page=support_requests');

            $redirect = 'index.php?page=support_request';
            if ($targetId > 0) {
                $redirect .= '&request_id=' . $targetId;
            }
            if ($backUrl !== 'index.php?page=support_requests') {
                $redirect .= '&back=' . rawurlencode($backUrl);
            }

            header('Location: ' . $redirect);
            exit;
        }

        $feedbackRequest = $_SESSION['support_request_feedback'] ?? null;
        unset($_SESSION['support_request_feedback']);

        $requestId = isset($_GET['request_id']) ? max((int) $_GET['request_id'], 0) : 0;
        if ($requestId <= 0) {
            $_SESSION['support_requests_feedback'] = [
                'success' => false,
                'message' => 'Richiesta non valida.',
                'errors' => ['Seleziona una richiesta esistente per continuare.'],
            ];
            header('Location: index.php?page=support_requests');
            exit;
        }

        $request = $supportRequestController->find($requestId);
        if ($request === null) {
            $_SESSION['support_requests_feedback'] = [
                'success' => false,
                'message' => 'Richiesta non trovata.',
                'errors' => ['La richiesta indicata non è più disponibile.'],
            ];
            header('Location: index.php?page=support_requests');
            exit;
        }

        $backCandidate = isset($_GET['back']) ? (string) $_GET['back'] : '';
        $backUrl = sanitizeInternalUrl($backCandidate, 'index.php?page=support_requests');
        $backEncoded = $backUrl === 'index.php?page=support_requests'
            ? ''
            : rawurlencode($backUrl);

        render('support_request_detail', [
            'request' => $request,
            'statusOptions' => $supportRequestController->statusOptions(),
            'feedback' => $feedbackRequest,
            'backUrl' => $backUrl,
            'backEncoded' => $backEncoded,
            'currentUser' => $currentUser,
            'pageTitle' => 'Richiesta assistenza #' . $requestId,
        ]);
        break;

    case 'offers':
        $feedback = $_SESSION['offers_feedback'] ?? null;
        unset($_SESSION['offers_feedback']);
        $offersPage = isset($_GET['page_no']) ? max((int) $_GET['page_no'], 1) : 1;
        $offersPerPage = 7;
        $offersSearch = isset($_GET['search']) ? trim((string) $_GET['search']) : '';

        if ($method === 'POST') {
            $action = $_POST['action'] ?? 'save';
            if ($action === 'toggle_status') {
                $offerId = (int) ($_POST['id'] ?? 0);
                $status = $_POST['status'] ?? 'Inactive';
                if ($offerId > 0) {
                    $offersController->setStatus($offerId, $status === 'Active' ? 'Active' : 'Inactive');
                    $_SESSION['offers_feedback'] = [
                        'success' => true,
                        'message' => 'Stato offerta aggiornato.',
                    ];
                }
            } else {
                $result = $offersController->save($_POST);
                if (($result['success'] ?? false) === true) {
                    $result['message'] = isset($_POST['id']) && $_POST['id'] !== ''
                        ? 'Offerta aggiornata.'
                        : 'Offerta creata.';
                }
                $_SESSION['offers_feedback'] = $result;
            }
            $redirectPage = isset($_POST['page_no']) ? max((int) $_POST['page_no'], 1) : $offersPage;
            $redirectSearch = isset($_POST['search_term']) ? trim((string) $_POST['search_term']) : $offersSearch;
            $redirectParams = ['page' => 'offers'];
            if ($redirectPage > 1) {
                $redirectParams['page_no'] = $redirectPage;
            }
            if ($redirectSearch !== '') {
                $redirectParams['search'] = $redirectSearch;
            }
            header('Location: index.php?' . http_build_query($redirectParams));
            exit;
        }

        $editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
        $editOffer = null;
        if ($editId !== null && $editId > 0) {
            $editOffer = $offersController->find($editId);
        }
        $offersList = $offersController->listPaginated($offersPage, $offersPerPage, null, $offersSearch !== '' ? $offersSearch : null);

        render('offers', [
            'offers' => $offersList['rows'],
            'providers' => $offersController->providers(),
            'editOffer' => $editOffer,
            'feedback' => $feedback,
            'currentUser' => $currentUser,
            'pageTitle' => 'Listini & Canvass',
            'pagination' => $offersList['pagination'],
            'search' => $offersSearch,
        ]);
        break;

    case 'notifications':
        if (!isset($systemNotificationService)) {
            http_response_code(503);
            echo 'Servizio notifiche non disponibile';
            exit;
        }

        $pageNo = isset($_GET['page_no']) ? max((int) $_GET['page_no'], 1) : 1;
        $perPage = isset($_GET['per_page']) ? max(5, min((int) $_GET['per_page'], 50)) : 20;
        $focusNotificationId = isset($_GET['focus']) ? max(0, (int) $_GET['focus']) : null;

        $userId = null;
        if (is_array($currentUser) && isset($currentUser['id'])) {
            $userId = (int) $currentUser['id'];
        }

        $feed = $systemNotificationService->getPaginatedFeed($userId, $pageNo, $perPage);

        render('notifications', [
            'notifications' => $feed['items'],
            'pagination' => $feed['pagination'],
            'currentUser' => $currentUser,
            'focusNotificationId' => $focusNotificationId,
            'pageTitle' => 'Notifiche',
        ]);
        break;

    default:
        http_response_code(404);
        echo 'Pagina non trovata';
        break;
}

    function sanitizeInternalUrl(?string $candidate, string $fallback = 'index.php?page=support_requests'): string
    {
        if (!is_string($candidate) || $candidate === '') {
            return $fallback;
        }

        $decoded = rawurldecode($candidate);
        $trimmed = trim($decoded);
        if ($trimmed === '') {
            return $fallback;
        }

        return str_starts_with($trimmed, 'index.php') ? $trimmed : $fallback;
    }

/**
 * @param array<string, mixed> $params
 */
function render(string $view, array $params = [], bool $layout = true): void
{
    $viewPath = __DIR__ . '/../views/' . $view . '.php';
    if (!file_exists($viewPath)) {
        throw new \RuntimeException('View non trovata: ' . $view);
    }

    $sessionToasts = pullFlashToasts();
    if (isset($params['initialToasts']) && is_array($params['initialToasts'])) {
        $params['initialToasts'] = array_merge($sessionToasts, $params['initialToasts']);
    } else {
        $params['initialToasts'] = $sessionToasts;
    }

    if ($layout && !isset($params['topbarNotifications'])) {
        $globalNotificationService = $GLOBALS['systemNotificationService'] ?? null;
        if ($globalNotificationService instanceof \App\Services\SystemNotificationService) {
            $userCandidate = $params['currentUser'] ?? null;
            $userIdForNotifications = null;
            if (is_array($userCandidate) && isset($userCandidate['id'])) {
                $userIdForNotifications = (int) $userCandidate['id'];
            }

            $limit = (int) ($GLOBALS['config']['notifications']['topbar_limit'] ?? 10);
            if ($limit <= 0) {
                $limit = 10;
            }

            $params['topbarNotifications'] = $globalNotificationService->getTopbarFeed($userIdForNotifications, $limit);
        }
    }

    extract($params, EXTR_SKIP);

    if ($layout) {
        ob_start();
        require $viewPath;
        $content = ob_get_clean();
        require __DIR__ . '/../views/layout.php';
    } else {
        require $viewPath;
    }
}

function formatRoleLabel(string $rawRole): string
{
    if ($rawRole === '') {
        return 'Operatore';
    }

    $normalized = str_replace('_', ' ', strtolower($rawRole));

    return ucwords($normalized);
}

/**
 * @return array<int, array<string, string>>
 */
function resolveProfileShortcuts(string $roleKey): array
{
    $map = [
        'admin' => [
            [
                'label' => 'Gestisci operatori',
                'description' => 'Crea o aggiorna gli account del team.',
                'href' => 'index.php?page=settings',
            ],
            [
                'label' => 'Consulta audit',
                'description' => 'Rivedi accessi e modifiche recenti.',
                'href' => 'index.php?page=settings&audit_open=1',
            ],
            [
                'label' => 'Sicurezza account',
                'description' => 'Configura MFA e codici di recupero personali.',
                'href' => 'index.php?page=security',
            ],
        ],
        'cassiere' => [
            [
                'label' => 'Nuova vendita',
                'description' => 'Apri la schermata per registrare una vendita.',
                'href' => 'index.php?page=sales_create',
            ],
            [
                'label' => 'Storico vendite',
                'description' => 'Consulta le operazioni effettuate finora.',
                'href' => 'index.php?page=sales_list',
            ],
            [
                'label' => 'Sicurezza account',
                'description' => 'Configura MFA e codici di recupero personali.',
                'href' => 'index.php?page=security',
            ],
        ],
    ];

    $normalized = strtolower($roleKey);

    return $map[$normalized] ?? [
        [
            'label' => 'Preferenze account',
            'description' => 'Aggiorna le informazioni del tuo profilo.',
            'href' => 'index.php?page=settings',
        ],
        [
            'label' => 'Sicurezza account',
            'description' => 'Configura MFA e codici di recupero personali.',
            'href' => 'index.php?page=security',
        ],
    ];
}

function resolveRoleSummary(string $roleKey): string
{
    return match (strtolower($roleKey)) {
        'admin' => 'Hai accesso completo alla piattaforma e puoi coordinare operatori, stock e reportistica.',
        'cassiere' => 'Gestisci il flusso vendite quotidiano e garantisci la correttezza del magazzino.',
        default => 'Visualizza e gestisci le attività legate al tuo ruolo operativo.',
    };
}

/**
 * @return array{total_sales:int,total_revenue:float,last_sale_at:?string,status_breakdown:array<string,int>}
 */
function buildUserProfileSalesSummary(PDO $pdo, int $userId): array
{
    $summary = [
        'total_sales' => 0,
        'total_revenue' => 0.0,
        'last_sale_at' => null,
        'status_breakdown' => [],
    ];

    if ($userId <= 0) {
        return $summary;
    }

    $completedStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total_sales, COALESCE(SUM(total), 0) AS total_revenue, MAX(created_at) AS last_sale_at
         FROM sales
         WHERE user_id = :uid AND status = 'Completed'"
    );
    $completedStmt->execute([':uid' => $userId]);
    $completedRow = $completedStmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($completedRow)) {
        $summary['total_sales'] = (int) ($completedRow['total_sales'] ?? 0);
        $summary['total_revenue'] = (float) ($completedRow['total_revenue'] ?? 0.0);
        $lastSale = $completedRow['last_sale_at'] ?? null;
        $summary['last_sale_at'] = $lastSale !== null ? (string) $lastSale : null;
    }

    $statusStmt = $pdo->prepare('SELECT status, COUNT(*) AS total FROM sales WHERE user_id = :uid GROUP BY status');
    $statusStmt->execute([':uid' => $userId]);
    while ($statusRow = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($statusRow) || !isset($statusRow['status'])) {
            continue;
        }
        $status = (string) $statusRow['status'];
        $count = (int) ($statusRow['total'] ?? 0);
        $summary['status_breakdown'][$status] = $count;
    }

    return $summary;
}

function getDashboardMetrics(PDO $pdo, string $period = 'day'): array
{
    $bounds = resolvePeriodBounds($period, false);
    $previousBounds = resolvePeriodBounds($period, true);

    $metrics = [
        'period_label' => $period,
        'period_range' => [
            'start' => $bounds['start']->format('Y-m-d H:i:s'),
            'end' => $bounds['end']->format('Y-m-d H:i:s'),
        ],
    ];

    $metrics['iccid_total'] = (int) ($pdo->query('SELECT COUNT(*) FROM iccid_stock')->fetchColumn() ?: 0);
    $metrics['iccid_available'] = (int) ($pdo->query("SELECT COUNT(*) FROM iccid_stock WHERE status = 'InStock'")->fetchColumn() ?: 0);

    $currentSales = aggregateSalesForPeriod($pdo, $bounds);
    $previousSales = aggregateSalesForPeriod($pdo, $previousBounds);

    $metrics['sales_count'] = $currentSales['count'];
    $metrics['revenue_sum'] = $currentSales['revenue'];
    $metrics['sales_today'] = $currentSales['count'];
    $metrics['revenue_today'] = $currentSales['revenue'];
    $metrics['average_ticket'] = $currentSales['count'] > 0 ? $currentSales['revenue'] / max(1, $currentSales['count']) : 0.0;
    $metrics['average_ticket_previous'] = $previousSales['count'] > 0 ? $previousSales['revenue'] / max(1, $previousSales['count']) : 0.0;

    $metrics['comparison'] = buildPeriodComparison($currentSales, $previousSales);

    $metrics['sales_trend'] = buildSalesTrend($pdo, 7);
    $metrics['campaign_performance'] = buildCampaignPerformance($pdo);
    $metrics['recent_events'] = fetchRecentEvents($pdo, 8);
    $metrics['operator_activity'] = fetchOperatorActivity($pdo, 6);

    $supportSummary = fetchSupportSummary($pdo);
    $customerIntelligence = buildCustomerIntelligence($pdo);
    $billingPipeline = fetchBillingPipeline($pdo);
    $forecast = buildSalesForecast($pdo, 7);
    $governance = fetchGovernanceSnapshot($pdo);

    $metrics['support_summary'] = $supportSummary;
    $metrics['customer_intelligence'] = $customerIntelligence;
    $metrics['billing'] = $billingPipeline;
    $metrics['forecast'] = $forecast;
    $metrics['governance'] = $governance;
    $metrics['analytics_overview'] = buildAnalyticsOverview(
        $metrics,
        $supportSummary,
        $customerIntelligence,
        $billingPipeline
    );

    return $metrics;
}

/**
 * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
 */
function resolvePeriodBounds(string $period, bool $previous = false): array
{
    $period = in_array($period, ['day', 'month', 'year'], true) ? $period : 'day';
    $anchor = new DateTimeImmutable('today');

    switch ($period) {
        case 'year':
            $start = $anchor->setDate((int) $anchor->format('Y'), 1, 1);
            if ($previous) {
                $start = $start->sub(new DateInterval('P1Y'));
            }
            $end = $start->setDate((int) $start->format('Y'), 12, 31)->setTime(23, 59, 59);
            break;

        case 'month':
            $start = $anchor->modify('first day of this month');
            if ($previous) {
                $start = $start->sub(new DateInterval('P1M'));
            }
            $start = $start->setTime(0, 0, 0);
            $end = $start->modify('last day of this month')->setTime(23, 59, 59);
            break;

        default:
            $start = $previous ? $anchor->sub(new DateInterval('P1D')) : $anchor;
            $start = $start->setTime(0, 0, 0);
            $end = $start->setTime(23, 59, 59);
            break;
    }

    return [
        'start' => $start,
        'end' => $end,
    ];
}

/**
 * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $bounds
 * @return array{count:int,revenue:float,discount:float,paid:float,balance_due:float}
 */
function aggregateSalesForPeriod(PDO $pdo, array $bounds): array
{
    $stmt = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN status = "Completed" THEN 1 ELSE 0 END) AS sales_count,
            COALESCE(SUM(CASE WHEN status = "Completed" THEN total ELSE 0 END), 0) AS revenue_total,
            COALESCE(SUM(CASE WHEN status = "Completed" THEN discount ELSE 0 END), 0) AS discount_total,
            COALESCE(SUM(CASE WHEN status = "Completed" THEN total_paid ELSE 0 END), 0) AS paid_total,
            COALESCE(SUM(CASE WHEN status = "Completed" THEN balance_due ELSE 0 END), 0) AS balance_due_total
         FROM sales
         WHERE created_at BETWEEN :start AND :end'
    );

    $stmt?->execute([
        ':start' => $bounds['start']->format('Y-m-d H:i:s'),
        ':end' => $bounds['end']->format('Y-m-d H:i:s'),
    ]);

    $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

    return [
        'count' => (int) ($row['sales_count'] ?? 0),
        'revenue' => (float) ($row['revenue_total'] ?? 0.0),
        'discount' => (float) ($row['discount_total'] ?? 0.0),
        'paid' => (float) ($row['paid_total'] ?? 0.0),
        'balance_due' => (float) ($row['balance_due_total'] ?? 0.0),
    ];
}

/**
 * @param array{count:int,revenue:float,discount:float,paid:float,balance_due:float} $current
 * @param array{count:int,revenue:float,discount:float,paid:float,balance_due:float} $previous
 * @return array<string, array<string, int|float|string|null>>
 */
function buildPeriodComparison(array $current, array $previous): array
{
    return [
        'sales' => calculateDelta($current['count'], $previous['count']),
        'revenue' => calculateDelta($current['revenue'], $previous['revenue']),
        'discount' => calculateDelta($current['discount'], $previous['discount']),
        'balance_due' => calculateDelta($current['balance_due'], $previous['balance_due']),
    ];
}

/**
 * @return array{current:float,previous:float,absolute:float,percent:?float,direction:string}
 */
function calculateDelta(float $current, float $previous): array
{
    $absolute = $current - $previous;
    $percent = $previous !== 0.0 ? ($absolute / $previous) * 100 : null;
    $direction = 'flat';
    if ($absolute > 0.0001) {
        $direction = 'up';
    } elseif ($absolute < -0.0001) {
        $direction = 'down';
    }

    return [
        'current' => $current,
        'previous' => $previous,
        'absolute' => $absolute,
        'percent' => $percent,
        'direction' => $direction,
    ];
}

/**
 * @return array<string, mixed>
 */
function fetchSupportSummary(PDO $pdo): array
{
    $statuses = ['Open', 'InProgress', 'Completed', 'Cancelled'];
    $summary = array_fill_keys($statuses, 0);

    $stmt = $pdo->query(
        'SELECT status, COUNT(*) AS total
         FROM customer_support_requests
         GROUP BY status'
    );

    if ($stmt !== false) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $status = $row['status'] ?? null;
            if (is_string($status) && isset($summary[$status])) {
                $summary[$status] = (int) ($row['total'] ?? 0);
            }
        }
    }

    $openBreachesStmt = $pdo->query(
        "SELECT COUNT(*) FROM customer_support_requests
         WHERE status IN ('Open','InProgress')
           AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
    );

    $openBreaches = (int) ($openBreachesStmt !== false ? ($openBreachesStmt->fetchColumn() ?: 0) : 0);

    $openTotal = $summary['Open'] + $summary['InProgress'];

    return [
        'by_status' => $summary,
        'open_total' => $openTotal,
        'breaches' => [
            'open_over_48h' => $openBreaches,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function buildCustomerIntelligence(PDO $pdo): array
{
    $summary = [
        'total_customers' => 0,
        'portal_users' => 0,
        'active_last_30' => 0,
        'new_last_30' => 0,
        'active_portal_last_30' => 0,
    ];

    $summary['total_customers'] = (int) ($pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() ?: 0);
    $summary['portal_users'] = (int) ($pdo->query('SELECT COUNT(*) FROM customer_portal_accounts')->fetchColumn() ?: 0);
    $summary['active_portal_last_30'] = (int) ($pdo->query('SELECT COUNT(*) FROM customer_portal_accounts WHERE last_login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)')->fetchColumn() ?: 0);
    $summary['new_last_30'] = (int) ($pdo->query('SELECT COUNT(*) FROM customers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)')->fetchColumn() ?: 0);

    $activeStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT customer_id)
         FROM sales
         WHERE customer_id IS NOT NULL
           AND status = "Completed"
           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
    );
    $activeStmt?->execute();
    $summary['active_last_30'] = (int) ($activeStmt !== false ? ($activeStmt->fetchColumn() ?: 0) : 0);

    $topCustomersStmt = $pdo->query(
        'SELECT
            COALESCE(c.fullname, s.customer_name, "Cliente" ) AS customer_name,
            c.id AS customer_id,
            COUNT(*) AS orders,
            COALESCE(SUM(s.total), 0) AS revenue,
            MAX(s.created_at) AS last_purchase
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         WHERE s.status = "Completed"
         GROUP BY c.id, customer_name
         ORDER BY revenue DESC
         LIMIT 5'
    );
    $topCustomers = $topCustomersStmt !== false ? $topCustomersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $atRiskStmt = $pdo->query(
        'SELECT
            COALESCE(c.fullname, s.customer_name, "Cliente") AS customer_name,
            MAX(s.created_at) AS last_purchase,
            COUNT(*) AS orders,
            COALESCE(SUM(s.total), 0) AS revenue
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         WHERE s.status = "Completed"
         GROUP BY c.id, customer_name
         HAVING MAX(s.created_at) < DATE_SUB(NOW(), INTERVAL 60 DAY)
         ORDER BY last_purchase ASC
         LIMIT 5'
    );
    $atRisk = $atRiskStmt !== false ? $atRiskStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $recentCustomersStmt = $pdo->query(
        'SELECT fullname, email, created_at
         FROM customers
         ORDER BY created_at DESC
         LIMIT 5'
    );
    $recentCustomers = $recentCustomersStmt !== false ? $recentCustomersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    return [
        'summary' => $summary,
        'top_customers' => $topCustomers,
        'at_risk_customers' => $atRisk,
        'recent_customers' => $recentCustomers,
    ];
}

/**
 * @return array<string, mixed>
 */
function fetchBillingPipeline(PDO $pdo): array
{
    $pendingPaymentsStmt = $pdo->query(
        'SELECT COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount
         FROM customer_payments
         WHERE status = "Pending"'
    );
    $pendingPaymentsRow = $pendingPaymentsStmt !== false ? $pendingPaymentsStmt->fetch(PDO::FETCH_ASSOC) : null;

    $overdueStmt = $pdo->query(
        "SELECT COUNT(*) AS total, COALESCE(SUM(balance_due), 0) AS amount
         FROM sales
         WHERE status = 'Completed'
           AND payment_status IN ('Overdue','Pending','Partial')
           AND due_date IS NOT NULL
           AND due_date < CURRENT_DATE()
           AND balance_due > 0"
    );
    $overdueRow = $overdueStmt !== false ? $overdueStmt->fetch(PDO::FETCH_ASSOC) : null;

    $dueSoonStmt = $pdo->query(
        "SELECT COUNT(*) AS total, COALESCE(SUM(balance_due), 0) AS amount
         FROM sales
         WHERE status = 'Completed'
           AND payment_status IN ('Pending','Partial')
           AND due_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY)
           AND balance_due > 0"
    );
    $dueSoonRow = $dueSoonStmt !== false ? $dueSoonStmt->fetch(PDO::FETCH_ASSOC) : null;

    return [
        'pending_payments' => [
            'count' => (int) ($pendingPaymentsRow['total'] ?? 0),
            'amount' => (float) ($pendingPaymentsRow['amount'] ?? 0.0),
        ],
        'overdue_invoices' => [
            'count' => (int) ($overdueRow['total'] ?? 0),
            'amount' => (float) ($overdueRow['amount'] ?? 0.0),
        ],
        'due_next_7_days' => [
            'count' => (int) ($dueSoonRow['total'] ?? 0),
            'amount' => (float) ($dueSoonRow['amount'] ?? 0.0),
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function buildSalesForecast(PDO $pdo, int $horizonDays = 7): array
{
    $horizonDays = max(1, min($horizonDays, 14));
    $lookbackDays = 28;
    $end = new DateTimeImmutable('today 23:59:59');
    $start = $end->sub(new DateInterval('P' . ($lookbackDays - 1) . 'D'))->setTime(0, 0, 0);

    $stmt = $pdo->prepare(
        'SELECT DATE(created_at) AS day,
                SUM(CASE WHEN status = "Completed" THEN 1 ELSE 0 END) AS sales_count,
                COALESCE(SUM(CASE WHEN status = "Completed" THEN total ELSE 0 END), 0) AS revenue_total
         FROM sales
         WHERE created_at BETWEEN :start AND :end
         GROUP BY DATE(created_at)
         ORDER BY DATE(created_at) ASC'
    );
    $stmt?->execute([
        ':start' => $start->format('Y-m-d H:i:s'),
        ':end' => $end->format('Y-m-d H:i:s'),
    ]);

    $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $map = [];
    foreach ($rows as $row) {
        $map[(string) ($row['day'] ?? '')] = [
            'sales' => (int) ($row['sales_count'] ?? 0),
            'revenue' => (float) ($row['revenue_total'] ?? 0.0),
        ];
    }

    $period = new DatePeriod($start, new DateInterval('P1D'), $end->add(new DateInterval('P1D')));
    $dailySales = [];
    $dailyRevenue = [];
    foreach ($period as $date) {
        $key = $date->format('Y-m-d');
        $entry = $map[$key] ?? ['sales' => 0, 'revenue' => 0.0];
        $dailySales[] = (int) $entry['sales'];
        $dailyRevenue[] = (float) $entry['revenue'];
    }

    $daysCount = count($dailySales);
    $totalSales = array_sum($dailySales);
    $totalRevenue = array_sum($dailyRevenue);
    $avgSales = $daysCount > 0 ? $totalSales / $daysCount : 0.0;
    $avgRevenue = $daysCount > 0 ? $totalRevenue / $daysCount : 0.0;

    $expectedSales = (int) round($avgSales * $horizonDays);
    $expectedRevenue = $avgRevenue * $horizonDays;

    $stdSales = 0.0;
    if ($daysCount > 0) {
        $variance = 0.0;
        foreach ($dailySales as $value) {
            $variance += pow($value - $avgSales, 2);
        }
        $variance /= max($daysCount, 1);
        $stdSales = sqrt($variance);
    }
    $coeff = ($avgSales > 0.0) ? ($stdSales / max($avgSales, 1)) : null;
    $confidence = 'bassa';
    if ($coeff === null) {
        $confidence = $avgSales > 0.0 ? 'media' : 'bassa';
    } elseif ($coeff < 0.35) {
        $confidence = 'alta';
    } elseif ($coeff < 0.6) {
        $confidence = 'media';
    }

    $recentWindow = array_slice($dailySales, -7) ?: [];
    $previousWindow = array_slice($dailySales, -14, 7) ?: [];
    $recentAvg = $recentWindow !== [] ? array_sum($recentWindow) / count($recentWindow) : 0.0;
    $previousAvg = $previousWindow !== [] ? array_sum($previousWindow) / count($previousWindow) : 0.0;
    $trendDirection = calculateDelta($recentAvg, $previousAvg)['direction'];

    return [
        'lookback_days' => $lookbackDays,
        'horizon_days' => $horizonDays,
        'avg_daily_sales' => $avgSales,
        'avg_daily_revenue' => $avgRevenue,
        'expected_sales' => $expectedSales,
        'expected_revenue' => $expectedRevenue,
        'confidence' => $confidence,
        'trend_direction' => $trendDirection,
        'recent_average_sales' => $recentAvg,
    ];
}

/**
 * @return array<string, mixed>
 */
function fetchGovernanceSnapshot(PDO $pdo): array
{
    $activePolicies = (int) ($pdo->query('SELECT COUNT(*) FROM privacy_policies WHERE is_active = 1')->fetchColumn() ?: 0);
    $latestPolicyStmt = $pdo->query(
        'SELECT version, updated_at
         FROM privacy_policies
         ORDER BY updated_at DESC
         LIMIT 1'
    );
    $latestPolicy = $latestPolicyStmt !== false ? $latestPolicyStmt->fetch(PDO::FETCH_ASSOC) : null;

    $portalAccounts = (int) ($pdo->query('SELECT COUNT(*) FROM customer_portal_accounts')->fetchColumn() ?: 0);
    $acceptancesTotal = (int) ($pdo->query('SELECT COUNT(*) FROM privacy_policy_acceptances')->fetchColumn() ?: 0);
    $uniqueAcceptances = (int) ($pdo->query('SELECT COUNT(DISTINCT portal_account_id) FROM privacy_policy_acceptances')->fetchColumn() ?: 0);
    $acceptanceRate = $portalAccounts > 0 ? ($uniqueAcceptances / $portalAccounts) * 100 : null;
    $pendingAcceptances = max(0, $portalAccounts - $uniqueAcceptances);

    $auditLast30 = (int) ($pdo->query('SELECT COUNT(*) FROM audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)')->fetchColumn() ?: 0);

    return [
        'active_policies' => $activePolicies,
        'latest_version' => $latestPolicy['version'] ?? null,
        'last_policy_update' => $latestPolicy['updated_at'] ?? null,
        'portal_accounts' => $portalAccounts,
        'acceptances_total' => $acceptancesTotal,
        'unique_acceptances' => $uniqueAcceptances,
        'acceptance_rate' => $acceptanceRate,
        'pending_acceptances' => $pendingAcceptances,
        'audit_events_last_30' => $auditLast30,
    ];
}

/**
 * @param array<string, mixed> $metrics
 * @param array<string, mixed> $supportSummary
 * @param array<string, mixed> $customerIntelligence
 * @param array<string, mixed> $billing
 * @return array<string, mixed>
 */
function buildAnalyticsOverview(array $metrics, array $supportSummary, array $customerIntelligence, array $billing): array
{
    $cards = [];

    $salesDelta = $metrics['comparison']['sales'] ?? calculateDelta(0.0, 0.0);
    $revenueDelta = $metrics['comparison']['revenue'] ?? calculateDelta(0.0, 0.0);

    $avgTicket = (float) ($metrics['average_ticket'] ?? 0.0);
    $avgTicketPrev = (float) ($metrics['average_ticket_previous'] ?? 0.0);
    $ticketDelta = calculateDelta($avgTicket, $avgTicketPrev);

    $customerSummary = $customerIntelligence['summary'] ?? [];
    $openSupport = (int) ($supportSummary['open_total'] ?? 0);
    $breaches = (int) ($supportSummary['breaches']['open_over_48h'] ?? 0);
    $overdueCount = (int) ($billing['overdue_invoices']['count'] ?? 0);
    $overdueAmount = (float) ($billing['overdue_invoices']['amount'] ?? 0.0);

    $stockTotal = (int) ($metrics['iccid_total'] ?? 0);
    $stockAvailable = (int) ($metrics['iccid_available'] ?? 0);
    $utilization = $stockTotal > 0 ? (1 - ($stockAvailable / max(1, $stockTotal))) * 100 : 0.0;

    $cards[] = [
        'id' => 'sales_volume',
        'label' => 'Vendite completate',
        'value' => (int) ($metrics['sales_count'] ?? 0),
        'meta' => 'Ticket medio € ' . number_format($avgTicket, 2, ',', '.'),
        'delta' => $salesDelta,
        'format' => 'number',
    ];

    $cards[] = [
        'id' => 'revenue_total',
        'label' => 'Fatturato periodo',
        'value' => (float) ($metrics['revenue_sum'] ?? 0.0),
        'meta' => 'Incassato vs precedente: ' . formatDeltaBadgeMeta($revenueDelta),
        'delta' => $revenueDelta,
        'format' => 'currency',
    ];

    $cards[] = [
        'id' => 'average_ticket',
        'label' => 'Ticket medio',
        'value' => $avgTicket,
        'meta' => 'Periodo precedente € ' . number_format($avgTicketPrev, 2, ',', '.'),
        'delta' => $ticketDelta,
        'format' => 'currency',
    ];

    $cards[] = [
        'id' => 'active_customers',
        'label' => 'Clienti attivi 30g',
        'value' => (int) ($customerSummary['active_last_30'] ?? 0),
        'meta' => 'Nuovi clienti 30g: ' . (int) ($customerSummary['new_last_30'] ?? 0),
        'delta' => null,
        'format' => 'number',
    ];

    $cards[] = [
        'id' => 'support_backlog',
        'label' => 'Ticket da evadere',
        'value' => $openSupport,
        'meta' => 'Fuori SLA: ' . $breaches,
        'delta' => null,
        'format' => 'number',
    ];

    $cards[] = [
        'id' => 'billing_risk',
        'label' => 'Partite critiche',
        'value' => $overdueCount,
        'meta' => 'Scaduto € ' . number_format($overdueAmount, 2, ',', '.'),
        'delta' => null,
        'format' => 'number',
    ];

    $cards[] = [
        'id' => 'inventory_health',
        'label' => 'Saturazione stock',
        'value' => $utilization,
        'meta' => 'Disponibili SIM: ' . $stockAvailable,
        'delta' => null,
        'format' => 'percent',
    ];

    return [
        'cards' => array_slice($cards, 0, 6),
    ];
}

/**
 * @param array<string, mixed> $metrics
 * @param array<int, array<string, mixed>> $stockAlerts
 * @param array<int, array<string, mixed>> $productAlerts
 * @param array<int, array<string, mixed>> $providerInsights
 * @param array<int, array<string, mixed>> $productInsights
 * @param array<string, mixed> $campaignPerformance
 * @param array<string, mixed> $supportSummary
 * @param array<string, mixed> $billing
 * @return array<string, mixed>
 */
function buildOperationalPulse(
    array $metrics,
    array $stockAlerts,
    array $productAlerts,
    array $providerInsights,
    array $productInsights,
    array $campaignPerformance,
    array $supportSummary,
    array $billing
): array {
    $expiringCampaigns = [];
    foreach ($campaignPerformance['items'] ?? [] as $item) {
        $days = $item['ends_in_days'] ?? null;
        if (!empty($item['is_active']) && $days !== null && is_numeric($days) && (int) $days >= 0 && (int) $days <= 7) {
            $expiringCampaigns[] = [
                'name' => (string) ($item['name'] ?? ''),
                'days' => (int) $days,
                'revenue' => (float) ($item['revenue_total'] ?? 0.0),
            ];
        }
    }

    usort($expiringCampaigns, static fn(array $a, array $b): int => $a['days'] <=> $b['days']);

    $lowStock = array_filter($providerInsights, static fn(array $info): bool => !empty($info['below_threshold']));
    $lowStockNames = array_map(static fn(array $info): string => (string) ($info['provider_name'] ?? ''), $lowStock);

    $lowStockProducts = array_filter($productInsights, static fn(array $info): bool => !empty($info['below_threshold']));
    $lowStockProductNames = array_map(static fn(array $info): string => (string) ($info['product_name'] ?? ''), $lowStockProducts);

    return [
        'provider_alerts' => $stockAlerts,
        'product_alerts' => $productAlerts,
        'low_stock_providers' => array_slice(array_filter($lowStockNames), 0, 5),
        'low_stock_products' => array_slice(array_filter($lowStockProductNames), 0, 5),
        'expiring_campaigns' => array_slice($expiringCampaigns, 0, 4),
        'recent_events' => $metrics['recent_events'] ?? [],
        'operator_activity' => $metrics['operator_activity'] ?? [],
        'support_summary' => $supportSummary,
        'billing' => $billing,
    ];
}

function formatDeltaBadgeMeta(array $delta): string
{
    if ($delta['percent'] === null) {
        $absolute = (float) ($delta['absolute'] ?? 0.0);
        if (abs($absolute) < 0.01) {
            return 'invariato';
        }
        $symbol = $absolute > 0 ? '+ ' : '- ';
        return $symbol . number_format(abs($absolute), 2, ',', '.');
    }

    $percentValue = (float) $delta['percent'];
    $symbol = $percentValue > 0 ? '+' : '';
    return $symbol . number_format($percentValue, 1, ',', '.') . '%';
}

function buildSalesTrend(PDO $pdo, int $days = 7): array
{
    $days = max(2, min($days, 30));
    $endDate = new DateTimeImmutable('today 23:59:59');
    $startDate = $endDate->sub(new DateInterval('P' . ($days - 1) . 'D'))->setTime(0, 0);

    $stmt = $pdo->prepare(
        'SELECT DATE(created_at) AS day,
                SUM(CASE WHEN status = "Completed" THEN 1 ELSE 0 END) AS sales_count,
                COALESCE(SUM(CASE WHEN status = "Completed" THEN total ELSE 0 END), 0) AS revenue_total
         FROM sales
         WHERE created_at BETWEEN :start AND :end
         GROUP BY DATE(created_at)
         ORDER BY DATE(created_at) ASC'
    );
    $stmt->execute([
        ':start' => $startDate->format('Y-m-d H:i:s'),
        ':end' => $endDate->format('Y-m-d H:i:s'),
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row['day']] = [
            'count' => (int) ($row['sales_count'] ?? 0),
            'revenue' => (float) ($row['revenue_total'] ?? 0.0),
        ];
    }

    $period = new DatePeriod($startDate, new DateInterval('P1D'), $endDate->add(new DateInterval('P1D')));
    $points = [];
    $maxCount = 0;
    $maxRevenue = 0.0;
    $totalCount = 0;
    $totalRevenue = 0.0;

    foreach ($period as $date) {
        $key = $date->format('Y-m-d');
        $value = $map[$key] ?? ['count' => 0, 'revenue' => 0.0];
        $count = (int) $value['count'];
        $revenue = (float) $value['revenue'];
        $maxCount = max($maxCount, $count);
        $maxRevenue = max($maxRevenue, $revenue);
        $totalCount += $count;
        $totalRevenue += $revenue;
        $points[] = [
            'date' => $key,
            'label' => $date->format('d/m'),
            'count' => $count,
            'revenue' => $revenue,
        ];
    }

    if ($maxCount > 0 || $maxRevenue > 0) {
        foreach ($points as &$point) {
            $point['count_pct'] = $maxCount > 0 ? (int) round(($point['count'] / $maxCount) * 100) : 0;
            $point['revenue_pct'] = $maxRevenue > 0 ? (int) round(($point['revenue'] / $maxRevenue) * 100) : 0;
        }
        unset($point);
    }

    return [
        'points' => $points,
        'max_count' => $maxCount,
        'max_revenue' => $maxRevenue,
        'total_count' => $totalCount,
        'total_revenue' => $totalRevenue,
        'start_label' => $startDate->format('d/m'),
        'end_label' => $endDate->format('d/m'),
    ];
}

function buildCampaignPerformance(PDO $pdo): array
{
    $sql = 'SELECT
                dc.id,
                dc.name,
                dc.type,
                dc.value,
                dc.is_active,
                dc.starts_at,
                dc.ends_at,
                SUM(CASE WHEN s.status = "Completed" THEN 1 ELSE 0 END) AS sales_count,
                COALESCE(SUM(CASE WHEN s.status = "Completed" THEN s.total ELSE 0 END), 0) AS revenue_total,
                COALESCE(SUM(CASE WHEN s.status = "Completed" THEN s.discount ELSE 0 END), 0) AS discount_total,
                SUM(CASE WHEN s.status = "Completed" AND DATE(s.created_at) = CURRENT_DATE() THEN 1 ELSE 0 END) AS sales_today
            FROM discount_campaigns dc
            LEFT JOIN sales s ON s.discount_campaign_id = dc.id
            GROUP BY dc.id
            ORDER BY dc.is_active DESC, dc.ends_at IS NULL DESC, dc.ends_at ASC, dc.created_at DESC';

    $stmt = $pdo->query($sql);
    $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $items = [];
    $activeTotal = 0;
    $discountAggregate = 0.0;

    foreach ($rows as $row) {
        $isActive = ((int) ($row['is_active'] ?? 0)) === 1;
        if ($isActive) {
            $activeTotal++;
        }
        $discountAggregate += (float) ($row['discount_total'] ?? 0.0);

        $endsAt = null;
        $daysToEnd = null;
        if (!empty($row['ends_at'])) {
            try {
                $endsAt = new DateTimeImmutable((string) $row['ends_at']);
                $diff = (new DateTimeImmutable('today'))->diff($endsAt);
                $daysToEnd = (int) $diff->format('%r%a');
            } catch (\Throwable $exception) {
                $endsAt = null;
            }
        }

        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'type' => (string) ($row['type'] ?? 'Fixed'),
            'value' => (float) ($row['value'] ?? 0.0),
            'is_active' => $isActive,
            'starts_at' => $row['starts_at'] ?? null,
            'ends_at' => $row['ends_at'] ?? null,
            'ends_in_days' => $daysToEnd,
            'sales_count' => (int) ($row['sales_count'] ?? 0),
            'sales_today' => (int) ($row['sales_today'] ?? 0),
            'revenue_total' => (float) ($row['revenue_total'] ?? 0.0),
            'discount_total' => (float) ($row['discount_total'] ?? 0.0),
        ];
    }

    return [
        'items' => $items,
        'active_total' => $activeTotal,
        'discount_total' => $discountAggregate,
    ];
}

function paginateAuditLogs(PDO $pdo, int $page, int $perPage = 10): array
{
    $page = max(1, $page);
    $perPage = max(5, min($perPage, 25));

    $countStmt = $pdo->query('SELECT COUNT(*) FROM audit_log');
    $total = (int) ($countStmt !== false ? ($countStmt->fetchColumn() ?: 0) : 0);
    $pages = $total > 0 ? (int) ceil($total / $perPage) : 1;
    if ($page > $pages) {
        $page = $pages;
    }

    $rows = [];
    if ($total > 0) {
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare(
            'SELECT al.id, al.action, al.description, al.created_at, u.fullname, u.username
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id
             ORDER BY al.created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        if ($stmt !== false) {
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    $mapped = array_map(static function (array $row): array {
        $user = $row['fullname'] ?? $row['username'] ?? null;
        $action = (string) ($row['action'] ?? '');
        $description = (string) ($row['description'] ?? '');
        $createdAtRaw = (string) ($row['created_at'] ?? '');
        $createdAtDisplay = $createdAtRaw;
        if ($createdAtRaw !== '') {
            try {
                $createdAtDisplay = (new DateTimeImmutable($createdAtRaw))->format('d/m/Y H:i');
            } catch (\Throwable $exception) {
                $createdAtDisplay = $createdAtRaw;
            }
        }

        return [
            'action' => $action,
            'action_label' => formatAuditActionLabel($action, $description),
            'description' => $description,
            'created_at' => $createdAtRaw,
            'created_at_display' => $createdAtDisplay,
            'user' => $user !== null ? (string) $user : null,
        ];
    }, $rows);

    return [
        'rows' => $mapped,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'has_prev' => $page > 1,
            'has_next' => $page < $pages,
        ],
    ];
}

function fetchRecentEvents(PDO $pdo, int $limit = 6): array
{
    $limit = max(1, min($limit, 20));
    $sql = 'SELECT al.action, al.description, al.created_at, u.fullname, u.username
            FROM audit_log al
            LEFT JOIN users u ON u.id = al.user_id
            ORDER BY al.created_at DESC
            LIMIT ' . $limit;
    $stmt = $pdo->query($sql);
    $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    return array_map(static function (array $row): array {
        $user = $row['fullname'] ?? $row['username'] ?? null;
        $actionCode = (string) ($row['action'] ?? '');
        $description = (string) ($row['description'] ?? '');
        return [
            'action' => $actionCode,
            'action_label' => formatAuditActionLabel($actionCode, $description),
            'description' => $description,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'user' => $user !== null ? (string) $user : null,
        ];
    }, $rows);
}

function formatAuditActionLabel(string $action, string $description = ''): string
{
    $map = [
        'sale_create' => 'Vendita registrata',
        'sale_cancel' => 'Vendita annullata',
        'sale_refund' => 'Reso vendita',
    ];

    if (isset($map[$action])) {
        return $map[$action];
    }

    $label = str_replace('_', ' ', trim($action));
    $label = $label !== '' ? ucfirst($label) : ($description !== '' ? $description : 'Aggiornamento');
    return $label;
}

function fetchOperatorActivity(PDO $pdo, int $limit = 5): array
{
    $limit = max(1, min($limit, 20));
    $sql = 'SELECT s.id, s.created_at, s.total, s.discount, s.payment_method, s.status,
                   u.fullname, u.username
            FROM sales s
            LEFT JOIN users u ON u.id = s.user_id
            ORDER BY s.created_at DESC
            LIMIT ' . $limit;
    $stmt = $pdo->query($sql);
    $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    return array_map(static function (array $row): array {
        $user = $row['fullname'] ?? $row['username'] ?? null;
        return [
            'sale_id' => (int) ($row['id'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'total' => (float) ($row['total'] ?? 0.0),
            'discount' => (float) ($row['discount'] ?? 0.0),
            'payment_method' => (string) ($row['payment_method'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'user' => $user !== null ? (string) $user : null,
        ];
    }, $rows);
}

/**
 * @param array<int, array<string, mixed>> $providerInsights
 * @return array<int, array<string, mixed>>
 */
function buildStockRiskSummary(array $providerInsights): array
{
    if ($providerInsights === []) {
        return [];
    }

    $insights = array_map(static function (array $info): array {
        $daysCover = $info['days_cover'] ?? null;
        $daysCover = is_numeric($daysCover) ? (float) $daysCover : null;
        $riskLevel = 'ok';
        if (!empty($info['below_threshold'])) {
            $riskLevel = 'warning';
            if ($daysCover !== null && $daysCover < 3) {
                $riskLevel = 'critical';
            }
        }
        return [
            'provider_name' => (string) ($info['provider_name'] ?? ''),
            'current_stock' => (int) ($info['current_stock'] ?? 0),
            'threshold' => (int) ($info['threshold'] ?? 0),
            'average_daily_sales' => (float) ($info['average_daily_sales'] ?? 0.0),
            'days_cover' => $daysCover,
            'suggested_reorder' => (int) ($info['suggested_reorder'] ?? 0),
            'risk_level' => $riskLevel,
        ];
    }, $providerInsights);

    usort($insights, static function (array $a, array $b): int {
        $rank = ['critical' => 0, 'warning' => 1, 'ok' => 2];
        $riskA = $rank[$a['risk_level']] ?? 2;
        $riskB = $rank[$b['risk_level']] ?? 2;
        if ($riskA !== $riskB) {
            return $riskA <=> $riskB;
        }
        $coverA = $a['days_cover'] ?? PHP_FLOAT_MAX;
        $coverB = $b['days_cover'] ?? PHP_FLOAT_MAX;
        return $coverA <=> $coverB;
    });

    return array_slice($insights, 0, 5);
}

/**
 * @param array<int, array<string, mixed>> $productInsights
 * @return array<int, array<string, mixed>>
 */
function buildProductStockRiskSummary(array $productInsights): array
{
    if ($productInsights === []) {
        return [];
    }

    $insights = array_map(static function (array $info): array {
        $daysCover = $info['days_cover'] ?? null;
        $daysCover = is_numeric($daysCover) ? (float) $daysCover : null;
        $riskLevel = 'ok';
        if (!empty($info['below_threshold'])) {
            $riskLevel = 'warning';
            if ($daysCover !== null && $daysCover < 4) {
                $riskLevel = 'critical';
            }
        }

        return [
            'product_name' => (string) ($info['product_name'] ?? ''),
            'current_stock' => (int) ($info['current_stock'] ?? 0),
            'stock_reserved' => (int) ($info['stock_reserved'] ?? 0),
            'threshold' => (int) ($info['threshold'] ?? 0),
            'average_daily_sales' => (float) ($info['average_daily_sales'] ?? 0.0),
            'days_cover' => $daysCover,
            'suggested_reorder' => (int) ($info['suggested_reorder'] ?? 0),
            'risk_level' => $riskLevel,
        ];
    }, $productInsights);

    usort($insights, static function (array $a, array $b): int {
        $rank = ['critical' => 0, 'warning' => 1, 'ok' => 2];
        $riskA = $rank[$a['risk_level']] ?? 2;
        $riskB = $rank[$b['risk_level']] ?? 2;
        if ($riskA !== $riskB) {
            return $riskA <=> $riskB;
        }
        $coverA = $a['days_cover'] ?? PHP_FLOAT_MAX;
        $coverB = $b['days_cover'] ?? PHP_FLOAT_MAX;
        return $coverA <=> $coverB;
    });

    return array_slice($insights, 0, 5);
}

/**
 * @param array<string, mixed> $metrics
 * @param array<int, array<string, mixed>> $providerInsights
 * @param array<int, array<string, mixed>> $productInsights
 * @param array<string, mixed> $campaignPerformance
 * @param array<int, array<string, mixed>> $stockAlerts
 * @param array<int, array<string, mixed>> $productAlerts
 * @return array<int, array<string, string>>
 */
function buildDashboardNextSteps(
    array $metrics,
    array $providerInsights,
    array $productInsights,
    array $campaignPerformance,
    array $stockAlerts,
    array $productAlerts
): array {
    $steps = [];

    $lowStock = array_filter($providerInsights, static fn(array $info): bool => !empty($info['below_threshold']));
    if ($lowStock !== []) {
        $names = array_map(static fn(array $info): string => (string) ($info['provider_name'] ?? ''), $lowStock);
        $reasonBuckets = [];
        $maxSuggested = 0;
        foreach ($lowStock as $info) {
            if (!empty($info['suggested_reorder'])) {
                $qty = (int) $info['suggested_reorder'];
                if ($qty > $maxSuggested) {
                    $maxSuggested = $qty;
                }
            }

            $cover = $info['days_cover'] ?? null;
            if ($cover !== null && is_numeric($cover) && !isset($reasonBuckets['cover']) && (float) $cover < 2.5) {
                $reasonBuckets['cover'] = 'Copertura inferiore a 3 giorni: crea urgenza con bundle smartphone + SIM.';
            }

            $avg = $info['average_daily_sales'] ?? null;
            if ($avg !== null && is_numeric($avg)) {
                $avgFloat = (float) $avg;
                if ($avgFloat <= 0.05 && !isset($reasonBuckets['no_sales'])) {
                    $reasonBuckets['no_sales'] = 'Zero vendite negli ultimi giorni: coinvolgi il team con promo flash e contest.';
                } elseif ($avgFloat > 0.05 && $avgFloat <= 0.3 && !isset($reasonBuckets['slow_sales'])) {
                    $reasonBuckets['slow_sales'] = 'Flusso lento: combina attivazione + accessorio per riaccendere le vendite.';
                }
            }
        }

        if ($maxSuggested > 0) {
            $reasonBuckets = ['reorder' => 'Riordina almeno ' . $maxSuggested . ' SIM e rilancia mini-incentivi sugli upsell.'] + $reasonBuckets;
        }

        if ($reasonBuckets === []) {
            $reasonBuckets['default'] = 'Accendi il corner operatori con demo live e offerte lampo per spingere le attivazioni.';
        }

        $motivation = implode(' • ', array_slice(array_values($reasonBuckets), 0, 2));
        $steps[] = [
            'label' => 'Riordina SIM per: ' . implode(', ', array_slice($names, 0, 3)),
            'motivation' => $motivation,
            'severity' => 'warning',
        ];
    }

    $criticalCover = array_filter($providerInsights, static function (array $info): bool {
        $days = $info['days_cover'] ?? null;
        return !empty($info['below_threshold']) && $days !== null && is_numeric($days) && (float) $days < 3;
    });
    if ($criticalCover !== []) {
        $names = array_map(static fn(array $info): string => (string) ($info['provider_name'] ?? ''), $criticalCover);
        $steps[] = [
            'label' => 'Copertura sotto i 3 giorni per: ' . implode(', ', array_slice($names, 0, 3)),
            'motivation' => null,
            'severity' => 'critical',
        ];
    }

    $hardwareLowStock = array_filter($productInsights, static fn(array $info): bool => !empty($info['below_threshold']));
    if ($hardwareLowStock !== []) {
        $names = array_map(static fn(array $info): string => (string) ($info['product_name'] ?? ''), $hardwareLowStock);
        $maxSuggested = 0;
        foreach ($hardwareLowStock as $info) {
            $candidate = (int) ($info['suggested_reorder'] ?? 0);
            if ($candidate > $maxSuggested) {
                $maxSuggested = $candidate;
            }
        }

        $motivation = $maxSuggested > 0
            ? 'Pianifica ordine per almeno ' . $maxSuggested . ' pezzi e aggiorna la disponibilità online.'
            : 'Controlla prenotazioni e resi per riallineare lo stock hardware.';

        $steps[] = [
            'label' => 'Prodotti critici: ' . implode(', ', array_slice($names, 0, 3)),
            'motivation' => $motivation,
            'severity' => 'warning',
        ];
    }

    $campaignItems = $campaignPerformance['items'] ?? [];
    $activeCampaigns = array_filter($campaignItems, static fn(array $item): bool => !empty($item['is_active']));
    if ($activeCampaigns === []) {
        $steps[] = [
            'label' => 'Attiva una campagna sconto per stimolare le vendite.',
            'motivation' => null,
            'severity' => 'info',
        ];
    } else {
        $endingSoon = array_filter($activeCampaigns, static function (array $item): bool {
            $days = $item['ends_in_days'] ?? null;
            return $days !== null && is_numeric($days) && (int) $days >= 0 && (int) $days <= 3;
        });
        if ($endingSoon !== []) {
            $names = array_map(static fn(array $item): string => (string) ($item['name'] ?? ''), $endingSoon);
            $steps[] = [
                'label' => 'Campagne in scadenza breve: ' . implode(', ', array_slice($names, 0, 3)),
                'motivation' => null,
                'severity' => 'warning',
            ];
        }
    }

    if ($stockAlerts !== []) {
        $steps[] = [
            'label' => 'Gestisci ' . count($stockAlerts) . ' alert di stock aperti.',
            'motivation' => null,
            'severity' => 'warning',
        ];
    }

    if ($productAlerts !== []) {
        $steps[] = [
            'label' => 'Verifica ' . count($productAlerts) . ' alert hardware.',
            'motivation' => null,
            'severity' => 'warning',
        ];
    }

    $trend = $metrics['sales_trend']['points'] ?? [];
    if (count($trend) >= 2) {
        $last = end($trend);
        $prev = prev($trend);
        if ($last !== false && $prev !== false && ($last['count'] ?? 0) < ($prev['count'] ?? 0)) {
            $steps[] = [
                'label' => 'Vendite in calo rispetto al giorno precedente, valuta una promo mirata.',
                'motivation' => null,
                'severity' => 'info',
            ];
        }
        reset($trend);
    }

    if ($steps === []) {
        $steps[] = [
            'label' => 'Dashboard in ordine: continua a monitorare le performance.',
            'motivation' => null,
            'severity' => 'success',
        ];
    }

    return array_slice($steps, 0, 5);
}

function isAjaxRequest(): bool
{
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return strtolower((string) $requestedWith) === 'xmlhttprequest';
}

/**
 * @return array<mixed>
 */
function getJsonBody(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || $rawBody === '') {
        $cached = [];
        return $cached;
    }

    $decoded = json_decode($rawBody, true);
    $cached = is_array($decoded) ? $decoded : [];
    return $cached;
}

/**
 * @param array<string, mixed> $data
 */
function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function formatSalesRowsForJson(array $rows): array
{
    return array_map(static function (array $sale): array {
        $customer = isset($sale['customer_name']) && $sale['customer_name'] !== null && $sale['customer_name'] !== ''
            ? (string) $sale['customer_name']
            : 'Cliente non specificato';
        $operator = isset($sale['fullname']) && $sale['fullname'] !== null && $sale['fullname'] !== ''
            ? (string) $sale['fullname']
            : (string) ($sale['username'] ?? 'Operatore');

        $statusConfig = match ($sale['status'] ?? null) {
            'Cancelled' => ['label' => 'Annullato', 'class' => 'badge--muted'],
            'Refunded' => ['label' => 'Reso', 'class' => 'badge--warning'],
            default => ['label' => 'Completato', 'class' => 'badge--success'],
        };

        try {
            $created = new DateTimeImmutable((string) ($sale['created_at'] ?? 'now'));
            $createdFormatted = $created->format('d/m/Y H:i');
        } catch (\Exception $exception) {
            $createdFormatted = (string) ($sale['created_at'] ?? '-');
        }

        $id = (int) ($sale['id'] ?? 0);

        return [
            'id' => $id,
            'created_at_display' => $createdFormatted,
            'customer_display' => $customer,
            'operator_display' => $operator,
            'payment_method' => (string) ($sale['payment_method'] ?? ''),
            'total_display' => '€ ' . number_format((float) ($sale['total'] ?? 0), 2, ',', '.'),
            'status_label' => $statusConfig['label'],
            'status_class' => $statusConfig['class'],
            'status_value' => (string) ($sale['status'] ?? ''),
            'print_url' => 'print_receipt.php?sale_id=' . $id,
        ];
    }, $rows);
}
