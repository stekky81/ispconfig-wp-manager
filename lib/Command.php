<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * An external command built from an argument list (never from a string), optionally run as another user.
 */
final class Command
{
    /** @param list<string> $args */
    public function __construct(
        public readonly array $args,
        public readonly ?string $runAs = null,
        public readonly ?string $cwd = null,
        /** Data written to the process stdin: used for secrets so they never appear in `ps`. */
        public readonly ?string $stdin = null,
    ) {
        if ($args === []) {
            throw new \InvalidArgumentException('Empty command');
        }
    }

    /** Full argv, including the sudo prefix when running as another user. */
    public function argv(): array
    {
        if ($this->runAs === null) {
            return $this->args;
        }
        return array_merge(['sudo', '-u', Validator::systemUser($this->runAs), '-H', '--'], $this->args);
    }

    /** Shell-safe single string, used for logs and dry-run output only. */
    public function toString(): string
    {
        return implode(' ', array_map('escapeshellarg', $this->argv()));
    }

    /** Same as toString() but with the given secrets masked. */
    public function toSafeString(array $secrets): string
    {
        $out = $this->toString();
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $out = str_replace($secret, '********', $out);
            }
        }
        return $out;
    }
}
