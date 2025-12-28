<?php

namespace App\Enums;

enum CheckoutErrorCode: string
{
    case NO_OFFERS = 'no_offers';
    case OUT_OF_STOCK = 'out_of_stock';
    case UNKNOWN_PRODUCT = 'unknown_product';
}
