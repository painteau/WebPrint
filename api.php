<?php
declare(strict_types=1);

require_once __DIR__ . '/app/ConfigLoader.php';
$isHttps = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443'));
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'");
$config = loadConfig();

if (isWeakApiToken($config['api_token'] ?? null)) {
    error_log('WebPrint: refusing to serve /api, api_token is empty/default/too short');
    jsonOut(503, ['success' => false, 'message' => 'Service misconfigured']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(405, ['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isValidBearerAuth($config)) {
    jsonOut(401, ['success' => false, 'message' => 'Missing or invalid token']);
    exit;
}

$printers = [];
$defaultPrinter = (string)($config['printer_name'] ?? '');
if (isset($config['printers']) && is_array($config['printers'])) {
    $printers = array_values(array_filter(array_map('strval', $config['printers']), static fn($p) => $p !== ''));
}
if (empty($printers) && $defaultPrinter !== '') {
    $printers = [$defaultPrinter];
}
$selectedPrinter = $defaultPrinter;
if (isset($_POST['printer'])) {
    $p = (string)$_POST['printer'];
    if ($p !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $p) && in_array($p, $printers, true)) {
        $selectedPrinter = $p;
    } else {
        jsonOut(400, ['success' => false, 'message' => 'Unknown printer']);
        exit;
    }
}
if ($selectedPrinter === '') {
    jsonOut(400, ['success' => false, 'message' => 'Printer not configured']);
    exit;
}

if (!isset($_FILES['file'])) {
    jsonOut(400, ['success' => false, 'message' => 'No file uploaded']);
    exit;
}

require_once __DIR__ . '/app/UploadHandler.php';
require_once __DIR__ . '/app/JobStore.php';
$originalName = sanitizeJobText((string)($_FILES['file']['name'] ?? ''), 150);
$upload = handleUpload($_FILES['file'], $config);
if (!$upload['ok']) {
    $httpStatus = match($upload['error']) {
        'Upload error'           => 400,
        'File too large'         => 413,
        'Unsupported media type' => 415,
        default                  => 500,
    };
    addJob([
        'source'   => 'api',
        'printer'  => $selectedPrinter,
        'filename' => $originalName,
        'status'   => 'rejected',
        'job_id'   => null,
        'message'  => $upload['error'],
    ]);
    jsonOut($httpStatus, ['success' => false, 'message' => $upload['error']]);
    exit;
}
$dest = $upload['path'];

require_once __DIR__ . '/app/PrinterService.php';
$service = new PrinterService();
$result = $service->printPdf($dest, $selectedPrinter);
if (!@unlink($dest)) {
    error_log('WebPrint: failed to delete tmp file ' . $dest);
}

addJob([
    'source'   => 'api',
    'printer'  => $selectedPrinter,
    'filename' => $originalName,
    'status'   => $result['success'] ? 'sent' : 'failed',
    'job_id'   => $result['job_id'],
    'message'  => $result['message'],
]);

if (!$result['success']) {
    jsonOut(502, ['success' => false, 'message' => $result['message']]);
    exit;
}

jsonOut(200, [
    'success' => true,
    'message' => $result['message'],
    'job_id'  => $result['job_id'],
]);
