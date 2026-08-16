<?php
declare(strict_types=1);

require_once __DIR__ . '/app/ConfigLoader.php';
require_once __DIR__ . '/app/JobStore.php';
$config = loadConfig();
$isHttps = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443'));
if (PHP_SESSION_ACTIVE !== session_status()) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
$pwd = (string)($config['index_password'] ?? '');
if ($pwd !== '' && (!isset($_SESSION['index_auth']) || $_SESSION['index_auth'] !== true)) {
    header('Location: index');
    exit;
}
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'");

require_once __DIR__ . '/app/ScanService.php';
$service = new ScanService($config);
$scanners = $service->listScanners();

$csrf = $_SESSION['csrf'] ?? null;
if (!is_string($csrf) || $csrf === '') {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $csrf = $_SESSION['csrf'];
}

$message = null;
$ok = false;
$downloadId = null;
$selectedScanner = $scanners[0] ?? '';
$selectedResolution = 300;
$selectedMode = 'Color';
$selectedFormat = 'pdf';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = (string)($_POST['csrf'] ?? '');
    if ($postCsrf === '' || !hash_equals($csrf, $postCsrf)) {
        $message = 'Requête invalide';
    } elseif (empty($scanners)) {
        $message = 'Aucun scanner configuré';
    } else {
        $selectedScanner = (string)($_POST['scanner'] ?? '');
        $selectedResolution = (int)($_POST['resolution'] ?? 300);
        $selectedMode = (string)($_POST['mode'] ?? 'Color');
        $selectedFormat = (string)($_POST['format'] ?? 'pdf');

        if (!in_array($selectedScanner, $scanners, true)) {
            $message = 'Scanner invalide';
        } else {
            $result = $service->scan($selectedScanner, $selectedResolution, $selectedMode, $selectedFormat);
            $ok = $result['success'];
            if ($ok && $result['path'] !== null) {
                $stored = storeScanFile($result['path'], (string)$result['ext']);
                $jobId = addJob([
                    'type'     => 'scan',
                    'source'   => 'ui',
                    'printer'  => $selectedScanner,
                    'filename' => basename((string)$stored),
                    'status'   => $stored !== null ? 'done' : 'failed',
                    'job_id'   => null,
                    'message'  => $stored !== null ? 'Scan enregistré' : 'Échec de sauvegarde du fichier scanné',
                    'file'     => $stored,
                ]);
                if ($stored !== null) {
                    $message = 'Scan terminé.';
                    $downloadId = $jobId;
                } else {
                    $ok = false;
                    $message = 'Scan effectué mais impossible de sauvegarder le fichier';
                }
            } else {
                $message = $result['message'];
                addJob([
                    'type'     => 'scan',
                    'source'   => 'ui',
                    'printer'  => $selectedScanner,
                    'filename' => '',
                    'status'   => 'failed',
                    'job_id'   => null,
                    'message'  => $result['message'],
                    'file'     => null,
                ]);
            }
        }
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Scanner un document</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="card">
    <h1>Scanner un document</h1>
    <?php if (empty($scanners)): ?>
        <p class="help">Aucun scanner n'est configuré (voir <code>scanners</code> dans la configuration).</p>
    <?php else: ?>
        <p class="help">Placez le document sur la vitre puis choisissez vos options.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <label for="scanner">Scanner</label>
            <select id="scanner" name="scanner" required>
                <?php foreach ($scanners as $s): ?>
                    <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>" <?= $s === $selectedScanner ? 'selected' : '' ?>><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <label for="resolution">Résolution</label>
            <select id="resolution" name="resolution" required>
                <?php foreach (ScanService::ALLOWED_RESOLUTIONS as $r): ?>
                    <option value="<?= $r ?>" <?= $r === $selectedResolution ? 'selected' : '' ?>><?= $r ?> dpi</option>
                <?php endforeach; ?>
            </select>
            <label for="mode">Couleur</label>
            <select id="mode" name="mode" required>
                <option value="Color" <?= $selectedMode === 'Color' ? 'selected' : '' ?>>Couleur</option>
                <option value="Gray" <?= $selectedMode === 'Gray' ? 'selected' : '' ?>>Niveaux de gris</option>
            </select>
            <label for="format">Format</label>
            <select id="format" name="format" required>
                <option value="pdf" <?= $selectedFormat === 'pdf' ? 'selected' : '' ?>>PDF</option>
                <option value="jpeg" <?= $selectedFormat === 'jpeg' ? 'selected' : '' ?>>JPEG</option>
                <option value="png" <?= $selectedFormat === 'png' ? 'selected' : '' ?>>PNG</option>
            </select>
            <div class="actions">
                <button type="submit">Scanner</button>
            </div>
        </form>
        <p class="nav-link"><a href="history">Voir l'historique</a> · <a href="index">Imprimer un document</a></p>
    <?php endif; ?>

    <?php if ($message !== null): ?>
        <div class="result <?= $ok ? 'ok' : 'err' ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($downloadId !== null): ?>
                <div><a href="download?id=<?= htmlspecialchars($downloadId, ENT_QUOTES, 'UTF-8') ?>">Télécharger le scan</a></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <footer>
        <div class="footer-meta">WebPrint — créé par Painteau · <a href="https://github.com/painteau/WebPrint" target="_blank" rel="noopener noreferrer">Projet GitHub</a> · <a href="LICENSE" target="_blank" rel="noopener noreferrer">License</a></div>
    </footer>
</main>
</body>
</html>
