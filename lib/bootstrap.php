<?php
// SPDX-License-Identifier: BSD-3-Clause

/**
 * Autoloader for the shared library, usable without Composer
 * (inside ISPConfig the module cannot rely on a vendor/ folder).
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'WpManager\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
