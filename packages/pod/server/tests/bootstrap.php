<?php

/*
 * Test bootstrap.
 *
 * The unit tests cover the framework-free domain code in server/src/Support,
 * which only needs phpoffice/phpspreadsheet, giggsey/libphonenumber-for-php and
 * phpunit. They run from the package's own vendor directory (composer install),
 * or from another vendor directory given in POD_TEST_VENDOR, without
 * installing Fleetbase itself.
 */

$vendor = getenv('POD_TEST_VENDOR') ?: __DIR__ . '/../../server_vendor';
require $vendor . '/autoload.php';

spl_autoload_register(function (string $class) {
    $prefixes = [
        'Fleetbase\\Pod\\Tests\\' => __DIR__ . '/',
        'Fleetbase\\Pod\\'        => __DIR__ . '/../src/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

// The config file uses Laravel's env() helper; outside Laravel, read the real environment.
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}
