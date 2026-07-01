<?php
require_once __DIR__ . '/db.php';

class Auth {
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function requireLogin(): array {
        self::startSession();

        if (empty($_SESSION['user'])) {
            header('Location: login.php');
            exit;
        }

        return $_SESSION['user'];
    }

    public static function currentUser(): ?array {
        self::startSession();
        return $_SESSION['user'] ?? null;
    }

    public static function requireRole(string ...$roles): array {
        $user = self::requireLogin();

        if (!in_array($user['role'], $roles, true)) {
            http_response_code(403);
            die('<p style="color:red">Access denied.</p>');
        }

        return $user;
    }
}

function require_login(): array {
    return Auth::requireLogin();
}

function current_user(): ?array {
    return Auth::currentUser();
}

function require_role(string ...$roles): array {
    return Auth::requireRole(...$roles);
}
