<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubOrder extends Model
{
    protected $fillable = [
        'order_id',
        'vendor_code',
        'status',
        'items_snapshot',
        'vendor_notified_at',
    ];

    protected $casts = [
        'status' => \App\Enums\OrderStatus::class,
        'items_snapshot' => 'array',
        'vendor_notified_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
