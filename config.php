<?php
/**
 * Minerva configuration.
 *
 * Copy this folder into your site root, then edit the paths below to match
 * your layout. On the first request Minerva automatically unzips the
 * archives into "unzip_dir" (skipped on later requests once the files
 * exist).
 */
declare(strict_types=1);

return [
    // Path to the figuredata zip. The archive contains a "figuredata/"
    // folder (compiled SWFs + figuredata.xml).
    //   Shockwave client:  tools/figuredata-shockwave.zip
    //   Flash 2013+ client: tools/figuredata-2013-helios.zip
    'figuredata_zip' => __DIR__ . '/tools/figuredata-shockwave.zip',

    // Path to the badges zip. The archive contains a "badges/" folder
    // (badge part PNGs + Shockwave template GIFs).
    'badges_zip' => __DIR__ . '/tools/badges.zip',

    // Path to badge_resource.json (badge definitions; shipped in tools/).
    'badge_resource' => __DIR__ . '/tools/badge_resource.json',

    // Directory the zips are extracted into. The archives contain
    // figuredata/ and badges/ folders, so these end up as
    // <unzip_dir>/figuredata/ and <unzip_dir>/badges/.
    // Defaults to this folder (the site root when the addon is copied in).
    'unzip_dir' => __DIR__,

    // Badge rendering mode:
    //   'shockwave' — Habbo versions using the Shockwave client
    //   'flash'     — Habbo versions using the Flash client (2013+)
    'badge_mode' => 'shockwave',
];
