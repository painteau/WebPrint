<?php
declare(strict_types=1);

require_once __DIR__ . '/app/ConfigLoader.php';
$config = loadConfig();
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                    $message = 'Type de fichier invalide';
                } else {
                    $tmpDir = sys_get_temp_dir();
                    $map = [
                        'application/pdf' => '.pdf',
                        'application/postscript' => '.ps',
                        'image/jpeg' => '.jpg',
                        'image/png' => '.png',
                        'image/tiff' => '.tiff',
                        'text/plain' => '.txt',
                        'image/pwg-raster' => '.pwg',
                        'image/urf' => '.urf',
                    ];
                    $ext = $map[$mime] ?? '.bin';
                    $tmpName = 'print_' . time() . '_' . bin2hex(random_bytes(4)) . $ext;
                    $dest = $tmpDir . DIRECTORY_SEPARATOR . $tmpName;
                    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
                        $message = 'Impossible de déplacer le fichier';
                    } else {
                        require_once __DIR__ . '/app/PrinterService.php';
                        $service = new PrinterService();
                        $result = $service->printPdf($dest, $selectedPrinter);
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
    <meta name="color-scheme" content="light dark">
    <link rel="stylesheet" href="style.css">
 </head>
<body>
<main class="card">
    <h1>Imprimer un document</h1>
    <p class="help">Choisissez un fichier (PDF, image JPEG/PNG/TIFF, texte) puis cliquez sur « Imprimer ». Le document sera envoyé à l’imprimante configurée.</p>

    <form method="post" enctype="multipart/form-data">
        <label for="printer">Imprimante</label>
        <select id="printer" name="printer" required>
            <?php foreach ($printers as $p): ?>
                <option value="<?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') ?>" <?= $p === $selectedPrinter ? 'selected' : '' ?>><?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <label for="file">Fichier</label>
        <input id="file" name="file" type="file" accept="application/pdf,image/jpeg,image/png,image/tiff,text/plain" required>
        <div class="actions">
            <button type="submit">Imprimer</button>
        </div>
    </form>

    <?php if ($message !== null): ?>
        <div class="result <?= $ok ? 'ok' : 'err' ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <footer>
        Imprimante : <?= htmlspecialchars($selectedPrinter, ENT_QUOTES, 'UTF-8') ?> · Taille max : <?= (int)($config['max_file_size_mb'] ?? 10) ?> Mo · Types : PDF, JPEG, PNG, TIFF, texte
    </footer>
</main>
</body>
</html>
