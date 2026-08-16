<?php
declare(strict_types=1);

if (!empty($_SERVER['SCRIPT_FILENAME']) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/ConfigLoader.php';

/**
 * Tiny wrapper around `scanimage` (SANE + sane-airscan) to scan a document
 * from a network scanner (eSCL/AirScan) — no USB passthrough needed.
 */
class ScanService
{
    public const ALLOWED_RESOLUTIONS = [75, 100, 200, 300, 600, 1200];
    public const ALLOWED_MODES = ['Color', 'Gray'];
    public const ALLOWED_FORMATS = [
        'pdf'  => ['ext' => 'pdf', 'mime' => 'application/pdf'],
        'jpeg' => ['ext' => 'jpg', 'mime' => 'image/jpeg'],
        'png'  => ['ext' => 'png', 'mime' => 'image/png'],
    ];

    private const SANE_CONF_PATH = '/etc/sane.d/airscan.conf';

    /** @var array<string, string> scanner name => eSCL base URL */
    private array $scanners;

    public function __construct(array $config)
    {
        $this->scanners = getValidatedScanners($config);
    }

    /**
     * @return array<string> configured scanner names
     */
    public function listScanners(): array
    {
        return array_keys($this->scanners);
    }

    /**
     * Rewrites /etc/sane.d/airscan.conf from the configured scanner list,
     * only if the content actually changed (avoids needless disk writes on
     * every request).
     */
    private function syncSaneConfig(): bool
    {
        $lines = ["[devices]"];
        foreach ($this->scanners as $name => $url) {
            $lines[] = $name . ' = ' . $url . ', eSCL';
        }
        $lines[] = '[options]';
        $lines[] = 'discovery = disable';
        $content = implode("\n", $lines) . "\n";

        $current = @file_get_contents(self::SANE_CONF_PATH);
        if ($current === $content) {
            return true;
        }
        return @file_put_contents(self::SANE_CONF_PATH, $content) !== false;
    }

    /**
     * Looks up the live SANE device identifier for a configured scanner name
     * by parsing `scanimage -L` (backend-assigned indices like "e0"/"e1" can
     * change, so we resolve by name rather than hardcoding them).
     */
    private function resolveDevice(string $name): ?string
    {
        $lines = [];
        $exit = 0;
        @exec('scanimage -L 2>&1', $lines, $exit);
        foreach ($lines as $line) {
            if (preg_match('/device `(airscan:e\d+:' . preg_quote($name, '/') . ')\'/', $line, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * @return array{success:bool,message:string,path:?string,ext:?string,mime:?string}
     */
    public function scan(string $scannerName, int $resolution, string $mode, string $format): array
    {
        if (!isset($this->scanners[$scannerName])) {
            return ['success' => false, 'message' => 'Unknown scanner', 'path' => null, 'ext' => null, 'mime' => null];
        }
        if (!in_array($resolution, self::ALLOWED_RESOLUTIONS, true)) {
            return ['success' => false, 'message' => 'Invalid resolution', 'path' => null, 'ext' => null, 'mime' => null];
        }
        if (!in_array($mode, self::ALLOWED_MODES, true)) {
            return ['success' => false, 'message' => 'Invalid mode', 'path' => null, 'ext' => null, 'mime' => null];
        }
        if (!isset(self::ALLOWED_FORMATS[$format])) {
            return ['success' => false, 'message' => 'Invalid format', 'path' => null, 'ext' => null, 'mime' => null];
        }

        if (!$this->syncSaneConfig()) {
            return ['success' => false, 'message' => 'Unable to write scanner configuration', 'path' => null, 'ext' => null, 'mime' => null];
        }

        $device = $this->resolveDevice($scannerName);
        if ($device === null) {
            return ['success' => false, 'message' => 'Scanner not reachable', 'path' => null, 'ext' => null, 'mime' => null];
        }

        $ext = self::ALLOWED_FORMATS[$format]['ext'];
        $mime = self::ALLOWED_FORMATS[$format]['mime'];
        $dest = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scan_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

        $cmd = sprintf(
            'scanimage -d %s --format=%s --resolution=%s --mode=%s -o %s 2>&1',
            escapeshellarg($device),
            escapeshellarg($format),
            escapeshellarg((string)$resolution),
            escapeshellarg($mode),
            escapeshellarg($dest)
        );

        $outputLines = [];
        $exitCode = 0;
        exec($cmd, $outputLines, $exitCode);

        if ($exitCode !== 0 || !is_file($dest)) {
            @unlink($dest);
            $output = trim(implode("\n", $outputLines));
            return ['success' => false, 'message' => $output !== '' ? $output : 'scanimage command failed', 'path' => null, 'ext' => null, 'mime' => null];
        }

        error_log(sprintf('WebPrint: scan completed from %s, file=%s', $scannerName, basename($dest)));

        return ['success' => true, 'message' => 'Scan completed', 'path' => $dest, 'ext' => $ext, 'mime' => $mime];
    }
}
