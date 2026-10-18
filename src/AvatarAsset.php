<?php

namespace Minerva;

/**
 * PHP port of Avatara's AvatarAsset (Avatara-Imager 1.0.2).
 *
 * Note: the original constructor's "lay" branch has a quirk preserved 1:1:
 * ImageX uses (canvasH / 2) - (isSmall ? -11 : -10), i.e. +11/+10 added.
 */
class AvatarAsset
{
    public string $name;
    public int $x;
    public int $y;
    public int $imageX;
    public int $imageY;
    public string $fileName;
    public FigurePart $part;
    public FigureSet $set;
    /** @var string[] */
    public array $parts;
    public int $renderOrder;
    public bool $isDrinkCanvas;

    public function __construct(
        bool $isSmall,
        string $action,
        string $name,
        string $fileName,
        int $x,
        int $y,
        FigurePart $part,
        FigureSet $set,
        int $canvasW,
        int $canvasH,
        array $parts
    ) {
        $this->name = $name;
        $this->x = $x;
        $this->y = $y;
        $this->fileName = $fileName;
        $this->part = $part;
        $this->set = $set;
        $this->parts = $parts;
        $this->renderOrder = $part->orderId;
        $this->isDrinkCanvas = false;

        if ($action === 'lay') {
            $this->imageY = $y + (int) ($canvasW / 2) + ($isSmall ? -5 : -20);
            $this->imageX = $x + (int) ($canvasH / 2) - ($isSmall ? -11 : -10);
        } else {
            $this->imageX = $x + (int) ($canvasW / 2) + ($isSmall ? 5 : 10);
            $this->imageY = $y + (int) ($canvasH / 2) - ($isSmall ? 11 : 20);
        }
    }
}
