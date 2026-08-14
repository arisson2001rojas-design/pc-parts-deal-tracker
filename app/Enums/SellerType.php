<?php

namespace App\Enums;

enum SellerType: string
{
    case Retailer = 'retailer';
    case Marketplace = 'marketplace';
    case Unknown = 'unknown';
}
