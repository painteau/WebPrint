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
        $n = trim((string)$v);
        if (preg_match('/^[A-Za-z0-9._-]+$/', $n)) {
            $env['printer_name'] = $n;
        }
    }
    $v = getenv('PRINTERS');
    if ($v !== false && $v !== '') {
        $parts = array_map(static fn($x) => trim((string)$x), explode(',', (string)$v));
        $parts = array_values(array_filter($parts, static fn($x) => $x !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $x)));
        if (!empty($parts)) {
            $env['printers'] = $parts;
        }
    }
    $v = getenv('CUPS_SERVER');
    if ($v !== false && $v !== '') {
        $h = trim((string)$v);
        if (preg_match('/^[A-Za-z0-9._-]+$/', $h) || filter_var($h, FILTER_VALIDATE_IP)) {
            $env['cups_server'] = $h;
        }
    }
    $v = getenv('CUPS_PORT');
    if ($v !== false && $v !== '' && ctype_digit((string)$v)) {
        $env['cups_port'] = (int)$v;
    }
    $v = getenv('API_TOKEN');
    if ($v !== false && $v !== '') {
        $env['api_token'] = trim((string)$v);
    }
    $v = getenv('MAX_FILE_SIZE_MB');
    if ($v !== false && $v !== '' && ctype_digit((string)$v)) {
        $env['max_file_size_mb'] = (int)$v;
    }
    $v = getenv('ALLOWED_MIME_TYPES');
    if ($v !== false && $v !== '') {
        $parts = array_map(static fn($x) => trim((string)$x), explode(',', (string)$v));
        $parts = array_values(array_filter($parts, static fn($x) => $x !== '' && preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $x)));
        if (!empty($parts)) {
            $env['allowed_mime_types'] = $parts;
        }
    }

    $v = getenv('INDEX_PASSWORD');
    if ($v !== false && $v !== '') {
        $env['index_password'] = (string)$v;
    }

    $v = getenv('SCANNERS');
    if ($v !== false && $v !== '') {
        $scanners = [];
        foreach (explode(',', (string)$v) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$name, $url] = explode('=', $pair, 2);
            $name = trim($name);
            $url = trim($url);
            if (isValidScannerName($name) && isValidScannerUrl($url)) {
                $scanners[$name] = $url;
            }
        }
        if (!empty($scanners)) {
            $env['scanners'] = $scanners;
        }
    }

    return array_merge($cfg, $env);
}

function isValidScannerName(string $name): bool
{
    return $name !== '' && preg_match('/\A[A-Za-z0-9._-]+\z/', $name) === 1;
}

function isValidScannerUrl(string $url): bool
{
    return preg_match('/\Ahttps?:\/\/[A-Za-z0-9.-]+(:\d{1,5})?(\/[A-Za-z0-9\/_.-]*)?\z/', $url) === 1;
}

/**
 * @return array<string, string> scanner name => eSCL base URL
 */
function getValidatedScanners(array $config): array
{
    $raw = $config['scanners'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $name => $url) {
        $name = (string)$name;
        $url = (string)$url;
        if (isValidScannerName($name) && isValidScannerUrl($url)) {
            $out[$name] = $url;
        }
    }
    return $out;
}

/**
 * Refuses to consider api_token safe if left empty, at the documented
 * placeholder value, or too short to resist brute-forcing.
 */
function isWeakApiToken(?string $token): bool
{
    $t = (string)$token;
    return $t === '' || $t === 'CHANGE_ME_SECRET_TOKEN' || strlen($t) < 16;
}
