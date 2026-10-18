<?php

namespace Minerva;

/** PHP port of Avatara.Util.FileUtil. */
class FileUtil
{
    /**
     * First file in $outputDirectory whose (extension-less) name contains
     * $fileNameContains (defaults to the directory name), parsed as XML.
     */
    public static function solveXmlFile(string $outputDirectory, ?string $fileNameContains = null): ?\DOMDocument
    {
        if ($fileNameContains === null) {
            // C# default is the directory name as passed (e.g. "figuredata");
            // use the basename so full-path arguments work too.
            $fileNameContains = basename($outputDirectory);
        }
        foreach (glob($outputDirectory . '/*') as $file) {
            if (str_contains(pathinfo($file, PATHINFO_FILENAME), $fileNameContains)) {
                return self::readXmlFile($file);
            }
        }
        return null;
    }

    /**
     * Loads $file as XML (DOMDocument), applying the same in-place text fixes
     * as the upstream C# FileUtil.ReadeXmlFile (newline before the XML
     * declaration, and the <graphics> wrapper used by some SWF exports).
     */
    public static function readXmlFile(string $file): ?\DOMDocument
    {
        $text = file_get_contents($file);

        if (str_contains($text, "\n<?xml")) {
            $text = str_replace("\n<?xml", '<?xml', $text);
            file_put_contents($file, $text);
        }

        if (str_contains($text, '<graphics>')) {
            $text = str_replace('<graphics>', '', $text);
            $text = str_replace('</graphics>', '            ', $text);
            file_put_contents($file, $text);
        }

        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($text);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $loaded ? $dom : null;
    }

    /**
     * First file in $outputDirectory matching $fileNameContains by equality,
     * suffix or substring (on the extension-less file name).
     */
    public static function solveFile(string $outputDirectory, string $fileNameContains, bool $endsWith = true, bool $equals = false): ?string
    {
        foreach (glob($outputDirectory . '/*') as $file) {
            $base = pathinfo($file, PATHINFO_FILENAME);
            if ($equals) {
                if ($base === $fileNameContains) {
                    return $file;
                }
            } elseif ($endsWith) {
                if (str_ends_with($base, $fileNameContains)) {
                    return $file;
                }
            } else {
                if (str_contains($base, $fileNameContains)) {
                    return $file;
                }
            }
        }
        return null;
    }

    public static function numericLetter(int $animationLayer): string
    {
        $alphabet = str_split('abcdefghijklmnopqrstuvwxyz');
        return $alphabet[$animationLayer];
    }
}
