<?php

namespace App\Enums;

enum OfferEvidenceQuality: string
{
    case Reliable = 'reliable';
    case Ambiguous = 'ambiguous';
    case Invalid = 'invalid';
}
