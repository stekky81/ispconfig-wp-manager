<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Public SSH keys for the SFTP user of a site. ISPConfig writes them in authorized_keys of the jailed user
 * (shell_user.ssh_rsa) and adds the key of the client, if it has one. Only plain keys are accepted: no options such as
 * command= or from=, which would change what a login can do, and the key inside must be of the type it says.
 */
final class SshKeys
{
    public const MAX_KEYS = 10;
    public const TYPES = [
        'ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com',
    ];

    /**
     * One key per line; empty lines are ignored.
     *
     * @return list<array{type:string, blob:string, comment:string}>
     */
    public static function parse(string $text): array
    {
        $keys = [];
        foreach (preg_split('/\R/', trim($text)) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^(\S+) ([A-Za-z0-9+\/]+={0,3})(?: ([\x20-\x7E]{0,200}))?$/', $line, $m) || !in_array($m[1], self::TYPES, true)) {
                throw new ValidationException('SSH key on line ' . ($number + 1) . ' is not a public key of a known type (ssh-ed25519, ssh-rsa, ecdsa, without options)');
            }
            $blob = base64_decode($m[2], true);
            // The key starts with its own type, as a string with its length: a pasted private key or garbage does not.
            if ($blob === false || strlen($blob) < 4 || substr($blob, 4, unpack('N', $blob)[1]) !== $m[1]) {
                throw new ValidationException('SSH key on line ' . ($number + 1) . ' is damaged: copy the whole line of the .pub file');
            }
            if ($m[1] === 'ssh-rsa' && strlen($blob) < 270) {
                throw new ValidationException('SSH key on line ' . ($number + 1) . ' is an RSA key shorter than 2048 bits');
            }
            $keys[$m[2]] = ['type' => $m[1], 'blob' => $m[2], 'comment' => trim($m[3] ?? '')];
        }
        if (count($keys) > self::MAX_KEYS) {
            throw new ValidationException('At most ' . self::MAX_KEYS . ' SSH keys');
        }
        return array_values($keys);
    }

    /** @param list<array{type:string, blob:string, comment:string}> $keys */
    public static function format(array $keys): string
    {
        return implode("\n", array_map(static fn(array $k): string => trim("{$k['type']} {$k['blob']} {$k['comment']}"), $keys));
    }

    /** As ssh-keygen -l prints it. */
    public static function fingerprint(string $blob): string
    {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', (string)base64_decode($blob, true), true)), '=');
    }
}
