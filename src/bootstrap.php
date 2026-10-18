<?php
/**
 * Bootstrap: loads the Minerva PHP library and configuration.
 */
declare(strict_types=1);

if (!defined('BASE_DIR')) {
    define('BASE_DIR', dirname(__DIR__));
}

if (!defined('MINERVA_CONFIG')) {
    $configFile = BASE_DIR . '/config.php';
    $config = is_file($configFile) ? (require $configFile) : [];
    $config = array_merge([
        'figuredata_zip' => BASE_DIR . '/tools/figuredata-shockwave.zip',
        'badges_zip' => BASE_DIR . '/tools/badges.zip',
        'badge_resource' => BASE_DIR . '/tools/badge_resource.json',
        'unzip_dir' => BASE_DIR,
        'badge_mode' => 'shockwave',
    ], $config);
    define('MINERVA_CONFIG', $config);
}

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (str_starts_with($class, 'Minerva\\')) {
        $file = __DIR__ . '/' . substr($class, strlen('Minerva\\')) . '.php';
    }
    if (is_file($file)) {
        require $file;
    }
});
