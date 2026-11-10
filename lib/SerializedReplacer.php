<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

/**
 * Replaces text inside a PHP serialized value without unserializing it, so no object is created
 * and no code of a plugin runs. WP-CLI skips objects of classes it has not loaded (with
 * --skip-plugins, every class of a plugin): this reaches them too.
 *
 * Only string values change, and their lengths are written again. Array keys and property names,
 * class names, numbers and references stay as they are. A string that holds serialized data
 * itself is handled the same way, as WP-CLI does. A value that is not entirely valid serialized
 * data, or uses a form that cannot be read safely (C: custom serialization), gives null.
 */
final class SerializedReplacer
{
    /** Deeper values are refused: a hostile value must not exhaust the stack of root. */
    private const MAX_DEPTH = 256;

    private string $data = '';
    private int $pos = 0;
    /** The last length read, for bytes(). */
    private int $length = 0;
    /** @var callable(string): string */
    private $replace;

    /** @param callable(string): string $replace applied to every string value */
    public function __construct(callable $replace)
    {
        $this->replace = $replace;
    }

    /** The value with its strings replaced, or null if it is not serialized data this can read. */
    public function replace(string $value): ?string
    {
        try {
            return $this->rewrite($value, 0);
        } catch (\OverflowException) {
            return null;
        }
    }

    /** A string inside a string counts towards the depth like an array inside an array. */
    private function rewrite(string $value, int $depth): ?string
    {
        $data = $this->data;
        $pos = $this->pos;
        try {
            $this->data = $value;
            $this->pos = 0;
            $result = $this->value($depth);
            return $this->pos === strlen($value) ? $result : null;
        } catch (\UnexpectedValueException) {
            return null;
        } finally {
            // Nested values reuse the parser.
            $this->data = $data;
            $this->pos = $pos;
        }
    }

    private function value(int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            // Not caught by rewrite(): a string this deep must not be taken for plain text.
            throw new \OverflowException('Too deep');
        }
        $type = $this->data[$this->pos] ?? '';
        switch ($type) {
            case 'N':
                return $this->expect('N;');
            case 'b':
                return $this->token('/\Gb:[01];/');
            case 'i':
                return $this->token('/\Gi:[+-]?\d+;/');
            case 'd':
                return $this->token('/\Gd:(?:[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?|-?INF|NAN);/');
            case 'r':
            case 'R':
                return $this->token('/\G[rR]:\d+;/');
            case 'E':
                // An enum case: class:case, no text of a site in it.
                $this->pos++;
                return 'E' . $this->expect(':') . $this->length() . '"' . $this->bytes() . '"' . $this->expect(';');
            case 's':
                $this->pos++;
                $this->expect(':');
                $this->length(); // checked by bytes()
                $text = $this->bytes();
                $this->expect(';');
                // WordPress serializes some values twice.
                $nested = $this->rewrite($text, $depth + 1);
                $new = $nested ?? ($this->replace)($text);
                return 's:' . strlen($new) . ':"' . $new . '";';
            case 'a':
                $this->pos++;
                $head = 'a' . $this->expect(':');
                $count = $this->number();
                return $head . $count . $this->expect(':') . $this->members((int)$count, $depth);
            case 'O':
                $this->pos++;
                $head = 'O' . $this->expect(':') . $this->length() . '"' . $this->bytes() . '"' . $this->expect(':');
                $count = $this->number();
                return $head . $count . $this->expect(':') . $this->members((int)$count, $depth);
            default:
                // C: and S: included: their content is not a structure this can rewrite.
                throw new \UnexpectedValueException('Unknown type');
        }
    }

    /** {key value ...}: keys are kept exactly, values are replaced. */
    private function members(int $count, int $depth): string
    {
        $out = $this->expect('{');
        for ($i = 0; $i < $count; $i++) {
            $out .= $this->key() . $this->value($depth + 1);
        }
        return $out . $this->expect('}');
    }

    private function key(): string
    {
        if (($this->data[$this->pos] ?? '') === 'i') {
            return $this->token('/\Gi:[+-]?\d+;/');
        }
        return $this->expect('s:') . $this->length() . '"' . $this->bytes() . '"' . $this->expect(';');
    }

    /** "N:" with the number remembered for bytes(). */
    private function length(): string
    {
        $raw = $this->number();
        $this->length = (int)$raw;
        return $raw . $this->expect(':');
    }

    /** The quoted string after a length: exactly that many bytes, then the closing quote. */
    private function bytes(): string
    {
        $this->expect('"');
        if ($this->pos + $this->length + 1 > strlen($this->data) || $this->data[$this->pos + $this->length] !== '"') {
            throw new \UnexpectedValueException('Wrong length');
        }
        $text = substr($this->data, $this->pos, $this->length);
        $this->pos += $this->length + 1;
        return $text;
    }

    private function number(): string
    {
        if (!preg_match('/\G\d{1,10}/', $this->data, $m, 0, $this->pos)) {
            throw new \UnexpectedValueException('Number expected');
        }
        $this->pos += strlen($m[0]);
        return $m[0];
    }

    private function token(string $pattern): string
    {
        if (!preg_match($pattern, $this->data, $m, 0, $this->pos)) {
            throw new \UnexpectedValueException('Invalid token');
        }
        $this->pos += strlen($m[0]);
        return $m[0];
    }

    private function expect(string $text): string
    {
        if (substr($this->data, $this->pos, strlen($text)) !== $text) {
            throw new \UnexpectedValueException('Expected ' . $text);
        }
        $this->pos += strlen($text);
        return $text;
    }
}
