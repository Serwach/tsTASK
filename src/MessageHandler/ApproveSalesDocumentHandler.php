<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\SalesDocument;
use App\Enum\SalesDocumentStatus;
use App\Enum\SalesDocumentType;
use App\Exception\SalesDocumentNotFound;
use App\Exception\SalesDocumentTransitionNotAllowed;
use App\Message\Command\ApproveSalesDocument;
use App\Notification\ApprovalNotifier;
use App\Repository\SalesDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ApproveSalesDocumentHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SalesDocumentRepository $repository,
        private readonly ApprovalNotifier $approvalNotifier,
    ) {
    }

    public function __invoke(ApproveSalesDocument $command): int
    {
        $approvedId = $this->entityManager->wrapInTransaction(function () use ($command) {
            $document = $this->repository->find($command->documentId);
            if ($document === null) {
                throw SalesDocumentNotFound::withId($command->documentId);
            }
            if ($document->getStatus() !== SalesDocumentStatus::Draft) {
                throw SalesDocumentTransitionNotAllowed::cannotApprove($document->getStatus());
            }

            $document->setStatus(SalesDocumentStatus::Approved);
            $document->setApprovedBy($command->approvedBy);
            $document->setApprovedAt(new \DateTimeImmutable());
            $document->setSellerSnapshot($this->buildSellerSnapshot($document));

            $approvedId = $document->getId();

            if ($document->getType() === SalesDocumentType::Quote) {
                $order = new SalesDocument();
                $order->setContractorId($document->getContractorId());
                $order->setCreatedBy($command->approvedBy);
                $order->setType(SalesDocumentType::Order);
                $order->setStatus(SalesDocumentStatus::Approved);
                $order->setApprovedBy($command->approvedBy);
                $order->setApprovedAt(new \DateTimeImmutable());
                $order->setParentQuoteId($document->getId());
                $order->setSellerSnapshot($document->getSellerSnapshot());
                $this->entityManager->persist($order);
                $this->entityManager->flush();
                $approvedId = $order->getId();
            }

            return $approvedId;
        });

        // The approval is committed and durable from here on. Notifying the
        // parties is a best-effort side effect: its failure must not propagate
        // out of the handler and be reported to the caller as a failed approval.
        $approvedDocument = $this->repository->find($approvedId);
        $this->approvalNotifier->documentApproved($approvedDocument);

        return $approvedId;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSellerSnapshot(SalesDocument $document): array
    {
        return [
            'contractor_id' => $document->getContractorId(),
            'snapshot_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }
}
