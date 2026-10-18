<?php

namespace Minerva;

/**
 * PHP port of Avatara's Avatar (Avatara-Imager 1.0.2).
 *
 * ImageSharp is replaced by Minerva\PixelImage (raw per-pixel RGBA with the
 * same Normal-blend semantics). All original quirks are preserved 1:1, e.g.
 * MutatePoint's (+1, +2) offset, the carry-drink direction remapping, and the
 * hidden-layer skip check that inspects every asset in the queue.
 */
class Avatar
{
    public string $figure;
    public string $size;
    public bool $isSmall;
    public int $bodyDirection;
    public int $headDirection;
    public FiguredataReader $figuredataReader;
    public string $action; // std
    public string $gesture;
    public int $frame;
    public int $carryDrink;

    public PixelImage $bodyCanvas;
    public PixelImage $faceCanvas;
    public PixelImage $drinkCanvas;

    public int $canvasHeight = 110;
    public int $canvasWidth = 64;
    public bool $renderEntireFigure;
    public bool $cropImage = false;

    public function __construct(
        FiguredataReader $figuredataReader,
        string $figure,
        string $size,
        int $bodyDirection,
        int $headDirection,
        string $action = 'std',
        string $gesture = 'std',
        bool $headOnly = false,
        int $frame = 1,
        int $carryDrink = 0,
        bool $cropImage = false
    ) {
        if ($figure !== '' && ctype_digit($figure)) {
            $figure = FigureConverter::getInstance()->convertOldToNew($figure);
            // The .NET original writes to the console; php -S (unlike the CLI
            // SAPI) does not define STDOUT/STDERR constants, so use the stream.
            fwrite(fopen('php://stdout', 'w'), "Converting old figure to new: $figure\n");
        }

        $this->figure = $figure;
        $this->size = strtolower($size);
        $this->isSmall = $this->size !== 'b' && $this->size !== 'l';
        $this->bodyDirection = $bodyDirection;
        $this->headDirection = $headDirection;
        $this->figuredataReader = $figuredataReader;
        $this->renderEntireFigure = !$headOnly;
        $this->cropImage = $cropImage;

        if ($this->isSmall) {
            $this->canvasWidth = intdiv($this->canvasWidth, 2);
            $this->canvasHeight = intdiv($this->canvasHeight, 2);
        }

        $this->action = $action;
        $this->gesture = $gesture;

        if ($action === 'lay') {
            $tmp = $this->canvasHeight;
            $this->canvasHeight = $this->canvasWidth;
            $this->canvasWidth = $tmp;
        }

        $fill = PixelImage::hexToColor('transparent');
        $this->bodyCanvas = PixelImage::create($this->canvasWidth, $this->canvasHeight, $fill);
        $this->faceCanvas = PixelImage::create($this->canvasWidth, $this->canvasHeight, $fill);
        $this->drinkCanvas = PixelImage::create($this->canvasWidth, $this->canvasHeight, $fill);

        $this->frame = $frame - 1;
        $this->carryDrink = $carryDrink;

        if ($action === 'lay') {
            $this->gesture = 'lay';
            $this->action = 'lay';
            $this->headDirection = $this->bodyDirection;

            if ($this->bodyDirection !== 2 && $this->bodyDirection !== 4) {
                $this->bodyDirection = 2;
            }
            if ($this->headDirection !== 2 && $this->headDirection !== 4) {
                $this->headDirection = 2;
            }

            $this->carryDrink = 0;
        }
    }

    public function run(): ?string
    {
        $buildQueue = $this->buildDrawQueue();

        if ($buildQueue === null) {
            return null;
        }

        return $this->drawImage($buildQueue);
    }

    private function drawImage(array $buildQueue): string
    {
        foreach ($buildQueue as $asset) {
            if (!$this->renderEntireFigure && !$this->isHead($asset->part->type)) {
                continue;
            }

            $this->drawAsset($buildQueue, $asset);
        }

        if ($this->headDirection === 4 || $this->headDirection === 6 || $this->headDirection === 5) {
            $this->faceCanvas->flipHorizontal();
        }

        if ($this->bodyDirection === 4 || $this->bodyDirection === 6 || $this->bodyDirection === 5) {
            $this->bodyCanvas->flipHorizontal();
            $this->drinkCanvas->flipHorizontal();
        }

        $this->bodyCanvas->draw($this->faceCanvas, 0, 0);
        $this->bodyCanvas->draw($this->drinkCanvas, 0, 0);

        $finalCanvas = $this->renderEntireFigure ? $this->bodyCanvas : $this->faceCanvas;
        return $this->renderImage($finalCanvas);
    }

    private function renderImage(PixelImage $croppedBitmap): string
    {
        if ($this->size === 'l') {
            $image = $croppedBitmap->resizeNearest($croppedBitmap->w * 2, $croppedBitmap->h * 2);
            return $image->toPng();
        }

        return $croppedBitmap->toPng();
    }

    private function drawAsset(array $buildQueue, AvatarAsset $asset): void
    {
        $image = PixelImage::fromFile($asset->fileName);

        // Quirk preserved: checks the hidden layers of *every* asset in the
        // queue against this asset's part type.
        $hidden = false;
        foreach ($buildQueue as $other) {
            if (in_array($asset->part->type, $other->set->hiddenLayers, true)) {
                $hidden = true;
                break;
            }
        }
        if ($hidden) {
            return;
        }

        if ($asset->part->type !== 'ey') {
            if ($asset->part->colorable) {
                $parts = $asset->parts;
                if (count($parts) > 2 && strlen($parts[2]) > 0 && self::isNumeric($parts[2])) {
                    $paletteId = (int) $parts[2];
                    if (isset($this->figuredataReader->figureSetTypes[$parts[0]])) {
                        $figureTypeSet = $this->figuredataReader->figureSetTypes[$parts[0]];
                        if (isset($this->figuredataReader->figurePalettes[$figureTypeSet->paletteId])) {
                            $palette = $this->figuredataReader->figurePalettes[$figureTypeSet->paletteId];
                            $colourData = null;
                            foreach ($palette as $x) {
                                if ($x->colourId === $parts[2]) {
                                    $colourData = $x;
                                    break; // FirstOrDefault
                                }
                            }
                            if ($colourData !== null) {
                                $image->tint($colourData->hexColor, 255);
                            }
                        }
                    }
                }
            }
        } else {
            $image->tint('FFFFFF', 255);
        }

        // The original wraps the draw in a try/catch ({}); missing files or
        // decode errors here are swallowed.
        try {
            if ($this->isHead($asset->part->type)) {
                $pointX = $this->faceCanvas->w - $asset->imageX;
                $pointY = $this->faceCanvas->h - $asset->imageY;
                $point = $this->mutatePoint($pointX, $pointY);
                $this->faceCanvas->draw($image, $point[0], $point[1]);
            } else {
                $pointX = $this->bodyCanvas->w - $asset->imageX;
                $pointY = $this->bodyCanvas->h - $asset->imageY;
                $point = $this->mutatePoint($pointX, $pointY);
                if (!$asset->isDrinkCanvas) {
                    $this->bodyCanvas->draw($image, $point[0], $point[1]);
                } else {
                    $this->drinkCanvas->draw($image, $point[0], $point[1]);
                }
            }
        } catch (\Throwable) {
            // swallow, as in the original
        }
    }

    private function mutatePoint(int $x, int $y): array
    {
        $x = $x + 1;
        $y = $y + 2;
        return [$x, $y];
    }

    private function buildDrawQueue(): ?array
    {
        $tempQueue = [];
        $figureData = [];

        foreach (explode('.', $this->figure) as $data) {
            $parts = explode('-', $data);
            $figureData[$parts[0]] = implode('-', $parts);
        }

        foreach ($figureData as $data) {
            $parts = explode('-', $data);
            if (count($parts) < 2) {
                return null;
            }

            // FigureSets is keyed by id in the original too (Dictionary id ->
            // FigureSet); the LINQ filter matches on the same id.
            $setList = [];
            foreach ($this->figuredataReader->figureSets as $set) {
                if ($set->id === $parts[1]) {
                    $setList[] = $set;
                }
            }
            if (count($setList) > 0) {
                foreach ($setList as $set) {
                    foreach ($set->figureParts as $part) {
                        $t = $this->loadFigureAsset($parts, $part, $set);
                        if ($t === null) {
                            continue;
                        }
                        $tempQueue[] = $t;
                    }
                }
            }
        }

        $headRenderList = array_filter($tempQueue, fn ($x) => $this->isHead($x->part->type));
        $headRenderOrder = count($headRenderList) > 0
            ? max(array_map(fn ($x) => $x->renderOrder, $headRenderList))
            : 1;

        if ($this->carryDrink > 0) {
            $carryItemAsset = $this->loadCarryItemAsset($this->carryDrink);
            if ($carryItemAsset !== null) {
                if ($this->bodyDirection === 1 || $this->bodyDirection === 5
                    || $this->bodyDirection === 6 || $this->bodyDirection === 0
                ) {
                    $carryItemAsset->isDrinkCanvas = false;
                    $carryItemAsset->renderOrder = 0;
                } else {
                    $carryItemAsset->renderOrder = $headRenderOrder + 1;
                    $carryItemAsset->isDrinkCanvas = true;
                }
                $tempQueue[] = $carryItemAsset;

                if ($this->action === 'drk') {
                    foreach ($tempQueue as $asset) {
                        if ($asset === $carryItemAsset) {
                            continue;
                        }
                        if (str_contains($asset->name, '_drk_')) {
                            $asset->renderOrder = 100;
                            $asset->isDrinkCanvas = true;
                        }
                    }
                }
            }
        }

        // .NET's OrderBy is a stable sort; PHP 8+ sorts are stable as well.
        usort($tempQueue, fn ($x, $y) => $x->renderOrder <=> $y->renderOrder);
        return $tempQueue;
    }

    private function loadCarryItemAsset(int $carryId): ?AvatarAsset
    {
        $direction = $this->bodyDirection;
        if ($this->bodyDirection === 4) {
            $direction = 2;
        }
        if ($this->bodyDirection === 6) {
            $direction = 0;
        }
        if ($this->bodyDirection === 5) {
            $direction = 1;
        }

        $part = new FigurePart('0', 'ri', false, 0);
        $set = new FigureSet('ri', '', '', false, false, false);

        $asset = $this->locateAsset(($this->isSmall ? 'sh' : 'h') . '_' . $this->action . '_ri_' . $carryId . '_' . $direction . '_0', null, $part, $set);
        if ($asset === null) {
            $asset = $this->locateAsset(($this->isSmall ? 'sh' : 'h') . '_crr_ri_' . $carryId . '_' . $direction . '_0', null, $part, $set);
        }
        if ($asset === null) {
            $asset = $this->locateAsset(($this->isSmall ? 'sh' : 'h') . '_std_ri_' . $carryId . '_0_0', null, $part, $set);
        }

        return $asset;
    }

    private function loadFigureAsset(array $parts, FigurePart $part, FigureSet $set): ?AvatarAsset
    {
        $direction = 0;
        $gesture = '';

        if ($this->isHead($part->type)) {
            $direction = $this->headDirection;
            $gesture = $this->gesture;
            if ($this->headDirection === 4) {
                $direction = 2;
            }
            if ($this->headDirection === 6) {
                $direction = 0;
            }
            if ($this->headDirection === 5) {
                $direction = 1;
            }
        } else {
            $direction = $this->bodyDirection;
            $gesture = $this->action;
            if ($this->bodyDirection === 4) {
                $direction = 2;
            }
            if ($this->bodyDirection === 6) {
                $direction = 0;
            }
            if ($this->bodyDirection === 5) {
                $direction = 1;
            }
        }

        if ($direction === 1 && $part->type === 'ls') {
            return null;
        }
        if ($this->action === 'lay') {
            if ($this->bodyDirection === 4) {
                $direction = 2;
            }
        }
        if ($this->carryDrink > 0 && $this->action !== 'lay' && $this->action !== 'drk') {
            $partsForAction = ['ls', 'lh'];
            if (in_array($part->type, $partsForAction, true)) {
                $gesture = 'std';
            }
        }

        $asset = null;

        if ($this->carryDrink > 0 && ($part->type === 'rs' || $part->type === 'rh')
            && $this->action !== 'drk' && $this->action !== 'crr'
        ) {
            $asset = $this->locateAsset(
                ($this->isSmall ? 'sh' : 'h') . '_crr_' . $part->type . '_' . $part->id . '_' . $direction . '_0',
                $parts,
                $part,
                $set
            );
        }

        if ($asset === null) {
            $asset = $this->locateAsset(
                ($this->isSmall ? 'sh' : 'h') . '_' . $gesture . '_' . $part->type . '_' . $part->id . '_' . $direction . '_' . $this->frame,
                $parts,
                $part,
                $set
            );
        }

        if ($asset === null) {
            $asset = $this->locateAsset(
                ($this->isSmall ? 'sh' : 'h') . '_std_' . $part->type . '_' . $part->id . '_' . $direction . '_' . $this->frame,
                $parts,
                $part,
                $set
            );
        }

        if ($asset === null) {
            $asset = $this->locateAsset(
                ($this->isSmall ? 'sh' : 'h') . '_std_' . $part->type . '_' . $part->id . '_' . $direction . '_0',
                $parts,
                $part,
                $set
            );
        }

        if ($this->isSmall) {
            if ($asset === null) {
                $asset = $this->locateAsset(
                    ($this->isSmall ? 'sh' : 'h') . '_std_' . $part->type . '_1_' . $direction . '_' . $this->frame,
                    $parts,
                    $part,
                    $set
                );
            }
        }

        return $asset;
    }

    public function isHead(string $figurePart): bool
    {
        return str_contains($figurePart, 'hr') ||
            str_contains($figurePart, 'hd') ||
            str_contains($figurePart, 'he') ||
            str_contains($figurePart, 'ha') ||
            str_contains($figurePart, 'ea') ||
            str_contains($figurePart, 'fa') ||
            str_contains($figurePart, 'ey') ||
            str_contains($figurePart, 'fc');
    }

    private function locateAsset(string $assetName, ?array $parts, FigurePart $part, FigureSet $set): ?AvatarAsset
    {
        $fe = FlashExtractor::getInstance();
        $offsets = $fe->parts[$assetName] ?? null;
        if ($offsets === null) {
            return null;
        }

        $file = FileUtil::solveFile(rtrim(MINERVA_CONFIG['unzip_dir'], '/') . '/figuredata/images/', $assetName, false, true);
        if ($file === null) {
            return null;
        }

        [$offsetX, $offsetY] = array_map('intval', explode(',', $offsets));

        // Quirk preserved: the C# caller passes (CANVAS_HEIGHT, CANVAS_WIDTH)
        // into the constructor's (canvasW, canvasH) parameters — the values
        // are swapped relative to their names.
        return new AvatarAsset(
            $this->isSmall,
            $this->action,
            $assetName,
            $file,
            $offsetX,
            $offsetY,
            $part,
            $set,
            $this->canvasHeight,
            $this->canvasWidth,
            $parts ?? []
        );
    }

    /**
     * Port of StringExtensions.IsNumeric (long.TryParse with
     * NumberStyles.Integer): optional sign, digits only.
     */
    public static function isNumeric(string $value): bool
    {
        return (bool) preg_match('/^[+-]?\d+$/', $value);
    }
}
