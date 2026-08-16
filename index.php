<?php
declare(strict_types=1);

require_once __DIR__ . '/app/ConfigLoader.php';
$config = loadConfig();
$isHttps = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443'));
if (PHP_SESSION_ACTIVE !== session_status()) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'");
$pwd = (string)($config['index_password'] ?? '');
$message = null;
$ok = false;
$printers = [];
$defaultPrinter = (string)($config['printer_name'] ?? '');
if (isset($config['printers']) && is_array($config['printers'])) {
    $printers = array_values(array_filter(array_map('strval', $config['printers']), static fn($p) => $p !== ''));
}
if (empty($printers) && $defaultPrinter !== '') {
    $printers = [$defaultPrinter];
}
if ($defaultPrinter === '' && !empty($printers)) {
    $defaultPrinter = (string)$printers[0];
}
$selectedPrinter = $defaultPrinter;
if (!empty($printers) && !in_array($selectedPrinter, $printers, true)) {
    $selectedPrinter = (string)$printers[0];
}

$csrf = $_SESSION['csrf'] ?? null;
if (!is_string($csrf) || $csrf === '') {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $csrf = $_SESSION['csrf'];
}

$allowedForAccept = $config['allowed_mime_types'] ?? ['application/pdf'];
$allowedForAccept = array_values(array_filter(array_map('strval', $allowedForAccept), static fn($x) => preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $x)));
$acceptAttr = implode(',', $allowedForAccept);

if ($pwd !== '' && (!isset($_SESSION['index_auth']) || $_SESSION['index_auth'] !== true)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
        require_once __DIR__ . '/app/RateLimiter.php';
        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $inp = (string)($_POST['login_password'] ?? '');
        $postCsrf = (string)($_POST['csrf'] ?? '');
        $attempts = (int)($_SESSION['login_attempts'] ?? 0);
        $lockUntil = (int)($_SESSION['login_lock_until'] ?? 0);
        $ipLock = loginRateLimitCheck($clientIp);
        if ($ipLock['locked'] || $lockUntil > time()) {
            $retryAfter = max($ipLock['retryAfter'], $lockUntil - time());
            $message = 'Trop de tentatives. Réessayez dans ' . ceil($retryAfter / 60) . ' min.';
        } elseif ($postCsrf === '' || !hash_equals($csrf, $postCsrf)) {
            $message = 'Requête invalide';
        } elseif ($inp !== '' && (str_starts_with($pwd, '$2y$') ? password_verify($inp, $pwd) : hash_equals($pwd, $inp))) {
            $_SESSION['index_auth'] = true;
            $_SESSION['login_attempts'] = 0;
            loginRateLimitReset($clientIp);
            header('Location: index');
            exit;
        } else {
            $attempts++;
            $_SESSION['login_attempts'] = $attempts;
            loginRateLimitRecordFailure($clientIp);
            if ($attempts >= 5) {
                $_SESSION['login_lock_until'] = time() + 300;
                $_SESSION['login_attempts'] = 0;
            }
            $message = 'Mot de passe invalide';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = (string)($_POST['csrf'] ?? '');
    if ($postCsrf === '' || !hash_equals($csrf, $postCsrf)) {
        $message = 'Requête invalide';
    }
    if ($message === null && isset($_POST['logout'])) {
        $_SESSION = [];
        if (session_id() !== '') {
            session_destroy();
        }
        header('Location: index');
        exit;
    }
    if (isset($_POST['printer'])) {
        $p = (string)$_POST['printer'];
        if ($p !== '' && in_array($p, $printers, true)) {
            $selectedPrinter = $p;
        } else {
            $message = 'Imprimante invalide';
        }
    }
    if ($message === null && !isset($_FILES['file'])) {
        $message = 'Aucun fichier reçu';
    } elseif ($message === null) {
        require_once __DIR__ . '/app/UploadHandler.php';
        require_once __DIR__ . '/app/JobStore.php';
        $originalName = sanitizeJobText((string)($_FILES['file']['name'] ?? ''), 150);
        $upload = handleUpload($_FILES['file'], $config);
        if (!$upload['ok']) {
            $message = $upload['error'];
            addJob([
                'source'   => 'ui',
                'printer'  => $selectedPrinter,
                'filename' => $originalName,
                'status'   => 'rejected',
                'job_id'   => null,
                'message'  => $upload['error'],
            ]);
        } else {
            $dest = $upload['path'];
            require_once __DIR__ . '/app/PrinterService.php';
            $service = new PrinterService();
            $result = $service->printPdf($dest, $selectedPrinter);
            if (!@unlink($dest)) {
                error_log('WebPrint: failed to delete tmp file ' . $dest);
            }
            $ok = (bool)$result['success'];
            $message = $result['message'] . ($result['job_id'] ? ' (ID: ' . $result['job_id'] . ')' : '');
            addJob([
                'source'   => 'ui',
                'printer'  => $selectedPrinter,
                'filename' => $originalName,
                'status'   => $ok ? 'sent' : 'failed',
                'job_id'   => $result['job_id'],
                'message'  => $result['message'],
            ]);
        }
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Impression PDF</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <link rel="stylesheet" href="style.css">
 </head>
<body>
<main class="card">
    <?php if ($pwd !== '' && (isset($_SESSION['index_auth']) && $_SESSION['index_auth'] === true)): ?>
    <div class="topbar">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <button class="logout-btn" type="submit" name="logout" value="1" title="Déconnexion" aria-label="Déconnexion">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                    <path d="M12 3v9m4.243-4.243a6 6 0 11-8.486 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                </svg>
            </button>
        </form>
    </div>
    <?php endif; ?>
    <?php if ($pwd !== '' && (!isset($_SESSION['index_auth']) || $_SESSION['index_auth'] !== true)): ?>
        <h1>Authentification requise</h1>
        <p class="help">Entrez le mot de passe pour accéder à l’interface d’impression.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <label for="login_password">Mot de passe</label>
            <input id="login_password" name="login_password" type="password" required>
            <div class="actions">
                <button type="submit">Se connecter</button>
            </div>
        </form>
    <?php else: ?>
        <h1>Imprimer un document</h1>
        <p class="help">Choisissez un fichier (PDF, image JPEG/PNG/TIFF, texte) puis cliquez sur « Imprimer ». Le document sera envoyé à l’imprimante configurée.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <label for="printer">Imprimante</label>
            <select id="printer" name="printer" required>
                <?php foreach ($printers as $p): ?>
                    <option value="<?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') ?>" <?= $p === $selectedPrinter ? 'selected' : '' ?>><?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <label for="file">Fichier</label>
            <div class="dropzone" id="dropzone">
                <input id="file" name="file" type="file" accept="<?= htmlspecialchars($acceptAttr, ENT_QUOTES, 'UTF-8') ?>" required>
                <p class="dropzone-hint" id="dropzone-hint">ou glissez-deposez un fichier ici</p>
            </div>
            <div class="actions">
                <button type="submit">Imprimer</button>
            </div>
        </form>
        <p class="nav-link"><a href="history">Voir l'historique des impressions</a></p>
    <?php endif; ?>

    <?php if ($message !== null): ?>
        <div class="result <?= $ok ? 'ok' : 'err' ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <footer>
        <?php if ($pwd !== '' && (!isset($_SESSION['index_auth']) || $_SESSION['index_auth'] !== true)): ?>
            Accès protégé · Entrez le mot de passe
        <?php else: ?>
            Imprimante : <?= htmlspecialchars($selectedPrinter, ENT_QUOTES, 'UTF-8') ?> · Taille max : <?= (int)($config['max_file_size_mb'] ?? 10) ?> Mo · Types : PDF, JPEG, PNG, TIFF, texte
        <?php endif; ?>
        <div class="footer-meta">WebPrint — créé par Painteau · <a href="https://github.com/painteau/WebPrint" target="_blank" rel="noopener noreferrer">Projet GitHub</a> · <a href="LICENSE" target="_blank" rel="noopener noreferrer">License</a></div>
    </footer>
</main>
<script src="app.js" defer></script>
</body>
</html>
