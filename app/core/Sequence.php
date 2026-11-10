<?php
declare(strict_types=1);

/** Gap-safe document numbering (row lock inside the caller's transaction). */
final class Sequence
{
    public const DEFAULTS = [
        'invoice'  => ['INV-', 1001, 0],
        'receipt'  => ['RCP-', 1, 5],
        'credit'   => ['CN-', 1, 4],
        'quote'    => ['QT-', 1, 4],
        'grn'      => ['GRN-', 1, 4],
        'voucher'  => ['PV-', 1, 4],
        'sreturn'  => ['SR-', 1, 4],
        'transfer' => ['TR-', 1, 4],
        'stocktake'=> ['ST-', 1, 4],
    ];

    public static function next(string $key): string
    {
        return DB::tx(static function () use ($key) {
            $row = DB::row('SELECT prefix, next_no, pad FROM sequences WHERE `key`=? FOR UPDATE', [$key]);
            if (!$row) {
                [$p, $n, $pad] = self::DEFAULTS[$key] ?? [strtoupper($key) . '-', 1, 0];
                DB::insert('sequences', ['key' => $key, 'prefix' => $p, 'next_no' => $n, 'pad' => $pad]);
                $row = ['prefix' => $p, 'next_no' => $n, 'pad' => $pad];
            }
            DB::q('UPDATE sequences SET next_no = next_no + 1 WHERE `key`=?', [$key]);
            $n = (string)$row['next_no'];
            if ((int)$row['pad'] > 0) $n = str_pad($n, (int)$row['pad'], '0', STR_PAD_LEFT);
            return $row['prefix'] . $n;
        });
    }

    public static function seed(): void
    {
        foreach (self::DEFAULTS as $k => [$p, $n, $pad]) {
            DB::q('INSERT IGNORE INTO sequences (`key`,prefix,next_no,pad) VALUES (?,?,?,?)', [$k, $p, $n, $pad]);
        }
    }
}
