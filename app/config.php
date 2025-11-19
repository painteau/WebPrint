<?php
declare(strict_types=1);

$sf = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (($sf && realpath(__FILE__) === realpath($sf)) || (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__))) {
    http_response_code(404);
    exit;
}

// Configuration for printer and API
return [
    'printer_name'       => 'DeskJet_3630',
    'cups_server'        => 'localhost',
    'cups_port'          => 631,
    'api_token'          => 'CHANGE_ME_SECRET_TOKEN',
    'max_file_size_mb'   => 20,
    'allowed_mime_types' => ['application/pdf'],
];
