<?php

namespace Minerva;

/** PHP port of Avatara.Figure.FigureColor. */
class FigureColor
{
    public string $colourId;
    public string $index;
    public bool $isClubRequired;
    public bool $isSelectable;
    public string $hexColor;

    public function __construct(string $colourId, string $index, bool $isClubRequired, bool $isSelectable, string $hexColor)
    {
        $this->colourId = $colourId;
        $this->index = $index;
        $this->isClubRequired = $isClubRequired;
        $this->isSelectable = $isSelectable;
        $this->hexColor = $hexColor;
    }
}
