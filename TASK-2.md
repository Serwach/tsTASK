# Problem 2 — kontroler mapuje każdy błąd na 500 (z surowym komunikatem) i omija repozytorium

## TL;DR

`SalesDocumentController::approve()` robił `catch (\Throwable)` → **500 z `$e->getMessage()`**
dla dowolnego błędu: nieistniejące ID, zła próba przejścia stanu, realny bug — wszystko tak
samo. Do tego czytał dokument **surowym SQL‑em** zamiast przez `SalesDocumentRepository`.

Naprawa:

- wprowadzone wyjątki domenowe (`App\Exception\SalesDocumentNotFound`,
  `App\Exception\SalesDocumentTransitionNotAllowed`), rzucane przez handler zamiast gołego
  `\RuntimeException`;
- kontroler łapie `HandlerFailedException`, rozpakowuje przyczynę i mapuje:
  **404** „nie znaleziono", **409** „operacja niedozwolona w tym stanie", a wszystko
  nierozpoznane **re‑throw** (framework robi 500 bez wycieku treści wyjątku);
- surowy SQL → `SalesDocumentRepository::find()` + serializacja encji.

Testy: `testApprovingMissingDocumentCurrentlyReturns500` → `testApprovingMissingDocumentReturns404`
(asercja `404`), dodany `testApprovingAnAlreadyApprovedDocumentReturns409`. Happy‑path
`testCreateAndApproveThroughHttp` — asercje nietknięte, dalej zielony.

---

## 1. Natura błędu

### Kod przed zmianą

```php
#[Route('/sales-documents/{id}/approve', methods: ['POST'])]
public function approve(int $id, Request $request): JsonResponse
{
    // ...
    try {
        $envelope = $this->commandBus->dispatch(new ApproveSalesDocument($id, $approvedBy));
        $resultId = $envelope->last(HandledStamp::class)->getResult();
    } catch (\Throwable $e) {
        return new JsonResponse(['error' => $e->getMessage()], 500);   // (1)
    }

    $row = $this->entityManager->getConnection()->fetchAssociative(     // (2)
        'SELECT id, type, status, parent_quote_id FROM sales_document WHERE id = ?',
        [$resultId],
    );

    return new JsonResponse([
        'id' => $row['id'], 'type' => $row['type'],
        'status' => $row['status'], 'parent_quote_id' => $row['parent_quote_id'],
    ]);
}
```

### Co jest nie tak

**(1) Każdy błąd = 500 + surowy komunikat wyjątku.**
Handler rzuca dziś dwa różne wyjątki dziedzinowe:

| Sytuacja | Wyjątek w handlerze | Poprawny kod HTTP | Co zwracał kontroler |
|---|---|---|---|
| nieistniejące `id` dokumentu | „Document N not found" | **404** | 500 |
| dokument nie jest w stanie `Draft` | „Document cannot be approved in its current status" | **409** (konflikt stanu) | 500 |
| realny błąd (np. padła baza) | dowolny `\Throwable` | 500 | 500 (ale z wyciekiem treści) |

Wszystko leci przez ten sam `catch (\Throwable)`. Klient:

- nie odróżni „podałem złe ID" (jego wina, 4xx) od „serwer się wywalił" (5xx) — więc nie wie,
  czy ponawiać;
- dostaje w odpowiedzi **surową treść wyjątku** (`Handling "App\Message\Command\ApproveSalesDocument"
  failed: …`) — wyciek szczegółów implementacji, a przy prawdziwym błędzie potencjalnie
  wrażliwych danych (ścieżki, fragmenty SQL, komunikaty sterownika).

**(2) Surowy SQL zamiast repozytorium.**
`SalesDocumentRepository` istnieje (jest wstrzykiwany do handlera), a kontroler pisze własny
`SELECT`. Skutki: duplikacja wiedzy o schemacie, brak hydratacji (dostajemy tablicę stringów,
nie encję z typami — `parent_quote_id` przychodzi jako `"2"` zamiast `2`), obejście warstwy
dostępu do danych. To „code smell", który `TASK.MD` wprost wytyka.

### Ścieżka wywołania (przykład: nieistniejące ID)

1. `POST /sales-documents/999999/approve`.
2. `commandBus->dispatch(new ApproveSalesDocument(999999, 9))`.
3. `ApproveSalesDocumentHandler` → `repository->find(999999)` → `null` → `throw` (not found).
4. `HandleMessageMiddleware` opakowuje w `HandlerFailedException`.
5. Kontroler: `catch (\Throwable $e)` → `new JsonResponse(['error' => $e->getMessage()], 500)`.
6. Klient dostaje **500** — mimo że to zwykłe „nie ma takiego zasobu" (**404**).

---

## 2. Jak namierzyłem — krok po kroku

### Krok 1 — reprodukcja 404‑jako‑500

```bash
curl -sS -i -X POST http://localhost:8080/sales-documents/999999/approve \
  -H 'Content-Type: application/json' -d '{"approved_by":9}'
```

```
HTTP/1.1 500 Internal Server Error
{"error":"Handling \"App\\Message\\Command\\ApproveSalesDocument\" failed: Sales document 999999 not found"}
```

To samo pokrywał (celowo) test `testApprovingMissingDocumentCurrentlyReturns500` —
`assertResponseStatusCodeSame(500)`.

### Krok 2 — reprodukcja 409‑jako‑500

```bash
# utwórz i zatwierdź
ID=$(curl -sS -X POST http://localhost:8080/sales-documents \
      -H 'Content-Type: application/json' -d '{"contractor_id":77,"created_by":5}' | jq -r .id)
curl -sS -o /dev/null -w "%{http_code}\n" -X POST "http://localhost:8080/sales-documents/$ID/approve" \
  -H 'Content-Type: application/json' -d '{"approved_by":9}'      # 200

# zatwierdź ponownie
curl -sS -i -X POST "http://localhost:8080/sales-documents/$ID/approve" \
  -H 'Content-Type: application/json' -d '{"approved_by":9}'
```

```
HTTP/1.1 500 Internal Server Error
{"error":"Handling \"…ApproveSalesDocument\" failed: Document cannot be approved in its current status"}
```

Druga, zupełnie inna sytuacja biznesowa — ten sam 500.

### Krok 3 — źródło w kodzie

`SalesDocumentController::approve()`: jeden `catch (\Throwable $e)` → zawsze `500` +
`$e->getMessage()`. Handler natomiast rozróżnia przypadki (`find() === null` vs.
`status !== Draft`) — informacja o rodzaju błędu **jest**, tylko kontroler ją gubi.

Dodatkowo w tej samej metodzie: `->getConnection()->fetchAssociative('SELECT … FROM sales_document …')`
— podczas gdy `SalesDocumentRepository` jest tuż obok.

### Krok 4 — jak Messenger przekazuje wyjątek

Sprawdzenie `vendor/symfony/messenger/Exception/HandlerFailedException.php`: konstruktor
przekazuje **pierwszy** wyjątek handlera jako `$previous`
(`parent::__construct(…, …, $firstFailure)`). Czyli w kontrolerze mam dostęp do oryginalnej
przyczyny przez `$e->getPrevious()` (albo `$e->getWrappedExceptions(Klasa::class)`).

---

## 3. Jak naprawiłem — krok po kroku

### Krok 1 — wyjątki domenowe: `src/Exception/`

`SalesDocumentNotFound` (→ 404):

```php
final class SalesDocumentNotFound extends \RuntimeException
{
    public static function withId(int $id): self
    {
        return new self(\sprintf('Sales document %d not found', $id));
    }
}
```

`SalesDocumentTransitionNotAllowed` (→ 409):

```php
final class SalesDocumentTransitionNotAllowed extends \RuntimeException
{
    public static function cannotApprove(SalesDocumentStatus $current): self { /* … */ }
    public static function cannotReject(SalesDocumentStatus $current): self  { /* … */ }
}
```

Oba `extends \RuntimeException` — nie wprowadzają zależności od HTTP (domena nie wie o kodach
statusu), a jednocześnie zachowują kompatybilność z testami, które oczekują `\RuntimeException`
(m.in. `RejectSalesDocumentHandlerTest`). `cannotReject(...)` jest już przygotowane pod
problem 4.

### Krok 2 — handler rzuca typy zamiast gołego `\RuntimeException`

`src/MessageHandler/ApproveSalesDocumentHandler.php`:

```php
// przed:
if ($document === null) {
    throw new \RuntimeException("Document {$command->documentId} not found");
}
if ($document->getStatus() !== SalesDocumentStatus::Draft) {
    throw new \RuntimeException('Document cannot be approved in its current status');
}

// po:
if ($document === null) {
    throw SalesDocumentNotFound::withId($command->documentId);
}
if ($document->getStatus() !== SalesDocumentStatus::Draft) {
    throw SalesDocumentTransitionNotAllowed::cannotApprove($document->getStatus());
}
```

### Krok 3 — kontroler: mapowanie + repozytorium

`src/Controller/SalesDocumentController.php`:

```php
public function __construct(
    private readonly MessageBusInterface $commandBus,
    private readonly SalesDocumentRepository $repository,   // było: EntityManagerInterface
) {}

public function approve(int $id, Request $request): JsonResponse
{
    // ...
    try {
        $envelope = $this->commandBus->dispatch(new ApproveSalesDocument($id, $approvedBy));
        $resultId = $envelope->last(HandledStamp::class)->getResult();
    } catch (HandlerFailedException $e) {
        return $this->mapDomainFailure($e);
    }

    $document = $this->repository->find($resultId);            // repozytorium, nie SQL

    return new JsonResponse([
        'id' => $document->getId(),
        'type' => $document->getType()->value,
        'status' => $document->getStatus()->value,
        'parent_quote_id' => $document->getParentQuoteId(),
    ]);
}

/**
 * Anything we do not explicitly recognise is genuinely unexpected and is
 * re-thrown so the framework turns it into a 500 (without leaking the raw
 * exception message to the client).
 */
private function mapDomainFailure(HandlerFailedException $e): JsonResponse
{
    $cause = $e->getPrevious() ?? $e;

    return match (true) {
        $cause instanceof SalesDocumentNotFound              => new JsonResponse(['error' => $cause->getMessage()], 404),
        $cause instanceof SalesDocumentTransitionNotAllowed  => new JsonResponse(['error' => $cause->getMessage()], 409),
        default                                              => throw $e,
    };
}
```

Kluczowe decyzje:

- łapię **`HandlerFailedException`**, nie `\Throwable` — wyjątki spoza busa (np. błąd
  serializacji requestu) nie są tu maskowane;
- **`default => throw $e`** — nierozpoznany błąd nie jest „udawany" jako obsłużony; leci dalej
  i framework robi z niego 500. W `prod` Symfony **nie** pokazuje treści wyjątku, więc znika
  wyciek z punktu (1);
- odpowiedź budowana z **encji** (`getType()->value`, `getParentQuoteId(): ?int`) — spójne
  typy w JSON, zero wiedzy o schemacie w kontrolerze;
- `409` (Conflict) dla kolizji stanu; `TASK.MD` dopuszcza też `422` — wybrałem `409`, bo
  problem nie jest w treści żądania, tylko w tym, że stan zasobu wyklucza tę operację.

### Krok 4 — testy

`tests/Functional/SalesDocumentControllerTest.php`:

- `testApprovingMissingDocumentCurrentlyReturns500` → **`testApprovingMissingDocumentReturns404`**,
  `assertResponseStatusCodeSame(404)` (zgodnie z `TASK.MD` pkt 2 — zmiana oczekiwana);
- dodany **`testApprovingAnAlreadyApprovedDocumentReturns409`** — create → approve (200) →
  approve (409);
- `testCreateAndApproveThroughHttp` — **bez zmian** (asercje nietknięte).

### Krok 5 — weryfikacja

```
✔ Create and approve through http
✔ Approving missing document returns 404
✔ Approving an already approved document returns 409
```

HTTP:

```
POST /sales-documents/999999/approve         -> 404
POST /sales-documents/1/approve (2. raz)     -> 409  {"error":"Document cannot be approved in its current status (approved)"}
```

---

## 4. Pliki zmienione w ramach problemu 2

| Plik | Zmiana |
|---|---|
| `src/Exception/SalesDocumentNotFound.php` | **nowy** — 404 |
| `src/Exception/SalesDocumentTransitionNotAllowed.php` | **nowy** — 409 |
| `src/MessageHandler/ApproveSalesDocumentHandler.php` | `\RuntimeException` → wyjątki domenowe |
| `src/Controller/SalesDocumentController.php` | `catch (HandlerFailedException)` + `mapDomainFailure()` (404/409/re‑throw); `EntityManagerInterface` + surowy SQL → `SalesDocumentRepository::find()` + encja |
| `tests/Functional/SalesDocumentControllerTest.php` | 500→404 (rename + asercja), dodany test 409 |

Ten sam plik kontrolera i testu niesie też poprawkę problemu 3 — patrz `TASK-3.md`.
