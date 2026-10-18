<?php

namespace Minerva;

/**
 * PHP port of Avatara's FiguredataReader (Avatara-Imager 1.0.2).
 *
 * Loads figure sets, set types and color palettes from figuredata.xml using
 * the same XPath queries as the C# original (which used XmlDocument.
 * SelectNodes).
 */
class FiguredataReader
{
    public static ?FiguredataReader $instance = null;

    /** @var array<string, array<string, FigureColor>> palette id => list of colors */
    public array $figurePalettes = [];
    /** @var array<string, FigureSetType> */
    public array $figureSetTypes = [];
    /** @var array<string, FigureSet> */
    public array $figureSets = [];

    public ?bool $mandatoryGenderFlag = null;

    public static function getInstance(): FiguredataReader
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function load(): void
    {
        $this->loadFigureSets();
        $this->loadFigureSetTypes();
        $this->loadFigurePalettes();
    }

    private function loadFigureSets(): void
    {
        $doc = FileUtil::solveXmlFile(rtrim(MINERVA_CONFIG['unzip_dir'], '/') . '/figuredata');
        if ($doc === null) {
            return;
        }
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//sets/settype/set');
        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $set) {
            $setType = $set->parentNode->getAttribute('type');
            $id = $set->getAttribute('id');
            $gender = $set->getAttribute('gender');
            $club = $set->getAttribute('club') === '1' || $set->getAttribute('club') === '2';
            $colourable = $set->getAttribute('colorable') === '1';
            $selectable = $set->getAttribute('selectable') === '1';

            $figureSet = new FigureSet($setType, $id, $gender, $club, $colourable, $selectable);

            // First pass: part elements (skip <hiddenlayers> and non-elements,
            // matching the C# "part.Name == 'hiddenlayers' || part.Attributes
            // == null" filter)
            foreach ($set->childNodes as $part) {
                if (!($part instanceof \DOMElement) || $part->nodeName === 'hiddenlayers') {
                    continue;
                }
                $figureSet->figureParts[] = new FigurePart(
                    $part->getAttribute('id'),
                    $part->getAttribute('type'),
                    $part->getAttribute('colorable') === '1',
                    (int) $part->getAttribute('index')
                );
            }

            // Second pass: hiddenlayers
            foreach ($set->childNodes as $part) {
                if (!($part instanceof \DOMElement) || $part->nodeName !== 'hiddenlayers') {
                    continue;
                }
                foreach ($part->childNodes as $hiddenLayer) {
                    if ($hiddenLayer instanceof \DOMElement) {
                        $figureSet->hiddenLayers[] = $hiddenLayer->getAttribute('parttype');
                    }
                }
            }

            $this->figureSets[$id] = $figureSet;
        }
    }

    private function loadFigurePalettes(): void
    {
        $doc = FileUtil::solveXmlFile(rtrim(MINERVA_CONFIG['unzip_dir'], '/') . '/figuredata');
        if ($doc === null) {
            return;
        }
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//colors/palette');
        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $palette) {
            $paletteId = (int) $palette->getAttribute('id');
            $this->figurePalettes[$paletteId] = [];

            foreach ($palette->childNodes as $colour) {
                if (!($colour instanceof \DOMElement)) {
                    continue;
                }
                $colourId = $colour->getAttribute('id');
                $index = $colour->getAttribute('index');
                $isClubRequired = $colour->getAttribute('club') === '1' || $colour->getAttribute('club') === '2';
                $isSelectable = $colour->getAttribute('selectable') === '1';

                $this->figurePalettes[$paletteId][] = new FigureColor(
                    $colourId,
                    $index,
                    $isClubRequired,
                    $isSelectable,
                    $colour->textContent
                );
            }
        }
    }

    private function loadFigureSetTypes(): void
    {
        $doc = FileUtil::solveXmlFile(rtrim(MINERVA_CONFIG['unzip_dir'], '/') . '/figuredata');
        if ($doc === null) {
            return;
        }
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//settype');
        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $setType) {
            $set = $setType->getAttribute('type');
            $paletteId = (int) $setType->getAttribute('paletteid');

            $isMandatoryFlag = $setType->hasAttribute('mandatory');

            $isMandatory = null;
            $isMaleMandatoryNonHC = null;
            $isMaleMandatoryHC = null;
            $isFemaleMandatoryNonHC = null;
            $isFemaleMandatoryHC = null;

            if ($isMandatoryFlag) {
                $isMandatory = $setType->hasAttribute('mandatory') && $setType->getAttribute('mandatory') === '1';

                if ($isMandatory !== null) {
                    $isMaleMandatoryHC = $isMandatory;
                    $isMaleMandatoryNonHC = $isMandatory;
                    $isFemaleMandatoryHC = $isMandatory;
                    $isFemaleMandatoryNonHC = $isMandatory;
                }
            } else {
                $isMaleMandatoryNonHC = $setType->hasAttribute('mand_m_0') && $setType->getAttribute('mand_m_0') === '1';
                $isMaleMandatoryHC = $setType->hasAttribute('mand_m_1') && $setType->getAttribute('mand_m_1') === '1';

                $isFemaleMandatoryNonHC = $setType->hasAttribute('mand_f_0') && $setType->getAttribute('mand_f_0') === '1';
                $isFemaleMandatoryHC = $setType->hasAttribute('mand_f_1') && $setType->getAttribute('mand_f_1') === '1';
            }

            $this->figureSetTypes[$set] = new FigureSetType(
                $set,
                $paletteId,
                $isMandatory,
                $isMaleMandatoryNonHC,
                $isMaleMandatoryHC,
                $isFemaleMandatoryNonHC,
                $isFemaleMandatoryHC
            );
        }
    }
}
