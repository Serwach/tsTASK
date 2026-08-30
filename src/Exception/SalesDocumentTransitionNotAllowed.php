<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\SalesDocumentStatus;

/**
 * A state transition was requested that the document's current status does not
 * allow (e.g. approving/rejecting a document that is no longer a draft).
 *
 * The request itself is well-formed, it just conflicts with the current state -
 * the HTTP layer maps it to 409.
 */
final class SalesDocumentTransitionNotAllowed extends \RuntimeException
{
    public static function cannotApprove(SalesDocumentStatus $current): self
    {
        return new self(\sprintf('Document cannot be approved in its current status (%s)', $current->value));
    }

    public static function cannotReject(SalesDocumentStatus $current): self
    {
        return new self(\sprintf('Document cannot be rejected in its current status (%s)', $current->value));
    }
}
