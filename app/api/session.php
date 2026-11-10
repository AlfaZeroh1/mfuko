<?php
return [
    'ping' => [
        'perm' => null,
        'fn' => function (array $in) {
            Auth::touch(preg_replace('/[^a-z\-]/', '', (string)($in['page'] ?? '')) ?: null);
            return ['t' => time()];
        },
    ],
    'store' => [
        'perm' => null, 'write' => true,
        'fn' => function (array $in) {
            $sid = (int)($in['store_id'] ?? 0);
            if (!Auth::canUseStore($sid)) throw new ApiError('You cannot use that store');
            $_SESSION['store_id'] = $sid;
            Audit::log('switch_store', 'store', $sid, 'Switched active store');
            return ['store_id' => $sid];
        },
    ],
];
