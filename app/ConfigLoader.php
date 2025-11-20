<?php
declare(strict_types=1);

function loadConfig(): array
{
    $primary = __DIR__ . '/config.php';
    $backup  = __DIR__ . '/config.php.example';
    $cfg = [];
    if (is_file($primary)) {
        /** @var array $c */
        $c = require $primary;
        $cfg = $c;
    } elseif (is_file($backup)) {
        /** @var array $c */
        $c = require $backup;
        $cfg = $c;
    }

    $env = [];
    $v = getenv('PRINTER_NAME');
    if ($v !== false && $v !== '') {
        $env['printer_name'] = (string)$v;
    }
    $v = getenv('PRINTERS');
    if ($v !== false && $v !== '') {
        $parts = array_map(static fn($x) => trim((string)$x), explode(',', (string)$v));
        $parts = array_values(array_filter($parts, static fn($x) => $x !== ''));
        if (!empty($parts)) {
            $env['printers'] = $parts;
        }
    }
    $v = getenv('CUPS_SERVER');
    if ($v !== false && $v !== '') {
        $env['cups_server'] = (string)$v;
    }
    $v = getenv('CUPS_PORT');
    if ($v !== false && $v !== '' && ctype_digit((string)$v)) {
        $env['cups_port'] = (int)$v;
    }
    $v = getenv('API_TOKEN');
    if ($v !== false && $v !== '') {
        $env['api_token'] = (string)$v;
    }
    $v = getenv('MAX_FILE_SIZE_MB');
    if ($v !== false && $v !== '' && ctype_digit((string)$v)) {
        $env['max_file_size_mb'] = (int)$v;
    }
    $v = getenv('ALLOWED_MIME_TYPES');
    if ($v !== false && $v !== '') {
        $parts = array_map(static fn($x) => trim((string)$x), explode(',', (string)$v));
        $parts = array_values(array_filter($parts, static fn($x) => $x !== ''));
        if (!empty($parts)) {
            $env['allowed_mime_types'] = $parts;
        }
    }

    return array_merge($cfg, $env);
}
