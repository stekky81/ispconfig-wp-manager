<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Input validation shared by the panel form and the worker.
 * Every value that reaches the Remote API or a shell command passes through here.
 */
final class Validator
{
    /** Lower-case hostname with at least one dot, IDN must already be punycode. */
    public static function domain(string $value): string
    {
        $value = strtolower(trim($value));
        if (strlen($value) > 253 || !str_contains($value, '.')) {
            throw new ValidationException("Invalid domain: {$value}");
        }
        foreach (explode('.', $value) as $label) {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                throw new ValidationException("Invalid domain: {$value}");
            }
        }
        return $value;
    }

    public static function email(string $value): string
    {
        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException("Invalid email: {$value}");
        }
        return $value;
    }

    /** WordPress admin user name: letters, digits, dot, dash, underscore, 3-60 chars, not "admin". */
    public static function wpUser(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $value)) {
            throw new ValidationException("Invalid WordPress user name: {$value}");
        }
        if (strtolower($value) === 'admin') {
            throw new ValidationException('The WordPress user name "admin" is not allowed');
        }
        return $value;
    }

    /** How the site owner reaches the files: 'sftp' (jailed shell user) or 'ftp' (FTP user). */
    public static function accessType(string $value): string
    {
        if (!in_array($value, ['sftp', 'ftp'], true)) {
            throw new ValidationException("Invalid access type: {$value}");
        }
        return $value;
    }

    /** WordPress locale, e.g. it_IT or de_DE_formal. */
    public static function locale(string $value): string
    {
        if (!preg_match('/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z]+)?$/', $value)) {
            throw new ValidationException("Invalid locale: {$value}");
        }
        return $value;
    }

    /** Plugin or theme slug from wordpress.org. */
    public static function wpSlug(string $value): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $value)) {
            throw new ValidationException("Invalid plugin or theme name: {$value}");
        }
        return $value;
    }

    /** @return list<string> slugs from a list separated by commas or spaces */
    public static function wpSlugList(string $value): array
    {
        $slugs = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_map(self::wpSlug(...), $slugs);
    }

    /** Linux system user created by ISPConfig for a website, e.g. web12. */
    public static function systemUser(string $value): string
    {
        if (!preg_match('/^web[0-9]{1,9}$/', $value)) {
            throw new ValidationException("Unexpected system user: {$value}");
        }
        return $value;
    }

    public static function positiveId(mixed $value): int
    {
        if (!is_numeric($value) || (int)$value < 1 || (string)(int)$value !== (string)$value) {
            throw new ValidationException('Invalid id: ' . var_export($value, true));
        }
        return (int)$value;
    }

    /** Id of an additional PHP version; 0 is the default PHP of the server. */
    public static function phpVersionId(mixed $value): int
    {
        return (string)$value === '0' ? 0 : self::positiveId($value);
    }

    /** Database or user name fragment: ISPConfig prefixes it, we keep it short and safe. */
    public static function dbName(string $value): string
    {
        if (!preg_match('/^[a-z0-9_]{1,32}$/', $value)) {
            throw new ValidationException("Invalid database name: {$value}");
        }
        return $value;
    }

    /** Derive a safe database name fragment from a domain: example-shop.com → example_shop_com */
    public static function dbNameFromDomain(string $domain): string
    {
        $domain = self::domain($domain);
        $name = trim((string)preg_replace('/[^a-z0-9]+/', '_', $domain), '_');
        if (strlen($name) > 24) {
            // A plain cut would give the same name to long domains with the same beginning.
            $name = rtrim(substr($name, 0, 17), '_') . '_' . substr(sha1($domain), 0, 6);
        }
        return self::dbName($name);
    }
}
