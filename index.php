<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$message = null;
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file'])) {
        $message = 'Aucun fichier reçu';
    } else {
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $message = 'Erreur de téléchargement';
        } else {
            $maxMb = (int)($config['max_file_size_mb'] ?? 10);
            $maxBytes = $maxMb * 1024 * 1024;
            if (($file['size'] ?? 0) > $maxBytes) {
                $message = 'Fichier trop volumineux';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $allowed = $config['allowed_mime_types'] ?? ['application/pdf'];
                if ($mime === false || !in_array($mime, $allowed, true)) {
                    $message = 'Type de fichier invalide (PDF requis)';
                } else {
                    $tmpDir = sys_get_temp_dir();
                    $tmpName = 'print_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
                    $dest = $tmpDir . DIRECTORY_SEPARATOR . $tmpName;
                    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
                        $message = 'Impossible de déplacer le fichier';
                    } else {
                        require_once __DIR__ . '/PrinterService.php';
                        $service = new PrinterService();
                        $result = $service->printPdf($dest);
                        @unlink($dest);
                        $ok = (bool)$result['success'];
                        $message = $result['message'] . ($result['job_id'] ? ' (ID: ' . $result['job_id'] . ')' : '');
                    }
                }
            }
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
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 2rem; }
        .box { max-width: 520px; margin: 0 auto; padding: 1rem 1.25rem; border: 1px solid #ddd; border-radius: 8px; }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p.help { color: #444; }
        .result { margin-top: 1rem; padding: .75rem; border-radius: 6px; white-space: pre-wrap; }
        .ok { background: #e6ffed; border: 1px solid #b7f5c6; color: #055b16; }
        .err { background: #ffecec; border: 1px solid #f5b7b7; color: #7a0b0b; }
        .actions { margin-top: .75rem; }
        button { padding: .5rem .9rem; font-size: 1rem; }
        input[type=file] { display: block; margin-top: .5rem; }
        footer { margin-top: 1rem; color: #666; font-size: .9rem; }
    </style>
 </head>
<body>
<div class="box">
    <h1>Imprimer un PDF</h1>
    <p class="help">Choisissez un fichier PDF puis cliquez sur « Imprimer ». Le document sera envoyé à l’imprimante configurée.</p>

    <form method="post" enctype="multipart/form-data">
        <label for="file">Fichier PDF</label>
        <input id="file" name="file" type="file" accept="application/pdf" required>
        <div class="actions">
            <button type="submit">Imprimer</button>
        </div>
    </form>

    <?php if ($message !== null): ?>
        <div class="result <?= $ok ? 'ok' : 'err' ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <footer>
        Taille max : <?= (int)($config['max_file_size_mb'] ?? 10) ?> Mo · Types : PDF
    </footer>
</div>
</body>
</html>