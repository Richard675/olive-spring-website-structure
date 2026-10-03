<?php
/**
 * Minimal, dependency-free mailer.
 * Drivers: "mail" (PHP mail()), "smtp" (STARTTLS/SSL + AUTH LOGIN), "log" (writes to storage/logs/mail.log).
 */
declare(strict_types=1);

/** Send a plain-text email. Returns true on success and never throws. */
function send_mail(string $to, string $subject, string $body, ?string $replyTo = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if ($replyTo !== null && (!filter_var($replyTo, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $replyTo))) $replyTo = null;

    $from = (string)config('mail_from');
    $fromName = (string)config('mail_from_name');
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        'From' => '=?UTF-8?B?' . base64_encode($fromName) . "?= <$from>",
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
        'X-Mailer' => 'OliveSprigsSite',
    ];
    if ($replyTo) $headers['Reply-To'] = $replyTo;
    $body = str_replace(["\r\n", "\r"], "\n", $body);

    try {
        switch (config('mail_driver', 'mail')) {
            case 'log':
                $entry = "To: $to\nSubject: $subject\n" . ($replyTo ? "Reply-To: $replyTo\n" : '') . "\n$body\n" . str_repeat('-', 60) . "\n";
                return file_put_contents(storage_path('logs/mail.log'), $entry, FILE_APPEND | LOCK_EX) !== false;
            case 'smtp':
                return smtp_send($to, $encodedSubject, $body, $headers, $from);
            default:
                return @mail($to, $encodedSubject, $body, $headers, '-f' . $from);
        }
    } catch (Throwable $e) {
        app_log('Mail failed: ' . $e->getMessage());
        return false;
    }
}

function smtp_send(string $to, string $subject, string $body, array $headers, string $from): bool
{
    $cfg = config('smtp');
    $host = (string)$cfg['host'];
    if ($host === '') throw new RuntimeException('SMTP host is not configured.');
    $port = (int)$cfg['port'];
    $ssl = $cfg['encryption'] === 'ssl';
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
    $socket = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, (int)$cfg['timeout'], STREAM_CLIENT_CONNECT, $context);
    if (!$socket) throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
    stream_set_timeout($socket, (int)$cfg['timeout']);

    $read = static function () use ($socket): array {
        $text = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return [(int)substr($text, 0, 3), $text];
    };
    $cmd = static function (string $command, array $expect) use ($socket, $read): string {
        fwrite($socket, $command . "\r\n");
        [$code, $text] = $read();
        if (!in_array($code, $expect, true)) {
            $shown = str_starts_with($command, 'AUTH') || strlen($command) > 60 ? '[credentials]' : $command;
            throw new RuntimeException("SMTP rejected $shown: " . trim($text));
        }
        return $text;
    };

    [$code] = $read();
    if ($code !== 220) throw new RuntimeException('SMTP server did not greet.');
    $ehloHost = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost') ?: 'localhost';
    $cmd("EHLO $ehloHost", [250]);
    if (!$ssl && $cfg['encryption'] === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new RuntimeException('SMTP STARTTLS failed.');
        }
        $cmd("EHLO $ehloHost", [250]);
    }
    if ($cfg['username'] !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode((string)$cfg['username']), [334]);
        $cmd(base64_encode((string)$cfg['password']), [235]);
    }
    $cmd("MAIL FROM:<$from>", [250]);
    $cmd("RCPT TO:<$to>", [250, 251]);
    $cmd('DATA', [354]);

    $headers['To'] = $to;
    $headers['Subject'] = $subject;
    $headers['Date'] = date('r');
    $headers['Message-ID'] = '<' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@'), 1) . '>';
    $raw = '';
    foreach ($headers as $k => $v) $raw .= "$k: $v\r\n";
    // Dot-stuffing per RFC 5321
    $lines = array_map(static fn ($l) => str_starts_with($l, '.') ? '.' . $l : $l, explode("\n", $body));
    $raw .= "\r\n" . implode("\r\n", $lines) . "\r\n.";
    $cmd($raw, [250]);
    $cmd('QUIT', [221]);
    fclose($socket);
    return true;
}
