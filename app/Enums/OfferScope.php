<?php

namespace App\Enums;

enum OfferScope: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';
    case None = 'none';
    case Unknown = 'unknown';
}
