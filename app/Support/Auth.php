<?php
declare(strict_types=1);

namespace App\Support;

final class Auth
{
    public static function attempt(string $username, string $password): bool
    {
        $user = Db::first('SELECT * FROM admin_users WHERE username = ?', [$username]);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Db::run('UPDATE admin_users SET password_hash = ? WHERE id = ?', [
                password_hash($password, PASSWORD_DEFAULT),
                $user['id'],
            ]);
        }

        session_regenerate_id(true);
        $_SESSION['admin_id']       = (int) $user['id'];
        $_SESSION['admin_username'] = (string) $user['username'];
        Db::run('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);

        return true;
    }

    public static function check(): bool
    {
        return isset($_SESSION['admin_id']);
    }

    public static function username(): string
    {
        return (string) ($_SESSION['admin_username'] ?? '');
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_username']);
        session_regenerate_id(true);
    }

    /** Требует авторизации: иначе перекидывает на форму входа. */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('index.php?page=login');
        }
    }

    public static function createUser(string $username, string $password): void
    {
        Db::run('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)', [
            $username,
            password_hash($password, PASSWORD_DEFAULT),
        ]);
    }

    public static function usersCount(): int
    {
        return (int) Db::scalar('SELECT COUNT(*) FROM admin_users');
    }
}
