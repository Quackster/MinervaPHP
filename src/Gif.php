<?php

namespace Minerva;

/**
 * Minimal pure-PHP GIF87a/89a decoder (first frame) — returns raw RGBA in
 * the 0..255 model (255 = opaque).
 *
 * Reason: this PHP/GD build's imagecreatefromgif() decodes the badge
 * template GIFs with wrong palette values (near-black instead of the true
 * grays) — the .NET reference (ImageSharp) decodes them correctly, so we
 * decode the format ourselves to match it.
 */
class Gif
{
    private string $data;
    private int $pos = 0;

    private function __construct(string $data)
    {
        $this->data = $data;
    }

    /** Decodes the first frame. Returns ['width'=>, 'height'=>, 'r'=>, 'g'=>, 'b'=>, 'a'=>] */
    public static function decode(string $data): ?array
    {
        if (strncmp($data, 'GIF', 3) !== 0) {
            return null;
        }
        $gif = new self($data);
        return $gif->parse();
    }

    private function u16(): int
    {
        return ord($this->data[$this->pos++]) | (ord($this->data[$this->pos++]) << 8);
    }

    private function parse(): ?array
    {
        // Header (GIF89a/GIF87a — 6 bytes) + logical screen descriptor
        $this->pos = 6;
        $w = $this->u16();
        $h = $this->u16();
        $packed = ord($this->data[$this->pos++]);
        $this->pos++; // background color index
        $this->pos++; // aspect ratio

        $gctSize = 0;
        if ($packed & 0x80) {
            $gctSize = 3 * (1 << ((($packed >> 0) & 0x07) + 1));
        }
        $gct = $this->readColorTable($gctSize);

        while ($this->pos < strlen($this->data)) {
            $intro = ord($this->data[$this->pos++]);
            if ($intro === 0x3B) { // trailer
                break;
            }
            if ($intro === 0x21) { // extension
                $label = ord($this->data[$this->pos++]);
                $transIndex = null;
                if ($label === 0xF9) { // graphic control extension
                    $blockSize = ord($this->data[$this->pos++]);
                    $flags = ord($this->data[$this->pos]);
                    if ($blockSize >= 4 && ($flags & 0x01)) {
                        $transIndex = ord($this->data[$this->pos + 3]);
                    }
                    $this->pos += $blockSize;
                    // skip trailing data sub-blocks
                    while (($b = ord($this->data[$this->pos])) !== 0) {
                        $this->pos += 1 + $b;
                    }
                    $this->pos++;
                } else {
                    // other extensions: skip sub-blocks
                    while (($b = ord($this->data[$this->pos])) !== 0) {
                        $this->pos += 1 + $b;
                    }
                    $this->pos++;
                }
            } elseif ($intro === 0x2C) { // image descriptor
                return $this->parseImage($gct, $transIndex);
            } else {
                return null; // unexpected block
            }
        }
        return null;
    }

    private function readColorTable(int $bytes): array
    {
        $colors = [];
        for ($i = 0; $i < $bytes / 3; $i++) {
            $colors[] = [
                ord($this->data[$this->pos++]),
                ord($this->data[$this->pos++]),
                ord($this->data[$this->pos++]),
            ];
        }
        return $colors;
    }

    private function parseImage(array $gct, ?int $transIndex): ?array
    {
        $ix = $this->u16();
        $iy = $this->u16();
        $iw = $this->u16();
        $ih = $this->u16();
        $packed = ord($this->data[$this->pos++]);

        $lct = null;
        if ($packed & 0x80) {
            $lct = $this->readColorTable(3 * (1 << ((($packed >> 0) & 0x07) + 1)));
        }
        $palette = $lct ?? $gct;

        if ($this->pos >= strlen($this->data)) {
            return null;
        }
        $lzwMin = ord($this->data[$this->pos++]);
        $data = '';
        while (($b = ord($this->data[$this->pos])) !== 0) {
            $data .= substr($this->data, $this->pos + 1, $b);
            $this->pos += 1 + $b;
        }
        $this->pos++;

        $codes = $this->lzwDecode($data, $lzwMin);
        if ($codes === null) {
            return null;
        }

        $n = $iw * $ih;
        $r = array_fill(0, $n, 0);
        $g = array_fill(0, $n, 0);
        $b = array_fill(0, $n, 0);
        $a = array_fill(0, $n, 255);
        $pi = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($pi >= count($codes)) {
                break;
            }
            $idx = $codes[$pi++];
            if (isset($palette[$idx])) {
                $r[$i] = $palette[$idx][0];
                $g[$i] = $palette[$idx][1];
                $b[$i] = $palette[$idx][2];
                // C#/ImageSharp keeps the palette RGB on transparent pixels.
                $a[$i] = $idx === $transIndex ? 0 : 255;
            }
        }

        return [
            'width' => $iw,
            'height' => $ih,
            'r' => $r,
            'g' => $g,
            'b' => $b,
            'a' => $a,
        ];
    }

    private function lzwDecode(string $data, int $minCodeSize): ?array
    {
        $codeSize = $minCodeSize + 1;
        $clearCode = 1 << $minCodeSize;
        $endCode = $clearCode + 1;

        $table = [];
        for ($i = 0; $i < $clearCode; $i++) {
            $table[$i] = [$i];
        }
        $table[$clearCode] = [];
        $table[$endCode] = [];
        $nextCode = $endCode + 1;

        $out = [];
        $bitPos = 0;
        $total = strlen($data) * 8;

        $readCode = function (int &$codeSize) use (&$bitPos, $data, $total): ?int {
            if ($bitPos + $codeSize > $total) {
                return null;
            }
            // Extract codeSize bits at bitPos (little-endian bit order).
            $code = 0;
            for ($i = 0; $i < $codeSize; $i++) {
                $bit = $bitPos + $i;
                $byte = ord($data[(int) ($bit >> 3)]);
                if (($byte >> ($bit & 7)) & 1) {
                    $code |= 1 << $i;
                }
            }
            $bitPos += $codeSize;
            return $code;
        };

        $resetTable = function () use (&$table, &$nextCode, &$codeSize, $clearCode, $endCode, $minCodeSize) {
            $table = [];
            for ($i = 0; $i < $clearCode; $i++) {
                $table[$i] = [$i];
            }
            $table[$clearCode] = [];
            $table[$endCode] = [];
            $nextCode = $endCode + 1;
            $codeSize = $minCodeSize + 1;
        };

        $bitPos = 0;
        $code = $readCode($codeSize);
        // A leading CLEAR code just resets; the following code starts a fresh
        // sequence (GIF quirk — e.g. these badge templates).
        while ($code === $clearCode) {
            $resetTable();
            $code = $readCode($codeSize);
        }
        if ($code === null || $code === $endCode) {
            return null;
        }
        $entry = $table[$code] ?? null;
        if ($entry === null) {
            return null;
        }
        foreach ($entry as $idx) {
            $out[] = $idx;
        }
        $prev = (string) $code;

        while (true) {
            $code = $readCode($codeSize);
            if ($code === null) {
                break;
            }
            if ($code === $clearCode) {
                $resetTable();
                $code = $readCode($codeSize);
                if ($code === null) {
                    break;
                }
                if ($code === $endCode) {
                    break;
                }
                $entry = $table[$code] ?? null;
                if ($entry === null) {
                    return null;
                }
                foreach ($entry as $idx) {
                    $out[] = $idx;
                }
                $prev = (string) $code;
                continue;
            }
            if ($code === $endCode) {
                break;
            }

            if (isset($table[$code])) {
                $entry = $table[$code];
            } elseif ($code === $nextCode) {
                $entry = array_merge($table[$prev] ?? [], [$table[$prev][0] ?? 0]);
            } else {
                return null;
            }
            foreach ($entry as $idx) {
                $out[] = $idx;
            }

            $table[$nextCode++] = array_merge($table[$prev] ?? [], [$entry[0]]);

            if ($nextCode >= (1 << $codeSize) && $codeSize < 12) {
                $codeSize++;
            }

            $prev = (string) $code;
        }

        return $out;
    }
}
