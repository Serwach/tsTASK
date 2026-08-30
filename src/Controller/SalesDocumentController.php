<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\SalesDocumentNotFound;
use App\Exception\SalesDocumentTransitionNotAllowed;
use App\Message\Command\ApproveSalesDocument;
use App\Message\Command\CreateSalesDocument;
use App\Message\Command\RejectSalesDocument;
use App\Repository\SalesDocumentRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

final class SalesDocumentController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly SalesDocumentRepository $repository,
    ) {
    }

    #[Route('/sales-documents', name: 'sales_document_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (empty($payload['contractor_id']) || empty($payload['created_by'])) {
            return new JsonResponse(['error' => 'Missing fields'], 400);
        }

        $envelope = $this->commandBus->dispatch(new CreateSalesDocument(
            contractorId: (int) $payload['contractor_id'],
            createdBy: (int) $payload['created_by'],
        ));

        $id = $envelope->last(HandledStamp::class)->getResult();

        return new JsonResponse(['id' => $id], 201);
    }

    #[Route('/sales-documents/{id}/approve', name: 'sales_document_approve', methods: ['POST'])]
    public function approve(int $id, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $approvedBy = (int) ($payload['approved_by'] ?? 0);

        try {
            $envelope = $this->commandBus->dispatch(new ApproveSalesDocument($id, $approvedBy));
            $resultId = $envelope->last(HandledStamp::class)->getResult();
        } catch (HandlerFailedException $e) {
            return $this->mapDomainFailure($e);
        }

        $document = $this->repository->find($resultId);

        return new JsonResponse([
            'id' => $document->getId(),
            'type' => $document->getType()->value,
            'status' => $document->getStatus()->value,
            'parent_quote_id' => $document->getParentQuoteId(),
        ]);
    }

    #[Route('/sales-documents/{id}/reject', name: 'sales_document_reject', methods: ['POST'])]
    public function reject(int $id, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $rejectedBy = (int) ($payload['rejected_by'] ?? 0);

        try {
            $this->commandBus->dispatch(new RejectSalesDocument($id, $rejectedBy));
        } catch (HandlerFailedException $e) {
            return $this->mapDomainFailure($e);
        }

        $document = $this->repository->find($id);

        return new JsonResponse([
            'id' => $document->getId(),
            'type' => $document->getType()->value,
            'status' => $document->getStatus()->value,
        ]);
    }

    /**
     * Translate a domain failure raised inside a handler into a meaningful HTTP
     * status. Anything we do not explicitly recognise is genuinely unexpected
     * and is re-thrown so the framework turns it into a 500 (without leaking the
     * raw exception message to the client).
     */
    private function mapDomainFailure(HandlerFailedException $e): JsonResponse
    {
        $cause = $e->getPrevious() ?? $e;

        return match (true) {
            $cause instanceof SalesDocumentNotFound => new JsonResponse(['error' => $cause->getMessage()], 404),
            $cause instanceof SalesDocumentTransitionNotAllowed => new JsonResponse(['error' => $cause->getMessage()], 409),
            default => throw $e,
        };
    }
}
