<?php

namespace Geocrowd;

/**
 * Envoi d'e-mails en texte brut, configuré par la clé 'mail' de config.php : par un serveur
 * SMTP si 'smtp' est renseigné, sinon par la fonction mail() de PHP. Sans configuration,
 * les fonctions qui en dépendent (double authentification par e-mail) sont indisponibles.
 */
final class Mailer
{
    private const TIMEOUT = 15;

    public function __construct(private readonly ?array $config)
    {
    }

    public function enabled(): bool
    {
        return is_string($this->config['from'] ?? null) && filter_var($this->config['from'], FILTER_VALIDATE_EMAIL);
    }

    /** Moyen d'envoi, pour l'affichage : 'smtp', 'mail' ou null. */
    public function transport(): ?string
    {
        return $this->enabled() ? (empty($this->config['smtp']['host']) ? 'mail' : 'smtp') : null;
    }

    public function send(string $to, string $subject, string $body): void
    {
        if (!$this->enabled()) {
            throw new HttpError(503, 'L\'envoi d\'e-mails n\'est pas configuré.');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject)) {
            throw new \InvalidArgumentException('Destinataire ou sujet invalide.');
        }
        try {
            $this->transport() === 'smtp' ? $this->smtp($to, $subject, $body) : $this->mail($to, $subject, $body);
        } catch (\RuntimeException $e) {
            error_log('geocrowd, envoi d\'e-mail : ' . $e->getMessage());
            throw new HttpError(502, 'L\'e-mail n\'a pas pu être envoyé. Vérifiez la configuration de l\'envoi (config.php).');
        }
    }

    private function mail(string $to, string $subject, string $body): void
    {
        $headers = $this->headers($to);
        unset($headers['To'], $headers['Subject']);
        $lines = array_map(fn ($name, $value) => "$name: $value", array_keys($headers), $headers);
        if (!mail($to, self::encodeHeader($subject), chunk_split(base64_encode($body)), implode("\r\n", $lines))) {
            throw new \RuntimeException('mail() a échoué.');
        }
    }

    private function smtp(string $to, string $subject, string $body): void
    {
        $smtp = $this->config['smtp'];
        $secure = $smtp['secure'] ?? 'tls';
        $port = (int) ($smtp['port'] ?? ($secure === 'ssl' ? 465 : 587));
        $socket = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $smtp['host'] . ':' . $port, $errno, $error, self::TIMEOUT);
        if (!$socket) {
            throw new \RuntimeException("connexion à {$smtp['host']}:$port impossible ($error)");
        }
        stream_set_timeout($socket, self::TIMEOUT);

        try {
            $this->expect($socket, 220);
            $hostname = gethostname() ?: 'localhost';
            $this->command($socket, "EHLO $hostname", 250);
            if ($secure === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('échec de STARTTLS');
                }
                $this->command($socket, "EHLO $hostname", 250);
            }
            if (!empty($smtp['user'])) {
                $this->command($socket, 'AUTH LOGIN', 334);
                $this->command($socket, base64_encode($smtp['user']), 334);
                $this->command($socket, base64_encode((string) ($smtp['password'] ?? '')), 235);
            }
            $this->command($socket, "MAIL FROM:<{$this->config['from']}>", 250);
            $this->command($socket, "RCPT TO:<$to>", [250, 251]);
            $this->command($socket, 'DATA', 354);

            $headers = $this->headers($to) + ['Subject' => self::encodeHeader($subject)];
            $message = implode("\r\n", array_map(fn ($name, $value) => "$name: $value", array_keys($headers), $headers))
                . "\r\n\r\n" . chunk_split(base64_encode($body));
            // Une ligne commençant par un point est doublée (RFC 5321) ; le base64 n'en contient pas.
            fwrite($socket, $message . ".\r\n");
            $this->expect($socket, 250);
            $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    private function headers(string $to): array
    {
        $from = $this->config['from'];
        $name = $this->config['from_name'] ?? 'geocrowd';
        return [
            'From' => self::encodeHeader($name) . " <$from>",
            'To' => "<$to>",
            'Date' => date(DATE_RFC2822),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . substr(strrchr($from, '@'), 1) . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
        ];
    }

    private static function encodeHeader(string $text): string
    {
        return preg_match('/^[\x20-\x7e]*$/', $text) ? $text : '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    /** @param int|int[] $expected */
    private function command($socket, string $line, int|array $expected): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expected);
    }

    /** Lit une réponse (éventuellement sur plusieurs lignes) et vérifie son code. */
    private function expect($socket, int|array $expected): void
    {
        $response = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if (!in_array((int) substr($response, 0, 3), (array) $expected, true)) {
            throw new \RuntimeException('réponse SMTP inattendue : ' . trim($response ?: 'aucune'));
        }
    }
}
