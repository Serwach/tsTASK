<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\SalesDocument;
use App\Enum\SalesDocumentStatus;
use App\Enum\SalesDocumentType;
use App\Message\Command\ApproveSalesDocument;
use App\Message\Command\CreateSalesDocument;
use App\Notification\InMemoryNotifier;
use App\Notification\NotifierPort;
use App\Repository\SalesDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ApproveSalesDocumentTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM ' . SalesDocument::class)->execute();
    }

    private function dispatch(object $command): mixed
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch($command);

        $handled = $envelope->last(\Symfony\Component\Messenger\Stamp\HandledStamp::class);

        return $handled->getResult();
    }

    public function testApprovingAQuoteSpawnsALinkedOrderAndNotifiesBothParties(): void
    {
        $notifier = new InMemoryNotifier();
        self::getContainer()->set(NotifierPort::class, $notifier);

        $quoteId = $this->dispatch(new CreateSalesDocument(contractorId: 77, createdBy: 5));
        $orderId = $this->dispatch(new ApproveSalesDocument(documentId: $quoteId, approvedBy: 9));

        self::assertNotSame($quoteId, $orderId, 'approving a quote must spawn a separate order document');

        /** @var SalesDocumentRepository $repository */
        $repository = self::getContainer()->get(SalesDocumentRepository::class);

        $quote = $repository->find($quoteId);
        self::assertSame(SalesDocumentType::Quote, $quote->getType());
        self::assertSame(SalesDocumentStatus::Approved, $quote->getStatus());

        $order = $repository->find($orderId);
        self::assertSame(SalesDocumentType::Order, $order->getType());
        self::assertSame($quoteId, $order->getParentQuoteId());

        self::assertCount(2, $notifier->sent, 'creator and contractor must both be notified');
    }

    public function testApprovingAQuoteNotifiesTheQuotesOwnPartiesNotTheApprover(): void
    {
        $notifier = new InMemoryNotifier();
        self::getContainer()->set(NotifierPort::class, $notifier);

        $quoteId = $this->dispatch(new CreateSalesDocument(contractorId: 77, createdBy: 5));
        $this->dispatch(new ApproveSalesDocument(documentId: $quoteId, approvedBy: 9));

        // Approving a quote spawns an order whose createdBy is the approver
        // (9), not the quote's own creator (5). Notifying based on that order
        // instead of the original quote would tell the approver about their
        // own action and never reach the quote's actual creator.
        $notifiedUserIds = array_column($notifier->sent, 'userId');

        self::assertContains(5, $notifiedUserIds, 'the quote creator must be notified');
        self::assertContains(77, $notifiedUserIds, 'the contractor must be notified');
        self::assertNotContains(9, $notifiedUserIds, 'the approver is not a party to the quote and must not be notified');
    }

    public function testApprovalDoesNotFailTheCallerWhenTheNotificationChannelFails(): void
    {
        $flakyNotifier = new InMemoryNotifier(failOnCallNumber: 1);
        self::getContainer()->set(NotifierPort::class, $flakyNotifier);

        $quoteId = $this->dispatch(new CreateSalesDocument(contractorId: 77, createdBy: 5));

        $this->dispatch(new ApproveSalesDocument(documentId: $quoteId, approvedBy: 9));

        self::getContainer()->get(EntityManagerInterface::class)->clear();

        /** @var SalesDocumentRepository $repository */
        $repository = self::getContainer()->get(SalesDocumentRepository::class);
        $quote = $repository->find($quoteId);

        self::assertNotNull($quote);
        self::assertSame(
            SalesDocumentStatus::Approved,
            $quote->getStatus(),
            'the approval must be durable even if the notification side-effect fails',
        );
    }
}
