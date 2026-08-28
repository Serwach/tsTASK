<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\SalesDocument;
use App\Enum\SalesDocumentStatus;
use App\Enum\SalesDocumentType;
use App\Message\Command\CreateSalesDocument;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateSalesDocumentHandler
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(CreateSalesDocument $command): int
    {
        $document = new SalesDocument();
        $document->setContractorId($command->contractorId);
        $document->setCreatedBy($command->createdBy);
        $document->setType(SalesDocumentType::Quote);
        $document->setStatus(SalesDocumentStatus::Draft);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document->getId();
    }
}
