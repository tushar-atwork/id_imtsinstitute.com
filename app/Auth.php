<?php
final class Auth
{
    public static function login(PDO $db, string $username, string $password): bool
    {
        $stmt = $db->prepare(
            "SELECT id, username, password_hash, name, is_active
             FROM crm_users
             WHERE username = ? LIMIT 1"
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || (int)$user['is_active'] !== 1 || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['crm_user'] = [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'name' => $user['name'],
        ];
        return true;
    }

    public static function user(): ?array
    {
        return $_SESSION['crm_user'] ?? null;
    }

    public static function requireLogin(): void
    {
        if (!self::user()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
