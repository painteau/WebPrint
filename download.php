<?php
declare(strict_types=1);

require_once __DIR__ . '/app/ConfigLoader.php';
require_once __DIR__ . '/app/JobStore.php';
$config = loadConfig();
$isHttps = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443'));
if (PHP_SESSION_ACTIVE !== session_status()) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
$pwd = (string)($config['index_password'] ?? '');
$sessionAuthed = $pwd === '' || (isset($_SESSION['index_auth']) && $_SESSION['index_auth'] === true);
if (!$sessionAuthed) {
    if (getAuthHeader() !== null) {
        // Looks like an API call (Authorization header present) — respond
        // with a plain 401 instead of redirecting to the login page.
        if (!isValidBearerAuth($config)) {
            http_response_code(401);
            echo 'Unauthorized';
            exit;
        }
    } else {
        header('Location: index');
        exit;
    }
}

$id = (string)($_GET['id'] ?? '');
$job = findJobById($id);
if ($job === null || ($job['type'] ?? '') !== 'scan' || ($job['status'] ?? '') !== 'done' || empty($job['file'])) {
    http_response_code(404);
    header('X-Content-Type-Options: nosniff');
    echo 'Not found';
    exit;
}

$path = scansDir() . '/' . basename((string)$job['file']);
if (!is_file($path)) {
    http_response_code(404);
    header('X-Content-Type-Options: nosniff');
    echo 'Not found';
    exit;
}

$ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'pdf'         => 'application/pdf',
    'jpg', 'jpeg' => 'image/jpeg',
    'png'         => 'image/png',
    default       => 'application/octet-stream',
};
$downloadName = basename((string)$job['file']);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'");
readfile($path);
