<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'subtotal_price',
        'discount',
        'total_price'
    ];

    protected $casts = [
        'subtotal_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    public function subOrders()
    {
        return $this->hasMany(SubOrder::class);
    }
}
