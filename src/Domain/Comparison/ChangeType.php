<?php

declare(strict_types=1);

namespace ApiGuard\Domain\Comparison;

enum ChangeType: string
{
    case PATH = 'path';
    case METHOD = 'method';
    case PARAMETER = 'parameter';
    case REQUEST_BODY = 'request_body';
    case RESPONSE_SCHEMA = 'response_schema';
    case STATUS = 'status';
    case UNKNOWN = 'unknown';
}
