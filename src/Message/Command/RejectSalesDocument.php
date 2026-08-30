<?php

declare(strict_types=1);

namespace App\Message\Command;

final class RejectSalesDocument
{
    public function __construct(
        public readonly int $documentId,
        public readonly int $rejectedBy,
    ) {
    }
}
