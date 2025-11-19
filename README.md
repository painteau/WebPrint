# WebPrint (Tiny PDF printing via CUPS)

A minimal PHP project to upload and print PDF files on a local network using CUPS (`lp`). Designed for Raspberry Pi Zero (DietPi) with Apache2 and PHP 8.

## Prerequisites
- Apache2 and PHP 8 installed and running
- CUPS installed and configured
- A working printer in CUPS (e.g., you can run: `lp -d DeskJet_3630 file.pdf`)

## Configuration
Edit `config.php` and set:
- `printer_name`: CUPS printer name
- `cups_server`: usually `localhost`
- `cups_port`: usually `631`
- `api_token`: secret token for the API (change it!)
- `max_file_size_mb`: maximum allowed upload size (MB)
- `allowed_mime_types`: keep as `['application/pdf']`

Example:
```php
return [
    'printer_name'       => 'DeskJet_3630',
    'cups_server'        => 'localhost',
    'cups_port'          => 631,
    'api_token'          => 'CHANGE_ME_SECRET_TOKEN',
    'max_file_size_mb'   => 10,
    'allowed_mime_types' => ['application/pdf'],
];
```

## Web UI
- Access the UI at `http://<pi-host-or-ip>/index.php`
- Upload a PDF and click “Imprimer”
- The result message shows success or error (and job ID if available)

## HTTP API
- Endpoint: `POST http://<pi-host-or-ip>/api.php`
- Auth: `Authorization: Bearer <token>` (must match `api_token` in `config.php`)
- Request: `multipart/form-data` with one file field named `file`
- Response (JSON):
  - Success: `{"success": true, "message": "Print job sent", "job_id": "123"}`
  - Error: `{"success": false, "message": "Error description"}`

### cURL example
```bash
curl -X POST \
  -H "Authorization: Bearer CHANGE_ME_SECRET_TOKEN" \
  -F "file=@/path/to/document.pdf" \
  http://<pi-host-or-ip>/api.php
```

## Notes
- No database, sessions, or external dependencies
- Strictly validates MIME type using `finfo`
- Enforces file size limits
- Uses `escapeshellarg()` for all `lp` arguments