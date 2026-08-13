<?php

namespace App\Enums;

enum IdentityResolutionState: string
{
    case Verified = 'verified';
    case Probable = 'probable';
    case Ambiguous = 'ambiguous';
    case Conflicting = 'conflicting';
    case Unverified = 'unverified';
}
