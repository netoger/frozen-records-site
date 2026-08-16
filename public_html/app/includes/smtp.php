<?php
// Минимальный SMTP-клиент на сокетах (без сторонних библиотек):
// SSL (465) или STARTTLS (587), AUTH LOGIN, письмо text/plain в UTF-8.

declare(strict_types=1);

final class SimpleSmtp
{
    /** @var resource|null */
    private $fp = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $secure, // 'ssl' | 'tls' | 'none'
        private readonly string $user,
        private readonly string $pass,
    ) {
    }

    /** Бросает RuntimeException при любой ошибке протокола или сети. */
    public function send(string $from, string $fromName, string $to, string $subject, string $body): void
    {
        $remote = ($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 10);
        if ($fp === false) {
            throw new RuntimeException("SMTP: нет соединения с {$this->host}:{$this->port} ($errstr)");
        }
        $this->fp = $fp;
        stream_set_timeout($fp, 10);

        try {
            $this->expect([220]);
            $this->cmd('EHLO ' . (parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost'), [250]);

            if ($this->secure === 'tls') {
                $this->cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP: не удалось включить TLS');
                }
                $this->cmd('EHLO ' . (parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost'), [250]);
            }

            if ($this->user !== '') {
                $this->cmd('AUTH LOGIN', [334]);
                $this->cmd(base64_encode($this->user), [334]);
                $this->cmd(base64_encode($this->pass), [235]);
            }

            $this->cmd("MAIL FROM:<{$from}>", [250]);
            $this->cmd("RCPT TO:<{$to}>", [250, 251]);
            $this->cmd('DATA', [354]);

            $headers = [
                'Date: ' . date('r'),
                'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . " <{$from}>",
                "To: <{$to}>",
                'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8'),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];
            $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));
            $this->cmd($data . '.', [250]);
            $this->cmd('QUIT', [221]);
        } finally {
            fclose($fp);
            $this->fp = null;
        }
    }

    /** @param int[] $codes */
    private function cmd(string $line, array $codes): string
    {
        fwrite($this->fp, $line . "\r\n");
        return $this->expect($codes);
    }

    /** @param int[] $codes */
    private function expect(array $codes): string
    {
        $response = '';
        // Ответ может быть многострочным: «250-…» продолжение, «250 …» конец
        while (($line = fgets($this->fp, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP: неожиданный ответ ' . trim($response));
        }
        return $response;
    }
}
