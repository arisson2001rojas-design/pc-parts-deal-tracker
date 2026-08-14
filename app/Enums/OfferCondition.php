<?php

namespace App\Enums;

enum OfferCondition: string
{
    case New = 'new';
    case Used = 'used';
    case Preowned = 'preowned';
    case Renewed = 'renewed';
    case Refurbished = 'refurbished';
    case OpenBox = 'open_box';
    case Unknown = 'unknown';
}
