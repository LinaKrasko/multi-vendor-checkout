<?php

namespace App\Enums;

enum OrderStatus: string
{
    case CREATED = 'created';
    case VENDOR_NOTIFIED = 'vendor_notified';
}
