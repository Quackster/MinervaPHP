<?php

namespace Minerva;

/**
 * PHP port of Badger's badge classes (Badger 1.0.0): BadgePartType,
 * BadgeSettings, BadgeResource, BadgeResourceManager, BadgePart, Badge.
 *
 * ImageSharp is replaced by Minerva\PixelImage. Original behavior — including
 * the Int32 overflow / IndexOutOfRange failure modes for unknown shockwave
 * badge colors — is preserved (PHP throws \OutOfBoundsException in the same
 * spots). Flash-mode resource lookups follow C# struct semantics: a missing
 * BadgeResource entry yields default(BadgeResource) (null ExtraData, no
 * exception), so unknown flash codes render an empty badge (HTTP 200) and
 * the "Color1"/"colour1" spelling quirk means flash badges are never tinted.
 */

/** Badger.BadgePartType */
class BadgePartType
{
    public const BASE = 0;
    public const SHAPE = 1;
}

/** Badger.BadgeSettings (struct) */
class BadgeSettings
{
    public string $basePath = '';
    public bool $isShockwaveBadge;

    public function __construct(bool $isShockwaveBadge = false, string $basePath = '')
    {
        $this->isShockwaveBadge = $isShockwaveBadge;
        $this->basePath = $basePath;
    }
}

/** Badger.BadgeResource (struct) */
class BadgeResource
{
    public int $id;
    public ?string $extraData1;
    public ?string $extraData2;
    public string $type;

    /** @param array{Id?:int,ExtraData1?:?string,ExtraData2?:?string,Type?:string} $data */
    public function __construct(array $data)
    {
        $this->id = (int) ($data['Id'] ?? 0);
        $this->extraData1 = $data['ExtraData1'] ?? null;
        $this->extraData2 = $data['ExtraData2'] ?? null;
        $this->type = (string) ($data['Type'] ?? '');
    }
}

/** Badger.BadgeResourceManager */
class BadgeResourceManager
{
    /** @var BadgeResource[] */
    public static array $badgeResources = [];

    public static function load(): void
    {
        if (count(self::$badgeResources) > 0) {
            return;
        }

        self::$badgeResources = [];

        // Path to badge_resource.json comes from config.php (defaults to
        // tools/; the original read it from the working directory).
        $path = MINERVA_CONFIG['badge_resource'];
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents !== false) {
            $list = json_decode($contents, true);
            if (is_array($list)) {
                self::$badgeResources = array_map(fn ($d) => new BadgeResource($d), $list);
            }
        }
    }
}

/** Badger.BadgePart */
class BadgePart
{
    public Badge $badge;
    public int $graphicResource;
    public int $colorResource;
    public ?string $symbol1;
    public ?string $symbol2;
    public ?string $color;
    public int $location;
    public int $type;

    public function __construct(Badge $badge, int $type, int $graphic, int $color, int $location)
    {
        $this->badge = $badge;
        $this->type = $type;
        $this->graphicResource = $graphic;
        $this->colorResource = $color;
        $this->location = $location;

        if (!$badge->settings->isShockwaveBadge) {
            if (count(BadgeResourceManager::$badgeResources) > 0) {
                switch ($type) {
                    case BadgePartType::SHAPE:
                        $this->symbol1 = self::lookupExtra($graphic, 'symbol', 1);
                        $this->symbol2 = self::lookupExtra($graphic, 'symbol', 2);
                        $this->color = self::lookupExtra($color, 'colour1', 1);
                        break;
                    case BadgePartType::BASE:
                        $this->symbol1 = self::lookupExtra($graphic, 'base', 1);
                        $this->symbol2 = self::lookupExtra($graphic, 'base', 2);
                        $this->color = self::lookupExtra($color, 'colour1', 1);
                        break;
                }
            }
        }
    }

    /**
     * Port of:
     *   BadgeResources.Where(x => x.Id == id).FirstOrDefault(x =>
     *       x.Type.ToLower() == type).ExtraData1/2
     * BadgeResource is a C# *struct*: FirstOrDefault on a struct list
     * returns default(BadgeResource) (ExtraData1/2 = null) when nothing
     * matches — no exception. The part is then simply not drawn
     * (Symbol1/Symbol2 null) or not tinted (Color null). Note the
     * bundled JSON spells the color type "Color1", which never matches
     * the "colour1" lookup, so Color is always null in flash mode.
     */
    private static function lookupExtra(int $id, string $type, int $which): ?string
    {
        foreach (BadgeResourceManager::$badgeResources as $x) {
            if ($x->id === $id && strtolower($x->type) === $type) {
                return $which === 1 ? $x->extraData1 : $x->extraData2;
            }
        }
        return null; // default(BadgeResource).ExtraData — no throw
    }

    public function getPosition(PixelImage $canvas, PixelImage $template): array
    {
        $x = 0;
        $y = 0;

        switch ($this->location) {
            case 1:
                $x = intdiv($canvas->w - $template->w, 2);
                break;
            case 2:
                $x = $canvas->w - $template->w;
                break;
            case 3:
                $y = intdiv($canvas->h, 2) - intdiv($template->h, 2);
                break;
            case 4:
                $x = intdiv($canvas->w - $template->w, 2);
                $y = intdiv($canvas->h, 2) - intdiv($template->h, 2);
                break;
            case 5:
                $x = $canvas->w - $template->w;
                $y = intdiv($canvas->h, 2) - intdiv($template->h, 2);
                break;
            case 6:
                // Quirk preserved: the original uses the template WIDTH for y.
                $y = $canvas->h - $template->w;
                break;
            case 7:
                $x = intdiv($canvas->w - $template->w, 2);
                $y = $canvas->h - $template->h;
                break;
            case 8:
                $x = $canvas->w - $template->w;
                $y = $canvas->h - $template->h;
                break;
        }

        return [$x, $y];
    }
}

/** Badger.Badge */
class Badge
{
    public const CANVAS_HEIGHT = 39;
    public const CANVAS_WIDTH = 39;

    public array $colors = [
        'ffd601',
        'ec7600',
        '84de00',
        '589a00',
        '50c1fb',
        '006fcf',
        'ff98e3',
        'f334bf',
        'ff2d2d',
        'af0a0a',
        'ffffff',
        'c0c0c0',
        '373737',
        'fbe7ac',
        '977641',
        'c2eaff',
        'fff165',
        'aaff7d',
    ];

    public array $baseTemplateProxies = [10, 11, 12, 17, 18, 19, 21, 23];

    public array $templateProxies = [
        3, 4, 5, 7, 13, 14, 17, 18, 21, 23,
        28, 29, 30, 31, 34, 35, 40, 41, 42, 50,
        51, 52, 53, 55, 61, 67,
    ];

    /** @var BadgePart[] */
    public array $parts = [];
    public bool $forceWhiteBackground;
    public BadgeSettings $settings;

    public function __construct(BadgeSettings $settings)
    {
        BadgeResourceManager::load();

        $this->settings = $settings;
        $this->parts = [];
        $this->forceWhiteBackground = false;
    }

    public static function parseBadgeData(BadgeSettings $settings, string $badgeCode): Badge
    {
        $badge = new self($settings);

        preg_match_all('/[b|s][0-9]{4,6}/', $badgeCode, $matches);

        foreach ($matches[0] as $partCode) {
            $shortMethod = strlen($partCode) <= 6;

            $partType = $partCode[0];
            $partId = 0;
            $partColor = 0;
            $partPosition = 0;

            if ($settings->isShockwaveBadge) {
                if ($shortMethod) {
                    $partId = (int) substr($partCode, 1, 2);
                    $partColor = (int) substr($partCode, 3, 2);
                    $partPosition = (int) (strlen($partCode) > 5 ? substr($partCode, 5) : '-1');
                } else {
                    $partId = (int) substr($partCode, 1, 3);
                    $partColor = (int) substr($partCode, 4, 2);
                    $partPosition = (int) substr($partCode, 6);
                }
            } else {
                if ($partType === 'b') {
                    $partId = (int) substr($partCode, 1, 3);
                    $partColor = (int) substr($partCode, 4);
                    $partPosition = -1;
                } else {
                    $rest = substr($partCode, 4);
                    $partId = (int) substr($partCode, 1, 3);
                    $colorStr = substr($rest, 0, strlen($rest) - 1);
                    // C# int.Parse throws FormatException for the empty
                    // string — a 5-character 's' code yields an empty color
                    // substring (HTTP 500). PHP's (int) cast would silently
                    // give 0.
                    if ($colorStr === '' || !ctype_digit($colorStr)) {
                        throw new \RuntimeException(
                            "The input string '$colorStr' was not in a correct format."
                        );
                    }
                    $partColor = (int) $colorStr;
                    $partPosition = (int) substr($partCode, strlen($partCode) - 1, 1);
                }
            }

            $badge->parts[] = new BadgePart(
                $badge,
                $partType === 's' ? BadgePartType::SHAPE : BadgePartType::BASE,
                $partId,
                $partColor,
                $partPosition
            );
        }

        return $badge;
    }

    public function render(bool $gifEncoder = true, bool $forceWhiteBackground = false): ?string
    {
        $this->forceWhiteBackground = $forceWhiteBackground;

        $canvas = PixelImage::create(self::CANVAS_WIDTH, self::CANVAS_HEIGHT, [0, 0, 0, 0]);

        foreach ($this->parts as $part) {
            if ($this->settings->isShockwaveBadge) {
                // DLL semantics: GetShockwaveTemplate never returns null
                // (missing file -> 1x1 transparent image), and there is no
                // null check here. Colors[...] is evaluated first, so an
                // out-of-range color throws IndexOutOfRange (HTTP 500) even
                // when the template file is missing.
                $template = $this->getShockwaveTemplate($part->type, $part->graphicResource, false);
                if (!isset($this->colors[$part->colorResource - 1])) {
                    throw new \OutOfBoundsException(
                        'Index was outside the bounds of the array.'
                    );
                }
                $template->tint($this->colors[$part->colorResource - 1], 255);
                [$px, $py] = $part->getPosition($canvas, $template);
                $canvas->draw($template, $px, $py);

                if ($this->isTemplateProxied($part->type, $part->graphicResource)) {
                    $template = $this->getShockwaveTemplate($part->type, $part->graphicResource, true);
                    [$px, $py] = $part->getPosition($canvas, $template);
                    $canvas->draw($template, $px, $py);
                }
            } else {
                if ($part->symbol1 !== null && $part->symbol1 !== '') {
                    // DLL semantics: GetTemplate has no existence check —
                    // a missing file throws (FileLoadException -> HTTP 500).
                    $template = $this->getTemplate($part->symbol1);
                    if ($part->color !== null) {
                        $template->tint($part->color, 255);
                    }
                    [$px, $py] = $part->getPosition($canvas, $template);
                    $canvas->draw($template, $px, $py);
                }

                if ($part->symbol2 !== null && $part->symbol2 !== '') {
                    $template = $this->getTemplate($part->symbol2);
                    [$px, $py] = $part->getPosition($canvas, $template);
                    $canvas->draw($template, $px, $py);
                }
            }
        }

        if ($this->forceWhiteBackground) {
            $canvas->fixTransparency();
        }

        return $gifEncoder ? $canvas->toGif() : $canvas->toPng();
    }

    public function isTemplateProxied(int $type, int $templateId): bool
    {
        if ($type === BadgePartType::BASE) {
            return in_array($templateId, $this->baseTemplateProxies, true);
        }

        return in_array($templateId, $this->templateProxies, true);
    }

    public function getTemplate(string $symbol): PixelImage
    {
        // DLL semantics: no existence check — Image.Load throws for a
        // missing file (FileLoadException -> HTTP 500).
        return PixelImage::fromFile(rtrim(MINERVA_CONFIG['unzip_dir'], '/') . '/badges/badgeparts/' . $symbol);
    }

    public function getShockwaveTemplate(int $type, int $templateId, bool $proxy = false): PixelImage
    {
        $fileGraphic = $templateId < 10 ? '0' . $templateId : (string) $templateId;

        if ($proxy) {
            $fileGraphic .= '_' . $fileGraphic;
        }

        $dir = rtrim(MINERVA_CONFIG['unzip_dir'], '/') . '/badges/shockwave/' . ($type === BadgePartType::BASE ? 'base' : 'templates');
        $filePath = $dir . '/' . ($templateId === 0 ? 'base' : $fileGraphic) . '.gif';

        if ($this->settings->basePath !== '') {
            $filePath = $this->settings->basePath . '/badges/shockwave/'
                . ($type === BadgePartType::BASE ? 'base' : 'templates')
                . '/' . ($templateId === 0 ? 'base' : $fileGraphic) . '.gif';
        }

        // DLL semantics: missing file -> 1x1 transparent image (never null).
        if (!is_file($filePath)) {
            return PixelImage::create(1, 1, [0, 0, 0, 0]);
        }

        return PixelImage::fromFile($filePath);
    }
}
