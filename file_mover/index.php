<?php
declare(strict_types=1);

$baseDir = __DIR__;
$uploadDir = $baseDir . DIRECTORY_SEPARATOR . 'uploads';
$delDir = $baseDir . DIRECTORY_SEPARATOR . 'del';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

if (!is_dir($delDir)) {
    mkdir($delDir, 0775, true);
}

$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_to_upload'])) {
    $upload = $_FILES['file_to_upload'];

    if (!isset($upload['error']) || is_array($upload['error'])) {
        $flash = 'Invalid upload request.';
        $flashType = 'danger';
    } elseif ($upload['error'] !== UPLOAD_ERR_OK) {
        $flash = 'Upload failed. Error code: ' . (int)$upload['error'];
        $flashType = 'danger';
    } else {
        $originalName = basename((string)$upload['name']);
        $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $originalName;

        if ($originalName === '' || $originalName === '.' || $originalName === '..') {
            $flash = 'Invalid file name.';
            $flashType = 'danger';
        } elseif (file_exists($targetPath)) {
            $flash = 'File already exists in upload list. Upload skipped.';
            $flashType = 'warning';
        } elseif (!move_uploaded_file((string)$upload['tmp_name'], $targetPath)) {
            $flash = 'Could not save uploaded file.';
            $flashType = 'danger';
        } else {
            $flash = 'File uploaded successfully.';
            $flashType = 'success';
        }
    }
}

if (isset($_GET['download'])) {
    $requestedFile = basename((string)$_GET['download']);
    $sourcePath = $uploadDir . DIRECTORY_SEPARATOR . $requestedFile;

    if ($requestedFile === '' || !is_file($sourcePath)) {
        http_response_code(404);
        echo 'File not found.';
        exit;
    }

    ignore_user_abort(true);
    set_time_limit(0);

    $downloadName = $requestedFile;
    $size = filesize($sourcePath);

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }

    if (ob_get_level()) {
        ob_end_clean();
    }

    $handle = fopen($sourcePath, 'rb');
    if ($handle !== false) {
        while (!feof($handle)) {
            echo (string)fread($handle, 8192);
            flush();
        }
        fclose($handle);
    }

    $timestamp = date('Ymd_His');
    $renamedFile = 'del_' . $timestamp . '_' . $requestedFile;
    $targetPath = $delDir . DIRECTORY_SEPARATOR . $renamedFile;
    $suffix = 1;

    while (file_exists($targetPath)) {
        $targetPath = $delDir . DIRECTORY_SEPARATOR . 'del_' . $timestamp . '_' . $suffix . '_' . $requestedFile;
        $suffix++;
    }

    rename($sourcePath, $targetPath);
    exit;
}

$availableFiles = [];
$files = scandir($uploadDir);
if ($files !== false) {
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $path = $uploadDir . DIRECTORY_SEPARATOR . $file;
        if (is_file($path)) {
            $availableFiles[] = [
                'name' => $file,
                'size' => filesize($path),
                'time' => filemtime($path),
            ];
        }
    }
}

usort(
    $availableFiles,
    static function (array $a, array $b): int {
        return ($b['time'] ?? 0) <=> ($a['time'] ?? 0);
    }
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>File Mover</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .app-shell { max-width: 780px; margin: 24px auto; }
        .small-note { font-size: 0.9rem; color: #6c757d; }
    </style>
</head>
<body>
<div class="container app-shell">
    <div class="card shadow-sm">
        <div class="card-header bg-dark text-white">Local LAN File Mover</div>
        <div class="card-body">
            <?php if ($flash !== ''): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> py-2">
                    <?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <h5 class="mb-3">Upload</h5>
            <form method="post" enctype="multipart/form-data" class="row g-2 align-items-center mb-4">
                <div class="col-md-8">
                    <input type="file" name="file_to_upload" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-4 d-grid">
                    <button type="submit" class="btn btn-primary btn-sm">Upload File</button>
                </div>
            </form>

            <h5 class="mb-3">Download</h5>
            <?php if (count($availableFiles) === 0): ?>
                <p class="small-note mb-0">No files available in uploads.</p>
            <?php else: ?>
                <div class="list-group mb-3">
                    <?php foreach ($availableFiles as $item): ?>
                        <?php
                        $safeName = htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8');
                        $sizeInKb = number_format(((int)$item['size']) / 1024, 2);
                        $downloadLink = '?download=' . rawurlencode((string)$item['name']);
                        ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-semibold"><?php echo $safeName; ?></div>
                                <small class="text-muted"><?php echo $sizeInKb; ?> KB</small>
                            </div>
                            <a class="btn btn-success btn-sm" href="<?php echo htmlspecialchars($downloadLink, ENT_QUOTES, 'UTF-8'); ?>">
                                Download Once
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
                <!-- <p class="small-note mb-0">After download, file moves to <code>del</code> as <code>del_timestamp_name</code>.</p> -->
            <?php endif; ?>
        </div>
        <div class="card-footer text-muted">
            
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
