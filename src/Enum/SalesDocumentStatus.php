<?php

declare(strict_types=1);

namespace App\Enum;

enum SalesDocumentStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
