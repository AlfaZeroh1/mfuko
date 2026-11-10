<?php
declare(strict_types=1);

final class Env
{
    private static array $vars = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            self::$vars[trim($k)] = $v;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = self::$vars[$key] ?? getenv($key);
        return ($v === false || $v === null || $v === '') ? $default : (string)$v;
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower((string)self::get($key, '0')), ['1', 'true', 'yes', 'on'], true);
    }

    /** Write a fresh .env (used by the installer). */
    public static function write(string $path, array $vars): bool
    {
        $out = "# Mfuko POS environment — generated " . date('Y-m-d H:i') . "\n";
        foreach ($vars as $k => $v) {
            $v = (string)$v;
            if (preg_match('/[\s#"\']/', $v)) $v = '"' . str_replace('"', '\"', $v) . '"';
            $out .= "$k=$v\n";
        }
        return file_put_contents($path, $out) !== false;
    }
}
