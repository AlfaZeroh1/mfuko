<?php
declare(strict_types=1);

final class Auth
{
    private static ?array $user = null;
    private static array $perms = [];

    public static function user(): ?array
    {
        if (self::$user !== null) return self::$user;
        $id = (int)($_SESSION['uid'] ?? 0);
        if (!$id) return null;
        $u = DB::row(
            'SELECT u.*, r.name AS role_name, r.dashboard, r.home_page
               FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.id = ? AND u.status = "active"',
            [$id]
        );
        if (!$u) { self::logout(false); return null; }
        unset($u['password_hash'], $u['pin_hash']);
        self::$user = $u;
        self::$perms = DB::col('SELECT permission FROM role_permissions WHERE role_id=?', [$u['role_id']]);
        self::touch();
        return $u;
    }

    public static function id(): int
    {
        return (int)(self::user()['id'] ?? 0);
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function can(string $perm): bool
    {
        if (!self::user()) return false;
        return in_array('*', self::$perms, true) || in_array($perm, self::$perms, true);
    }

    public static function canAny(array $perms): bool
    {
        foreach ($perms as $p) if (self::can($p)) return true;
        return false;
    }

    public static function permissions(): array
    {
        self::user();
        return in_array('*', self::$perms, true) ? Perm::all() : self::$perms;
    }

    public static function isOwner(): bool
    {
        self::user();
        return in_array('*', self::$perms, true);
    }

    /** Throttled presence update powering the "who's online" view. */
    public static function touch(?string $page = null): void
    {
        $u = self::$user;
        if (!$u) return;
        $last = $_SESSION['seen_at'] ?? 0;
        if ($page === null && time() - $last < 30) return;
        $_SESSION['seen_at'] = time();
        DB::q('UPDATE users SET last_seen_at=NOW(), last_ip=?' . ($page ? ', last_page=?' : '') . ' WHERE id=?',
            $page ? [client_ip(), $page, $u['id']] : [client_ip(), $u['id']]);
    }

    /** Password or PIN login. Returns [ok, message]. */
    public static function attempt(string $username, string $secret, bool $pin = false): array
    {
        if ($pin) {
            // PIN login is store-counter friendly: PIN must be unique per user.
            $candidates = DB::all('SELECT id, pin_hash, status, locked_until, username FROM users WHERE pin_hash IS NOT NULL AND status="active"');
            foreach ($candidates as $c) {
                if (password_verify($secret, $c['pin_hash'])) return self::finish((int)$c['id'], 'pin');
            }
            Audit::log('login_failed', 'user', null, 'Invalid PIN attempt', null, null, 0);
            return [false, 'Invalid PIN'];
        }

        $u = DB::row('SELECT id, password_hash, status, locked_until, failed_logins FROM users WHERE username=? OR (email<>"" AND email=?) LIMIT 1', [$username, $username]);
        if (!$u) return [false, 'Invalid username or password'];
        if ($u['status'] !== 'active') return [false, 'This account is suspended'];
        if ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
            return [false, 'Too many attempts. Try again after ' . date('H:i', strtotime($u['locked_until']))];
        }
        if (!password_verify($secret, $u['password_hash'])) {
            $fails = (int)$u['failed_logins'] + 1;
            DB::q('UPDATE users SET failed_logins=?, locked_until=? WHERE id=?', [
                $fails, $fails >= 5 ? date('Y-m-d H:i:s', time() + 600) : null, $u['id'],
            ]);
            Audit::log('login_failed', 'user', $u['id'], "Wrong password for $username", null, null, (int)$u['id']);
            return [false, 'Invalid username or password'];
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            DB::q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($secret, PASSWORD_DEFAULT), $u['id']]);
        }
        return self::finish((int)$u['id'], 'password');
    }

    private static function finish(int $id, string $how): array
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $id;
        $_SESSION['seen_at'] = 0;
        DB::q('UPDATE users SET last_login_at=NOW(), last_seen_at=NOW(), failed_logins=0, locked_until=NULL, last_ip=? WHERE id=?', [client_ip(), $id]);
        self::$user = null;
        Audit::log('login', 'user', $id, "Signed in with $how", null, null, $id);
        return [true, 'ok'];
    }

    public static function logout(bool $audit = true): void
    {
        if ($audit && !empty($_SESSION['uid'])) {
            DB::q('UPDATE users SET last_seen_at=DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id=?', [$_SESSION['uid']]);
            Audit::log('logout', 'user', $_SESSION['uid'], 'Signed out');
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        self::$user = null;
    }

    /** Stores the current user may operate in. Owners see all. */
    public static function stores(): array
    {
        $u = self::user();
        if (!$u) return [];
        if (self::isOwner() || self::can('sales.view_all')) {
            $rows = DB::all('SELECT id, name FROM stores WHERE is_active=1 ORDER BY name');
        } else {
            $rows = DB::all('SELECT s.id, s.name FROM stores s JOIN user_stores us ON us.store_id=s.id WHERE us.user_id=? AND s.is_active=1 ORDER BY s.name', [$u['id']]);
            if (!$rows && $u['default_store_id']) {
                $rows = DB::all('SELECT id, name FROM stores WHERE id=?', [$u['default_store_id']]);
            }
            // No store assigned at all (typical single-shop setup): allow every active store.
            if (!$rows) $rows = DB::all('SELECT id, name FROM stores WHERE is_active=1 ORDER BY name');
        }
        return $rows;
    }

    public static function canUseStore(int $storeId): bool
    {
        foreach (self::stores() as $s) if ((int)$s['id'] === $storeId) return true;
        return false;
    }

    public static function currentStore(): int
    {
        $sid = (int)($_SESSION['store_id'] ?? 0);
        if ($sid && self::canUseStore($sid)) return $sid;
        $u = self::user();
        if ($u && $u['default_store_id'] && self::canUseStore((int)$u['default_store_id'])) return (int)$u['default_store_id'];
        $s = self::stores();
        return $s ? (int)$s[0]['id'] : 0;
    }
}
