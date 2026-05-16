<?php

declare(strict_types=1);

namespace App\Docs;

use RuntimeException;

/**
 * Production backend: a remote Turso (libSQL) database spoken over the
 * stateless HTTP pipeline API (`POST /v2/pipeline`). Uses the built-in
 * https stream wrapper — no extension, so it runs on any plain PHP
 * host.
 *
 * @see https://docs.turso.tech/sdk/http/reference
 */
final class TursoConnection implements SqlConnection
{
    private string $endpoint;

    public function __construct(
        string $url,
        private readonly string $authToken,
    ) {
        $this->endpoint = self::httpEndpoint($url);
    }

    /**
     * Turso hands out `libsql://host` URLs; the HTTP API lives on the
     * same host over https. Accept the common scheme spellings.
     */
    private static function httpEndpoint(string $url): string
    {
        $url = \trim($url);
        $url = \preg_replace('#^libsql://#i', 'https://', $url) ?? $url;
        $url = \preg_replace('#^wss://#i', 'https://', $url) ?? $url;
        $url = \preg_replace('#^ws://#i', 'http://', $url) ?? $url;
        if (!\preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        return \rtrim($url, '/') . '/v2/pipeline';
    }

    public function query(string $sql, array $params = []): array
    {
        $results = $this->pipeline([[$sql, $params]]);

        return $this->rowsToAssoc($results[0]);
    }

    public function execute(string $sql, array $params = []): void
    {
        $this->pipeline([[$sql, $params]]);
    }

    public function transactional(array $ops): void
    {
        $statements = [['BEGIN', []]];
        foreach ($ops as $op) {
            $statements[] = $op;
        }
        $statements[] = ['COMMIT', []];

        $this->pipeline($statements);
    }

    /**
     * Send a list of [sql, params] as one pipeline and return the
     * libSQL "execute" result objects (cols + rows), one per statement.
     *
     * @param list<array{0: string, 1: list<mixed>}> $statements
     *
     * @return list<array{cols: list<string>, rows: list<list<mixed>>}>
     */
    private function pipeline(array $statements): array
    {
        $requests = [];
        foreach ($statements as [$sql, $params]) {
            $requests[] = [
                'type' => 'execute',
                'stmt' => [
                    'sql' => $sql,
                    'args' => \array_map([self::class, 'encodeArg'], $params),
                ],
            ];
        }
        $requests[] = ['type' => 'close'];

        $payload = \json_encode(['requests' => $requests], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        $context = \stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->authToken,
                ],
                'content' => $payload,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $body = @\file_get_contents($this->endpoint, false, $context);

        if (false === $body) {
            $err = \error_get_last();

            throw new RuntimeException('Turso request failed: ' . ($err['message'] ?? 'network error'));
        }

        $status = self::statusFromHeaders($http_response_header ?? []);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Turso HTTP {$status}: " . \substr($body, 0, 500));
        }

        /** @var array{results?: list<array<string, mixed>>} $decoded */
        $decoded = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        $out = [];
        foreach ($decoded['results'] ?? [] as $index => $result) {
            if (($result['type'] ?? '') !== 'ok') {
                $message = $result['error']['message'] ?? 'unknown error';

                throw new RuntimeException("Turso statement {$index} failed: {$message}");
            }

            $response = $result['response'] ?? [];
            if (($response['type'] ?? '') !== 'execute') {
                continue;
            }

            $res = $response['result'] ?? [];
            $cols = [];
            foreach ($res['cols'] ?? [] as $col) {
                $cols[] = (string) ($col['name'] ?? '');
            }
            $rows = [];
            foreach ($res['rows'] ?? [] as $row) {
                $rows[] = \array_map([self::class, 'decodeValue'], $row);
            }
            $out[] = ['cols' => $cols, 'rows' => $rows];
        }

        return $out;
    }

    /**
     * @param list<string> $headers
     */
    private static function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $header) {
            if (\preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /**
     * @param array{cols: list<string>, rows: list<list<mixed>>} $result
     *
     * @return list<array<string, mixed>>
     */
    private function rowsToAssoc(array $result): array
    {
        $cols = $result['cols'];
        $assoc = [];
        foreach ($result['rows'] as $row) {
            $entry = [];
            foreach ($cols as $i => $name) {
                $entry[$name] = $row[$i] ?? null;
            }
            $assoc[] = $entry;
        }

        return $assoc;
    }

    /**
     * @return array<string, mixed>
     */
    private static function encodeArg(mixed $value): array
    {
        return match (true) {
            null === $value => ['type' => 'null'],
            \is_bool($value) => ['type' => 'integer', 'value' => $value ? '1' : '0'],
            \is_int($value) => ['type' => 'integer', 'value' => (string) $value],
            \is_float($value) => ['type' => 'float', 'value' => $value],
            default => ['type' => 'text', 'value' => (string) $value],
        };
    }

    private static function decodeValue(mixed $cell): mixed
    {
        if (!\is_array($cell)) {
            return $cell;
        }

        return match ($cell['type'] ?? '') {
            'null' => null,
            'integer' => (int) ($cell['value'] ?? 0),
            'float' => (float) ($cell['value'] ?? 0),
            'blob' => \base64_decode((string) ($cell['base64'] ?? ''), true) ?: '',
            default => (string) ($cell['value'] ?? ''),
        };
    }
}
