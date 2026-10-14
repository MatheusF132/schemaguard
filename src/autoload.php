<?php
// Autoloader simples (dispensa "composer install" por enquanto).
spl_autoload_register(function (string $class): void {
    $prefix = 'SchemaGuard\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});
