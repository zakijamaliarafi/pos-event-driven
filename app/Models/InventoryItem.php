<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'unit',
        'current_stock',
        'low_stock_threshold',
    ];

    protected function casts(): array
    {
        return [
            'current_stock' => 'decimal:2',
            'low_stock_threshold' => 'decimal:2',
            'reserved_stock' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }
}
