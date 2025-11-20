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
  - `printers`: optional array of printer names for UI selection
  - `cups_server`: usually `localhost`
  - `cups_port`: usually `631`
  - `api_token`: secret token for the API (change it!)
  - `max_file_size_mb`: maximum allowed upload size (MB)
  - `allowed_mime_types`: array of allowed MIME types (e.g. `['application/pdf','image/png']`)
  - `index_password`: optional UI password; if non-empty, `/index` requires login
  - Note: environment variables override values from this file when present

Example:
```php
return [
    'printer_name'       => 'DeskJet_3630',
    'printers'           => ['DeskJet_3630', 'OfficeLaser'],
    'cups_server'        => 'localhost',
    'cups_port'          => 631,
    'api_token'          => 'CHANGE_ME_SECRET_TOKEN',
    'max_file_size_mb'   => 20,
    'allowed_mime_types' => [
        'application/pdf',
        'application/postscript',
        'image/jpeg',
        'image/png',
        'image/tiff',
        'text/plain',
        'image/pwg-raster',
        'image/urf',
    ],
    'index_password'     => '',
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
- Optional: `printer` field to target a specific printer from config/env
- Invalid `printer` value returns `400`.
- Response (JSON):
  - ✅ Success: `{"success": true, "message": "Print job sent", "job_id": "123"}`
  - ❌ Error: `{"success": false, "message": "Error description"}`

### 🧪 cURL Example
```bash
curl -X POST \
  -H "Authorization: Bearer CHANGE_ME_SECRET_TOKEN" \
  -F "printer=DeskJet_3630" \
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
- Strict MIME check with `finfo` (default `application/pdf`)
- Max file size enforced from config
- All `lp` arguments escaped via `escapeshellarg()`
- Web UI security:
  - Optional password via `INDEX_PASSWORD` (session-based)
  - CSRF tokens on login and print forms
  - Security headers (CSP, nosniff, frame deny)

## 📝 Notes
- No DB, no sessions, no external dependencies
- Dark mode UI with responsive centered layout

## 🐳 Docker Usage
- Image: `ghcr.io/painteau/webprint` (multi-arch: `linux/amd64`, `linux/arm64`, `linux/arm/v6`)
- CUPS server must run on the host. Configure host address via env or config file.

### Environment Variables
- `PRINTER_NAME`: CUPS printer name (ex: `DeskJet_3630`)
- `PRINTERS`: comma-separated printers list (ex: `DeskJet_3630,OfficeLaser`)
- `CUPS_SERVER`: CUPS host (Docker Desktop: `host.docker.internal`, Linux: host IP)
- `CUPS_PORT`: CUPS port (default `631`)
- `API_TOKEN`: token for HTTP API auth
- `MAX_FILE_SIZE_MB`: max upload size in MB (default `20`)
- `ALLOWED_MIME_TYPES`: comma-separated list (ex: `application/pdf,image/png`)
- `INDEX_PASSWORD`: protect Web UI (`/index`) with a password prompt
- Precedence: env overrides values from `app/config.php` (if present) or `app/config.php.example`.
- Validation: printer names use `[A-Za-z0-9._-]`; MIME types must be `type/subtype`.

### Run Examples
- Linux/macOS:
  - `docker run -d --name webprint -p 8081:80 --restart unless-stopped ghcr.io/painteau/webprint:latest`
  - With env: `docker run -d --name webprint -p 8081:80 --restart unless-stopped -e PRINTER_NAME=DeskJet_3630 -e CUPS_SERVER=host.docker.internal -e CUPS_PORT=631 -e API_TOKEN=CHANGE_ME_SECRET_TOKEN -e MAX_FILE_SIZE_MB=20 -e ALLOWED_MIME_TYPES=application/pdf ghcr.io/painteau/webprint:latest`
  - Multiple printers: `docker run -d --name webprint -p 8081:80 --restart unless-stopped -e PRINTERS=DeskJet_3630,OfficeLaser -e CUPS_SERVER=host.docker.internal -e CUPS_PORT=631 -e API_TOKEN=CHANGE_ME_SECRET_TOKEN ghcr.io/painteau/webprint:latest`
  - Protect UI: `docker run -d --name webprint -p 8081:80 --restart unless-stopped -e INDEX_PASSWORD=MySecret ghcr.io/painteau/webprint:latest`
- Windows PowerShell:
  - `docker run -d --name webprint -p 8081:80 --restart unless-stopped ghcr.io/painteau/webprint:latest`
  - With env: `docker run -d --name webprint -p 8081:80 --restart unless-stopped -e PRINTER_NAME=DeskJet_3630 -e CUPS_SERVER=host.docker.internal -e CUPS_PORT=631 -e API_TOKEN=CHANGE_ME_SECRET_TOKEN -e MAX_FILE_SIZE_MB=20 -e ALLOWED_MIME_TYPES=application/pdf ghcr.io/painteau/webprint:latest`
  - Multiple printers: `docker run -d --name webprint -p 8081:80 --restart unless-stopped -e PRINTERS=DeskJet_3630,OfficeLaser -e CUPS_SERVER=host.docker.internal -e CUPS_PORT=631 -e API_TOKEN=CHANGE_ME_SECRET_TOKEN ghcr.io/painteau/webprint:latest`
  - Protect UI: `docker run -d --name webprint -p 8081:80 --restart unless-stopped -e INDEX_PASSWORD=MySecret ghcr.io/painteau/webprint:latest`
- Mount local config instead of env:
  - Linux/macOS: `-v /path/to/config.php:/var/www/html/app/config.php:ro`
  - Windows PowerShell: `-v ${PWD}\app\config.php:/var/www/html/app/config.php:ro`

### Configure CUPS Host
- Docker Desktop: use `CUPS_SERVER=host.docker.internal`.
- Linux: use your host IP (ex: `192.168.x.x`). Ensure CUPS listens on the interface and port `631`.

### Multiple Printers in UI
- If `printers` or `PRINTERS` defines multiple names, the web UI shows a selector and sends the job to the chosen printer.
- Invalid printer selection is rejected.

### Tags
- `latest`: pushed on `main`.
- Branch/tag/sha tags are also published (e.g. `main`, `v1.0.0`, `sha-<short>`).

## License
- MIT License. See [`LICENSE`](LICENSE).
- WebPrint — créé par Painteau. Contribuez sur GitHub: https://github.com/painteau/WebPrint
