<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'mail_helper.php';

$baseDir = __DIR__;
$attachmentsDir = $baseDir . DIRECTORY_SEPARATOR . 'attachements';
$archiveDir = $baseDir . DIRECTORY_SEPARATOR . 'del_attachements';
$envPath = $baseDir . DIRECTORY_SEPARATOR . '.env';

if (!is_dir($attachmentsDir)) {
    mkdir($attachmentsDir, 0775, true);
}
if (!is_dir($archiveDir)) {
    mkdir($archiveDir, 0775, true);
}

$defaultRecipients = loadRecipientsFromCsv($baseDir);
$defaultSubject = loadDefaultSubject($baseDir);
$defaultBody = loadDefaultBody($baseDir);
$existingAttachments = listFilesInDirectory($attachmentsDir);
$env = loadEnvFile($envPath);
$envReady = isset($env['SMTP_SERVER'], $env['SMTP_PORT'], $env['EMAIL_USER'], $env['EMAIL_PASS']);

$flash = '';
$flashType = 'info';
$form = [
    'to' => recipientsToText($defaultRecipients),
    'subject' => $defaultSubject,
    'body' => $defaultBody,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['to'] = trim((string)($_POST['to'] ?? ''));
    $form['subject'] = trim((string)($_POST['subject'] ?? ''));
    $form['body'] = (string)($_POST['body'] ?? '');

    $recipients = parseRecipientInput($form['to']);
    $subject = applyCurrentMonth(stripBlockComments($form['subject']));
    $body = applyCurrentMonth(stripBlockComments($form['body']));

    if (!$envReady) {
        $flash = 'SMTP settings are missing. Copy .env.example to .env and fill in your email credentials.';
        $flashType = 'danger';
    } elseif ($recipients === []) {
        $flash = 'Please enter at least one valid email address in Send To.';
        $flashType = 'danger';
    } elseif ($subject === '') {
        $flash = 'Subject cannot be empty.';
        $flashType = 'danger';
    } elseif (trim($body) === '') {
        $flash = 'Body cannot be empty.';
        $flashType = 'danger';
    } else {
        $uploadedFiles = collectUploadedAttachments($_FILES['attachments'] ?? [], $attachmentsDir);
        $allAttachmentFiles = mergeAttachmentFiles($existingAttachments, array_map(
            static fn(string $path): array => [
                'name' => basename($path),
                'path' => $path,
                'size' => is_file($path) ? (int)filesize($path) : 0,
            ],
            $uploadedFiles['savedPaths']
        ));
        $attachmentsToSend = buildAttachmentsForSend($allAttachmentFiles);
        $attachmentNames = formatAttachmentNames($allAttachmentFiles);

        try {
            $mailer = new SmtpMailer(
                $env['SMTP_SERVER'],
                (int)$env['SMTP_PORT'],
                $env['EMAIL_USER'],
                $env['EMAIL_PASS'],
                $env['EMAIL_USER'],
                (string)($env['DISPLAY_NAME'] ?? '')
            );

            $mailer->send($recipients, $subject, $body, $attachmentsToSend);
            archiveUploadedFiles(
                array_map(static fn(array $file): string => $file['path'], $allAttachmentFiles),
                $archiveDir
            );
            $existingAttachments = [];

            $attachmentNote = $attachmentsToSend === []
                ? 'No attachments were included.'
                : 'Attachments sent: ' . $attachmentNames . '.';

            $flash = 'Email sent successfully to ' . implode(', ', $recipients) . '. ' . $attachmentNote;
            $flashType = 'success';
        } catch (Throwable $e) {
            $flash = 'Failed to send email: ' . $e->getMessage();
            $flashType = 'danger';
            $existingAttachments = listFilesInDirectory($attachmentsDir);
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Random Email Sender</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f8; }
        .page-wrap { max-width: 920px; margin: 24px auto; padding: 0 16px; }
        .card { border: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        textarea { font-family: Consolas, 'Courier New', monospace; }
        .hint { font-size: 0.875rem; color: #6c757d; }
        .attachment-list { margin: 0; padding-left: 1.1rem; }
        .attachment-list li { font-size: 0.9rem; }
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-1">Random Email Sender</h1>
            <div class="hint">Defaults come from <code>email_recipients.csv</code>, <code>subject.txt</code>, and <code>body.txt</code>.</div>
        </div>
        <a href="/pranay_helper_software/index.php" class="btn btn-sm btn-outline-secondary">Back to Dashboard</a>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if (!$envReady): ?>
        <div class="alert alert-warning">
            SMTP is not configured yet. Copy <code>.env.example</code> to <code>.env</code> in this folder and add your email settings.
        </div>
    <?php endif; ?>

    <?php if ($defaultRecipients === []): ?>
        <div class="alert alert-warning">
            No recipients found in <code>email_recipients.csv</code>. You can still type addresses manually below.
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <div class="mb-3">
                    <label for="to" class="form-label">Send To</label>
                    <textarea class="form-control" id="to" name="to" rows="3" required><?= htmlspecialchars($form['to'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div class="form-text">One email per line, or separated by commas. Loaded from CSV by default.</div>
                </div>

                <div class="mb-3">
                    <label for="subject" class="form-label">Subject</label>
                    <input type="text" class="form-control" id="subject" name="subject" value="<?= htmlspecialchars($form['subject'], ENT_QUOTES, 'UTF-8') ?>" required>
                    <div class="form-text">Use <code>{current_month}</code> for the current month and year.</div>
                </div>

                <div class="mb-3">
                    <label for="body" class="form-label">Body</label>
                    <textarea class="form-control" id="body" name="body" rows="10" required><?= htmlspecialchars($form['body'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div class="form-text">Use <code>{current_month}</code> anywhere in the body if needed.</div>
                </div>

                <div class="mb-3">
                    <label for="attachments" class="form-label">Attachments</label>
                    <input class="form-control" type="file" id="attachments" name="attachments[]" multiple>
                    <div id="selectedAttachments" class="form-text mt-2"></div>

                    <?php if ($existingAttachments !== []): ?>
                        <div class="mt-2">
                            <div class="small fw-semibold">Ready to attach from folder:</div>
                            <ul class="attachment-list">
                                <?php foreach ($existingAttachments as $file): ?>
                                    <li><?= htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8') ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="form-text mt-2">No files in <code>attachements</code> yet. Pick files above to add them.</div>
                    <?php endif; ?>

                    <div class="form-text">Files in <code>attachements</code> are included automatically. After a successful send, sent files are moved to <code>del_attachements</code>.</div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary"<?= $envReady ? '' : ' disabled' ?>>Send Email</button>
                    <button type="button" class="btn btn-outline-secondary" id="resetDefaultsBtn">Reset to Defaults</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const defaults = {
        to: <?= json_encode(recipientsToText($defaultRecipients), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        subject: <?= json_encode($defaultSubject, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        body: <?= json_encode($defaultBody, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
    };

    document.getElementById('resetDefaultsBtn').addEventListener('click', function () {
        document.getElementById('to').value = defaults.to;
        document.getElementById('subject').value = defaults.subject;
        document.getElementById('body').value = defaults.body;
    });

    const attachmentsInput = document.getElementById('attachments');
    const selectedAttachments = document.getElementById('selectedAttachments');

    attachmentsInput.addEventListener('change', function () {
        const names = Array.from(attachmentsInput.files || []).map((file) => file.name);
        if (names.length === 0) {
            selectedAttachments.textContent = '';
            return;
        }
        selectedAttachments.innerHTML = '<span class="fw-semibold">Selected to upload:</span> ' + names.join(', ');
    });
})();
</script>
</body>
</html>
