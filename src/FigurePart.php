<?php

namespace Minerva;

/** PHP port of Avatara.Figure.FigurePart (render order table included). */
class FigurePart
{
    public string $id;
    public string $type;
    public bool $colorable;
    public int $index;
    public int $orderId;

    public function __construct(string $id, string $type, bool $colorable, int $index)
    {
        $this->id = $id;
        $this->type = $type;
        $this->colorable = $colorable;
        $this->index = $index;
        $this->orderId = $this->getOrder();
    }

    private function getOrder(): int
    {
        return match ($this->type) {
            'sh' => 5,
            'lg' => 6,
            'ch' => 7,
            'wa' => 8,
            'ca' => 9,
            'fa' => 27,
            'ea' => 28,
            'ha' => 29,
            'he' => 29, // 20
            'cc' => 1,
            'cp' => 6,
            'hd' => 22,
            'bd' => 1,
            'fc' => 23,
            'hr' => 24,
            'lh' => 5,
            'ls' => 7,
            'rh' => 10,
            'rs' => 11,
            'ey' => 24,
            'li' => 0,
            'hrb' => 26,
            'ri' => 9,
            'lc' => 23,
            'rc' => 24,
            'fx' => 100,
            default => -1,
        };
    }
}
