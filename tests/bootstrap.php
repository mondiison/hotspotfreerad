<?php

$cachePath = dirname(__DIR__).'/bootstrap/cache';
$configCache = $cachePath.'/config.php';

if (is_file($configCache)) {
    @unlink($configCache);
}

foreach (glob($cachePath.'/routes-*.php') ?: [] as $routeCache) {
    @unlink($routeCache);
}

require dirname(__DIR__).'/vendor/autoload.php';
