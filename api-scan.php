<?php
declare(strict_types=1);

require_once __DIR__ . '/app/ConfigLoader.php';
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'");
$config = loadConfig();

if (isWeakApiToken($config['api_token'] ?? null)) {
    error_log('WebPrint: refusing to serve /api-scan, api_token is empty/default/too short');
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

require_once __DIR__ . '/app/ScanService.php';
require_once __DIR__ . '/app/JobStore.php';
$scanners = getValidatedScanners($config);
$scannerNames = array_keys($scanners);

if (empty($scannerNames)) {
    jsonOut(503, ['success' => false, 'message' => 'No scanner configured']);
    exit;
}

$scannerName = isset($_POST['scanner']) ? (string)$_POST['scanner'] : $scannerNames[0];
$resolution = isset($_POST['resolution']) ? (int)$_POST['resolution'] : 300;
$mode = isset($_POST['mode']) ? (string)$_POST['mode'] : 'Color';
$format = isset($_POST['format']) ? (string)$_POST['format'] : 'pdf';
$webhookUrl = isset($_POST['webhook_url']) ? trim((string)$_POST['webhook_url']) : '';

if (strlen($scannerName) > 100 || strlen($mode) > 20 || strlen($format) > 20 || strlen($webhookUrl) > 2048) {
    jsonOut(400, ['success' => false, 'message' => 'Field too long']);
    exit;
}

if (!in_array($scannerName, $scannerNames, true)) {
    jsonOut(400, ['success' => false, 'message' => 'Unknown scanner']);
    exit;
}
if (!in_array($resolution, ScanService::ALLOWED_RESOLUTIONS, true)) {
    jsonOut(400, ['success' => false, 'message' => 'Invalid resolution']);
    exit;
}
if (!in_array($mode, ScanService::ALLOWED_MODES, true)) {
    jsonOut(400, ['success' => false, 'message' => 'Invalid mode']);
    exit;
}
if (!isset(ScanService::ALLOWED_FORMATS[$format])) {
    jsonOut(400, ['success' => false, 'message' => 'Invalid format']);
    exit;
}
if ($webhookUrl !== '' && !isValidWebhookUrl($webhookUrl)) {
    jsonOut(400, ['success' => false, 'message' => 'Invalid webhook_url']);
    exit;
}

// The scan itself runs in a detached background process (can take well over
// a minute at high resolution) — respond immediately with a job id, the
// caller polls /api-status or waits for the optional webhook.
$jobId = addJob([
    'type'     => 'scan',
    'source'   => 'api',
    'printer'  => $scannerName,
    'filename' => '',
    'status'   => 'scanning',
    'job_id'   => null,
    'message'  => 'Scan en cours…',
    'file'     => null,
]);

// PHP_BINARY is empty under the Apache/mod_php SAPI (unlike CLI) — PHP_BINDIR
// is a compile-time constant and stays correct regardless of SAPI.
$phpCli = PHP_BINDIR . '/php';
$cmd = sprintf(
    '%s %s %s %s %s %s %s %s > /dev/null 2>&1 &',
    escapeshellarg($phpCli),
    escapeshellarg(__DIR__ . '/app/scan-worker.php'),
    escapeshellarg($jobId),
    escapeshellarg($scannerName),
    escapeshellarg((string)$resolution),
    escapeshellarg($mode),
    escapeshellarg($format),
    escapeshellarg($webhookUrl)
);
exec($cmd);

jsonOut(202, [
    'success'    => true,
    'message'    => 'Scan started',
    'job_id'     => $jobId,
    'status'     => 'scanning',
    'status_url' => '/api-status?id=' . $jobId,
]);
