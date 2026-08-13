<?php

namespace App\Enums;

enum PriceUpdateStatus: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Unchanged = 'unchanged';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
