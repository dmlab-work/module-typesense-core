<?php
/**
 * Copyright © DMLab. All rights reserved.
 *
 * Standalone unit-test bootstrap: loads Magento's Composer autoloader (for the
 * framework classes this module depends on), registers a PSR-4 map for this
 * module so its own classes resolve without a full `composer install`, then
 * runs the module registration.
 */
declare(strict_types=1);

$moduleRoot = dirname(__DIR__, 2);

$candidates = [
    getenv('MAGENTO_ROOT') ? rtrim((string)getenv('MAGENTO_ROOT'), '/') . '/vendor/autoload.php' : null,
    '/var/www/html/vendor/autoload.php',
    $moduleRoot . '/vendor/autoload.php',
    $moduleRoot . '/../../src/vendor/autoload.php',
];

$autoloaderLoaded = false;
foreach ($candidates as $candidate) {
    if ($candidate !== null && is_file($candidate)) {
        require $candidate;
        $autoloaderLoaded = true;
        break;
    }
}

if (!$autoloaderLoaded) {
    fwrite(STDERR, "Unable to locate a Composer autoloader (set MAGENTO_ROOT).\n");
    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(1);
}

spl_autoload_register(static function (string $class) use ($moduleRoot): void {
    $prefix = 'DmLab\\TypesenseCore\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $moduleRoot . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// When the module is composer-installed, Magento's autoloader has already run its
// registration.php; re-requiring it would throw "already defined". Swallow that so the
// suite runs whether or not the module is installed.
try {
    require $moduleRoot . '/registration.php';
} catch (\LogicException $e) {
    // Already registered by the Composer autoloader — nothing to do.
}
