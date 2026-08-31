<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\SalesDocument;
use App\Enum\SalesDocumentStatus;
use App\Repository\SalesDocumentRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class SalesDocumentControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        self::getContainer()->get('doctrine.orm.entity_manager')
            ->createQuery('DELETE FROM ' . SalesDocument::class)
            ->execute();
    }

    public function testCreateAndApproveThroughHttp(): void
    {
        $this->client->request('POST', '/sales-documents', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'contractor_id' => 77,
            'created_by' => 5,
        ]));
        self::assertResponseStatusCodeSame(201);
        $quoteId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/sales-documents/{$quoteId}/approve", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'approved_by' => 9,
        ]));
        self::assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('order', $body['type']);
        self::assertSame($quoteId, $body['parent_quote_id']);
    }

    public function testApprovingMissingDocumentReturns404(): void
    {
        $this->client->request('POST', '/sales-documents/999999/approve', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'approved_by' => 9,
        ]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testApprovingAnAlreadyApprovedDocumentReturns409(): void
    {
        $this->client->request('POST', '/sales-documents', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'contractor_id' => 77,
            'created_by' => 5,
        ]));
        $quoteId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/sales-documents/{$quoteId}/approve", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['approved_by' => 9]));
        self::assertResponseIsSuccessful();

        $this->client->request('POST', "/sales-documents/{$quoteId}/approve", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['approved_by' => 9]));
        self::assertResponseStatusCodeSame(409);
    }

    public function testCreatedDocumentKeepsThePayloadOwnership(): void
    {
        $this->client->request('POST', '/sales-documents', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'contractor_id' => 111,
            'created_by' => 222,
        ]));
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        /** @var SalesDocumentRepository $repository */
        $repository = self::getContainer()->get(SalesDocumentRepository::class);
        $document = $repository->find($id);

        self::assertSame(111, $document->getContractorId(), 'contractor_id from the payload must be stored as the contractor');
        self::assertSame(222, $document->getCreatedBy(), 'created_by from the payload must be stored as the creator');
    }

    public function testApprovingWithoutApprovedByReturns400(): void
    {
        $this->client->request('POST', '/sales-documents', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'contractor_id' => 77,
            'created_by' => 5,
        ]));
        $quoteId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/sales-documents/{$quoteId}/approve", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([]));

        self::assertResponseStatusCodeSame(400);
    }

    public function testRejectingADraftThroughHttp(): void
    {
        $this->client->request('POST', '/sales-documents', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'contractor_id' => 77,
            'created_by' => 5,
        ]));
        $quoteId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/sales-documents/{$quoteId}/reject", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'rejected_by' => 9,
        ]));
        self::assertResponseIsSuccessful();
        self::assertSame('rejected', json_decode($this->client->getResponse()->getContent(), true)['status']);

        /** @var SalesDocumentRepository $repository */
        $repository = self::getContainer()->get(SalesDocumentRepository::class);
        $document = $repository->find($quoteId);
        self::assertSame(SalesDocumentStatus::Rejected, $document->getStatus());
        self::assertSame(9, $document->getRejectedBy());
        self::assertNotNull($document->getRejectedAt());
    }

    public function testRejectingAMissingDocumentReturns404(): void
    {
        $this->client->request('POST', '/sales-documents/999999/reject', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'rejected_by' => 9,
        ]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testApprovingWithAnOverflowingIdReturns404InsteadOfLeakingAStackTrace(): void
    {
        // A route segment too large to fit a PHP int used to reach the
        // controller as a raw string and crash on the native `int $id` type
        // hint before any application code ran, leaking a full debug page.
        $this->client->request('POST', '/sales-documents/99999999999999999999999/approve', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'approved_by' => 9,
        ]));

        self::assertResponseStatusCodeSame(404);
        self::assertSame(
            ['error' => 'Sales document not found'],
            json_decode($this->client->getResponse()->getContent(), true),
        );
    }

    #[DataProvider('operationProvider')]
    public function testAnUnexpectedFailureFromTheCommandBusReturnsASafeGenericErrorAndIsLogged(string $operation, string $actorField): void
    {
        $failure = new \RuntimeException('Sensitive database connection string leaked here');

        $commandBus = $this->createMock(MessageBusInterface::class);
        $commandBus->expects(self::once())->method('dispatch')->willThrowException($failure);
        self::getContainer()->set(MessageBusInterface::class, $commandBus);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Unexpected failure while processing a sales document operation',
            self::callback(static fn (array $context): bool => $context['operation'] === $operation
                && $context['documentId'] === 123
                && $context['exception'] === $failure),
        );
        self::getContainer()->set(LoggerInterface::class, $logger);

        $this->client->request('POST', "/sales-documents/123/{$operation}", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            $actorField => 9,
        ]));

        self::assertResponseStatusCodeSame(500);
        self::assertSame(
            ['error' => 'Internal server error'],
            json_decode($this->client->getResponse()->getContent(), true),
            'the raw exception message must never reach the client',
        );
    }

    public static function operationProvider(): iterable
    {
        yield 'approve' => ['approve', 'approved_by'];
        yield 'reject' => ['reject', 'rejected_by'];
    }

    public function testRejectingAnApprovedDocumentThroughHttpReturns409(): void
    {
        $this->client->request('POST', '/sales-documents', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'contractor_id' => 77,
            'created_by' => 5,
        ]));
        $quoteId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/sales-documents/{$quoteId}/approve", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['approved_by' => 9]));
        self::assertResponseIsSuccessful();

        $this->client->request('POST', "/sales-documents/{$quoteId}/reject", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['rejected_by' => 9]));
        self::assertResponseStatusCodeSame(409);
    }
}
