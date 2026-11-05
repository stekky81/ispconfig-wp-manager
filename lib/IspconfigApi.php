<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Minimal client for the ISPConfig REST Remote API (remote/json.php).
 * The HTTP transport is injectable so tests (and dry-run) never touch a real server.
 */
final class IspconfigApi
{
    /** @var callable(string $url, array $payload): array */
    private $transport;
    private ?string $session = null;
    /** @var list<array{method:string, params:array}> */
    private array $calls = [];

    public function __construct(
        private readonly string $url,
        private readonly string $user,
        private readonly string $password,
        ?callable $transport = null,
        private readonly bool $verifyTls = true,
    ) {
        $this->transport = $transport ?? $this->curlTransport(...);
    }

    public static function fromConfig(Config $config, ?callable $transport = null): self
    {
        return new self(
            $config->get('remote_api.url'),
            $config->get('remote_api.user'),
            $config->get('remote_api.password'),
            $transport,
            (bool)$config->get('remote_api.verify_tls'),
        );
    }

    /** @return mixed the "response" field of the API answer */
    public function call(string $method, array $params = []): mixed
    {
        if (!preg_match('/^[a-z_]+$/', $method)) {
            throw new \InvalidArgumentException("Invalid API method: {$method}");
        }
        if ($method !== 'login') {
            $params = ['session_id' => $this->session()] + $params;
        }
        $this->calls[] = ['method' => $method, 'params' => self::maskSecrets($params)];

        $answer = ($this->transport)($this->url . '?' . $method, $params);
        if (($answer['code'] ?? null) !== 'ok') {
            throw new ApiException($method, (string)($answer['message'] ?? 'unknown error'));
        }
        return $answer['response'] ?? null;
    }

    private function session(): string
    {
        if ($this->session === null) {
            $id = $this->call('login', ['username' => $this->user, 'password' => $this->password]);
            if (!is_string($id) || $id === '') {
                throw new ApiException('login', 'no session id returned');
            }
            $this->session = $id;
        }
        return $this->session;
    }

    public function logout(): void
    {
        if ($this->session !== null) {
            try {
                $this->call('logout');
            } finally {
                $this->session = null;
            }
        }
    }

    /** Call history with passwords masked, for logs and tests. */
    public function calls(): array
    {
        return $this->calls;
    }

    private static function maskSecrets(array $params): array
    {
        array_walk_recursive($params, function (&$v, $k) {
            if (is_string($k) && (str_contains($k, 'password') || $k === 'session_id')) {
                $v = '********';
            }
        });
        return $params;
    }

    private function curlTransport(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false) {
            throw new ApiException('transport', $error);
        }
        if ($status !== 200) {
            throw new ApiException('transport', "HTTP {$status}");
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new ApiException('transport', 'invalid JSON answer');
        }
        return $data;
    }
}
