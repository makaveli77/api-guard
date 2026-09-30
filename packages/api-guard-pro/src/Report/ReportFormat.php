<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Report;

enum ReportFormat: string
{
    case TEXT = 'text';
    case JSON = 'json';
    case MARKDOWN = 'markdown';
}