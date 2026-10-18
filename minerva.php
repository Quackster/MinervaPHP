<?php

/**
 * Minerva (PHP port) — web entry point.
 *
 * Addon mode: copy this folder into your existing site's root, edit
 * config.php, and .htaccess routes /habbo-imaging/... to this file.
 * On the first request the configured zips are unzipped automatically
 * (see AssetExtractor).
 *
 * Endpoints (mirrors the ASP.NET routing of the original Minerva host):
 *
 *   GET /habbo-imaging/avatarimage            -> image/png (403 without figure)
 *   GET /habbo-imaging/avatarimage/convert    -> application/json (500 + message on error)
 *   GET /habbo-imaging/badge/{badgeCode}      -> image/gif (.gif) or image/png (403 otherwise)
 *   GET /habbo-imaging/badge-fill/{badgeCode} -> image/gif (403 otherwise)
 */

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Minerva\AssetExtractor;
use Minerva\Avatar;
use Minerva\Badge;
use Minerva\BadgeSettings;
use Minerva\FigureConverter;
use Minerva\FiguredataReader;
use Minerva\FlashExtractor;

/**
 * Badge mode from config.php ('shockwave' default, like Program.cs when
 * neither CLI flag is given).
 */
function minerva_shockwave(): bool
{
    return (MINERVA_CONFIG['badge_mode'] ?? 'shockwave') === 'shockwave';
}

function minerva_ensure_assets_loaded(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    // First request: unzip the configured archives (no-op once extracted).
    AssetExtractor::ensureExtracted();

    // .NET loads these once at startup; here it happens on the first
    // request of each PHP process (extraction is skipped because the
    // files already exist, so this is a fast XML parse).
    FlashExtractor::getInstance()->load();
    error_log('Minerva: ' . count(FlashExtractor::getInstance()->parts) . ' flash assets loaded');

    FiguredataReader::getInstance()->load();
    error_log(
        'Minerva: ' . count(FiguredataReader::getInstance()->figureSets) . ' figure sets, '
        . count(FiguredataReader::getInstance()->figureSetTypes) . ' figure set types, '
        . count(FiguredataReader::getInstance()->figurePalettes) . ' figure palettes loaded'
    );
}

function minerva_error(int $status, string $body = '', string $contentType = 'text/plain; charset=utf-8'): void
{
    http_response_code($status);
    header('Content-Type: ' . $contentType);
    if ($body !== '') {
        echo $body;
    }
}

function minerva_avatarimage(): void
{
    $size = 'b';
    $bodyDirection = 2;
    $headDirection = 2;
    $figure = null;
    $action = 'std';
    $gesture = 'std';
    $headOnly = false;
    $frame = 1;
    $carryDrink = -1;
    $cropImage = false;

    if (array_key_exists('figure', $_GET)) {
        $figure = (string) $_GET['figure'];
    }
    if (array_key_exists('action', $_GET)) {
        $action = (string) $_GET['action'];
    }
    if (array_key_exists('gesture', $_GET)) {
        $gesture = (string) $_GET['gesture'];
    }
    if (array_key_exists('size', $_GET)) {
        $size = (string) $_GET['size'];
    }
    if (array_key_exists('head', $_GET)) {
        $headOnly = $_GET['head'] === '1' || $_GET['head'] === 'true';
    }
    if (array_key_exists('direction', $_GET)) {
        $v = (string) $_GET['direction'];
        if (Avatar::isNumeric($v)) {
            $bodyDirection = (int) $v;
        }
    }
    if (array_key_exists('head_direction', $_GET)) {
        $v = (string) $_GET['head_direction'];
        if (Avatar::isNumeric($v)) {
            $headDirection = (int) $v;
        }
    }
    if (array_key_exists('frame', $_GET)) {
        $v = (string) $_GET['frame'];
        if (Avatar::isNumeric($v)) {
            $n = (int) $v;
            $frame = $n < 1 ? 1 : $n;
        }
    }
    if (array_key_exists('drk', $_GET)) {
        $v = (string) $_GET['drk'];
        $action = ($v === '1' || $v === 'true') ? 'drk' : $action;
    }
    if (array_key_exists('crop', $_GET)) {
        $v = (string) $_GET['crop'];
        $cropImage = ($v === '1' || $v === 'true');
    }
    if (array_key_exists('crr', $_GET)) {
        $v = (string) $_GET['crr'];
        if (Avatar::isNumeric($v)) {
            $carryDrink = (int) $v;
        }
    }

    if ($figure !== null && $figure !== '') {
        $avatar = new Avatar(
            FiguredataReader::getInstance(),
            $figure,
            $size,
            $bodyDirection,
            $headDirection,
            action: $action,
            gesture: $gesture,
            headOnly: $headOnly,
            frame: $frame,
            carryDrink: $carryDrink,
            cropImage: $cropImage
        );
        $data = $avatar->run();

        // File(figureData, "image/png") — a null payload throws in the
        // original (500); mirrored here.
        if ($data === null) {
            minerva_error(500, 'The figure could not be rendered.');
            return;
        }

        header('Content-Type: image/png');
        echo $data;
        return;
    }

    minerva_error(403);
}

function minerva_convert(): void
{
    $figure = null;
    if (array_key_exists('figure', $_GET)) {
        $figure = (string) $_GET['figure'];
    }

    try {
        $converted = FigureConverter::getInstance()->convertOldToNew($figure ?? '');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['figure' => $converted]);
    } catch (\Throwable $ex) {
        minerva_error(500, $ex->getMessage());
    }
}

function minerva_badge(string $badgeCode): void
{
    if ($badgeCode !== '') {
        $badge = Badge::parseBadgeData(
            new BadgeSettings(minerva_shockwave()),
            $badgeCode
        );

        if (str_ends_with($badgeCode, '.gif')) {
            $data = $badge->render(gifEncoder: true);
            if ($data !== null) {
                header('Content-Type: image/gif');
                echo $data;
                return;
            }
        } else {
            $data = $badge->render(gifEncoder: false);
            if ($data !== null) {
                header('Content-Type: image/png');
                echo $data;
                return;
            }
        }
    }

    minerva_error(403);
}

function minerva_badge_fill(string $badgeCode): void
{
    if ($badgeCode !== '') {
        $badge = Badge::parseBadgeData(
            new BadgeSettings(minerva_shockwave()),
            $badgeCode
        );

        $data = $badge->render(gifEncoder: true, forceWhiteBackground: true);
        if ($data !== null) {
            header('Content-Type: image/gif');
            echo $data;
            return;
        }
    }

    minerva_error(403);
}

// ---------------------------------------------------------------------------
// routing
// ---------------------------------------------------------------------------

minerva_ensure_assets_loaded();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// .NET routing only maps GET for these endpoints.
if ($method !== 'GET' && $method !== 'HEAD') {
    minerva_error(405);
    exit;
}

if ($uri === '/habbo-imaging/avatarimage') {
    minerva_avatarimage();
} elseif ($uri === '/habbo-imaging/avatarimage/convert') {
    minerva_convert();
} elseif (preg_match('#^/habbo-imaging/badge/(.+)$#', $uri, $m)) {
    minerva_badge($m[1]);
} elseif (preg_match('#^/habbo-imaging/badge-fill/(.+)$#', $uri, $m)) {
    minerva_badge_fill($m[1]);
} else {
    minerva_error(404);
}
