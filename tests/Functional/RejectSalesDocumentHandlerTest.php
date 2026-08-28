<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\SalesDocument;
use App\Enum\SalesDocumentStatus;
use App\Message\Command\ApproveSalesDocument;
use App\Message\Command\CreateSalesDocument;
use App\Message\Command\RejectSalesDocument;
use App\Repository\SalesDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class RejectSalesDocumentHandlerTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM ' . SalesDocument::class)
            ->execute();
    }

    private function dispatch(object $command): mixed
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch($command);

        return $envelope->last(HandledStamp::class)?->getResult();
    }

    public function testRejectingADraftQuoteMarksItRejected(): void
    {
        $quoteId = $this->dispatch(new CreateSalesDocument(contractorId: 77, createdBy: 5));

        $this->dispatch(new RejectSalesDocument(documentId: $quoteId, rejectedBy: 9));

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $document = self::getContainer()->get(SalesDocumentRepository::class)->find($quoteId);

        self::assertSame(SalesDocumentStatus::Rejected, $document->getStatus());
    }

    public function testRejectingAnAlreadyApprovedDocumentIsRejectedByTheDomain(): void
    {
        $quoteId = $this->dispatch(new CreateSalesDocument(contractorId: 77, createdBy: 5));
        $this->dispatch(new ApproveSalesDocument($quoteId, 9));

        $this->expectException(\RuntimeException::class);

        $this->dispatch(new RejectSalesDocument(documentId: $quoteId, rejectedBy: 9));
    }
}
