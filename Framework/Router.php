<?php

require_once __DIR__ . '/bootstrap.php';

$routes = require BASE_PATH . '/routes.php';
$routeFiles = [];
$normalizedBasePath = trim(BASE_URL, '/');

foreach ($routes as $name => $relativePath) {
    $routeFiles[route_token($name)] = BASE_PATH . '/' . ltrim($relativePath, '/');
}

$fullPath = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/');
$segments = $fullPath === '' ? [] : explode('/', $fullPath);
$requestToken = $segments === [] ? '' : (string) end($segments);
$legacyRoute = $_GET['legacy'] ?? '';

if (is_string($legacyRoute) && isset($routes[$legacyRoute])) {
    require BASE_PATH . '/' . $routes[$legacyRoute];
    exit;
}

if ($fullPath === $normalizedBasePath || $requestToken === '' || $requestToken === 'index') {
    require BASE_PATH . '/' . $routes['welcomepage'];
    exit;
}

if (isset($routeFiles[$requestToken]) && file_exists($routeFiles[$requestToken])) {
    require $routeFiles[$requestToken];
    exit;
}

http_response_code(404);
echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>404 - Page Not Found</h1></body></html>';
exit;
