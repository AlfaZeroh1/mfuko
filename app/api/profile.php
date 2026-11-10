<?php
/** Self-service account management for every user. */
return [
    'theme' => [
        'perm' => null, 'write' => true,
        'fn' => function (array $in) {
            $t = in_array($in['theme'] ?? '', ['auto', 'light', 'dark'], true) ? $in['theme'] : 'auto';
            DB::q('UPDATE users SET theme=? WHERE id=?', [$t, Auth::id()]);
            return ['theme' => $t];
        },
    ],
    'update' => [
        'perm' => null, 'write' => true,
        'fn' => function (array $in) {
            $u = DB::row('SELECT * FROM users WHERE id=?', [Auth::id()]);
            $d = ['first_name' => trim((string)($in['first_name'] ?? '')), 'last_name' => trim((string)($in['last_name'] ?? '')) ?: null,
                'email' => trim((string)($in['email'] ?? '')) ?: null, 'phone' => trim((string)($in['phone'] ?? '')) ?: null];
            if ($d['first_name'] === '') throw new ApiError('First name is required');
            if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new ApiError('Email is not valid');
            DB::update('users', $d, 'id=?', [$u['id']]);
            Audit::log('profile_update', 'user', $u['id'], 'Updated own profile', $u, $d);
            return ['ok' => true];
        },
    ],
    'password' => [
        'perm' => null, 'write' => true,
        'fn' => function (array $in) {
            $u = DB::row('SELECT id, password_hash FROM users WHERE id=?', [Auth::id()]);
            if (!password_verify((string)($in['current'] ?? ''), $u['password_hash'])) throw new ApiError('Current password is wrong');
            $new = (string)($in['new'] ?? '');
            if (strlen($new) < 8) throw new ApiError('New password must be at least 8 characters');
            DB::q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            Audit::log('password_change', 'user', $u['id'], 'Changed own password');
            return ['ok' => true];
        },
    ],
    'pin' => [
        'perm' => null, 'write' => true,
        'fn' => function (array $in) {
            $pin = (string)($in['pin'] ?? '');
            if ($pin === '') { DB::q('UPDATE users SET pin_hash=NULL WHERE id=?', [Auth::id()]); Audit::log('pin_remove', 'user', Auth::id(), 'Removed quick PIN'); return ['ok' => true]; }
            if (!preg_match('/^\d{4,8}$/', $pin)) throw new ApiError('PIN must be 4–8 digits');
            foreach (DB::all('SELECT id, pin_hash FROM users WHERE pin_hash IS NOT NULL AND id<>?', [Auth::id()]) as $o) {
                if (password_verify($pin, $o['pin_hash'])) throw new ApiError('That PIN is taken — choose another');
            }
            DB::q('UPDATE users SET pin_hash=? WHERE id=?', [password_hash($pin, PASSWORD_DEFAULT), Auth::id()]);
            Audit::log('pin_set', 'user', Auth::id(), 'Set quick PIN');
            return ['ok' => true];
        },
    ],
    'avatar' => [
        'perm' => null, 'write' => true,
        'fn' => function (array $in) {
            $old = DB::val('SELECT avatar FROM users WHERE id=?', [Auth::id()]);
            if (!empty($in['remove'])) { Upload::delete($old); DB::q('UPDATE users SET avatar=NULL WHERE id=?', [Auth::id()]); return ['avatar' => null]; }
            $path = Upload::image($_FILES['avatar'] ?? null, 'avatars', 320);
            Upload::delete($old);
            DB::q('UPDATE users SET avatar=? WHERE id=?', [$path, Auth::id()]);
            Audit::log('avatar_update', 'user', Auth::id(), 'Changed profile picture');
            return ['avatar' => $path];
        },
    ],
    'activity' => [
        'perm' => null,
        'fn' => function (array $in) {
            $per = min(50, (int)($in['per'] ?? 15)); $off = (max(1, (int)($in['page'] ?? 1)) - 1) * $per;
            return ['total' => (int)DB::val('SELECT COUNT(*) FROM audit_logs WHERE user_id=?', [Auth::id()]),
                'rows' => DB::all("SELECT action, summary, ip, user_agent, created_at FROM audit_logs WHERE user_id=? ORDER BY id DESC LIMIT $per OFFSET $off", [Auth::id()])];
        },
    ],
];
