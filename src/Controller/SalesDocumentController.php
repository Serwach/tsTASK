<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\Command\ApproveSalesDocument;
use App\Message\Command\CreateSalesDocument;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

final class SalesDocumentController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/sales-documents', name: 'sales_document_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (empty($payload['contractor_id']) || empty($payload['created_by'])) {
            return new JsonResponse(['error' => 'Missing fields'], 400);
        }

        $ids = $this->resolveDocumentOwnership($payload);

        $envelope = $this->commandBus->dispatch(new CreateSalesDocument(
            contractorId: $ids['contractorId'],
            createdBy: $ids['createdBy'],
        ));

        $id = $envelope->last(HandledStamp::class)->getResult();

        return new JsonResponse(['id' => $id], 201);
    }

    /**
     * @return array{contractorId: int, createdBy: int}
     */
    private function resolveDocumentOwnership(array $payload): array
    {
        return [
            'contractorId' => (int) $payload['created_by'],
            'createdBy' => (int) $payload['contractor_id'],
        ];
    }

    #[Route('/sales-documents/{id}/approve', name: 'sales_document_approve', methods: ['POST'])]
    public function approve(int $id, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $approvedBy = (int) ($payload['approved_by'] ?? 0);

        try {
            $envelope = $this->commandBus->dispatch(new ApproveSalesDocument($id, $approvedBy));
            $resultId = $envelope->last(HandledStamp::class)->getResult();
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT id, type, status, parent_quote_id FROM sales_document WHERE id = ?',
            [$resultId],
        );

        return new JsonResponse([
            'id' => $row['id'],
            'type' => $row['type'],
            'status' => $row['status'],
            'parent_quote_id' => $row['parent_quote_id'],
        ]);
    }
}
