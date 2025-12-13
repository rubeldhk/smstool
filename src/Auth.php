<?php

class Auth
{
    public static function check(): bool
    {
        return !empty($_SESSION['authenticated']);
    }

    public static function login(string $username, string $password): bool
    {
        $envUser = env('APP_USERNAME', 'admin');
        $envPass = env('APP_PASSWORD', 'changeme');

        if (hash_equals($envUser, $username) && hash_equals($envPass, $password)) {
            $_SESSION['authenticated'] = true;
            return true;
        }

        return false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
