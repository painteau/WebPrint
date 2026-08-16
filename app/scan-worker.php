<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/ConfigLoader.php';
require_once __DIR__ . '/ScanService.php';
require_once __DIR__ . '/JobStore.php';

/**
 * Best-effort webhook notification, HMAC-signed with api_token so the
 * receiver can verify it really came from this instance. Fire-and-forget:
 * no retry/DLQ, acceptable for an optional convenience notification.
 */
function sendScanWebhook(string $url, array $payload, string $secret): void
{
    if ($secret === '') {
        return;
    }
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return;
    }
    $sig = hash_hmac('sha256', $body, $secret);
    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nX-WebPrint-Signature: sha256=$sig\r\n",
            'content'       => $body,
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
    ]);
    if (@file_get_contents($url, false, $context) === false) {
        error_log('WebPrint: webhook delivery failed for ' . $url);
    }
}

[$jobId, $scannerName, $resolution, $mode, $format, $webhookUrl] = array_pad(array_slice($argv, 1), 6, '');
if ($jobId === '') {
    exit(1);
}

$config = loadConfig();
$service = new ScanService($config);
$result = $service->scan((string)$scannerName, (int)$resolution, (string)$mode, (string)$format);

$status = 'failed';
$message = $result['message'];
$downloadUrl = null;

if ($result['success'] && $result['path'] !== null) {
    $stored = storeScanFile($result['path'], (string)$result['ext']);
    if ($stored !== null) {
        $status = 'done';
        $message = 'Scan enregistré';
        updateJob($jobId, ['status' => $status, 'message' => $message, 'filename' => basename($stored), 'file' => $stored]);
        $downloadUrl = '/download?id=' . $jobId;
    } else {
        $message = 'Scan completed but failed to save file';
        updateJob($jobId, ['status' => 'failed', 'message' => $message]);
    }
} else {
    updateJob($jobId, ['status' => 'failed', 'message' => $message]);
}

if ($webhookUrl !== '') {
    sendScanWebhook((string)$webhookUrl, [
        'job_id'       => $jobId,
        'status'       => $status,
        'message'      => $message,
        'download_url' => $downloadUrl,
    ], (string)($config['api_token'] ?? ''));
}
