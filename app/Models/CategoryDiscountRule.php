<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryDiscountRule extends Model
{
    protected $table = 'category_discount_rules';

    protected $fillable = [
        'category',
        'percent',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'percent' => 'float',
    ];
}
