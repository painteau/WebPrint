<?php
declare(strict_types=1);

if (!empty($_SERVER['SCRIPT_FILENAME']) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(404);
    exit;
}

/**
 * Tiny printer service to send PDFs to CUPS using `lp`.
 */
class PrinterService
{
    private array $config;

    public function __construct()
    {
        require_once __DIR__ . '/ConfigLoader.php';
        $this->config = loadConfig();
    }

    /**
     * Validate and print a PDF file via CUPS.
     * @return array{success:bool,message:string,job_id:?(string)}
     */
    public function printPdf(string $filePath): array
    {
        if (!is_file($filePath)) {
            return ['success' => false, 'message' => 'File not found', 'job_id' => null];
        }

        $allowed = $this->config['allowed_mime_types'] ?? ['application/pdf'];
        $maxMb   = (int)($this->config['max_file_size_mb'] ?? 10);
        $maxBytes = $maxMb * 1024 * 1024;

        $size = @filesize($filePath);
        if ($size === false) {
            return ['success' => false, 'message' => 'Unable to read file size', 'job_id' => null];
        }
        if ($size > $maxBytes) {
            return ['success' => false, 'message' => 'File too large', 'job_id' => null];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($filePath);
        if ($mime === false) {
            return ['success' => false, 'message' => 'Unable to detect MIME type', 'job_id' => null];
        }
        if (!in_array($mime, $allowed, true)) {
            return ['success' => false, 'message' => 'Invalid MIME type', 'job_id' => null];
        }

        $printer = (string)($this->config['printer_name'] ?? '');
        $host    = (string)($this->config['cups_server'] ?? 'localhost');
        $port    = (int)($this->config['cups_port'] ?? 631);
        if ($printer === '') {
            return ['success' => false, 'message' => 'Printer name not configured', 'job_id' => null];
        }

        // Build lp command: lp -d <printer> -h <host:port> <file>
        $hostPort = $host . ':' . $port;
        $cmd = sprintf(
            'lp -d %s -h %s %s 2>&1',
            escapeshellarg($printer),
            escapeshellarg($hostPort),
            escapeshellarg($filePath)
        );

        $outputLines = [];
        $exitCode = 0;
        @exec($cmd, $outputLines, $exitCode);
        $output = trim(implode("\n", $outputLines));

        if ($exitCode !== 0) {
            $msg = $output !== '' ? $output : 'lp command failed';
            return ['success' => false, 'message' => $msg, 'job_id' => null];
        }

        // Try to parse job id, e.g. "request id is DeskJet_3630-123 (1 file)"
        $jobIdNum = null;
        if ($output !== '') {
            if (preg_match('/[A-Za-z0-9_\-]+-(\d+)/', $output, $m)) {
                $jobIdNum = $m[1];
            }
        }

        return [
            'success' => true,
            'message' => 'Print job sent',
            'job_id'  => $jobIdNum,
        ];
    }
}
