<?php

namespace Minerva;

/**
 * PHP port of the FigureConverter as shipped in the packaged Avatara.dll
 * (decompiled and verified — differs from the newer GitHub corpus source:
 * the DLL uses fixed body positions (ch=8, lg=4, sh=6), resolves new colors
 * through figuredata/converter/newfiguredata.json's "palette" structure, and
 * takes hat colors directly without cross-palette conversion).
 */
class FigureConverter
{
    private static ?FigureConverter $instance = null;

    private ?array $oldFigureData = null;
    private ?array $newFigureData = null;

    public static function getInstance(): FigureConverter
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function convertOldToNew(string $oldFigure): string
    {
        if ($oldFigure === '' || strlen($oldFigure) < 22) {
            throw new \InvalidArgumentException('Invalid figure string');
        }

        // 5 pairs of (3-digit setId, 2-digit colorIndex)
        $partsString = [];
        $start = 0;
        for ($i = 0; $i < 10; $i++) {
            $length = in_array($i, [0, 2, 4, 6, 8], true) ? 3 : 2;
            $partsString[$i] = substr($oldFigure, $start, $length);
            $start += $length;
        }

        $parts = array_map('intval', $partsString);

        $hrColor = $this->convertOldColorToNew('hr', $parts[0], $parts[1]);

        return
            "hr-{$parts[0]}-" . ($hrColor ?? '') .
            ".hd-{$parts[2]}-" . ($this->convertOldColorToNew('hd', $parts[2], $parts[3]) ?? '') .
            ".ch-{$parts[8]}-" . ($this->convertOldColorToNew('ch', $parts[8], $parts[9]) ?? '') .
            ".lg-{$parts[4]}-" . ($this->convertOldColorToNew('lg', $parts[4], $parts[5]) ?? '') .
            ".sh-" . ($parts[6] === 730 ? 3206 : $parts[6]) . '-' . ($this->convertOldColorToNew('sh', $parts[6], $parts[7]) ?? '') .
            $this->takeCareOfHats($parts[0], (int) ($hrColor ?? 0));
    }

    private function getOldFigureData(): array
    {
        if ($this->oldFigureData === null) {
            $this->oldFigureData = json_decode(
                (string) file_get_contents(rtrim(MINERVA_CONFIG['unzip_dir'], '/') . '/figuredata/converter/oldfiguredata.json'),
                true
            );
        }
        return $this->oldFigureData;
    }

    private function getNewFigureData(): array
    {
        if ($this->newFigureData === null) {
            $this->newFigureData = json_decode(
                (string) file_get_contents(rtrim(MINERVA_CONFIG['unzip_dir'], '/') . '/figuredata/converter/newfiguredata.json'),
                true
            );
        }
        return $this->newFigureData;
    }

    private function getOldColorFromFigureList(string $part, int $sprite, int $colorIndex): ?string
    {
        $genders = $this->getOldFigureData()['genders'] ?? [];

        foreach ($genders as $partTypes) {
            if (!is_array($partTypes)) {
                continue;
            }
            foreach ($partTypes as $partTypeObj) {
                if (!is_array($partTypeObj)
                    || !array_key_exists($part, $partTypeObj)
                    || !is_array($partTypeObj[$part])
                ) {
                    continue;
                }
                foreach ($partTypeObj[$part] as $dataArray) {
                    if (!is_array($dataArray)) {
                        continue;
                    }
                    foreach ($dataArray as $d) {
                        if (is_array($d) && (int) ($d['s'] ?? 0) === $sprite) {
                            return (string) $d['c'][$colorIndex - 1];
                        }
                    }
                }
            }
        }
        return null;
    }

    private function convertOldColorToNew(string $part, int $sprite, int $colorIndex): ?string
    {
        $oldColor = $this->getOldColorFromFigureList($part, $sprite, $colorIndex);
        if ($oldColor === null) {
            return null;
        }

        $palette = $this->getNewFigureData()['palette'] ?? [];
        foreach ($palette as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach ($entry as $name => $obj) {
                if (is_array($obj)
                    && array_key_exists('color', $obj)
                    && (string) $obj['color'] === $oldColor
                ) {
                    return (string) $name;
                }
            }
        }
        return null;
    }

    private function takeCareOfHats(int $spriteId, int $colorId): string
    {
        switch ($spriteId) {
            case 120:
                return '.ha-1001-0';
            case 140:
            case 525:
                return ".ha-1002-{$colorId}";
            case 150:
            case 535:
                return ".ha-1003-{$colorId}";
            case 160:
            case 565:
                return ".ha-1004-{$colorId}";
            case 570:
                return ".ha-1005-{$colorId}";
            case 175:
            case 585:
                return '.ha-1006-0';
            case 176:
            case 580:
                return '.ha-1007-0.fa-1202-70';
            case 177:
            case 590:
                return '.ha-1008-0';
            case 178:
            case 595:
                return '.ha-1009-1321';
            case 130:
                return ".ha-1010-{$colorId}";
            case 801:
                return ".hr-829-{$colorId}.fa-1201-62.ha-1011-{$colorId}";
            case 800:
            case 810:
                return ".ha-1012-{$colorId}";
            case 802:
            case 811:
                return ".ha-1013-{$colorId}";
            default:
                return ".ha-0-{$colorId}";
        }
    }
}
