<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_number',
        'user_id',
        'device_id',
        'table_number',
        'customer_name',
        'customer_phone',
        'status',
        'order_type',
        'total_amount',
        'payment_method',
        'version',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'version' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
