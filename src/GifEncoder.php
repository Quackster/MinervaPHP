<?php

namespace Minerva;

/**
 * Minimal GIF89a encoder with a transparency index.
 *
 * Reason: GD's imagetruecolortopalette() + imagecolortransparent() lets the
 * quantizer pick whatever palette color the transparent slot lands on (observed
 * (71,112,76)), while ImageSharp's writer marks a black entry as the
 * transparent index. A hand-rolled encoder gives us exact control.
 */
class GifEncoder
{
    /**
     * @param array{width:int,height:int,r:int[],g:int[],b:int[],a:int[]} $img
     */
    public static function encode(array $img): string
    {
        $w = $img['width'];
        $h = $img['height'];
        $n = $w * $h;

        // Collect distinct opaque colors with their pixel counts.
        $colorCount = [];
        $colorKeys = [];
        for ($i = 0; $i < $n; $i++) {
            if ($img['a'][$i] === 0) {
                continue;
            }
            $key = $img['r'][$i] . ',' . $img['g'][$i] . ',' . $img['b'][$i];
            if (!isset($colorCount[$key])) {
                $colorCount[$key] = 0;
                $colorKeys[] = $key;
            }
            $colorCount[$key]++;
        }

        if (count($colorKeys) > 255) {
            // Too many colors for a GIF: quantize. Bucket each distinct color
            // into a 5-bit grid cell, keep the 255 most frequent buckets, and
            // map every other color to its nearest kept bucket (a cheap
            // octree-style approximation; badges rarely exceed 255 colors).
            $buckets = [];
            for ($k = 0; $k < count($colorKeys); $k++) {
                [$r, $g, $b] = array_map('intval', explode(',', $colorKeys[$k]));
                $bk = ((int) ($r / 8)) . ',' . ((int) ($g / 8)) . ',' . ((int) ($b / 8));
                if (!isset($buckets[$bk])) {
                    $buckets[$bk] = ['count' => 0, 'color' => [$r, $g, $b], 'first' => $k];
                }
                $buckets[$bk]['count'] += $colorCount[$colorKeys[$k]];
            }
            usort($buckets, function ($a, $b) {
                return $b['count'] <=> $a['count'] ?: $a['first'] <=> $b['first'];
            });
            $kept = array_slice(array_values($buckets), 0, 255);

            $palette = [[0, 0, 0]]; // index 0 = transparent entry (black, as in the .NET reference)
            $map = ['0,0,0' => 0];
            foreach ($kept as $bk) {
                $palette[] = $bk['color'];
            }
            foreach ($palette as $idx => $c) {
                if ($idx === 0) {
                    continue;
                }
                $map[$c[0] . ',' . $c[1] . ',' . $c[2]] = $idx;
            }

            // Pixel index buffer: transparent -> 0; each color maps to its own
            // kept bucket or the nearest kept bucket.
            $indices = [];
            $keptList = array_map(fn ($x) => $x['color'], $kept);
            for ($i = 0; $i < $n; $i++) {
                if ($img['a'][$i] === 0) {
                    $indices[] = 0;
                    continue;
                }
                $key = $img['r'][$i] . ',' . $img['g'][$i] . ',' . $img['b'][$i];
                if (isset($map[$key])) {
                    $indices[] = $map[$key];
                    continue;
                }
                $bk = ((int) ($img['r'][$i] / 8)) . ',' . ((int) ($img['g'][$i] / 8)) . ',' . ((int) ($img['b'][$i] / 8));
                if (isset($map[$bk])) {
                    $indices[] = $map[$bk];
                    continue;
                }
                $best = 1;
                $bestD = PHP_INT_MAX;
                for ($k = 0; $k < count($keptList); $k++) {
                    $dr = $img['r'][$i] - $keptList[$k][0];
                    $dg = $img['g'][$i] - $keptList[$k][1];
                    $db = $img['b'][$i] - $keptList[$k][2];
                    $d = $dr * $dr + $dg * $dg + $db * $db;
                    if ($d < $bestD) {
                        $bestD = $d;
                        $best = $k + 1;
                    }
                }
                $indices[] = $best;
            }
        } else {
            $palette = [[0, 0, 0]]; // index 0 = transparent entry (black, as in the .NET reference)
            $map = ['0,0,0' => 0];
            foreach ($colorKeys as $key) {
                $map[$key] = count($palette);
                $palette[] = array_map('intval', explode(',', $key));
            }

            // Pixel index buffer: transparent -> 0.
            $indices = [];
            for ($i = 0; $i < $n; $i++) {
                $indices[] = $img['a'][$i] === 0
                    ? 0
                    : $map[$img['r'][$i] . ',' . $img['g'][$i] . ',' . $img['b'][$i]];
            }
        }

        $paletteCount = count($palette);
        $gctSize = 1;
        while ($gctSize < $paletteCount) {
            $gctSize <<= 1;
        }
        // The GIF size field encodes 2^(N+1) entries; 1 entry is not
        // representable, so the minimum is 2.
        if ($gctSize < 2) {
            $gctSize = 2;
        }
        $gctBits = 0;
        while ((1 << $gctBits) < $gctSize) {
            $gctBits++;
        }

        // Pad the palette to a power of two.
        while (count($palette) < $gctSize) {
            $palette[] = [0, 0, 0];
        }

        $lzwData = self::lzwEncode($indices, $gctSize);

        $minCodeSize = 0;
        $tmp = $gctSize;
        while ($tmp > 1) {
            $tmp >>= 1;
            $minCodeSize++;
        }
        if ($minCodeSize < 2) {
            $minCodeSize = 2;
        }

        $out = 'GIF89a';
        $out .= self::u16($w) . self::u16($h);
        $out .= chr(0x80 | (($gctBits - 1) & 0x07)); // GCT flag, N bits
        $out .= chr(0); // background color index
        $out .= chr(0); // aspect ratio

        foreach ($palette as [$r, $g, $b]) {
            $out .= chr($r) . chr($g) . chr($b);
        }

        // Graphic control extension: 0 delay, transparent color flag, index 0.
        $out .= "\x21\xF9\x04\x01\x00\x00\x00\x00";

        // Image descriptor (no local color table, not interlaced).
        $out .= "\x2C" . self::u16(0) . self::u16(0) . self::u16($w) . self::u16($h) . "\x00";

        $out .= chr($minCodeSize); // LZW minimum code size
        for ($i = 0; $i < strlen($lzwData); $i += 255) {
            $chunk = substr($lzwData, $i, 255);
            $out .= chr(strlen($chunk)) . $chunk;
        }
        $out .= "\x00"; // data sub-block terminator
        $out .= "\x3B"; // trailer

        return $out;
    }

    /**
     * LZW-encodes the pixel index buffer (GIF flavor: clear code, code size
     * growth, little-endian bit packing).
     *
     * @param int[] $indices
     */
    private static function lzwEncode(array $indices, int $codeCount): string
    {
        $minCodeSize = 0;
        $tmp = $codeCount;
        while ($tmp > 1) {
            $tmp >>= 1;
            $minCodeSize++;
        }
        if ($minCodeSize < 2) {
            $minCodeSize = 2;
        }

        $clearCode = 1 << $minCodeSize;
        $endCode = $clearCode + 1;
        $codeSize = $minCodeSize + 1;

        $bitBuf = 0;
        $bitCount = 0;
        $out = '';

        $emit = function (int $code, int $size) use (&$bitBuf, &$bitCount, &$out) {
            $bitBuf |= $code << $bitCount;
            $bitCount += $size;
            while ($bitCount >= 8) {
                $out .= chr($bitBuf & 0xFF);
                $bitBuf >>= 8;
                $bitCount -= 8;
            }
        };

        $table = [];
        $next = $endCode + 1;

        $emit($clearCode, $codeSize);

        if (count($indices) === 0) {
            $emit($endCode, $codeSize);
        } else {
            // Standard GIF LZW growth pairing (verified against PIL and all
            // bundled badge templates): the decoder adds a table entry after
            // reading every code after the first, and grows the code size
            // when the post-increment next-code counter reaches (1 << size).
            // Because the decoder's first entry is added after C_2, the entry
            // added while reading C_k decides the width of C_{k+2}. The
            // encoder therefore applies the decision it computes while emitting
            // C_k two emits later. A two-slot register is not enough (it
            // collapses to a one-emit delay); the value must survive two
            // emits, so a three-slot ring is used and rotated before the
            // emit it guards. Match iterations emit nothing and must not
            // advance the registers.
            $s1 = false; // decision of the last emit
            $s2 = false; // decision of the emit before that
            $s3 = false; // applied to this emit
            $prev = (string) $indices[0];
            for ($i = 1; $i < count($indices); $i++) {
                $idx = (string) $indices[$i];
                $key = $prev . ',' . $idx;
                if (isset($table[$key])) {
                    $prev = $table[$key];
                    continue;
                }
                [$s1, $s2, $s3] = [false, $s1, $s2];
                if ($s3 && $codeSize < 12) {
                    $codeSize++;
                }
                $emit((int) $prev, $codeSize);
                $table[$key] = (string) $next++;
                $s1 = ($next === (1 << $codeSize)) && $codeSize < 12;
                $prev = $idx;
            }
            [$s1, $s2, $s3] = [false, $s1, $s2];
            if ($s3 && $codeSize < 12) {
                $codeSize++;
            }
            $emit((int) $prev, $codeSize);
            // END: the decoder adds one final entry after reading the last
            // code, so its next-code counter equals $next here; END is read
            // at the grown width only when that counter has reached
            // (1 << size).
            if ($codeSize < 12 && $next >= (1 << $codeSize)) {
                $codeSize++;
            }
            $emit($endCode, $codeSize);
        }

        if ($bitCount > 0) {
            $out .= chr($bitBuf & 0xFF);
        }

        return $out;
    }

    private static function u16(int $v): string
    {
        return chr($v & 0xFF) . chr(($v >> 8) & 0xFF);
    }
}
