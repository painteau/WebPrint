<?php
declare(strict_types=1);

if (!empty($_SERVER['SCRIPT_FILENAME']) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(404);
    exit;
}

/**
 * Tiny append-only JSON store for print job history.
 * Kept as a flat file (no DB) since volume is low and app/ is already
 * blocked from direct HTTP access.
 */
const JOBSTORE_MAX_ENTRIES = 300;
const JOBSTORE_STATUS_MAX_AGE = 86400;

function jobStoreFile(): string
{
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/jobs.json';
}

function sanitizeJobText(string $s, int $maxLen): string
{
    $s = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $s) ?? '';
    $s = trim($s);
    if (function_exists('mb_substr')) {
        $s = mb_substr($s, 0, $maxLen, 'UTF-8');
    } elseif (strlen($s) > $maxLen) {
        $s = substr($s, 0, $maxLen);
    }
    return $s;
}

/**
 * @return array<int, array<string, mixed>>
 */
function readJobs(): array
{
    $file = jobStoreFile();
    $fh = @fopen($file, 'r');
    if ($fh === false) {
        return [];
    }
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function writeJobs(array $jobs): void
{
    usort($jobs, static fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
    $jobs = array_slice($jobs, 0, JOBSTORE_MAX_ENTRIES);
    $file = jobStoreFile();
    $fh = @fopen($file, 'c');
    if ($fh === false) {
        return;
    }
    flock($fh, LOCK_EX);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode(array_values($jobs), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

/**
 * @param array{source:string,printer:?string,filename:string,status:string,job_id:?string,message:string} $job
 */
function addJob(array $job): void
{
    $jobs = readJobs();
    $jobs[] = [
        'id'       => bin2hex(random_bytes(6)),
        'ts'       => time(),
        'source'   => sanitizeJobText((string)($job['source'] ?? 'ui'), 10),
        'printer'  => sanitizeJobText((string)($job['printer'] ?? ''), 100),
        'filename' => sanitizeJobText((string)($job['filename'] ?? ''), 150),
        'status'   => sanitizeJobText((string)($job['status'] ?? 'sent'), 20),
        'job_id'   => $job['job_id'] !== null && $job['job_id'] !== '' ? sanitizeJobText((string)$job['job_id'], 20) : null,
        'message'  => sanitizeJobText((string)($job['message'] ?? ''), 300),
    ];
    writeJobs($jobs);
}

/**
 * Best-effort refresh: jobs still marked "sent" (accepted by CUPS) are checked
 * against the current CUPS queue in a single lpstat call; if no longer queued
 * they're assumed completed. Jobs older than JOBSTORE_STATUS_MAX_AGE are left
 * alone (CUPS has usually already purged them from the queue either way).
 */
function refreshQueuedStatuses(array $jobs, string $host, int $port): array
{
    $toCheck = array_filter($jobs, static function (array $j) {
        return ($j['status'] ?? '') === 'sent'
            && !empty($j['job_id'])
            && !empty($j['printer'])
            && (time() - (int)($j['ts'] ?? 0)) < JOBSTORE_STATUS_MAX_AGE;
    });
    if (empty($toCheck)) {
        return $jobs;
    }

    $hostPort = $host . ':' . $port;
    $cmd = sprintf('lpstat -h %s -o 2>&1', escapeshellarg($hostPort));
    $lines = [];
    $exit = 0;
    @exec($cmd, $lines, $exit);
    if ($exit !== 0) {
        return $jobs;
    }
    $pending = [];
    foreach ($lines as $line) {
        if (preg_match('/^(\S+)-(\d+)\s/', $line, $m)) {
            $pending[$m[1] . '-' . $m[2]] = true;
        }
    }

    $changed = false;
    foreach ($jobs as $idx => $j) {
        if (($j['status'] ?? '') !== 'sent' || empty($j['job_id']) || empty($j['printer'])) {
            continue;
        }
        if ((time() - (int)($j['ts'] ?? 0)) >= JOBSTORE_STATUS_MAX_AGE) {
            continue;
        }
        $key = $j['printer'] . '-' . $j['job_id'];
        $jobs[$idx]['status'] = isset($pending[$key]) ? 'queued' : 'done';
        $changed = true;
    }

    if ($changed) {
        writeJobs($jobs);
    }
    return $jobs;
}

/**
 * @return array<int, array<string, mixed>>
 */
function listJobs(int $limit, string $host, int $port): array
{
    $jobs = readJobs();
    $jobs = refreshQueuedStatuses($jobs, $host, $port);
    usort($jobs, static fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
    return array_slice($jobs, 0, $limit);
}
