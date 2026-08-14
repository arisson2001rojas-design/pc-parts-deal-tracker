<?php

namespace App\Enums;

enum FulfillmentType: string
{
    case Retailer = 'retailer';
    case Platform = 'platform';
    case Seller = 'seller';
    case Unknown = 'unknown';
}
