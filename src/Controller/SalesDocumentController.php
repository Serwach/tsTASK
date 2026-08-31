<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SalesDocument;
use App\Exception\SalesDocumentNotFound;
use App\Exception\SalesDocumentTransitionNotAllowed;
use App\Message\Command\ApproveSalesDocument;
use App\Message\Command\CreateSalesDocument;
use App\Message\Command\RejectSalesDocument;
use App\Repository\SalesDocumentRepository;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/sales-documents', name: 'sales_document_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        $contractorId = self::readId($payload, 'contractor_id');
        $createdBy = self::readId($payload, 'created_by');
        if ($contractorId === null || $createdBy === null) {
            return new JsonResponse(['error' => 'contractor_id and created_by must be positive integers'], 400);
        }

        try {
            $envelope = $this->commandBus->dispatch(new CreateSalesDocument(
                contractorId: $contractorId,
                createdBy: $createdBy,
            ));
        } catch (\Throwable $e) {
            return $this->unexpectedFailure('create', null, $e);
        }

        $id = $envelope->last(HandledStamp::class)->getResult();

        return new JsonResponse(['id' => $id], 201);
    }

    #[Route('/sales-documents/{id}/approve', name: 'sales_document_approve', methods: ['POST'])]
    public function approve(string $id, Request $request): JsonResponse
    {
        $documentId = self::readId(['id' => $id], 'id');
        if ($documentId === null) {
            return new JsonResponse(['error' => 'Sales document not found'], 404);
        }

        $approvedBy = self::readId(json_decode($request->getContent(), true), 'approved_by');
        if ($approvedBy === null) {
            return new JsonResponse(['error' => 'approved_by must be a positive integer'], 400);
        }

        try {
            $envelope = $this->commandBus->dispatch(new ApproveSalesDocument($documentId, $approvedBy));
            $resultId = $envelope->last(HandledStamp::class)->getResult();
        } catch (HandlerFailedException $e) {
            return $this->mapDomainFailure('approve', $documentId, $e);
        } catch (\Throwable $e) {
            return $this->unexpectedFailure('approve', $documentId, $e);
        }

        $document = $this->repository->find($resultId);
        \assert($document instanceof SalesDocument);

        return new JsonResponse([
            'id' => $document->getId(),
            'type' => $document->getType()->value,
            'status' => $document->getStatus()->value,
            'parent_quote_id' => $document->getParentQuoteId(),
        ]);
    }

    #[Route('/sales-documents/{id}/reject', name: 'sales_document_reject', methods: ['POST'])]
    public function reject(string $id, Request $request): JsonResponse
    {
        $documentId = self::readId(['id' => $id], 'id');
        if ($documentId === null) {
            return new JsonResponse(['error' => 'Sales document not found'], 404);
        }

        $rejectedBy = self::readId(json_decode($request->getContent(), true), 'rejected_by');
        if ($rejectedBy === null) {
            return new JsonResponse(['error' => 'rejected_by must be a positive integer'], 400);
        }

        try {
            $this->commandBus->dispatch(new RejectSalesDocument($documentId, $rejectedBy));
        } catch (HandlerFailedException $e) {
            return $this->mapDomainFailure('reject', $documentId, $e);
        } catch (\Throwable $e) {
            return $this->unexpectedFailure('reject', $documentId, $e);
        }

        $document = $this->repository->find($documentId);
        \assert($document instanceof SalesDocument);

        return new JsonResponse([
            'id' => $document->getId(),
            'type' => $document->getType()->value,
            'status' => $document->getStatus()->value,
        ]);
    }

    /**
     * A required id read from either the JSON body or the route path: it must be
     * present and a positive integer. Returns null on anything malformed
     * (missing, non-numeric, float, "0", nested structure, or too large to fit
     * a PHP int) so the caller can answer with a deliberate status instead of
     * silently coercing garbage to 0 or crashing on a native type error.
     */
    private static function readId(mixed $payload, string $key): ?int
    {
        $value = \is_array($payload) ? ($payload[$key] ?? null) : null;

        if (\is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (\is_string($value)) {
            // FILTER_VALIDATE_INT rejects floats, signs other than a plain
            // positive number, and anything too large to fit a PHP int
            // (instead of silently wrapping/truncating it) - important since
            // this also parses the {id} route segment, which is attacker-
            // controlled and otherwise reaches here as an arbitrary string.
            $parsed = filter_var($value, FILTER_VALIDATE_INT);

            return $parsed !== false && $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    /**
     * Translate a domain failure raised inside a handler into a meaningful HTTP
     * status. Anything we do not explicitly recognise is genuinely unexpected -
     * it falls through to unexpectedFailure() rather than leaking the raw
     * exception message to the client.
     */
    private function mapDomainFailure(string $operation, int $documentId, HandlerFailedException $e): JsonResponse
    {
        $cause = $e->getPrevious() ?? $e;

        return match (true) {
            $cause instanceof SalesDocumentNotFound => new JsonResponse(['error' => $cause->getMessage()], 404),
            $cause instanceof SalesDocumentTransitionNotAllowed => new JsonResponse(['error' => $cause->getMessage()], 409),
            default => $this->unexpectedFailure($operation, $documentId, $cause),
        };
    }

    /**
     * A genuinely unexpected failure (not one of our recognised domain errors):
     * log it with context for diagnosis and answer with a generic, safe 500 -
     * never the raw exception message, regardless of the environment's debug
     * setting.
     */
    private function unexpectedFailure(string $operation, ?int $documentId, \Throwable $e): JsonResponse
    {
        $this->logger->error('Unexpected failure while processing a sales document operation', [
            'operation' => $operation,
            'documentId' => $documentId,
            'exception' => $e,
        ]);

        return new JsonResponse(['error' => 'Internal server error'], 500);
    }
}
