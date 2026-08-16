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
        $printers = [];
        foreach (explode(',', (string)$v) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (str_contains($part, '=')) {
                [$name, $label] = explode('=', $part, 2);
                $name = trim($name);
                $label = trim($label);
            } else {
                $name = $part;
                $label = $part;
            }
            if (preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
                $printers[$name] = $label;
            }
        }
        if (!empty($printers)) {
            $env['printers'] = $printers;
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

function isValidPrinterName(string $name): bool
{
    return $name !== '' && preg_match('/\A[A-Za-z0-9._-]+\z/', $name) === 1;
}

/**
 * `printers` accepts either a flat list (`['DeskJet_3630']`, label = queue
 * name) or a map of CUPS queue name => display label (`['DeskJet_3630' =>
 * 'Imprimante salon']`) for a friendlier public-facing name than the
 * technical CUPS queue name.
 *
 * @return array<string, string> CUPS queue name => display label
 */
function getValidatedPrinters(array $config): array
{
    $raw = $config['printers'] ?? [];
    $out = [];
    if (is_array($raw)) {
        foreach ($raw as $key => $value) {
            $name = is_int($key) ? (string)$value : (string)$key;
            $label = is_int($key) ? (string)$value : (string)$value;
            if (!isValidPrinterName($name)) {
                continue;
            }
            $label = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $label) ?? '';
            $label = trim($label);
            $label = function_exists('mb_substr') ? mb_substr($label, 0, 60, 'UTF-8') : substr($label, 0, 60);
            $out[$name] = $label !== '' ? $label : $name;
        }
    }
    $defaultName = (string)($config['printer_name'] ?? '');
    if (empty($out) && isValidPrinterName($defaultName)) {
        $out[$defaultName] = $defaultName;
    }
    return $out;
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

function jsonOut(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function getAuthHeader(): ?string
{
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

/**
 * Checks a request's `Authorization: Bearer <token>` header against the
 * configured api_token (constant-time, and only if the token isn't weak).
 */
function isValidBearerAuth(array $config): bool
{
    if (isWeakApiToken($config['api_token'] ?? null)) {
        return false;
    }
    $auth = getAuthHeader();
    if (!$auth || !preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        return false;
    }
    return hash_equals((string)$config['api_token'], $m[1]);
}

/**
 * Callers supply this at request time (unlike the admin-configured
 * `scanners` URLs), so it must accept arbitrary hosts/paths — just check
 * it's a well-formed http(s) URL. The caller is already an authenticated
 * Bearer-token holder (same trust level as the rest of the API), so this
 * isn't hardened against SSRF from a hostile caller.
 */
function isValidWebhookUrl(string $url): bool
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return $scheme === 'http' || $scheme === 'https';
}
