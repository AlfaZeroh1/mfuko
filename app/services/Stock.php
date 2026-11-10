<?php
declare(strict_types=1);

/** Every stock change goes through Stock::move so the ledger always balances. */
final class Stock
{
    /**
     * @return float new balance
     * @throws ApiError when going negative is not allowed
     */
    public static function move(
        int $storeId,
        int $productId,
        float $delta,
        string $type,
        ?string $refType = null,
        ?int $refId = null,
        ?string $note = null,
        ?float $unitCost = null,
        ?bool $allowNegative = null
    ): float {
        $allowNegative ??= Settings::bool('allow_negative_stock');
        $cur = DB::val('SELECT qty FROM stock WHERE store_id=? AND product_id=? FOR UPDATE', [$storeId, $productId]);
        if ($cur === null) {
            DB::q('INSERT INTO stock (store_id, product_id, qty) VALUES (?,?,0)', [$storeId, $productId]);
            $cur = 0;
        }
        $new = round((float)$cur + $delta, 3);
        if ($delta < 0 && $new < 0 && !$allowNegative) {
            $name = DB::val('SELECT name FROM products WHERE id=?', [$productId]) ?? "#$productId";
            throw new ApiError(sprintf('Not enough stock for %s (available %s, need %s)', $name, qty($cur), qty(-$delta)));
        }
        DB::q('UPDATE stock SET qty=? WHERE store_id=? AND product_id=?', [$new, $storeId, $productId]);
        DB::insert('stock_movements', [
            'store_id' => $storeId, 'product_id' => $productId, 'type' => $type, 'qty' => $delta, 'balance' => $new,
            'unit_cost' => $unitCost, 'ref_type' => $refType, 'ref_id' => $refId, 'note' => $note,
            'user_id' => Auth::id() ?: null,
        ]);
        return $new;
    }

    public static function level(int $storeId, int $productId): float
    {
        return (float)(DB::val('SELECT qty FROM stock WHERE store_id=? AND product_id=?', [$storeId, $productId]) ?? 0);
    }
}
