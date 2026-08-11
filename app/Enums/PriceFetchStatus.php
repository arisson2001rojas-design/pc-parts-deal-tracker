<?php

namespace App\Enums;

enum PriceFetchStatus: string
{
    case Success = 'success';
    case NoPrice = 'no_price';
    case Timeout = 'timeout';
    case NetworkError = 'network_error';
    case Challenge = 'challenge';
    case InvalidResponse = 'invalid_response';
}
