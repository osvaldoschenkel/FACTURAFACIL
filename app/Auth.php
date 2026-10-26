<?php
declare(strict_types=1);

namespace FacturaFacil;

use PDO;
use RuntimeException;

final class Auth
{
    public function __construct(private PDO $db) {}

    public function user(): ?array
    {
        if (empty($_SESSION['user_id']) || time() - (int)($_SESSION['authenticated_at'] ?? 0) > 28800) { unset($_SESSION['user_id']); return null; }
        $q = $this->db->prepare('SELECT id,email FROM users WHERE id = ?');
        $q->execute([$_SESSION['user_id']]);
        return $q->fetch() ?: null;
    }

    public function installed(): bool { return (bool)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn(); }

    public function login(string $email, string $password, string $ip): bool
    {
        $q = $this->db->prepare('SELECT attempts,window_start FROM login_attempts WHERE ip = ?');
        $q->execute([$ip]);
        $attempt = $q->fetch();
        $now = time();
        if ($attempt && (int)$attempt['window_start'] > $now - 900 && (int)$attempt['attempts'] >= 10) { throw new RuntimeException('Demasiados intentos. Esperá 15 minutos.'); }
        $q = $this->db->prepare('SELECT id,password_hash FROM users WHERE email = ?');
        $q->execute([mb_strtolower(trim($email))]);
        $user = $q->fetch();
        // Use a real password hash even for unknown accounts to reduce timing differences.
        $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if (!password_verify($password, $hash) || !$user) {
            $q = $this->db->prepare('INSERT INTO login_attempts (ip,attempts,window_start) VALUES (?,1,?) ON CONFLICT(ip) DO UPDATE SET attempts=CASE WHEN login_attempts.window_start > ? THEN login_attempts.attempts+1 ELSE 1 END, window_start=CASE WHEN login_attempts.window_start > ? THEN login_attempts.window_start ELSE excluded.window_start END');
            $q->execute([$ip, $now, $now-900, $now-900]);
            return false;
        }
        $this->db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['authenticated_at'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return true;
    }

    public function changePassword(string $old, string $new): void
    {
        $user = $this->user();
        if (!$user) { throw new RuntimeException('Ingresá nuevamente.'); }
        $q = $this->db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $q->execute([$user['id']]);
        if (!password_verify($old, (string)$q->fetchColumn())) { throw new RuntimeException('La contraseña actual no es correcta.'); }
        self::validatePassword($new);
        $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
    }

    public static function validatePassword(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 72) { throw new RuntimeException('La contraseña debe tener entre 12 y 72 caracteres.'); }
    }
}
