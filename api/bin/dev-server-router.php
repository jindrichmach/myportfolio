<?php

declare(strict_types=1);

/**
 * Router pro vestavěný PHP server — vývoj a CI, ne produkce.
 *
 *   php -S 127.0.0.1:8080 api/bin/dev-server-router.php
 *
 * Zrcadlí jen ta pravidla z .htaccess / web.config, která potřebuje aplikace
 * doběhnout: /api/* jde na Slim front controller, zbytek je statika z web/dist
 * se SPA fallbackem. Bezpečnostní hlavičky, blokace citlivých adresářů ani
 * cache politiku NEŘEŠÍ — od toho je v produkci webserver.
 *
 * V CI slouží k tomu, aby doběhly integrační testy, které mluví na běžící
 * instanci přes HTTP (BearerAuthTest a spol.).
 */

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api/')) {
    $_SERVER['SCRIPT_NAME'] = '/api/public/index.php';
    require $root . '/api/public/index.php';
    return true;
}

// Statika: build z web/dist a logo ze styles/ v rootu. Posíláme ji sami — vestavěný server
// by ji našel jen ve svém docrootu, takže by záleželo na tom, s jakým -t běží.
$dist = $root . '/web/dist';
$base = str_starts_with($path, '/styles/') ? $root . '/styles' : $dist;
$candidate = realpath(($base === $dist ? $dist : $root) . $path);
if ($candidate !== false
    && is_file($candidate)
    && str_starts_with($candidate, (string) realpath($base) . DIRECTORY_SEPARATOR)
) {
    $types = [
        'html' => 'text/html; charset=utf-8', 'js' => 'text/javascript', 'css' => 'text/css',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/vnd.microsoft.icon', 'webp' => 'image/webp',
        'woff2' => 'font/woff2', 'json' => 'application/json', 'webmanifest' => 'application/manifest+json',
    ];
    header('Content-Type: ' . ($types[strtolower(pathinfo($candidate, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($candidate);
    return true;
}

$index = $dist . '/index.html';
if (is_file($index)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($index);
    return true;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
return true;
