# 🖨️ WebPrint — Tiny PDF Printing via CUPS

Minimal PHP app to upload and print PDFs over your local network using CUPS (`lp`). Built for Raspberry Pi Zero (DietPi) with Apache2 + PHP 8.

## ⚙️ Prerequisites
- 🐧 Apache2 + PHP 8 installed and running
- 🧰 CUPS installed and configured
- 🖨️ A working CUPS printer (e.g. `lp -d DeskJet_3630 file.pdf` works)

## 🔧 Configuration
- Copy the example to a real config:
  - `cp app/config.php.example app/config.php`
- Edit `app/config.php` and set:
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
    'max_file_size_mb'   => 20,
    'allowed_mime_types' => ['application/pdf'],
];
```

## 🌐 Web UI
- URL: `http://<pi-host-or-ip>/index`
- Action: upload a PDF and click “Imprimer”
- Feedback: shows success/error and job ID when available

## 🔐 HTTP API
- Method: `POST`
- URL: `http://<pi-host-or-ip>/api`
- Auth: `Authorization: Bearer <token>` (matches `api_token`)
- Request: `multipart/form-data` with one file field named `file`
- Response (JSON):
  - ✅ Success: `{"success": true, "message": "Print job sent", "job_id": "123"}`
  - ❌ Error: `{"success": false, "message": "Error description"}`

### 🧪 cURL Example
```bash
curl -X POST \
  -H "Authorization: Bearer CHANGE_ME_SECRET_TOKEN" \
  -F "file=@/path/to/document.pdf" \
  http://<pi-host-or-ip>/api
```

## 🚀 Enable Clean URLs
- Ensure Apache rewrite is enabled and `.htaccess` is honored:
  - `sudo a2enmod rewrite && sudo systemctl restart apache2`
  - In your vhost: `AllowOverride All` for the document root

## 🛡️ Security & Robustness
- `app/` code is blocked from direct HTTP access
- `app/config.php` is git-ignored so you can safely adjust secrets on the server
- Strict MIME check with `finfo` (`application/pdf` only)
- Max file size enforced from config
- All `lp` arguments escaped via `escapeshellarg()`

## 📝 Notes
- No DB, no sessions, no external dependencies
- Dark mode UI with responsive centered layout
