<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\SalesDocument;
use App\Repository\SalesDocumentRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
}
