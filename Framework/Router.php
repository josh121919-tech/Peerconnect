<?php

require_once __DIR__ . '/bootstrap.php';

$routes = require BASE_PATH . '/routes.php';
$routeFiles = [];
$routeNames = [];
$normalizedBasePath = trim(BASE_URL, '/');

foreach ($routes as $name => $relativePath) {
    $token = route_token($name);
    $routeFiles[$token] = BASE_PATH . '/' . ltrim($relativePath, '/');
    // The tokens in a URL are md5(name + secret), so a page cannot tell which
    // route it is serving. pc_verification_gate() needs that name to know
    // whether this page is one a half-registered account may still reach, so
    // the router hands it over in PC_ROUTE before the page runs.
    $routeNames[$token] = $name;
}

$fullPath = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/');
$segments = $fullPath === '' ? [] : explode('/', $fullPath);
$requestToken = $segments === [] ? '' : (string) end($segments);
$legacyRoute = $_GET['legacy'] ?? '';

if (is_string($legacyRoute) && isset($routes[$legacyRoute])) {
    define('PC_ROUTE', $legacyRoute);
    require BASE_PATH . '/' . $routes[$legacyRoute];
    exit;
}

if ($fullPath === $normalizedBasePath || $requestToken === '' || $requestToken === 'index') {
    define('PC_ROUTE', 'welcomepage');
    require BASE_PATH . '/' . $routes['welcomepage'];
    exit;
}

if (isset($routeFiles[$requestToken]) && file_exists($routeFiles[$requestToken])) {
    define('PC_ROUTE', $routeNames[$requestToken]);
    require $routeFiles[$requestToken];
    exit;
}

http_response_code(404);
echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>404 - Page Not Found</h1></body></html>';
exit;
