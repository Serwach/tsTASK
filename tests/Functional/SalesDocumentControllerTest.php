<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\SalesDocument;
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

    public function testApprovingMissingDocumentCurrentlyReturns500(): void
    {
        $this->client->request('POST', '/sales-documents/999999/approve', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'approved_by' => 9,
        ]));

        self::assertResponseStatusCodeSame(500);
    }
}
