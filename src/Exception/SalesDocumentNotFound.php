<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The referenced sales document does not exist.
 *
 * This is a client error (wrong id), not a server fault - the HTTP layer maps
 * it to 404.
 */
final class SalesDocumentNotFound extends \RuntimeException
{
    public static function withId(int $id): self
    {
        return new self(\sprintf('Sales document %d not found', $id));
    }
}
