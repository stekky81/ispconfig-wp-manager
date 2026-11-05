<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Fake Remote API used in dry-run mode and in tests: answers like ISPConfig, changes nothing.
 * Set $failOn to a method name to simulate an API error at that step.
 */
final class DryRunTransport
{
    private int $nextId = 1000;
    /** @var array<int, array> */
    private array $domains = [];
    /** @var list<string> */
    public array $received = [];

    /** @param list<string> $existingDomains */
    public function __construct(
        public ?string $failOn = null,
        private readonly array $existingDomains = [],
        public ?string $failDeleteOn = null,
    ) {
    }

    public function __invoke(string $url, array $payload): array
    {
        $method = substr($url, (int)strrpos($url, '?') + 1);
        $this->received[] = $method;

        if ($method === $this->failOn || $method === $this->failDeleteOn) {
            return ['code' => 'remote_fault', 'message' => "simulated failure in {$method}", 'response' => false];
        }

        return match (true) {
            $method === 'login' => $this->ok('dry-run-session'),
            $method === 'logout' => $this->ok(true),
            $method === 'sites_web_domain_get' => $this->ok($this->domainGet($payload['primary_id'] ?? null)),
            $method === 'sites_web_domain_add' => $this->ok($this->domainAdd($payload['params'] ?? [])),
            str_ends_with($method, '_add') => $this->ok($this->nextId++),
            str_ends_with($method, '_delete') => $this->ok(1),
            default => ['code' => 'remote_fault', 'message' => "not simulated: {$method}", 'response' => false],
        };
    }

    private function ok(mixed $response): array
    {
        return ['code' => 'ok', 'message' => '', 'response' => $response];
    }

    private function domainAdd(array $params): int
    {
        $id = $this->nextId++;
        $this->domains[$id] = [
            'domain_id' => $id,
            'domain' => $params['domain'] ?? '',
            'system_user' => 'web' . $id,
            'system_group' => 'client1',
            'document_root' => '/var/www/clients/client1/web' . $id,
        ];
        return $id;
    }

    private function domainGet(mixed $filter): array
    {
        if (is_array($filter) && isset($filter['domain'])) {
            if (in_array($filter['domain'], $this->existingDomains, true)) {
                return [['domain_id' => 1, 'domain' => $filter['domain']]];
            }
            return [];
        }
        return $this->domains[(int)$filter] ?? [];
    }
}
