<?php
return [
    'login' => [
        'public' => true,
        'fn' => function (array $in) {
            if (!csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) throw new ApiError('Session expired, refresh the page.', 419);
            // Light brute-force protection per session
            $_SESSION['login_tries'] = ($_SESSION['login_tries'] ?? 0) + 1;
            if ($_SESSION['login_tries'] > 20) throw new ApiError('Too many attempts. Close the browser and try again later.', 429);
            if (!empty($in['pin'])) {
                if (!preg_match('/^\d{4,8}$/', (string)$in['pin'])) throw new ApiError('PIN must be 4–8 digits');
                [$ok, $msg] = Auth::attempt('', (string)$in['pin'], true);
            } else {
                [$ok, $msg] = Auth::attempt(trim((string)($in['username'] ?? '')), (string)($in['password'] ?? ''));
            }
            if (!$ok) throw new ApiError($msg);
            $_SESSION['login_tries'] = 0;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $u = Auth::user();
            return ['redirect' => url($u['home_page'] ?: 'dashboard')];
        },
    ],
];
