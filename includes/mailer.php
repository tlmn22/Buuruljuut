<?php
/**
 * Гадны сангүй энгийн SMTP илгээгч (STARTTLS / SSL, AUTH LOGIN, UTF-8, HTML + текст).
 * Тохиргоо: config/mail.php (загвар нь config/mail.php.example).
 */

/** config/mail.php байгаа эсэх — байхгүй бол имэйлээр сэргээх боломжгүй */
function mailConfigured(): bool {
    $file = $_SERVER['DOCUMENT_ROOT'] . '/buuruljuut/config/mail.php';
    if (!is_file($file)) return false;
    require_once $file;
    return defined('MAIL_HOST') && MAIL_HOST !== '' && MAIL_HOST !== 'smtp.example.com';
}

/**
 * Имэйл илгээнэ. Алдаа гарвал Exception шиднэ (мессеж нь SMTP-ийн хариуг агуулна).
 */
function sendMail(string $toEmail, string $toName, string $subject, string $html, string $text): void {
    if (!mailConfigured()) throw new Exception('Имэйлийн тохиргоо (config/mail.php) хийгдээгүй байна.');

    $remote = (MAIL_SECURE === 'ssl' ? 'ssl://' : 'tcp://') . MAIL_HOST . ':' . MAIL_PORT;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new Exception("SMTP сервертэй холбогдож чадсангүй: $errstr ($errno)");
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break; // олон мөрт хариуны сүүлийн мөр
        }
        return $data;
    };
    $cmd = function (string $command, array $expect) use ($fp, $read): string {
        if ($command !== '') fwrite($fp, $command . "\r\n");
        $resp = $read();
        if (!in_array((int)substr($resp, 0, 3), $expect, true)) {
            fclose($fp);
            // AUTH-ийн үед нууц үг лог руу орохгүйн тулд командыг харуулахгүй
            throw new Exception('SMTP алдаа: ' . trim($resp));
        }
        return $resp;
    };

    $hostname = gethostname() ?: 'localhost';
    $cmd('', [220]);
    $cmd("EHLO $hostname", [250]);
    if (MAIL_SECURE === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            fclose($fp);
            throw new Exception('SMTP TLS холболт тогтоож чадсангүй.');
        }
        $cmd("EHLO $hostname", [250]);
    }
    if (MAIL_USERNAME !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode(MAIL_USERNAME), [334]);
        $cmd(base64_encode(MAIL_PASSWORD), [235]);
    }
    $cmd('MAIL FROM:<' . MAIL_FROM_EMAIL . '>', [250]);
    $cmd('RCPT TO:<' . $toEmail . '>', [250, 251]);
    $cmd('DATA', [354]);

    $enc = function (string $s): string { return '=?UTF-8?B?' . base64_encode($s) . '?='; };
    $boundary = 'b' . bin2hex(random_bytes(12));
    $headers = [
        'Date: ' . date('r'),
        'From: ' . $enc(MAIL_FROM_NAME) . ' <' . MAIL_FROM_EMAIL . '>',
        'To: ' . ($toName !== '' ? $enc($toName) . ' ' : '') . '<' . $toEmail . '>',
        'Subject: ' . $enc($subject),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . (explode('@', MAIL_FROM_EMAIL)[1] ?? 'localhost') . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($text))
          . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($html))
          . "--$boundary--";
    // base64 агуулгад "." -ээр эхэлсэн мөр гарахгүй ч SMTP dot-stuffing-ийг баталгаажуулна
    $message = preg_replace('/^\./m', '..', implode("\r\n", $headers) . "\r\n\r\n" . $body);
    $cmd($message . "\r\n.", [250]);
    $cmd('QUIT', [221]);
    fclose($fp);
}
