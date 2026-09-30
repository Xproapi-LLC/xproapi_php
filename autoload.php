<?php

/*
 * For projects without Composer: require this file, then use Xproapi\Client.
 * With Composer, "composer require xproapi/xproapi-php" (or a path repository) autoloads instead.
 */

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Xproapi\\')) {
        $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen('Xproapi\\'))).'.php';

        if (is_file($file)) {
            require $file;
        }
    }
});
