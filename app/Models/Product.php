<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'price',
        'image_url',
        'is_available',
        'current_stock',
        'low_stock_threshold',
        'reserved_stock',
        'version',
    ];

    // Keep computed attributes opt-in so list views do not serialize active_price
    // and accidentally trigger discount lookups when it is not needed.
    protected $appends = [];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'price' => 'decimal:2',
            'current_stock' => 'integer',
            'low_stock_threshold' => 'integer',
            'reserved_stock' => 'integer',
            'version' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // 1. Add the Many-to-Many Relationship
    public function discounts(): BelongsToMany
    {
        return $this->belongsToMany(Discount::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    // 2. Add the Logic to Calculate the Active Price
    protected function activePrice(): Attribute
    {
        return Attribute::make(
            get: function () {
                $now = Carbon::now();
                $todayDate = $now->toDateString();
                $currentDay = $now->dayOfWeek; // 0 (Sun) to 6 (Sat)
                $currentTime = $now->format('H:i:s');

                $bestPrice = $this->price;

                // Loop through all assigned discounts to find the best active one
                foreach ($this->discounts as $discount) {
                    if (! $discount->is_active) {
                        continue;
                    }

                    $isValid = false;

                    // Rule A: Interval (Specific Date Range)
                    if ($discount->schedule_type === 'interval') {
                        if ($discount->start_date && $discount->end_date) {
                            $isValid =
                                $todayDate >=
                                    $discount->start_date->toDateString() &&
                                $todayDate <=
                                    $discount->end_date->toDateString();
                        }
                    }
                    // Rule B: Routine (Recurring Schedule)
                    elseif ($discount->schedule_type === 'routine') {
                        if ($discount->routine_day === $currentDay) {
                            $isValid =
                                $currentTime >= $discount->start_time &&
                                $currentTime <= $discount->end_time;
                        }
                    }

                    // If the time constraint matches, apply the math
                    if ($isValid) {
                        $calculatedPrice = $this->price;

                        if ($discount->discount_type === 'percentage') {
                            $calculatedPrice =
                                $this->price -
                                $this->price *
                                    ($discount->discount_value / 100);
                        } elseif ($discount->discount_type === 'fixed_amount') {
                            $calculatedPrice =
                                $this->price - $discount->discount_value;
                        }

                        // Prevent negative prices just in case
                        $calculatedPrice = max(0, $calculatedPrice);

                        // If multiple discounts overlap, we give the customer the best (lowest) price
                        if ($calculatedPrice < $bestPrice) {
                            $bestPrice = $calculatedPrice;
                        }
                    }
                }

                return round($bestPrice, 2);
            },
        );
    }
}
