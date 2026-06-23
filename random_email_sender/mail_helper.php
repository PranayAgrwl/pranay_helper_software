<?php
declare(strict_types=1);

function loadEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $vars[trim($name)] = trim($value);
    }

    return $vars;
}

function stripBlockComments(string $text): string
{
    return trim((string)preg_replace('/\/\*.*?\*\//s', '', $text));
}

function applyCurrentMonth(string $text): string
{
    return str_replace('{current_month}', date('F Y'), $text);
}

function loadDefaultSubject(string $baseDir): string
{
    $path = $baseDir . DIRECTORY_SEPARATOR . 'subject.txt';
    if (!is_file($path)) {
        return '';
    }

    return applyCurrentMonth(stripBlockComments((string)file_get_contents($path)));
}

function loadDefaultBody(string $baseDir): string
{
    $path = $baseDir . DIRECTORY_SEPARATOR . 'body.txt';
    if (!is_file($path)) {
        return '';
    }

    return applyCurrentMonth(stripBlockComments((string)file_get_contents($path)));
}

function loadRecipientsFromCsv(string $baseDir): array
{
    $path = $baseDir . DIRECTORY_SEPARATOR . 'email_recipients.csv';
    if (!is_file($path)) {
        return [];
    }

    $emails = [];
    if (($fh = fopen($path, 'rb')) === false) {
        return [];
    }

    while (($row = fgetcsv($fh)) !== false) {
        $email = trim((string)($row[0] ?? ''));
        if ($email === '' || strcasecmp($email, 'Email') === 0) {
            continue;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }
    fclose($fh);

    return array_values(array_unique($emails));
}

function recipientsToText(array $emails): string
{
    return implode("\n", $emails);
}

function parseRecipientInput(string $input): array
{
    $parts = preg_split('/[\s,;]+/', $input) ?: [];
    $emails = [];

    foreach ($parts as $part) {
        $email = trim((string)$part);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    return array_values(array_unique($emails));
}

function encodeMimeHeader(string $text): string
{
    if ($text === '' || preg_match('/^[\x20-\x7E]*$/', $text)) {
        return $text;
    }

    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

final class SmtpMailer
{
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $fromEmail;
    private string $fromName;
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        string $host,
        int $port,
        string $user,
        string $pass,
        string $fromEmail,
        string $fromName
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
    }

    /**
     * @param list<string> $to
     * @param list<array{name:string,data:string,mime:string}> $attachments
     */
    public function send(array $to, string $subject, string $body, array $attachments = []): void
    {
        if ($to === []) {
            throw new RuntimeException('No recipients provided.');
        }

        $this->connect();
        try {
            $this->expect(220);
            $this->command('EHLO localhost', 250);

            if ($this->port === 587) {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Could not enable TLS.');
                }
                $this->command('EHLO localhost', 250);
            }

            $this->command('AUTH LOGIN', 334);
            $this->command(base64_encode($this->user), 334);
            $this->command(base64_encode($this->pass), 235);
            $this->command('MAIL FROM:<' . $this->fromEmail . '>', 250);

            foreach ($to as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
            }

            $this->command('DATA', 354);

            $message = $this->buildMessage($to, $subject, $body, $attachments);
            fwrite($this->socket, $message . "\r\n.\r\n");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            $this->disconnect();
        }
    }

    private function connect(): void
    {
        $remote = ($this->port === 465 ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT
        );

        if ($socket === false) {
            throw new RuntimeException('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($socket, 30);
        $this->socket = $socket;
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    private function command(string $command, int|array $expectedCodes): void
    {
        fwrite($this->socket, $command . "\r\n");
        $this->expect($expectedCodes);
    }

    private function expect(int|array $expectedCodes): string
    {
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        if ($response === '') {
            throw new RuntimeException('Empty SMTP response.');
        }

        $code = (int)substr($response, 0, 3);
        $expected = is_array($expectedCodes) ? $expectedCodes : [$expectedCodes];
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP error: ' . trim($response));
        }

        return $response;
    }

    /**
     * @param list<string> $to
     * @param list<array{name:string,data:string,mime:string}> $attachments
     */
    private function buildMessage(array $to, string $subject, string $body, array $attachments): string
    {
        $fromHeader = $this->fromName !== ''
            ? encodeMimeHeader($this->fromName) . ' <' . $this->fromEmail . '>'
            : $this->fromEmail;

        $headers = [
            'From: ' . $fromHeader,
            'To: ' . implode(', ', $to),
            'Subject: ' . encodeMimeHeader($subject),
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
        ];

        if ($attachments === []) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';

            return implode("\r\n", $headers) . "\r\n\r\n" . $body;
        }

        $boundary = '----=_Part_' . bin2hex(random_bytes(8));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $message = implode("\r\n", $headers) . "\r\n\r\n";
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $body . "\r\n";

        foreach ($attachments as $attachment) {
            $safeName = str_replace(['"', "\r", "\n"], '', $attachment['name']);
            $message .= '--' . $boundary . "\r\n";
            $message .= 'Content-Type: ' . $attachment['mime'] . '; name="' . $safeName . '"' . "\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= 'Content-Disposition: attachment; filename="' . $safeName . '"' . "\r\n\r\n";
            $message .= chunk_split(base64_encode($attachment['data'])) . "\r\n";
        }

        $message .= '--' . $boundary . '--';

        return $message;
    }
}

function archiveUploadedFiles(array $savedPaths, string $archiveDir): void
{
    if (!is_dir($archiveDir)) {
        mkdir($archiveDir, 0775, true);
    }

    $timestamp = date('Ymd_His');
    foreach ($savedPaths as $path) {
        if (!is_file($path)) {
            continue;
        }
        $destination = $archiveDir . DIRECTORY_SEPARATOR . 'del_' . $timestamp . '_' . basename($path);
        @rename($path, $destination);
    }
}

function guessMimeType(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($ext) {
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'txt' => 'text/plain',
        default => 'application/octet-stream',
    };
}

/**
 * @return list<array{name:string,path:string,size:int}>
 */
function listFilesInDirectory(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            continue;
        }
        $size = filesize($path);
        $files[] = [
            'name' => $name,
            'path' => $path,
            'size' => $size === false ? 0 : $size,
        ];
    }

    usort($files, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    return $files;
}

/**
 * @param list<array{name:string,path:string,size:int}> $files
 * @return list<array{name:string,data:string,mime:string}>
 */
function buildAttachmentsForSend(array $files): array
{
    $attachments = [];
    foreach ($files as $file) {
        $data = (string)file_get_contents($file['path']);
        if ($data === '') {
            continue;
        }
        $attachments[] = [
            'name' => $file['name'],
            'data' => $data,
            'mime' => guessMimeType($file['name']),
        ];
    }

    return $attachments;
}

/**
 * @param list<array{name:string,path:string,size:int}> $files
 */
function formatAttachmentNames(array $files): string
{
    if ($files === []) {
        return '';
    }

    return implode(', ', array_map(static fn(array $file): string => $file['name'], $files));
}

/**
 * @return array{attachments:list<array{name:string,data:string,mime:string}>,savedPaths:list<string>,names:list<string>}
 */
function collectUploadedAttachments(array $filesInput, string $attachmentsDir): array
{
    $savedPaths = [];
    $fileRecords = [];

    if (!isset($filesInput['name']) || !is_array($filesInput['name'])) {
        return ['attachments' => [], 'savedPaths' => [], 'names' => []];
    }

    foreach ($filesInput['name'] as $index => $name) {
        if (!is_string($name) || $name === '') {
            continue;
        }

        $error = (int)($filesInput['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Attachment upload failed for ' . basename($name) . '.');
        }

        $safeName = basename($name);
        $tmpPath = (string)($filesInput['tmp_name'][$index] ?? '');
        $size = (int)($filesInput['size'][$index] ?? 0);

        if ($size <= 0 || !is_uploaded_file($tmpPath)) {
            continue;
        }
        if ($size > 20 * 1024 * 1024) {
            throw new RuntimeException('Attachment too large (max 20 MB each): ' . $safeName);
        }

        $savedPath = $attachmentsDir . DIRECTORY_SEPARATOR . $safeName;
        if (!move_uploaded_file($tmpPath, $savedPath)) {
            throw new RuntimeException('Could not save attachment: ' . $safeName);
        }

        $savedPaths[] = $savedPath;
        $fileRecords[] = [
            'name' => $safeName,
            'path' => $savedPath,
            'size' => $size,
        ];
    }

    return [
        'attachments' => buildAttachmentsForSend($fileRecords),
        'savedPaths' => $savedPaths,
        'names' => array_map(static fn(array $file): string => $file['name'], $fileRecords),
    ];
}

/**
 * @param list<array{name:string,path:string,size:int}> $existing
 * @param list<array{name:string,path:string,size:int}> $uploaded
 * @return list<array{name:string,path:string,size:int}>
 */
function mergeAttachmentFiles(array $existing, array $uploaded): array
{
    $merged = [];
    foreach (array_merge($existing, $uploaded) as $file) {
        $merged[$file['name']] = $file;
    }

    return array_values($merged);
}
