<?php

declare(strict_types=1);

namespace App\Message\Command;

final class CreateSalesDocument
{
    public function __construct(
        public readonly int $contractorId,
        public readonly int $createdBy,
    ) {
    }
}
