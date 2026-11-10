<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * What must change in the database of a clone. The rules run through `wp search-replace --regex`,
 * which unserializes every value, replaces and serializes it again, so the lengths stay right.
 * Regexes because a plain replace of old.com would also turn old.company.it into new.testpany.it.
 *
 * Email addresses and bare domains in a text are left alone on purpose: they are content.
 */
final class SearchReplacePlan
{
    /** Where a domain ends: not followed by more of a host name. A final dot ends a sentence. */
    private const HOST_END = '(?![a-z0-9-]|\.[a-z0-9])';

    private readonly string $oldHost;
    private readonly string $newBase;

    public function __construct(
        string $oldUrl,
        string $newUrl,
        private readonly string $oldPath,
        private readonly string $newPath,
    ) {
        $host = strtolower((string)parse_url($oldUrl, PHP_URL_HOST));
        try {
            // The address comes from the options of the site: its owner controls it.
            Validator::domain($host);
        } catch (ValidationException) {
            throw new \InvalidArgumentException("Unusable site address: {$oldUrl}");
        }
        $this->oldHost = (string)preg_replace('/^www\./', '', $host);
        $this->newBase = rtrim($newUrl, '/');
    }

    public function oldHost(): string
    {
        return $this->oldHost;
    }

    /** @return list<array{string, string}> pattern for the # delimiter and flag i, replacement for preg_replace */
    public function rules(): array
    {
        $host = '(www\.)?' . preg_quote($this->oldHost, '#') . self::HOST_END;
        $scheme = (string)parse_url($this->newBase, PHP_URL_SCHEME);
        $rest = substr($this->newBase, strlen($scheme) + 3);
        $literal = static fn(string $text): string => str_replace(['\\', '$'], ['\\\\', '\\$'], $text);

        return [
            // Before the plain form, which would not match them anyway: \/ is not / and %2F is not /.
            ['https?:\\\\/\\\\/' . $host, $literal($scheme . ':\\/\\/' . str_replace('/', '\\/', $rest))],
            ['https?%3A%2F%2F' . $host, $literal($scheme . '%3A%2F%2F' . str_replace('/', '%2F', $rest))],
            ['https?://' . $host, $literal($this->newBase)],
            // What is left has no scheme: //old.com/image.png
            ['(?<![a-z0-9:])//' . $host, $literal('//' . $rest)],
            [preg_quote($this->oldPath, '#') . '(?![a-z0-9_-])', $literal($this->newPath)],
        ];
    }

    /** The rules applied here, as WP-CLI applies them to each value. */
    public function apply(string $text): string
    {
        foreach ($this->rules() as [$pattern, $replacement]) {
            $text = (string)preg_replace('#' . $pattern . '#i', $replacement, $text);
        }
        return $text;
    }

    /**
     * The old host as a name of its own, with or without www, also in an email address: not as the
     * end of a longer host (staging.old.com is the new address of a staging of old.com).
     */
    public function mentions(string $text): bool
    {
        $host = preg_quote($this->oldHost, '#');
        return (bool)preg_match('#(?:(?<![a-z0-9.-])www\.|(?<![a-z0-9.-]))' . $host . self::HOST_END . '#i', $text);
    }

    /** Text of the database rows worth a second look after WP-CLI: they mention the old site. */
    public function needles(): array
    {
        return [$this->oldHost, $this->oldPath];
    }

    /**
     * Pieces of the old host in base64, at the three possible offsets: a plain search finds
     * encoded values, which no replace can reach and which the report must mention.
     *
     * @return list<string>
     */
    public function base64Needles(): array
    {
        $needles = [];
        foreach (['', 'x', 'xx'] as $i => $padding) {
            $encoded = rtrim(base64_encode($padding . $this->oldHost), '=');
            $needles[] = substr($encoded, $i === 0 ? 0 : ($i === 1 ? 2 : 3), -2);
        }
        return $needles;
    }
}
