# Problem 4 — nowa operacja `RejectSalesDocument` (implementacja z samego testu)

## TL;DR

`tests/Functional/RejectSalesDocumentHandlerTest.php` istniał, ale `RejectSalesDocument` /
`RejectSalesDocumentHandler` — nie. Zaprojektowane i zaimplementowane **w tym samym stylu co
`ApproveSalesDocument`**: Command → Handler na `command.bus`, transakcja, guardy, wyjątki
domenowe. Dodany stan `SalesDocumentStatus::Rejected`, kolumny audytu `rejected_by` /
`rejected_at` (analogicznie do `approved_by` / `approved_at`) + migracja, oraz endpoint
HTTP `POST /sales-documents/{id}/reject` korzystający z tego samego mapowania błędów co
`approve` (problem 2).

Oba testy przechodzą; treść ich asercji nietknięta.

---

## 1. Co mówi test (to jest specyfikacja)

```php
use App\Message\Command\RejectSalesDocument;

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
```

Wnioski:

| Sygnał z testu | Decyzja projektowa |
|---|---|
| `new RejectSalesDocument(documentId: …, rejectedBy: …)` | Command z dwoma polami `int`, konstruktor z nazwanymi promowanymi właściwościami (jak `ApproveSalesDocument`) |
| `dispatch()` przez `MessageBusInterface`, `->last(HandledStamp::class)?->getResult()` | Handler `#[AsMessageHandler(bus: 'command.bus')]`, zwraca `int` (id) |
| draft → `SalesDocumentStatus::Rejected` | nowy case w enumie; handler ustawia status |
| already-approved → `\RuntimeException` | guard `status !== Draft` rzuca wyjątek dziedzinowy będący `\RuntimeException` |
| `em->clear()` + ponowny `find()` | zmiana musi być **zapisana do bazy** (flush), nie tylko w pamięci |

---

## 2. Implementacja — „w tym samym stylu"

### `src/Enum/SalesDocumentStatus.php`

```php
case Draft = 'draft';
case Approved = 'approved';
case Rejected = 'rejected';        // nowy
```

Kolumna `status` to `VARCHAR(255)` — sam enum nie wymaga migracji.

### `src/Message/Command/RejectSalesDocument.php` (nowy)

```php
final class RejectSalesDocument
{
    public function __construct(
        public readonly int $documentId,
        public readonly int $rejectedBy,
    ) {}
}
```

### `src/MessageHandler/RejectSalesDocumentHandler.php` (nowy)

```php
#[AsMessageHandler(bus: 'command.bus')]
final class RejectSalesDocumentHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SalesDocumentRepository $repository,
    ) {}

    public function __invoke(RejectSalesDocument $command): int
    {
        return $this->entityManager->wrapInTransaction(function () use ($command): int {
            $document = $this->repository->find($command->documentId);
            if ($document === null) {
                throw SalesDocumentNotFound::withId($command->documentId);
            }
            if ($document->getStatus() !== SalesDocumentStatus::Draft) {
                throw SalesDocumentTransitionNotAllowed::cannotReject($document->getStatus());
            }

            $document->setStatus(SalesDocumentStatus::Rejected);
            $document->setRejectedBy($command->rejectedBy);
            $document->setRejectedAt(new \DateTimeImmutable());

            return $document->getId();
        });
    }
}
```

Ta sama struktura co `ApproveSalesDocumentHandler`: `wrapInTransaction`, guard „nie znaleziono"
(`SalesDocumentNotFound`), guard „zły stan" (`SalesDocumentTransitionNotAllowed` — ten sam
typ, który problem 2 mapuje na 409). `SalesDocumentTransitionNotAllowed extends \RuntimeException`,
a Messenger opakowuje wyjątek handlera w `HandlerFailedException` (też `extends RuntimeException`)
— więc `expectException(\RuntimeException::class)` w teście przechodzi.

Rejection jest terminalna — nic nie spawnuje (w przeciwieństwie do approve → order).

### `src/Entity/SalesDocument.php` — audyt, symetrycznie do approve

```php
#[ORM\Column(nullable: true)]
private ?int $rejectedBy = null;

#[ORM\Column(nullable: true)]
private ?\DateTimeImmutable $rejectedAt = null;
// + gettery/settery
```

`approved_by` / `approved_at` mają swój odpowiednik → `rejected_by` / `rejected_at`. Dzięki
temu pole `rejectedBy` z komendy jest faktycznie używane (nie jest martwym parametrem).

### `migrations/Version20260830000000.php` (nowy)

```php
public function up(Schema $schema): void
{
    $this->addSql('ALTER TABLE sales_document ADD rejected_by INT DEFAULT NULL, ADD rejected_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
}
public function down(Schema $schema): void
{
    $this->addSql('ALTER TABLE sales_document DROP rejected_by, DROP rejected_at');
}
```

Kolumny `nullable` — bezpieczne dla istniejących wierszy. Entrypoint kontenera odpala
`doctrine:migrations:migrate` dla `dev` i `test`, więc wjeżdża automatycznie.

### `src/Controller/SalesDocumentController.php` — endpoint HTTP w tym samym stylu

```php
#[Route('/sales-documents/{id}/reject', name: 'sales_document_reject', methods: ['POST'])]
public function reject(int $id, Request $request): JsonResponse
{
    $payload = json_decode($request->getContent(), true) ?? [];
    $rejectedBy = (int) ($payload['rejected_by'] ?? 0);

    try {
        $this->commandBus->dispatch(new RejectSalesDocument($id, $rejectedBy));
    } catch (HandlerFailedException $e) {
        return $this->mapDomainFailure($e);      // to samo 404/409 co approve
    }

    $document = $this->repository->find($id);

    return new JsonResponse([
        'id' => $document->getId(),
        'type' => $document->getType()->value,
        'status' => $document->getStatus()->value,
    ]);
}
```

Test dotyczył tylko handlera, ale endpoint domyka „nową operację w tym samym stylu" i
potwierdza, że mapowanie błędów z problemu 2 jest reużywalne.

### Świadomie pominięte

**Powiadomienia.** `ApproveSalesDocumentHandler` woła `ApprovalNotifier`; `reject` — nie.
Test tego nie wymaga, a dodawanie równoległego `RejectionNotifier` byłoby spekulacją co do
niesprecyzowanego wymagania. Wzorzec best-effort (`ApprovalNotifier`) jest gotowy do
ponownego użycia, gdyby pojawiła się taka potrzeba.

---

## 3. Weryfikacja

```
✔ Rejecting a draft quote marks it rejected
✔ Rejecting an already approved document is rejected by the domain
```

HTTP:

```
POST /sales-documents/1/reject  {"rejected_by":9}          -> 200 {"id":1,"type":"quote","status":"rejected"}
POST /sales-documents/2/reject  (2 jest approved)          -> 409 {"error":"Document cannot be rejected in its current status (approved)"}
POST /sales-documents/999/reject                           -> 404
```

```
 id |  status  | rejected_by |     rejected_at
----+----------+-------------+---------------------
  1 | rejected |           9 | 2026-08-30 05:33:37
```

---

## 4. Pliki dodane / zmienione w ramach problemu 4

| Plik | Zmiana |
|---|---|
| `src/Message/Command/RejectSalesDocument.php` | **nowy** |
| `src/MessageHandler/RejectSalesDocumentHandler.php` | **nowy** |
| `migrations/Version20260830000000.php` | **nowy** — `rejected_by` / `rejected_at` |
| `src/Enum/SalesDocumentStatus.php` | + `case Rejected` |
| `src/Entity/SalesDocument.php` | + `rejectedBy` / `rejectedAt` + akcesory |
| `src/Controller/SalesDocumentController.php` | + endpoint `POST /sales-documents/{id}/reject` |

Zależność: wyjątki `App\Exception\*` wprowadzone w problemie 2 są tu reużyte.
