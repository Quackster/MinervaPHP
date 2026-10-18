<?php

namespace Minerva;

/**
 * One-time asset setup: unzips the configured figuredata/badges archives
 * into the configured directory on the first request (replaces the
 * build-time `unzip` step the Docker image used to do).
 */
class AssetExtractor
{
    public static function ensureExtracted(): void
    {
        $unzip = rtrim(MINERVA_CONFIG['unzip_dir'], '/');

        // figuredata zip -> <unzip>/figuredata/compiled/*.swf
        self::unzipIfNeeded(MINERVA_CONFIG['figuredata_zip'], $unzip, "$unzip/figuredata/compiled");
        // badges zip -> <unzip>/badges/badgeparts/*.png
        self::unzipIfNeeded(MINERVA_CONFIG['badges_zip'], $unzip, "$unzip/badges/badgeparts");
    }

    private static function unzipIfNeeded(string $zip, string $dest, string $markerDir): void
    {
        if (is_dir($markerDir) && count(glob($markerDir . '/*')) > 0) {
            return; // already extracted
        }
        if (!is_file($zip)) {
            error_log("Minerva: configured zip not found: $zip");
            return;
        }

        if (class_exists('ZipArchive')) {
            $zipFile = new \ZipArchive();
            if ($zipFile->open($zip) !== true) {
                error_log("Minerva: could not open zip: $zip");
                return;
            }
            $zipFile->extractTo($dest);
            $zipFile->close();
        } else {
            // Fallback when the zip extension is missing (e.g. bare php:cli).
            $cmd = sprintf('unzip -qqo %s -d %s 2>&1', escapeshellarg($zip), escapeshellarg($dest));
            $output = shell_exec($cmd);
            if ($output !== null && trim($output) !== '') {
                error_log("Minerva: unzip failed for $zip: $output");
                return;
            }
        }
        error_log("Minerva: extracted $zip into $dest");
    }
}
