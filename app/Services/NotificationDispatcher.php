<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Instrada le notifiche verso webhook esterni o code di messaggistica (es. RabbitMQ).
 */
final class NotificationDispatcher
{
    /** @var array<int, string> */
    private array $webhookUrls;

    /** @var array<string, string> */
    private array $webhookHeaders;

    /** @var array<string, mixed>|null */
    private ?array $queueConfig;

    private ?string $logFile;

    public function __construct(
        ?string $webhookUrl = null,
        array $webhookHeaders = [],
        ?array $queueConfig = null,
        ?string $logFile = null
    ) {
        $this->webhookUrls = $this->normalizeWebhookUrls($webhookUrl);
        $this->webhookHeaders = $this->normalizeHeaders($webhookHeaders);
        $this->queueConfig = $queueConfig;
        $this->logFile = $logFile;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(array $payload): void
    {
        if ($this->webhookUrls !== []) {
            $this->dispatchWebhook($payload);
        }

        if ($this->queueConfig !== null) {
            $this->dispatchQueue($payload);
        }
    }

    /**
     * @return array<int, string>
     */
    private function normalizeWebhookUrls(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $segments = preg_split('/[\s,;]+/', trim($raw)) ?: [];
        $urls = [];
        foreach ($segments as $segment) {
            $candidate = trim($segment);
            if ($candidate === '') {
                continue;
            }
            $urls[] = $candidate;
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $name = is_int($key) ? (is_array($value) ? (string) array_key_first($value) : null) : (string) $key;
            if ($name === null || trim($name) === '') {
                continue;
            }
            if (is_array($value)) {
                $firstKey = array_key_first($value);
                $value = $firstKey !== null ? $value[$firstKey] : '';
            }
            $normalized[trim($name)] = trim((string) $value);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchWebhook(array $payload): void
    {
        if (!function_exists('curl_init')) {
            $this->log('Webhook non inviato: cURL non disponibile.');
            return;
        }

        $encoded = $this->encodePayload($payload);
        if ($encoded === null) {
            $this->log('Webhook non inviato: serializzazione payload fallita.');
            return;
        }

        $baseHeaders = array_change_key_case($this->webhookHeaders, CASE_LOWER);
        $hasContentType = array_key_exists('content-type', $baseHeaders);
        $headersToSend = [];
        foreach ($this->webhookHeaders as $name => $value) {
            $headersToSend[] = $name . ': ' . $value;
        }
        if (!$hasContentType) {
            $headersToSend[] = 'Content-Type: application/json';
        }

        foreach ($this->webhookUrls as $url) {
            $ch = curl_init($url);
            if ($ch === false) {
                $this->log('Webhook non inviato: init cURL fallito per ' . $url);
                continue;
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headersToSend);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);

            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($response === false || $status < 200 || $status >= 300) {
                $error = curl_error($ch);
                $this->log(sprintf('Webhook %s fallito (status %s): %s', $url, (string) $status, $response === false ? $error : $response));
            }

            curl_close($ch);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchQueue(array $payload): void
    {
        $config = $this->queueConfig;
        if ($config === null) {
            return;
        }

        $dsn = isset($config['dsn']) ? (string) $config['dsn'] : '';
        if ($dsn === '') {
            $this->log('Queue non inviata: DSN mancante.');
            return;
        }

        $connectionParams = $this->parseAmqpDsn($dsn);
        if ($connectionParams === null) {
            $this->log('Queue non inviata: DSN non valido.');
            return;
        }

        $connectionClass = '\\PhpAmqpLib\\Connection\\AMQPStreamConnection';
        $messageClass = '\\PhpAmqpLib\\Message\\AMQPMessage';
        if (!class_exists($connectionClass) || !class_exists($messageClass)) {
            $this->log('Queue non inviata: PhpAmqpLib non disponibile.');
            return;
        }

        $encoded = $this->encodePayload($payload);
        if ($encoded === null) {
            $this->log('Queue non inviata: serializzazione payload fallita.');
            return;
        }

        $exchange = isset($config['exchange']) && $config['exchange'] !== '' ? (string) $config['exchange'] : 'coresuite.notifications';
        $routingKey = isset($config['routing_key']) ? (string) $config['routing_key'] : 'event';
        $queueName = isset($config['queue']) && $config['queue'] !== '' ? (string) $config['queue'] : null;

        try {
            /** @var object $connection */
            $connection = new $connectionClass(
                $connectionParams['host'],
                $connectionParams['port'],
                $connectionParams['user'],
                $connectionParams['password'],
                $connectionParams['vhost']
            );
            $channel = $connection->channel();
            $channel->exchange_declare($exchange, 'topic', false, true, false);
            if ($queueName !== null) {
                $channel->queue_declare($queueName, false, true, false, false);
                $channel->queue_bind($queueName, $exchange, $routingKey);
            }

            /** @var object $message */
            $message = new $messageClass(
                $encoded,
                [
                    'content_type' => 'application/json',
                    'delivery_mode' => 2,
                ]
            );
            $channel->basic_publish($message, $exchange, $routingKey);

            $channel->close();
            $connection->close();
        } catch (\Throwable $exception) {
            $this->log('Queue non inviata: ' . $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseAmqpDsn(string $dsn): ?array
    {
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        $vhost = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '';

        return [
            'host' => (string) $parts['host'],
            'port' => isset($parts['port']) ? (int) $parts['port'] : 5672,
            'user' => isset($parts['user']) ? (string) $parts['user'] : 'guest',
            'password' => isset($parts['pass']) ? (string) $parts['pass'] : 'guest',
            'vhost' => $vhost !== '' ? $vhost : '/',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): ?string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? null : $encoded;
    }

    private function log(string $message): void
    {
        if ($this->logFile === null) {
            return;
        }

        $line = sprintf('[%s] %s%s', date('c'), $message, PHP_EOL);
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
