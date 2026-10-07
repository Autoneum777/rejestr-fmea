<?php

declare(strict_types=1);

namespace App;

final class Mailer
{
    public function status(): array
    {
        $config = $this->config();
        return [
            'ready' => $config['host'] !== '' && $config['from'] !== '',
            'from' => $config['from'],
            'host' => $config['host'],
        ];
    }

    public function send(string $to, string $subject, string $body): ?string
    {
        $config = $this->config();
        if ($config['host'] === '' || $config['from'] === '') {
            return 'Brak konfiguracji SMTP w api/data/mail.json';
        }
        $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8');
        $message = "From: {$config['from']}\r\n";
        $message .= "To: {$to}\r\n";
        $message .= "Subject: {$encodedSubject}\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "\r\n" . str_replace("\n", "\r\n", $body) . "\r\n";

        $socket = @fsockopen($config['host'], $config['port'], $errno, $errstr, 8);
        if (!is_resource($socket)) {
            return 'Nie udało się połączyć z serwerem poczty: ' . $errstr;
        }
        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO rejestr-fmea', [250]);
            $this->command($socket, 'MAIL FROM:<' . $config['from'] . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            fwrite($socket, $message . ".\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } catch (\RuntimeException $error) {
            return $error->getMessage();
        } finally {
            fclose($socket);
        }
        return null;
    }

    private function config(): array
    {
        $path = Database::dataDir() . DIRECTORY_SEPARATOR . 'mail.json';
        $data = [];
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        return [
            'from' => trim((string) ($data['from'] ?? '')),
            'host' => trim((string) ($data['host'] ?? '')),
            'port' => max(1, (int) ($data['port'] ?? 25)),
        ];
    }

    private function command($socket, string $command, array $ok): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expect($socket, $ok);
    }

    private function expect($socket, array $ok): void
    {
        $response = '';
        while (($line = fgets($socket, 512)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new \RuntimeException(trim($response) !== '' ? trim($response) : 'Pusta odpowiedź serwera poczty');
        }
    }
}
