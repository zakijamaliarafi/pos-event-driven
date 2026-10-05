<?php

namespace App\Domain\Catalog;

use App\Models\Product;
use App\Models\Recipe;

final class ProductAvailability
{
    public function hasValidRecipe(Product $product): bool
    {
        return $product->recipes->isNotEmpty() && $product->recipes->every(
            fn (Recipe $recipe): bool => $recipe->inventoryItem !== null && self::hundredths($recipe->quantity_required) > 0,
        );
    }

    public function availableQuantity(Product $product): int
    {
        if (! $this->hasValidRecipe($product)) {
            return 0;
        }

        return $product->recipes->min(function (Recipe $recipe): int {
            $ingredient = $recipe->inventoryItem;
            $remaining = max(0, self::hundredths($ingredient->current_stock) - self::hundredths($ingredient->reserved_stock));

            return intdiv($remaining, self::hundredths($recipe->quantity_required));
        });
    }

    public static function hundredths(string|int|float $quantity): int
    {
        return (int) round((float) $quantity * 100);
    }
}
