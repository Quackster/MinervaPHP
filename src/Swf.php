<?php

namespace Minerva;

/**
 * Minimal SWF (Shockwave Flash) file parser.
 *
 * PHP replacement for the parts of the Flazzy library used by Avatara's
 * FlashExtractor: CWS/zlib decompression, frame-record skipping and access to
 * SymbolClass (tag 76), DefineBitsLossless2 (tag 36 in this pipeline) and
 * DefineBinaryData (tag 87) tags.
 *
 * These compiled figuredata SWFs follow the Flazzy.NET wire conventions, which
 * differ from the textbook SWF spec in two ways (verified against the .NET
 * reference implementation):
 *   1. The zlib stream is wrapped in an 8-byte pseudo-header followed by a
 *      frame record (Nbits byte + 2-byte Rate + 2-byte Count) before the
 *      actual tag stream.
 *   2. Tag headers and all multi-byte integers are read little-endian
 *      (Flazzy's BinaryReader convention).
 */
class Swf
{
    /** @var array<int, string> tag code => concatenated raw payloads (in order) */
    private array $tags = [];
    /** @var array<int, array<int, string>> tag code => list of raw payloads */
    private array $tagList = [];

    /**
     * @param string $path path to a (CWS) SWF file
     */
    public function __construct(string $path)
    {
        $raw = file_get_contents($path);
        $this->parseDecoded($this->decompress($raw));
    }

    /** Parses a single DefineBitsLossless2 payload (stateless; usable for
     *  raw tag payloads extracted from an already-parsed Swf). */
    public static function parseDefineBitsLossless2(string $payload): array
    {
        $id = unpack('v', substr($payload, 0, 2))[1];
        $format = ord($payload[2]);
        $width = unpack('v', substr($payload, 3, 2))[1];
        $height = unpack('v', substr($payload, 5, 2))[1];
        $offset = 7;
        if ($format === 3) {
            $offset += 1; // ColorTableSize
        }
        $compressed = substr($payload, $offset);
        $pixels = @gzuncompress($compressed);
        if ($pixels === false) {
            // Not zlib-wrapped in this file; assume raw ARGB.
            $pixels = $compressed;
        }
        return [
            'id' => $id,
            'width' => $width,
            'height' => $height,
            'format' => $format,
            'pixels' => $pixels,
        ];
    }

    private function decompress(string $raw): string
    {
        $sig = substr($raw, 0, 3);
        if ($sig === 'CWS') {
            // The zlib stream starts 8 bytes in (sig + version + FileLength);
            // some files carry one extra 0x00 byte, so try 7 as fallback.
            $data = @gzuncompress(substr($raw, 8));
            if ($data === false) {
                $data = @gzuncompress(substr($raw, 7));
            }
            if ($data === false) {
                $data = $this->inflatePrefixSearch(substr($raw, 7));
            }
            if ($data !== false) {
                return $data;
            }
        }
        return substr($raw, 7);
    }

    private function inflatePrefixSearch(string $stream): ?string
    {
        $lo = 0;
        $hi = strlen($stream);
        $best = false;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $out = @gzuncompress(substr($stream, 0, $mid));
            if ($out !== false) {
                $best = $out;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        return $best === false ? null : $best;
    }

    private function parseDecoded(string $data): void
    {
        // Skip the 8-byte pseudo-header (signature + version + FileLength) and
        // the frame record: Nbits byte (lower 5 bits) + bit-packed rect
        // (ceil(4*Nbits/8) bytes) + 2-byte Rate + 2-byte Count.
        $pos = 8;
        $nbits = ord($data[$pos]) & 0x1F;
        $pos += 1 + (intdiv(4 * $nbits + 7, 8)) + 4;

        $len = strlen($data);
        while ($pos + 2 <= $len) {
            // Little-endian u16, as Flazzy's BinaryReader reads it.
            $value = ord($data[$pos]) | (ord($data[$pos + 1]) << 8);
            $code = $value >> 6;
            $tagLen = $value & 0x3F;
            $extra = 0;
            if ($tagLen === 0x3F) {
                if ($pos + 6 > $len) {
                    break;
                }
                $tagLen = unpack('V', substr($data, $pos + 2, 4))[1]; // little-endian
                $extra = 4;
            }
            if ($code > 91 || $pos + 2 + $extra + $tagLen > $len) {
                break;
            }
            $payload = substr($data, $pos + 2 + $extra, $tagLen);
            $this->tags[$code] = ($this->tags[$code] ?? '') . $payload;
            $this->tagList[$code][] = $payload;
            $pos += 2 + $extra + $tagLen;
            if ($code === 0) {
                break; // End
            }
        }
    }

    public function hasTag(int $code): bool
    {
        return isset($this->tags[$code]);
    }

    public function tagList(int $code): array
    {
        return $this->tagList[$code] ?? [];
    }

    // --- SymbolClass (tag 76) -------------------------------------------------

    /** @return array{ids: int[], names: string[]} */
    public function symbolClass(): array
    {
        $payloads = $this->tagList(76);
        if (empty($payloads)) {
            return ['ids' => [], 'names' => []];
        }
        $d = $payloads[0];
        $count = unpack('v', substr($d, 0, 2))[1]; // little-endian u16
        $p = 2;
        $len = strlen($d);
        $ids = [];
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            if ($p + 2 > $len) {
                break;
            }
            $ids[] = unpack('v', substr($d, $p, 2))[1];
            $p += 2;
            // NUL-terminated string (Flazzy's ReadNullString), no length prefix
            $start = $p;
            while ($p < $len && $d[$p] !== "\x00") {
                $p++;
            }
            $names[] = substr($d, $start, $p - $start);
            $p += 1; // consume the NUL terminator
        }
        return ['ids' => $ids, 'names' => $names];
    }

    // --- DefineBitsLossless2 (tag 36 in this pipeline) -------------------------

    /**
     * Parses a DefineBitsLossless2 payload (Flazzy layout, little-endian):
     *   Id (u16) | Format (1 byte: 5=Rgb32, 3=ColorMap8)
     *   | Width (u16) | Height (u16) | [ColorTableSize (1) if ColorMap8]
     *   | zlib-compressed ARGB pixel data (Rgb32)
     *
     * @return array{width: int, height: int, format: int, pixels: string}
     *         pixels is decompressed ARGB (4 bytes per pixel)
     */
    public function defineBitsLossless2(string $payload): array
    {
        return self::parseDefineBitsLossless2($payload);
    }

    // --- DefineBinaryData (tag 87 in this pipeline) ----------------------------

    /**
     * Flazzy layout: Id (u16 LE) + reserved (u32) + Data.
     * @return array{id: int, data: string}
     */
    public function defineBinaryData(string $payload): array
    {
        return [
            'id' => unpack('v', substr($payload, 0, 2))[1], // little-endian u16
            'data' => substr($payload, 6),                  // skip id + u32
        ];
    }
}
