<?php

namespace Minerva;

/**
 * PHP port of Avatara's FlashExtractor (Avatara-Imager 1.0.2).
 *
 * Extracts the embedded XML manifests (DefineBinaryData, tag 87) and the
 * embedded bitmaps (DefineBitsLossless2, tag 36, addressed through the
 * SymbolClass) from every compiled SWF in figuredata/compiled/ into
 * figuredata/xml/ and figuredata/images/, then builds the
 * asset-name => "x,y" offset table from the XML manifests.
 */
class FlashExtractor
{
    public static ?FlashExtractor $instance = null;

    /** @var array<string, string> asset name => "x,y" offsets */
    public array $parts = [];

    public static function getInstance(): FlashExtractor
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function load(): void
    {
        $this->parts = [];

        $base = rtrim(MINERVA_CONFIG['unzip_dir'], '/') . '/figuredata';
        foreach (['xml', 'converter', 'images'] as $dir) {
            if (!is_dir("$base/$dir")) {
                mkdir("$base/$dir", 0755, true);
            }
        }

        // Upstream only writes these when missing (bundled figuredata zip
        // always ships them, so this is a no-op in practice).
        if (!file_exists("$base/converter/oldfiguredata.json")) {
            file_put_contents("$base/converter/oldfiguredata.json", '');
        }

        $needExtraction = empty(glob("$base/xml/*")) || empty(glob("$base/images/*"));
        if ($needExtraction) {
            foreach (glob("$base/compiled/*") as $file) {
                $fileName = pathinfo($file, PATHINFO_FILENAME);

                $swf = new Swf($file);
                $symbolClass = $swf->symbolClass();
                $symbolClassIds = $symbolClass['ids'];
                $symbolClassNames = $symbolClass['names'];

                // XML manifests from DefineBinaryData tags (tag 87)
                foreach ($swf->tagList(87) as $payload) {
                    $data = $swf->defineBinaryData($payload);
                    $idIndex = array_search($data['id'], $symbolClassIds, true);
                    if ($idIndex === false) {
                        continue;
                    }
                    $name = $symbolClassNames[$idIndex];
                    $xmlPath = "$base/xml/$fileName.xml";
                    if (!file_exists($xmlPath)) {
                        file_put_contents($xmlPath, $data['data']);
                    }
                }

                // Bitmaps from DefineBitsLossless2 tags (tag 36)
                $symbolsImages = [];
                foreach ($swf->tagList(36) as $payload) {
                    $id = unpack('v', substr($payload, 0, 2))[1];
                    $symbolsImages[$id] = $payload;
                }

                foreach ($symbolClassNames as $symbol) {
                    $symbolId = $symbolClassIds[array_search($symbol, $symbolClassNames, true)];
                    if (!isset($symbolsImages[$symbolId])) {
                        continue;
                    }
                    $xmlName = substr($symbol, strlen($fileName) + 1);
                    $this->writeImage($symbolsImages[$symbolId], "$base/images/$xmlName.png");
                }
            }
        }

        foreach (glob("$base/xml/*") as $manifest) {
            $this->parseXml($manifest);
        }
    }

    private function parseXml(string $fileName): void
    {
        $doc = FileUtil::readXmlFile($fileName);
        if ($doc === null) {
            return;
        }

        // Same XPath as upstream C# (works whether the root is <manifest> or
        // <manifest> is nested).
        $xpath = new \DOMXPath($doc);
        $assets = $xpath->query('//manifest/library/assets/asset');
        if ($assets === false) {
            return;
        }

        foreach ($assets as $asset) {
            $name = $asset->getAttribute('name');
            if ($name === '') {
                continue;
            }
            if (count(explode('_', $name)) < 3) {
                continue;
            }

            $offsets = null;
            // Upstream uses ChildNodes.Item(0) with XmlDocument's default
            // PreserveWhitespace=false, i.e. the first *element* child.
            foreach ($asset->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $offsets = $child->getAttribute('value') !== '' ? $child->getAttribute('value') : null;
                    break;
                }
            }

            if ($offsets !== null && $offsets !== '' && !isset($this->parts[$name])) {
                $this->parts[$name] = $offsets;
            }
        }
    }

    /**
     * Renders a DefineBitsLossless2 payload to a PNG file (ImageSharp
     * equivalent: transparent canvas + ARGB pixel map + PNG encode).
     */
    private function writeImage(string $payload, string $path): void
    {
        if (file_exists($path)) {
            return;
        }

        $image = Swf::parseDefineBitsLossless2($payload);
        $width = $image['width'];
        $height = $image['height'];

        $img = imagecreatetruecolor($width, $height);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);

        $pixels = $image['pixels'];
        $p = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                // ARGB byte order
                $a = ord($pixels[$p]);
                $r = ord($pixels[$p + 1]);
                $g = ord($pixels[$p + 2]);
                $b = ord($pixels[$p + 3]);
                $color = imagecolorallocatealpha($img, $r, $g, $b, 127 - (int) round($a * 127 / 255));
                imagesetpixel($img, $x, $y, $color);
                $p += 4;
            }
        }

        imagepng($img, $path);
    }
}
