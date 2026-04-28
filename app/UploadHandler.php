<?php
declare(strict_types=1);

if (!empty($_SERVER['SCRIPT_FILENAME']) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(404);
    exit;
}

/**
 * Validates an uploaded file and moves it to a secure temp path.
 *
 * @param array $file     Entry from $_FILES['file']
 * @param array $config   App config (allowed_mime_types, max_file_size_mb)
 * @return array{ok:bool, error:?string, path:?string}
 */
function handleUpload(array $file, array $config): array
{
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload error', 'path' => null];
    }

    $maxBytes = (int)($config['max_file_size_mb'] ?? 10) * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'File too large', 'path' => null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = $config['allowed_mime_types'] ?? ['application/pdf'];
    if ($mime === false || !in_array($mime, $allowed, true)) {
        return ['ok' => false, 'error' => 'Unsupported media type', 'path' => null];
    }

    static $extMap = [
        'application/pdf'        => '.pdf',
        'application/postscript' => '.ps',
        'image/jpeg'             => '.jpg',
        'image/png'              => '.png',
        'image/tiff'             => '.tiff',
        'text/plain'             => '.txt',
        'image/pwg-raster'       => '.pwg',
        'image/urf'              => '.urf',
    ];
    $ext  = $extMap[$mime] ?? '.bin';
    $dest = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'print_' . time() . '_' . bin2hex(random_bytes(4)) . $ext;

    if (!is_uploaded_file($file['tmp_name']) || !@move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Failed to store temporary file', 'path' => null];
    }

    return ['ok' => true, 'error' => null, 'path' => $dest];
}
