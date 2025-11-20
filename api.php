<?php
declare(strict_types=1);

require_once __DIR__ . '/app/ConfigLoader.php';
$config = loadConfig();

function jsonOut(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function getAuthHeader(): ?string {
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return (string)$_SERVER['HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                return (string)$v;
            }
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(405, ['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$auth = getAuthHeader();
if (!$auth || !preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
    jsonOut(401, ['success' => false, 'message' => 'Missing or invalid token']);
    exit;
}
$token = $m[1];
if (!hash_equals((string)$config['api_token'], $token)) {
    jsonOut(401, ['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_FILES['file'])) {
    jsonOut(400, ['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    jsonOut(400, ['success' => false, 'message' => 'Upload error']);
    exit;
}

$maxMb = (int)($config['max_file_size_mb'] ?? 10);
$maxBytes = $maxMb * 1024 * 1024;
if (($file['size'] ?? 0) > $maxBytes) {
    jsonOut(413, ['success' => false, 'message' => 'File too large']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowed = $config['allowed_mime_types'] ?? ['application/pdf'];
if ($mime === false || !in_array($mime, $allowed, true)) {
    jsonOut(415, ['success' => false, 'message' => 'Unsupported media type']);
    exit;
}

$tmpDir = sys_get_temp_dir();
$map = [
    'application/pdf' => '.pdf',
    'application/postscript' => '.ps',
    'image/jpeg' => '.jpg',
    'image/png' => '.png',
    'image/tiff' => '.tiff',
    'text/plain' => '.txt',
    'image/pwg-raster' => '.pwg',
    'image/urf' => '.urf',
];
$ext = $map[$mime] ?? '.bin';
$tmpName = 'print_' . time() . '_' . bin2hex(random_bytes(4)) . $ext;
$dest = $tmpDir . DIRECTORY_SEPARATOR . $tmpName;
if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    jsonOut(500, ['success' => false, 'message' => 'Failed to store temporary file']);
    exit;
}

require_once __DIR__ . '/app/PrinterService.php';
$service = new PrinterService();
$result = $service->printPdf($dest);
@unlink($dest);

if (!$result['success']) {
    jsonOut(502, ['success' => false, 'message' => $result['message']]);
    exit;
}

jsonOut(200, [
    'success' => true,
    'message' => $result['message'],
    'job_id'  => $result['job_id'],
]);
