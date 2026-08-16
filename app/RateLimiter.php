<?php
declare(strict_types=1);

if (!empty($_SERVER['SCRIPT_FILENAME']) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(404);
    exit;
}

/**
 * Tiny file-based fixed-window rate limiter, keyed by client IP.
 * Independent of PHP sessions so it can't be bypassed by dropping the session cookie.
 */
function loginRateLimitCheck(string $ip): array
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webprint_ratelimit';
    $file = $dir . DIRECTORY_SEPARATOR . 'login_' . hash('sha256', $ip) . '.json';

    $lockUntil = 0;
    $raw = @file_get_contents($file);
    if ($raw !== false && $raw !== '') {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            $lockUntil = (int)($data['lockUntil'] ?? 0);
        }
    }

    $now = time();
    if ($lockUntil > $now) {
        return ['locked' => true, 'retryAfter' => $lockUntil - $now];
    }
    return ['locked' => false, 'retryAfter' => 0];
}

function loginRateLimitRecordFailure(string $ip, int $maxAttempts = 5, int $lockSeconds = 300): void
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webprint_ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . DIRECTORY_SEPARATOR . 'login_' . hash('sha256', $ip) . '.json';

    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return;
    }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $attempts = 0;
    $lockUntil = 0;
    if ($raw !== false && $raw !== '') {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            $attempts = (int)($data['attempts'] ?? 0);
            $lockUntil = (int)($data['lockUntil'] ?? 0);
        }
    }
    $now = time();
    if ($lockUntil <= $now) {
        $attempts++;
    }
    if ($attempts >= $maxAttempts) {
        $lockUntil = $now + $lockSeconds;
        $attempts = 0;
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode(['attempts' => $attempts, 'lockUntil' => $lockUntil]));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

function loginRateLimitReset(string $ip): void
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webprint_ratelimit';
    $file = $dir . DIRECTORY_SEPARATOR . 'login_' . hash('sha256', $ip) . '.json';
    @unlink($file);
}
