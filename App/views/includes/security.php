<?php
/**
 * security.php — Centralized security helpers
 * Include at the top of every view that needs auth checks.
 * Provides: require_role(), require_admin(), require_auth()
 */
if (!function_exists('require_auth')) {
    function require_auth(): void {
        if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
            header('Location: ' . url('welcomepage'));
            exit;
        }
    }
}

if (!function_exists('require_role')) {
    function require_role(string ...$roles): void {
        require_auth();
        if (!in_array($_SESSION['role'], $roles, true)) {
            http_response_code(403);
            echo '<!DOCTYPE html><html><body><h1>403 Forbidden</h1></body></html>';
            exit;
        }
    }
}

if (!function_exists('require_admin')) {
    function require_admin(): void {
        require_role('admin');
    }
}

if (!function_exists('require_post')) {
    function require_post(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
    }
}

if (!function_exists('check_csrf')) {
    function check_csrf(): void {
        if (!verify_csrf()) {
            http_response_code(403);
            exit('CSRF token mismatch.');
        }
    }
}
