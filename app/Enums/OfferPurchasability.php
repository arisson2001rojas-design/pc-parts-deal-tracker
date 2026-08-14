<?php

namespace App\Enums;

enum OfferPurchasability: string
{
    case Active = 'active';
    case BuyingChoicesOnly = 'buying_choices_only';
    case Unavailable = 'unavailable';
    case Unknown = 'unknown';
}
