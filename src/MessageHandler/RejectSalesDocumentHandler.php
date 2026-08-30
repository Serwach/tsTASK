<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\SalesDocumentStatus;
use App\Exception\SalesDocumentNotFound;
use App\Exception\SalesDocumentTransitionNotAllowed;
use App\Message\Command\RejectSalesDocument;
use App\Repository\SalesDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RejectSalesDocumentHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SalesDocumentRepository $repository,
    ) {
    }

    public function __invoke(RejectSalesDocument $command): int
    {
        return $this->entityManager->wrapInTransaction(function () use ($command): int {
            $document = $this->repository->find($command->documentId);
            if ($document === null) {
                throw SalesDocumentNotFound::withId($command->documentId);
            }
            if ($document->getStatus() !== SalesDocumentStatus::Draft) {
                throw SalesDocumentTransitionNotAllowed::cannotReject($document->getStatus());
            }

            $document->setStatus(SalesDocumentStatus::Rejected);
            $document->setRejectedBy($command->rejectedBy);
            $document->setRejectedAt(new \DateTimeImmutable());

            return $document->getId();
        });
    }
}
