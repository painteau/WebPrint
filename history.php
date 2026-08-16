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
$scannerNames = array_keys(getValidatedScanners($config));
$deviceLabels = getValidatedPrinters($config) + array_combine($scannerNames, $scannerNames);

$statusLabels = [
    'sent'     => ['Envoyé', 'badge-pending'],
    'queued'   => ['En file', 'badge-pending'],
    'scanning' => ['En cours', 'badge-pending'],
    'done'     => ['Terminé', 'badge-ok'],
    'failed'   => ['Échec', 'badge-err'],
    'rejected' => ['Rejeté', 'badge-err'],
];
$typeLabels = [
    'print' => 'Impression',
    'scan'  => 'Scan',
];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Historique</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="card card-wide">
    <h1>Historique</h1>
    <p class="help">Les 50 dernières impressions et scans depuis cette instance.</p>

    <?php if (empty($jobs)): ?>
        <p class="empty">Aucune activité enregistrée pour le moment.</p>
    <?php else: ?>
        <table class="jobs">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Fichier</th>
                    <th>Appareil</th>
                    <th>Source</th>
                    <th>Statut</th>
                    <th>Détail</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $j): ?>
                    <?php
                    $type = (string)($j['type'] ?? 'print');
                    $typeLabel = $typeLabels[$type] ?? $type;
                    $status = (string)($j['status'] ?? '');
                    [$label, $badgeClass] = $statusLabels[$status] ?? [$status !== '' ? $status : 'Inconnu', 'badge-pending'];
                    $canDownload = $type === 'scan' && $status === 'done' && !empty($j['file']) && !empty($j['id']);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars(date('Y-m-d H:i', (int)($j['ts'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($j['filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($deviceLabels[$j['printer'] ?? ''] ?? (string)($j['printer'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(strtoupper((string)($j['source'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge <?= htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td>
                            <?= htmlspecialchars((string)($j['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?= !empty($j['job_id']) ? ' (ID: ' . htmlspecialchars((string)$j['job_id'], ENT_QUOTES, 'UTF-8') . ')' : '' ?>
                            <?php if ($canDownload): ?>
                                <div><a href="download?id=<?= htmlspecialchars((string)$j['id'], ENT_QUOTES, 'UTF-8') ?>">Télécharger</a></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p class="nav-link"><a href="index">Imprimer</a> · <a href="scan">Scanner</a></p>
</main>
</body>
</html>
