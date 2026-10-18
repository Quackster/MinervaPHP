<?php

namespace Minerva;

/** PHP port of Avatara.Figure.FigureSet. */
class FigureSet
{
    /** @var FigurePart[] */
    public array $figureParts = [];
    /** @var string[] */
    public array $hiddenLayers = [];
    public string $setType;
    public string $id;
    public string $gender;
    public bool $club;
    public bool $colourable;
    public bool $selectable;

    public function __construct(string $setType, string $id, string $gender, bool $club, bool $colourable, bool $selectable)
    {
        $this->setType = $setType;
        $this->id = $id;
        $this->gender = $gender;
        $this->club = $club;
        $this->colourable = $colourable;
        $this->selectable = $selectable;
    }
}
