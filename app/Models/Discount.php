<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Discount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'discount_type',
        'discount_value',
        'schedule_type',
        'start_date',
        'end_date',
        'routine_day',
        'start_time',
        'end_time',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'discount_value' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'routine_day' => 'integer',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
