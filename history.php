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

$host = (string)($config['cups_server'] ?? 'localhost');
$port = (int)($config['cups_port'] ?? 631);
$jobs = listJobs(50, $host, $port);

$statusLabels = [
    'sent'     => ['Envoyé', 'badge-pending'],
    'queued'   => ['En file', 'badge-pending'],
    'done'     => ['Terminé', 'badge-ok'],
    'failed'   => ['Échec', 'badge-err'],
    'rejected' => ['Rejeté', 'badge-err'],
];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Historique des impressions</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="card card-wide">
    <h1>Historique des impressions</h1>
    <p class="help">Les 50 derniers documents envoyés depuis cette instance.</p>

    <?php if (empty($jobs)): ?>
        <p class="empty">Aucune impression enregistrée pour le moment.</p>
    <?php else: ?>
        <table class="jobs">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Fichier</th>
                    <th>Imprimante</th>
                    <th>Source</th>
                    <th>Statut</th>
                    <th>Détail</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $j): ?>
                    <?php
                    $status = (string)($j['status'] ?? '');
                    [$label, $badgeClass] = $statusLabels[$status] ?? [$status !== '' ? $status : 'Inconnu', 'badge-pending'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars(date('Y-m-d H:i', (int)($j['ts'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($j['filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($j['printer'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(strtoupper((string)($j['source'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge <?= htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string)($j['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?= !empty($j['job_id']) ? ' (ID: ' . htmlspecialchars((string)$j['job_id'], ENT_QUOTES, 'UTF-8') . ')' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p class="nav-link"><a href="index">&larr; Retour à l'impression</a></p>
</main>
</body>
</html>
