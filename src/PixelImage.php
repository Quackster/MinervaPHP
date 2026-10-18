<?php

namespace Minerva;

/**
 * Raw per-pixel RGBA image with the operations the ported Avatara/Badger code
 * needs (tint, Normal-blend compositing, flip, nearest-neighbor resize,
 * PNG/GIF encoding).
 *
 * This replaces SixLabors.ImageSharp. Compositing uses the standard
 * "source over" formula for straight (non-premultiplied) alpha, matching
 * ImageSharp's PixelColorBlendingMode.Normal; fast paths (fully transparent
 * source, fully opaque source) are exact.
 */
class PixelImage
{
    public int $w;
    public int $h;

    /** @var int[] flat, index = y * w + x */
    public array $r;
    /** @var int[] */
    public array $g;
    /** @var int[] */
    public array $b;
    /** @var int[] */
    public array $a;

    /** @param array{width:int,height:int,r:int[],g:int[],b:int[],a:int[]} $data */
    private function __construct(
        int $w,
        int $h,
        ?array $r = null,
        ?array $g = null,
        ?array $b = null,
        ?array $a = null
    ) {
        $this->w = $w;
        $this->h = $h;
        if ($r === null) {
            $n = $w * $h;
            $this->r = array_fill(0, $n, 0);
            $this->g = array_fill(0, $n, 0);
            $this->b = array_fill(0, $n, 0);
            $this->a = array_fill(0, $n, 0);
        } else {
            $this->r = $r;
            $this->g = $g;
            $this->b = $b;
            $this->a = $a;
        }
    }

    public static function create(int $w, int $h, array $fill = [0, 0, 0, 0]): self
    {
        $img = new self($w, $h);
        $img->fill($fill);
        return $img;
    }

    public function fill(array $fill): void
    {
        $n = $this->w * $this->h;
        $this->r = array_fill(0, $n, (int) $fill[0]);
        $this->g = array_fill(0, $n, (int) $fill[1]);
        $this->b = array_fill(0, $n, (int) $fill[2]);
        $this->a = array_fill(0, $n, (int) $fill[3]);
    }

    /** Loads a PNG (or GIF) file into raw pixels. */
    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException("Image file not found: $file");
        }
        $head = file_get_contents($file, false, null, 0, 6);

        // GIFs are decoded with our own parser: this PHP/GD build's
        // imagecreatefromgif() misdecodes the badge template GIFs.
        if (strncmp((string) $head, 'GIF', 3) === 0) {
            $decoded = Gif::decode((string) file_get_contents($file));
            if ($decoded === null) {
                throw new \RuntimeException("Cannot decode image: $file");
            }
            return new self(
                $decoded['width'],
                $decoded['height'],
                $decoded['r'],
                $decoded['g'],
                $decoded['b'],
                $decoded['a']
            );
        }

        $gd = @imagecreatefrompng($file);
        if ($gd === false) {
            $gd = @imagecreatefromgif($file);
        }
        if ($gd === false) {
            throw new \RuntimeException("Cannot decode image: $file");
        }
        $w = imagesx($gd);
        $h = imagesy($gd);
        $img = new self($w, $h);
        $n = $w * $h;
        $alphaHigh = self::alphaLayoutHigh();
        for ($i = 0; $i < $n; $i++) {
            $x = $i % $w;
            $y = (int) ($i / $w);
            $c = imagecolorat($gd, $x, $y);
            // GD alpha: 0 (opaque) .. 127 (transparent). Our model: 0 (transparent)
            // .. 255 (opaque).
            $gdA = $alphaHigh ? (($c >> 24) & 0xFF) : ($c & 0xFF);
            $img->a[$i] = 255 - (int) round($gdA * 255 / 127);
            if ($alphaHigh) {
                $img->r[$i] = ($c >> 16) & 0xFF;
                $img->g[$i] = ($c >> 8) & 0xFF;
                $img->b[$i] = $c & 0xFF;
            } else {
                $img->r[$i] = ($c >> 24) & 0xFF;
                $img->g[$i] = ($c >> 16) & 0xFF;
                $img->b[$i] = ($c >> 8) & 0xFF;
            }
        }
        return $img;
    }

    /**
     * imagecolorat's packing changed between PHP versions (0xRRGGBBAA before
     * 8.5, 0xAARRGGBB from 8.5). Detect once per process by decoding a
     * known 1x1 PNG with 50% alpha red (GD alpha 63 = 0x3f):
     * legacy -> 0xFF00003f (low byte 0x3f), modern -> 0x3FFF0000 (high byte 0x3f).
     * (An in-memory imagesetpixel test does not work: this build reports
     * alpha 0 for in-memory truecolor pixels.)
     */
    private static ?bool $alphaHigh = null;

    private static function alphaLayoutHigh(): bool
    {
        if (self::$alphaHigh !== null) {
            return self::$alphaHigh;
        }
                $f = tempnam(sys_get_temp_dir(), 'aldet');
        $probe = imagecreatetruecolor(1, 1);
        imagesavealpha($probe, true);
        imagefill($probe, 0, 0, imagecolorallocatealpha($probe, 255, 0, 0, 63));
        imagepng($probe, $f);
        $g = imagecreatefrompng($f);
        $c = imagecolorat($g, 0, 0);
        self::$alphaHigh = (($c & 0xFF) === 0) && ((($c >> 24) & 0xFF) > 0);
        unlink($f);
        return self::$alphaHigh;
    }

    /** Port of Avatara's HexToColor. "transparent" -> [0,0,0,0]. */
    public static function hexToColor(string $hexColourCode): array
    {
        if ($hexColourCode === 'transparent') {
            return [0, 0, 0, 0];
        }
        $hexColourCode = strtolower(str_replace('#', '', $hexColourCode));
        return [
            hexdec(substr($hexColourCode, 0, 2)),
            hexdec(substr($hexColourCode, 2, 2)),
            hexdec(substr($hexColourCode, 4, 2)),
            255,
        ];
    }

    /**
     * Port of Avatar.TintImage / Badge.TintImage:
     * for every pixel with A > 0: channel = (byte)(tint * channel / 255), A = alpha.
     */
    public function tint(string $colourCode, int $alpha): void
    {
        $rgb = self::hexToColor($colourCode);
        $tr = (int) $rgb[0];
        $tg = (int) $rgb[1];
        $tb = (int) $rgb[2];

        $n = $this->w * $this->h;
        for ($i = 0; $i < $n; $i++) {
            if ($this->a[$i] > 0) {
                $this->r[$i] = (int) ($tr * $this->r[$i] / 255);
                $this->g[$i] = (int) ($tg * $this->g[$i] / 255);
                $this->b[$i] = (int) ($tb * $this->b[$i] / 255);
                $this->a[$i] = $alpha;
            }
        }
    }

    /**
     * Port of ImageSharp DrawImage with PixelColorBlendingMode.Normal
     * (source over destination, straight alpha), with clipping.
     */
    public function draw(self $src, int $dx, int $dy): void
    {
        $sw = $src->w;
        $sh = $src->h;

        // A source pixel (sx, sy) lands at destination (sx + dx, sy + dy);
        // clip so both coordinates stay in bounds.
        $sx0 = max(0, -$dx);
        $sy0 = max(0, -$dy);
        $sx1 = min($sw, $this->w - $dx);
        $sy1 = min($sh, $this->h - $dy);
        if ($sx1 <= $sx0 || $sy1 <= $sy0) {
            return;
        }

        $rD = &$this->r;
        $gD = &$this->g;
        $bD = &$this->b;
        $aD = &$this->a;
        $rS = $src->r;
        $gS = $src->g;
        $bS = $src->b;
        $aS = $src->a;
        $w = $this->w;

        for ($sy = $sy0; $sy < $sy1; $sy++) {
            $si = $sy * $sw + $sx0 - 1;
            $di = ($sy + $dy) * $w + $sx0 + $dx - 1;
            for ($sx = $sx0; $sx < $sx1; $sx++) {
                $si += 1;
                $di += 1;
                $sa = $aS[$si];
                if ($sa === 0) {
                    continue;
                }
                if ($sa === 255) {
                    // source over with sa = 1: result = source
                    $rD[$di] = $rS[$si];
                    $gD[$di] = $gS[$si];
                    $bD[$di] = $bS[$si];
                    $aD[$di] = 255;
                    continue;
                }
                $da = $aD[$di];
                $saF = $sa / 255.0;
                $daF = $da / 255.0;
                $oa = $saF + $daF * (1.0 - $saF);
                if ($oa <= 0.0) {
                    $rD[$di] = 0;
                    $gD[$di] = 0;
                    $bD[$di] = 0;
                    $aD[$di] = 0;
                    continue;
                }
                $inv = 1.0 / $oa;
                $sc = $saF * $inv;
                $dc = $daF * (1.0 - $saF) * $inv;
                $rD[$di] = (int) ($rS[$si] * $sc + $rD[$di] * $dc);
                $gD[$di] = (int) ($gS[$si] * $sc + $gD[$di] * $dc);
                $bD[$di] = (int) ($bS[$si] * $sc + $bD[$di] * $dc);
                $aD[$di] = (int) ($oa * 255.0);
            }
        }
    }

    /** Port of ImageSharp Flip(FlipMode.Horizontal). */
    public function flipHorizontal(): void
    {
        for ($y = 0; $y < $this->h; $y++) {
            $row = $y * $this->w;
            for ($x = 0; $x < (int) ($this->w / 2); $x++) {
                $i = $row + $x;
                $j = $row + ($this->w - 1 - $x);
                [$this->r[$i], $this->r[$j]] = [$this->r[$j], $this->r[$i]];
                [$this->g[$i], $this->g[$j]] = [$this->g[$j], $this->g[$i]];
                [$this->b[$i], $this->b[$j]] = [$this->b[$j], $this->b[$i]];
                [$this->a[$i], $this->a[$j]] = [$this->a[$j], $this->a[$i]];
            }
        }
    }

    /** Port of ImageSharp Resize with KnownResamplers.NearestNeighbor (2x). */
    public function resizeNearest(int $w2, int $h2): self
    {
        $out = new self($w2, $h2);
        $ratioX = $this->w / $w2;
        $ratioY = $this->h / $h2;
        for ($y = 0; $y < $h2; $y++) {
            $sy = (int) ($y * $ratioY);
            if ($sy >= $this->h) {
                $sy = $this->h - 1;
            }
            for ($x = 0; $x < $w2; $x++) {
                $sx = (int) ($x * $ratioX);
                if ($sx >= $this->w) {
                    $sx = $this->w - 1;
                }
                $si = $sy * $this->w + $sx;
                $oi = $y * $w2 + $x;
                $out->r[$oi] = $this->r[$si];
                $out->g[$oi] = $this->g[$si];
                $out->b[$oi] = $this->b[$si];
                $out->a[$oi] = $this->a[$si];
            }
        }
        return $out;
    }

    /** Port of Badge.FixTransparency. */
    public function fixTransparency(): void
    {
        $n = $this->w * $this->h;
        for ($i = 0; $i < $n; $i++) {
            // Color.White = (255,255,255,255); Color.Transparent = (0,0,0,0)
            if ($this->r[$i] === 255 && $this->g[$i] === 255 && $this->b[$i] === 255 && $this->a[$i] === 255) {
                $this->r[$i] = 254;
                $this->g[$i] = 254;
                $this->b[$i] = 254;
                $this->a[$i] = 255;
                continue;
            }
            if ($this->r[$i] === 0 && $this->g[$i] === 0 && $this->b[$i] === 0 && $this->a[$i] === 0) {
                $this->r[$i] = 255;
                $this->g[$i] = 255;
                $this->b[$i] = 255;
                $this->a[$i] = 255;
            }
        }
    }

    public function toPng(): string
    {
        $gd = imagecreatetruecolor($this->w, $this->h);
        imagealphablending($gd, false);
        imagesavealpha($gd, true);
        $n = $this->w * $this->h;
        $cache = [];
        for ($i = 0; $i < $n; $i++) {
            $x = $i % $this->w;
            $y = (int) ($i / $this->w);
            $a = $this->a[$i];
            $gdA = 127 - min(127, max(0, (int) round($a * 127 / 255)));
            $key = $this->r[$i] . ',' . $this->g[$i] . ',' . $this->b[$i] . ',' . $gdA;
            if (!isset($cache[$key])) {
                $cache[$key] = imagecolorallocatealpha($gd, $this->r[$i], $this->g[$i], $this->b[$i], $gdA);
            }
            imagesetpixel($gd, $x, $y, $cache[$key]);
        }
        ob_start();
        imagepng($gd);
        $data = ob_get_clean();
        return $data;
    }

    /**
     * Encodes as GIF89a with a transparency index (our own encoder — GD's
     * quantizer cannot be forced to mark a black entry as the transparent
     * index the way ImageSharp's writer does).
     */
    public function toGif(): string
    {
        return GifEncoder::encode([
            'width' => $this->w,
            'height' => $this->h,
            'r' => $this->r,
            'g' => $this->g,
            'b' => $this->b,
            'a' => $this->a,
        ]);
    }
}
