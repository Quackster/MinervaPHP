# Minerva (PHP port)

A 1:1 PHP port of [Minerva](https://github.com/Quackster/Minerva) — the
Habbo Hotel imager server for Shockwave and Flash releases. The original is a
thin .NET host around the [Avatara](https://github.com/Quackster/Avatara) and
[Badger](https://github.com/Quackster/Badger) libraries; this port
re-implements the host **and both libraries** in pure PHP (PHP 8.4+, GD and
zlib only, no Composer dependencies).

It ships as a **drop-in addon**: copy the folder into your existing site's
root, edit `config.php`, done. `.htaccess` routes the imager endpoints to
`minerva.php`, and on the first request the figuredata/badges zips are
unzipped automatically (no build step, no CLI).

## Quick start

### Prerequisites

- PHP 8.4+ with the `gd` extension and either the `zip` extension or the
  `unzip` binary (for first-request extraction)
- Apache with `mod_rewrite` (for the `.htaccess` routing)

### Install

1. Copy this folder into your site's root directory.
2. Edit `config.php`:

   ```php
   return [
       // figuredata zip — shockwave or flash 2013+ (see tools/)
       'figuredata_zip' => __DIR__ . '/tools/figuredata-shockwave.zip',
       // badges zip
       'badges_zip' => __DIR__ . '/tools/badges.zip',
       // badge definitions JSON
       'badge_resource' => __DIR__ . '/tools/badge_resource.json',
       // where the zips are extracted (they contain figuredata/ and badges/)
       'unzip_dir' => __DIR__,
       // 'shockwave' (pre-2013 client) or 'flash' (2013+ client)
       'badge_mode' => 'shockwave',
   ];
   ```

3. Request anything — the first request unzips the configured archives into
   `unzip_dir` and caches the figure data for the process:

   ```
   Minerva: 9762 flash assets loaded
   Minerva: 301 figure sets, 11 figure set types, 3 figure palettes loaded
   ```

   (Startup lines go to the PHP error log, not the response.)

### Smoke test

To run the server ad-hoc without Apache/nginx, `minerva.php` doubles as a
router for the PHP built-in server (it dispatches on `REQUEST_URI`):

```sh
cd MinervaPHP
php -S 127.0.0.1:8080 minerva.php
```

Then hit the endpoints against `http://127.0.0.1:8080`:

```sh
curl -o avatar.png "http://127.0.0.1:8080/habbo-imaging/avatarimage?figure=hd-180-1.hr-100-61.ch-210-66.lg-270-82.sh-290-80&size=b&direction=4&head_direction=4&crr=0&gesture=sml&frame=1"
curl -o badge.gif "http://127.0.0.1:8080/habbo-imaging/badge/b0101.gif"
curl "http://127.0.0.1:8080/habbo-imaging/avatarimage/convert?figure=1000118001270012900121001"
# → {"figure":"hr-100-1150.hd-180-1026.ch-210-1284.lg-270-1223.sh-290-1247.ha-0-1150"}
```

(Or use the `https://your-site/...` URLs in the same commands once the
addon is deployed behind Apache/nginx.)

## API endpoints

| Endpoint | Description |
|---|---|
| `GET /habbo-imaging/avatarimage?figure=...` | Avatar PNG. Query params: `figure` (required), `size` (`s`/`b`/`l`), `direction`, `head_direction`, `head=1`, `frame`, `drk=1`, `crr=N`, `action`, `gesture`, `crop` |
| `GET /habbo-imaging/avatarimage/convert?figure=...` | Old→new figure code conversion JSON |
| `GET /habbo-imaging/badge/{badge-code}.gif` | Badge GIF (Flash client) |
| `GET /habbo-imaging/badge/{badge-code}` | Badge PNG |
| `GET /habbo-imaging/badge-fill/{badge-code}.gif` | Badge with white background (Shockwave client `group_logo_url_template`) |

### In-client setup

Point the client at the badge-fill endpoint, exactly as in the original:

```
group_logo_url_template=https://your-site/habbo-imaging/badge-fill/%imagerdata%.gif
```

## Project layout

```
MinervaPHP/
├── .htaccess             # routes /habbo-imaging/... to minerva.php
├── minerva.php           # HTTP entry point (controllers equivalent)
├── config.php            # zip paths, unzip location, badge mode
├── src/                  # Ported Avatara + Badger libraries
│   ├── AssetExtractor.php  # first-request zip extraction
│   ├── Avatar.php / AvatarAsset.php / PixelImage.php
│   ├── Badge.php         # Badge, BadgePart, BadgeResource(Manager), BadgeSettings
│   ├── FlashExtractor.php / Swf.php   # pure-PHP SWF tag parser
│   ├── FiguredataReader.php + figure classes
│   ├── FigureConverter.php
│   ├── Gif.php           # GIF decoder (12-bit LZW)
│   └── GifEncoder.php    # GIF89a writer (transparency + quantization)
└── tools/                # figuredata + badges tool zips (as in the original)
```

## What was ported

| Original (.NET) | This port (PHP) |
|---|---|
| `Minerva/Program.cs` (host, startup banner) | `minerva.php` + `src/AssetExtractor.php` |
| ASP.NET controllers | `minerva.php` (routing) |
| `Avatara`: `FlashExtractor` + Flazzy SWF decoding | `src/FlashExtractor.php` + `src/Swf.php` |
| `Avatara`: `FiguredataReader`, `FigureSet(Type)`, `FigurePart`, `FigureColor` | `src/FiguredataReader.php` + figure classes (XML via DOM/XPath) |
| `Avatara`: `FigureConverter` | `src/FigureConverter.php` (JSON via `json_decode`) |
| `Avatara`: `Avatar`, `AvatarAsset` (ImageSharp) | `src/Avatar.php` + `src/AvatarAsset.php` + `src/PixelImage.php` |
| `Badger`: `Badge`, `BadgePart`, `BadgeResource(Manager)`, `BadgeSettings` | `src/Badge.php` |
| GIF read/write (badge templates + output) | `src/Gif.php` + `src/GifEncoder.php` |
| ImageSharp pixel processing (tint, Normal blending, flip, NN resize) | `src/PixelImage.php` (raw per-pixel RGBA, same "source over" formula) |

Known original quirks are preserved 1:1 (e.g. `FigureSetType` overwriting the
HC mandatory flags, `BadgePart.GetPosition` case 6 using the template *width*
for `y`, the avatar `MutatePoint` (+1, +2) offset, the `int.Parse`
FormatException on 5-character flash `s`-badge codes, flash-mode badge
resources never tinting because the JSON spells the color type "Color1" while
the lookup asks for "colour1").

## Server rewrite rules

The imager endpoints must be routed to `minerva.php`.

### Apache (`.htaccess`)

The bundled `.htaccess` already does this:

```apache
RewriteEngine On
RewriteRule ^habbo-imaging(/.*)?$ /minerva.php [L,QSA]
```

### Nginx

Inside your `server { ... }` block:

```nginx
location ~ ^/habbo-imaging(/.*)?$ {
    rewrite ^ /minerva.php last;
}
```

`$query_string` is passed through automatically, so no extra handling is needed
for the `avatarimage` query parameters.

## Differences from the original

- The original is a standalone Kestrel server; this port is a drop-in addon
  routed through your site's existing PHP/Apache setup.
- Badge mode is set in `config.php` instead of a CLI flag
  (`--shockwave-badge-render` / `--flash-badge-render`).
- The build-time `unzip` step is replaced by automatic first-request
  extraction via `ZipArchive` (`src/AssetExtractor.php`).
- GIF output uses a hand-rolled GIF89a encoder (`src/GifEncoder.php`) instead
  of ImageSharp's octree quantizer: geometry, transparency and tinting are
  identical, the exact palette bytes can differ between the two encoders.
  Badge templates are read through a hand-rolled decoder (`src/Gif.php`)
  because GD mis-decodes their palettes.
