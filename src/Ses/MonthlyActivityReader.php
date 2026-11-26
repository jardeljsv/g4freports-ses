<?php

namespace GlpiPlugin\G4freports\Ses;

/** Selective, bounded reader for an immutable snapshot; never retains raw evidence rows. */
final class MonthlyActivityReader
{
    private $handle;
    private $onRow;
    private array $limits;
    private string $buffer = '';
    private int $position = 0;
    private bool $eof = false;
    private int $bytes = 0;
    private $hash;
    private float $started;
    private int $rows = 0;
    private array $counts = ['tickets' => 0, 'calls' => 0, 'excluded_calls' => 0];

    /**
     * The caller owns/closes the readable stream and verifies returned bytes/hash.
     * Callbacks are provisional until the entire JSON and its checksum are verified.
     */
    public static function read($handle, callable $onRow, array $limits = []): array
    {
        $reader = new self($handle, $onRow, $limits);
        return $reader->snapshot();
    }

    private function __construct($handle, callable $onRow, array $limits)
    {
        $defaults = ['max_bytes' => 67108864, 'max_rows' => 200000, 'max_collection_rows' => 100000,
            'max_call_rows' => 100000, 'max_depth' => 128, 'max_seconds' => 15.0,
            'max_scalar_bytes' => 4096, 'chunk_bytes' => 65536];
        if (!is_resource($handle) || get_resource_type($handle) !== 'stream'
            || array_diff(array_keys($limits), array_keys($defaults))) throw self::failure();
        $this->limits = array_replace($defaults, $limits);
        foreach ($this->limits as $key => $value) {
            if ($key === 'max_seconds') {
                if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0 || $value > 60) throw self::failure();
            } elseif (!is_int($value) || $value < 1 || $value > $defaults[$key]) throw self::failure();
        }
        $this->handle = $handle;
        $this->onRow = $onRow;
        $this->hash = hash_init('sha256');
        $this->started = hrtime(true) / 1000000000;
    }

    private function snapshot(): array
    {
        $metadata = ['schema_version' => null, 'generated_at' => null, 'period' => [], 'input' => [], 'totals' => [],
            'collections' => ['tickets' => false, 'calls' => false, 'excluded_calls' => false]];
        $fields = ['period' => ['month', 'start', 'end_exclusive', 'date_basis', 'call_date_basis'],
            'input' => ['month', 'timezone'], 'totals' => ['tickets', 'calls_received', 'calls_excluded']];
        $seen = [];
        $this->whitespace();
        $this->expect('{');
        $this->whitespace();
        if ($this->peek() !== '}') {
            while (true) {
                $key = $this->string(true);
                $this->whitespace(); $this->expect(':'); $this->whitespace();
                $recognized = isset($fields[$key]) || array_key_exists($key, $metadata['collections'])
                    || in_array($key, ['schema_version', 'generated_at'], true);
                if ($recognized && isset($seen[$key])) throw self::failure();
                if ($recognized) $seen[$key] = true;
                if (isset($fields[$key])) {
                    if ($this->peek() === '{') $metadata[$key] = $this->selectedObject($fields[$key], 2);
                    else $this->skipValue(2);
                } elseif (array_key_exists($key, $metadata['collections'])) {
                    $this->collection($key);
                    $metadata['collections'][$key] = true;
                } elseif (in_array($key, ['schema_version', 'generated_at'], true)) {
                    $metadata[$key] = $this->scalar(2);
                } else $this->skipValue(2);
                $this->whitespace();
                if ($this->peek() === '}') break;
                $this->expect(','); $this->whitespace();
            }
        }
        $this->expect('}'); $this->whitespace();
        if ($this->peek() !== null) throw self::failure();
        $this->budget();
        $metadata['row_counts'] = $this->counts;
        $metadata['sha256'] = hash_final($this->hash);
        $metadata['bytes'] = $this->bytes;
        return $metadata;
    }

    private function collection(string $name): void
    {
        $this->depth(2);
        $this->expect('['); $this->whitespace();
        if ($this->peek() !== ']') {
            while (true) {
                if (++$this->rows > $this->limits['max_rows']
                    || ++$this->counts[$name] > $this->limits['max_collection_rows']
                    || $this->counts['calls'] + $this->counts['excluded_calls'] > $this->limits['max_call_rows']) throw self::failure();
                $this->budget();
                if ($this->peek() === '{') {
                    $row = $this->selectedObject($name === 'tickets' ? ['id', 'solved_at']
                        : ['id', 'queue', 'call_key', 'entered_at'], 3);
                } else {
                    // A malformed row still occupies one archived observation; the reducer marks its date unassigned.
                    $this->skipValue(3); $row = [];
                }
                ($this->onRow)($name, $row);
                $this->whitespace();
                if ($this->peek() === ']') break;
                $this->expect(','); $this->whitespace();
            }
        }
        $this->expect(']');
    }

    private function selectedObject(array $fields, int $depth): array
    {
        $this->depth($depth);
        $selected = []; $seen = [];
        $this->expect('{'); $this->whitespace();
        if ($this->peek() !== '}') {
            while (true) {
                $key = $this->string(true);
                $this->whitespace(); $this->expect(':'); $this->whitespace();
                if (in_array($key, $fields, true)) {
                    if (isset($seen[$key])) throw self::failure();
                    $seen[$key] = true;
                    $selected[$key] = $this->scalar($depth + 1);
                } else $this->skipValue($depth + 1);
                $this->whitespace();
                if ($this->peek() === '}') break;
                $this->expect(','); $this->whitespace();
            }
        }
        $this->expect('}');
        return $selected;
    }

    private function scalar(int $depth)
    {
        $this->depth($depth);
        $char = $this->peek();
        if ($char === '"') return $this->string(true);
        if ($char === 't') { $this->literal('true'); return true; }
        if ($char === 'f') { $this->literal('false'); return false; }
        if ($char === 'n') { $this->literal('null'); return null; }
        if ($char === '-' || ($char !== null && $char >= '0' && $char <= '9')) return $this->number(true);
        $this->skipValue($depth);
        return null;
    }

    private function skipValue(int $depth): void
    {
        $this->depth($depth);
        $char = $this->peek();
        if ($char === '"') { $this->string(false); return; }
        if ($char === 't') { $this->literal('true'); return; }
        if ($char === 'f') { $this->literal('false'); return; }
        if ($char === 'n') { $this->literal('null'); return; }
        if ($char === '-' || ($char !== null && $char >= '0' && $char <= '9')) { $this->number(false); return; }
        if ($char !== '{' && $char !== '[') throw self::failure();
        $object = $char === '{'; $close = $object ? '}' : ']';
        $this->position++; $this->whitespace();
        if ($this->peek() !== $close) {
            while (true) {
                if ($object) { $this->string(false); $this->whitespace(); $this->expect(':'); $this->whitespace(); }
                $this->skipValue($depth + 1); $this->whitespace();
                if ($this->peek() === $close) break;
                $this->expect(','); $this->whitespace();
            }
        }
        $this->expect($close);
    }

    private function number(bool $capture)
    {
        $token = '';
        while (($char = $this->peek()) !== null && strpos('0123456789eE+-.', $char) !== false) {
            $token .= $char; $this->position++;
            if (strlen($token) > $this->limits['max_scalar_bytes']) throw self::failure();
        }
        if (!preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?\z/D', $token)) throw self::failure();
        if (!$capture) return null;
        try { $value = json_decode($token, true, 2, JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { throw self::failure(); }
        if (is_float($value) && !is_finite($value)) throw self::failure();
        return $value;
    }

    /** Read strings in chunks. Unselected strings may be large but are never accumulated. */
    private function string(bool $capture): ?string
    {
        $this->expect('"'); $token = ''; $carry = '';
        $stop = "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F";
        while (true) {
            if ($this->peek() === null) throw self::failure();
            $length = strcspn($this->buffer, $stop, $this->position);
            if ($length > 0) {
                $part = substr($this->buffer, $this->position, $length);
                $this->utf8($part, $carry);
                $this->append($token, $part, $capture);
                $this->position += $length;
                continue;
            }
            if ($carry !== '') throw self::failure();
            $char = $this->take();
            if ($char === '"') {
                if (!$capture) return null;
                try { return json_decode('"' . $token . '"', true, 2, JSON_THROW_ON_ERROR); }
                catch (\Throwable $error) { throw self::failure(); }
            }
            if ($char !== '\\') throw self::failure();
            $escape = $this->take();
            if ($escape === null || strpos('"\\/bfnrtu', $escape) === false) throw self::failure();
            $encoded = '\\' . $escape;
            if ($escape === 'u') {
                $hex = $this->hex(); $encoded .= $hex; $code = hexdec($hex);
                if ($code >= 0xD800 && $code <= 0xDBFF) {
                    $this->expect('\\'); $this->expect('u'); $low = $this->hex(); $number = hexdec($low);
                    if ($number < 0xDC00 || $number > 0xDFFF) throw self::failure();
                    $encoded .= '\\u' . $low;
                } elseif ($code >= 0xDC00 && $code <= 0xDFFF) throw self::failure();
            }
            $this->append($token, $encoded, $capture);
        }
    }

    private function hex(): string
    {
        $hex = '';
        for ($n = 0; $n < 4; $n++) {
            $char = $this->take();
            if ($char === null || !ctype_xdigit($char)) throw self::failure();
            $hex .= $char;
        }
        return $hex;
    }

    private function append(string &$token, string $part, bool $capture): void
    {
        if (!$capture) return;
        if (strlen($token) + strlen($part) > $this->limits['max_scalar_bytes']) throw self::failure();
        $token .= $part;
    }

    /** Preserve at most an incomplete UTF-8 codepoint across input chunks. */
    private function utf8(string $part, string &$carry): void
    {
        $value = $carry . $part;
        if (preg_match('//u', $value) === 1) { $carry = ''; return; }
        for ($tail = 1; $tail <= 3 && $tail <= strlen($value); $tail++) {
            $prefix = substr($value, 0, -$tail); $suffix = substr($value, -$tail);
            if (preg_match('//u', $prefix) === 1
                && preg_match('/\A(?:[\xC2-\xDF]|[\xE0-\xEF][\x80-\xBF]?|[\xF0-\xF4][\x80-\xBF]{0,2})\z/D', $suffix)) {
                $carry = $suffix; return;
            }
        }
        throw self::failure();
    }

    private function literal(string $word): void
    {
        for ($n = 0; $n < strlen($word); $n++) $this->expect($word[$n]);
    }

    private function whitespace(): void
    {
        while (($char = $this->peek()) !== null && strpos(" \t\r\n", $char) !== false) $this->position++;
    }

    private function expect(string $char): void
    {
        if ($this->take() !== $char) throw self::failure();
    }

    private function take(): ?string
    {
        $char = $this->peek();
        if ($char !== null) $this->position++;
        return $char;
    }

    private function peek(): ?string
    {
        if ($this->position >= strlen($this->buffer)) {
            if ($this->eof) return null;
            $this->budget();
            $size = min($this->limits['chunk_bytes'], $this->limits['max_bytes'] - $this->bytes + 1);
            if ($size < 1) throw self::failure();
            $chunk = @fread($this->handle, $size);
            if (!is_string($chunk)) throw self::failure();
            if ($chunk === '') {
                if (!feof($this->handle)) throw self::failure();
                $this->eof = true; $this->buffer = ''; $this->position = 0; return null;
            }
            $this->bytes += strlen($chunk);
            if ($this->bytes > $this->limits['max_bytes']) throw self::failure();
            hash_update($this->hash, $chunk);
            $this->buffer = $chunk; $this->position = 0;
        }
        return $this->buffer[$this->position];
    }

    private function depth(int $depth): void
    {
        if ($depth > $this->limits['max_depth']) throw self::failure();
    }

    private function budget(): void
    {
        if (hrtime(true) / 1000000000 - $this->started >= $this->limits['max_seconds']) throw self::failure();
    }

    private static function failure(): \RuntimeException
    {
        return new \RuntimeException('Não foi possível ler o detalhe diário dentro dos limites seguros do arquivo mensal. Nenhum resumo diário parcial foi publicado.', 503);
    }
}
