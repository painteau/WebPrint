<?php
declare(strict_types=1);

require_once __DIR__ . '/app/ConfigLoader.php';
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'");
$config = loadConfig();

if (isWeakApiToken($config['api_token'] ?? null)) {
    jsonOut(503, ['success' => false, 'message' => 'Service misconfigured']);
    exit;
}

if (!isValidBearerAuth($config)) {
    jsonOut(401, ['success' => false, 'message' => 'Missing or invalid token']);
    exit;
}

require_once __DIR__ . '/app/JobStore.php';
$id = (string)($_GET['id'] ?? '');
$job = findJobById($id);
if ($job === null || ($job['type'] ?? '') !== 'scan') {
    jsonOut(404, ['success' => false, 'message' => 'Not found']);
    exit;
}

$status = (string)($job['status'] ?? '');
jsonOut(200, [
    'success'      => true,
    'job_id'       => $job['id'],
    'status'       => $status,
    'message'      => $job['message'] ?? '',
    'download_url' => ($status === 'done' && !empty($job['file'])) ? '/download?id=' . $job['id'] : null,
]);
