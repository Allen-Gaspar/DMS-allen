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
            header('Location: /DMS-allen/DMS-allen/login.php');
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

        // Check if the logged-in user's role exists inside your permitted roles array
        if (!$user || !in_array($user['role'], $roles, true)) {
            // Force a clean absolute redirect straight back to the login screen
            header('Location: /DMS-allen/DMS-allen/login.php');
            exit;
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
