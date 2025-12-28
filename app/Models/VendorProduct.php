<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorProduct extends Model
{
    protected $table = 'vendor_products';

    public $incrementing = false;
    protected $primaryKey = null;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'vendor_code',
        'product_code',
        'category',
        'price',
        'stock',
    ];
}
