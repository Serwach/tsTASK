<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Entity\SalesDocument;
use App\Enum\SalesDocumentStatus;
use App\Enum\SalesDocumentType;
use App\Notification\ApprovalNotifier;
use App\Notification\InMemoryNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ApprovalNotifierTest extends TestCase
{
    public function testNotifiesBothParties(): void
    {
        $notifier = new InMemoryNotifier();
        (new ApprovalNotifier($notifier, new NullLogger()))->documentApproved($this->document());

        self::assertCount(2, $notifier->sent);
        self::assertSame([5, 77], array_column($notifier->sent, 'userId'));
    }

    public function testAFailingRecipientIsSwallowedAndDoesNotStopTheOthers(): void
    {
        $notifier = new InMemoryNotifier(failOnCallNumber: 1);

        (new ApprovalNotifier($notifier, new NullLogger()))->documentApproved($this->document());

        // first delivery threw and was contained; the second one still happened
        self::assertCount(1, $notifier->sent);
        self::assertSame(77, $notifier->sent[0]['userId']);
    }

    private function document(): SalesDocument
    {
        $document = new SalesDocument();
        $document->setContractorId(77);
        $document->setCreatedBy(5);
        $document->setType(SalesDocumentType::Quote);
        $document->setStatus(SalesDocumentStatus::Approved);

        return $document;
    }
}
