<?php

declare(strict_types=1);

namespace App\Enum;

enum SalesDocumentType: string
{
    case Quote = 'quote';
    case Order = 'order';
}
