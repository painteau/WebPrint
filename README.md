# 🖨️ WebPrint — Tiny PDF Printing & Scanning

Minimal PHP app to print documents over your local network using CUPS (`lp`), and scan them back
using a network scanner (eSCL/AirScan, via `scanimage`). Built for Raspberry Pi Zero (DietPi) with
Apache2 + PHP 8.

## ⚙️ Prerequisites
- 🐧 Apache2 + PHP 8 installed and running
- 🧰 CUPS installed and configured
- 🖨️ A working CUPS printer (e.g. `lp -d DeskJet_3630 file.pdf` works)
- 🖶 (optional) A scanner reachable over the network via eSCL/AirScan (most modern all-in-one
  printers expose this — no USB passthrough needed). Test with
  `scanimage -L` after installing `sane-utils` + `sane-airscan`.

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
  - `index_password`: optional UI password (plain text or bcrypt hash — see below); if non-empty, `/index` requires login
  - `scanners`: optional map of scanner name => eSCL base URL (e.g. `http://192.168.1.50/eSCL`) — leave empty/absent to hide the scan feature
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
    'index_password'     => '',   // plain text or bcrypt hash
    'scanners'           => [
        'DeskJet_3630' => 'http://192.168.1.50/eSCL',
    ],
];
```

## ❤️ Health Check
- URL: `GET http://<host>/health`
- Response: `{"status":"ok"}` — HTTP 200
- No authentication required. Suitable for Docker/reverse proxy health checks.

## 🌐 Web UI
- URL: `http://<pi-host-or-ip>/index`
- Action: upload a PDF (or drag & drop it onto the drop zone) and click “Imprimer”
- Feedback: shows success/error and job ID when available

## 🕘 History
- URL: `http://<pi-host-or-ip>/history`
- Shows the last 50 jobs (print and scan, UI and API), with file name, device, source, and status
- Status: `Envoyé`/`En file` (accepted, CUPS queue checked on page load), `Terminé` (left the queue, or scan completed), `Échec` (rejected by CUPS/scanner), `Rejeté` (rejected before printing — bad MIME type, too large, etc.)
- Protected by the same optional `index_password` as the Web UI
- Stored as a small JSON file at `app/data/jobs.json` (gitignored, blocked from direct HTTP access, capped at 300 entries). In Docker, mount a volume over `app/data` to keep history (and scanned files) across container recreations.

## 🖶 Scan
- URL: `http://<pi-host-or-ip>/scan`
- Requires at least one entry in `scanners` config/env — hidden otherwise
- Scans over the network via eSCL/AirScan (`scanimage` + `sane-airscan`), no USB/device passthrough
- Options: scanner, resolution (75 to 1200 dpi), color mode (color/grayscale), format (PDF/JPEG/PNG)
- Result files are saved under `app/data/scans/` (gitignored, blocked from direct HTTP access) and downloadable from the scan result page or from `/history`
- `GET /download?id=<job id>` streams a scanned file — requires the same optional `index_password` session as the rest of the UI

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
- **Docker**: handled automatically — `a2enmod rewrite` is run in the Dockerfile.
- **Manual install**: enable Apache rewrite and allow `.htaccess` override:
  ```bash
  sudo a2enmod rewrite && sudo systemctl restart apache2
  ```
  In your vhost config:
  ```apache
  <Directory /var/www/html>
      AllowOverride All
  </Directory>
  ```

## 🛡️ Security & Robustness
- `app/` code is blocked from direct HTTP access
- `app/config.php` is git-ignored so you can safely adjust secrets on the server
- Strict MIME check with `finfo` (default `application/pdf`)
- Max file size enforced from config
- All `lp` arguments escaped via `escapeshellarg()`
- `CUPS_SERVER` validated as hostname or IP — arbitrary injection rejected
- Temp files cleaned up after each job; failures logged via `error_log`
- Print jobs logged (printer name, job ID) via `error_log`
- Web UI security:
  - Optional password via `INDEX_PASSWORD` (session-based)
  - Password accepts plain text or bcrypt hash — generate with:
    ```bash
    php -r "echo password_hash('mypassword', PASSWORD_BCRYPT);"
    ```
  - Login rate-limited: 5 failed attempts trigger a 5-minute lockout, tracked both per-session and per-IP (a dropped session cookie doesn't reset the lockout)
  - CSRF tokens on login and print forms
  - Security headers (CSP, nosniff, frame deny)
- `/api` refuses to serve (`503`) if `api_token` is left empty, at the documented placeholder value, or under 16 characters
- `display_errors` disabled in the Docker image (no stack traces/file paths leaked on uncaught errors)
- Scan: resolution/color mode/format are checked against a fixed whitelist, scanner name against the configured list, all `scanimage` arguments escaped via `escapeshellarg()`; `/etc/sane.d/airscan.conf` is regenerated from the validated scanner list only (no raw config injection)
- `/download` only serves files referenced by a completed scan job in the history store — no arbitrary path access

## 📝 Notes
- No external DB — small JSON files under `app/data/` store print/scan history (`jobs.json`) and scanned documents (`scans/`), no other persistence
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
- `INDEX_PASSWORD`: protect Web UI (`/index`) with a password prompt (plain text or bcrypt hash)
- `SCANNERS`: comma-separated `name=eSCL_url` pairs (ex: `DeskJet_3630=http://192.168.1.50/eSCL,OfficeMFP=http://192.168.1.51/eSCL`)
- Precedence: env overrides values from `app/config.php` (if present) or `app/config.php.example`.
- Validation: printer/scanner names use `[A-Za-z0-9._-]`; MIME types must be `type/subtype`; scanner URLs must be `http(s)://host[:port][/path]`.

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
- Persist print/scan history and scanned files across container recreations:
  - Linux/macOS: `-v /path/to/data:/var/www/html/app/data`
  - Windows PowerShell: `-v ${PWD}\data:/var/www/html/app/data`
- With scanners: `docker run -d --name webprint -p 8081:80 --restart unless-stopped -e SCANNERS=DeskJet_3630=http://192.168.1.50/eSCL ghcr.io/painteau/webprint:latest`

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
