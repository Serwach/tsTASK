<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\SalesDocument;
use Psr\Log\LoggerInterface;

/**
 * Announces that a sales document was approved.
 *
 * Notifications are a best-effort side effect that runs *after* the approval is
 * already committed and durable. A failing notification channel must never turn
 * a successful approval into a failed command, so every delivery is isolated:
 * one recipient failing is logged and does not stop the others.
 */
final class ApprovalNotifier
{
    public function __construct(
        private readonly NotifierPort $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function documentApproved(SalesDocument $document): void
    {
        $message = "Document #{$document->getId()} has been approved";

        $this->safeNotify($document->getCreatedBy(), $message, $document);
        $this->safeNotify($document->getContractorId(), $message, $document);
    }

    private function safeNotify(int $userId, string $message, SalesDocument $document): void
    {
        try {
            $this->notifier->notify($userId, $message);
        } catch (\Throwable $e) {
            $this->logger->error('Approval notification failed', [
                'documentId' => $document->getId(),
                'userId' => $userId,
                'exception' => $e,
            ]);
        }
    }
}
