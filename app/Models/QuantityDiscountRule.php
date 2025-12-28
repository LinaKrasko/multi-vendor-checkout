<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuantityDiscountRule extends Model
{
    protected $table = 'quantity_discount_rules';

    protected $fillable = [
        'min_qty',
        'percent',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'percent' => 'float',
    ];
}
