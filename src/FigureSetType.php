<?php

namespace Minerva;

/**
 * PHP port of Avatara.Figure.FigureSetType.
 *
 * Note: the upstream constructor overwrites IsFemaleMandatoryNonHC with the HC
 * value; that quirk is preserved.
 */
class FigureSetType
{
    public string $set;
    public int $paletteId;
    public ?bool $isMandatory;
    public ?bool $isMaleMandatoryNonHC;
    public ?bool $isMaleMandatoryHC;
    public ?bool $isFemaleMandatoryNonHC;
    public ?bool $isFemaleMandatoryHC;

    public function __construct(
        string $set,
        int $paletteId,
        ?bool $isMandatory,
        ?bool $isMaleMandatoryNonHC,
        ?bool $isMaleMandatoryHC,
        ?bool $isFemaleMandatoryNonHC,
        ?bool $isFemaleMandatoryHC
    ) {
        $this->set = $set;
        $this->paletteId = $paletteId;
        $this->isMandatory = $isMandatory;
        $this->isMaleMandatoryNonHC = $isMaleMandatoryNonHC;
        $this->isMaleMandatoryHC = $isMaleMandatoryHC;
        $this->isFemaleMandatoryNonHC = $isFemaleMandatoryNonHC;
        $this->isFemaleMandatoryNonHC = $isFemaleMandatoryHC; // upstream quirk
    }
}
