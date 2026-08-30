# Problem 3 — „pomieszane dane właściciela: kontrahent i twórca zamienieni miejscami, ale nie zawsze"

## TL;DR

`SalesDocumentController` miał prywatny helper `resolveDocumentOwnership()` z **jawnie
odwróconym mapowaniem** pól:

```php
'contractorId' => (int) $payload['created_by'],     // <-- odwrotnie
'createdBy'    => (int) $payload['contractor_id'],   // <-- odwrotnie
```

Dotyczyło to **tylko ścieżki HTTP `POST /sales-documents`**. Testy handlerowe dispatchują
`CreateSalesDocument` bezpośrednio (omijają kontroler), a jedyny test HTTP nie sprawdzał pól
właściciela — dlatego „nie widać tego w żadnym z testów", a w raportach „nie za każdym razem".

Naprawa: helper nie miał żadnego sensownego zadania (tylko przepakowywał klucze i je zamieniał)
— usunięty; pola z payloadu idą wprost do komendy z zachowaniem znaczenia. Dodany test
regresyjny round‑tripu HTTP.

---

## 1. Natura błędu

### Kod przed zmianą

```php
public function create(Request $request): JsonResponse
{
    $payload = json_decode($request->getContent(), true);

    if (empty($payload['contractor_id']) || empty($payload['created_by'])) {
        return new JsonResponse(['error' => 'Missing fields'], 400);
    }

    $ids = $this->resolveDocumentOwnership($payload);          // (!)

    $envelope = $this->commandBus->dispatch(new CreateSalesDocument(
        contractorId: $ids['contractorId'],
        createdBy: $ids['createdBy'],
    ));
    // ...
}

/** @return array{contractorId: int, createdBy: int} */
private function resolveDocumentOwnership(array $payload): array
{
    return [
        'contractorId' => (int) $payload['created_by'],        // kontrahent <- twórca
        'createdBy'    => (int) $payload['contractor_id'],      // twórca <- kontrahent
    ];
}
```

Payload `{"contractor_id": 111, "created_by": 222}` → w bazie `contractor_id = 222,
created_by = 111`. `CreateSalesDocumentHandler` i encja `SalesDocument` są **poprawne** —
błąd jest wyłącznie w tym helperze kontrolera. Nazwa `resolveDocumentOwnership` brzmi
niewinnie („ustal właścicielstwo dokumentu"), co maskuje, że to po prostu swap.

### Dlaczego „nie za każdym razem" i niewidoczne w testach

1. **Tylko ścieżka HTTP `create`.** Dokumenty powstają dwiema drogami:
   - `POST /sales-documents` → kontroler → **swap**;
   - `commandBus->dispatch(new CreateSalesDocument(...))` bezpośrednio → **bez swapa**.
   Testy `ApproveSalesDocumentTest` i `RejectSalesDocumentHandlerTest` używają wyłącznie drugiej
   drogi (`KernelTestCase` + `dispatch`), więc nigdy nie przechodzą przez wadliwy kod.

2. **Jedyny test HTTP nie asertuje właściciela.** `testCreateAndApproveThroughHttp` sprawdza
   tylko `type` (`'order'`) i `parent_quote_id` — nigdy `contractor_id` / `created_by`. Jest
   zielony mimo swapa.

3. **Propagacja zmienia kształt.** Przy zatwierdzeniu oferty spawnowany `order` bierze
   `contractorId` ze (już zamienionej) oferty, a `createdBy = approvedBy`. Więc dokumenty
   potomne są „źle, ale inaczej źle" — dokładnie „jakby zamienione miejscami, ale nie za
   każdym razem".

4. **Czasem swap jest niewidoczny.** Jeśli integracja wyśle `contractor_id == created_by`,
   albo jedno z pól jest tym samym userem, zamiana niczego nie zmienia. Stąd „część nowo
   utworzonych dokumentów" w zgłoszeniu supportu.

---

## 2. Jak zdiagnozowałem — jakim tropem poszedłem

### Krok 1 — zawężenie: która droga tworzenia jest wadliwa

Zgłoszenie mówi „nowo utworzone dokumenty" + „nie widać w testach". Dokumenty tworzy
`CreateSalesDocument` / jego handler. Skoro testy handlera (`ApproveSalesDocumentTest`
tworzy ofertę przez `dispatch(new CreateSalesDocument(contractorId: 77, createdBy: 5))`
i potem sprawdza `getContractorId()` pośrednio) są zielone — **handler i encja są OK**.
Zostaje warstwa nad handlerem: kontroler HTTP.

### Krok 2 — reprodukcja przez HTTP z rozróżnialnymi wartościami

```bash
curl -sS -X POST http://localhost:8080/sales-documents \
  -H 'Content-Type: application/json' -d '{"contractor_id":111,"created_by":222}'
# => {"id":1}

docker compose exec database psql -U app -d app \
  -c "SELECT id, contractor_id, created_by FROM sales_document ORDER BY id"
```

```
 id | contractor_id | created_by
----+---------------+------------
  1 |           222 |        111      <-- payload miał 111 / 222
```

Potwierdzone: zamiana zachodzi między requestem a zapisem, czyli w kontrolerze.

### Krok 3 — Xdebug: dokładne miejsce

`XDEBUG_MODE=debug docker compose up -d`, breakpoint w `SalesDocumentController::create()`
na linii `$ids = $this->resolveDocumentOwnership($payload);`:

- `$payload` = `['contractor_id' => 111, 'created_by' => 222]` — **poprawnie**;
- step into `resolveDocumentOwnership()` → widać `'contractorId' => (int) $payload['created_by']`
  — zwraca `['contractorId' => 222, 'createdBy' => 111]` — **zamienione**;
- dalej `new CreateSalesDocument(contractorId: 222, createdBy: 111)` → handler zapisuje
  wiernie to, co dostał.

### Krok 4 — czy helper ma jakiekolwiek uzasadnienie / inne wywołania

```bash
git grep -n resolveDocumentOwnership
# src/Controller/SalesDocumentController.php: 1 wywołanie, 1 definicja
```

Helper nie robi nic poza przepakowaniem `snake_case` → `camelCase` i **zamianą**. Żadnej
walidacji, normalizacji, lookupu. Czyli: czysty bug do usunięcia, nie „logika do poprawienia".

### Krok 5 — dlaczego testy tego nie łapały (potwierdzenie hipotezy z sekcji 1)

- `grep -rn "createdBy\|contractorId\|contractor_id\|created_by" tests/` → w `ApproveSalesDocumentTest`
  wartości `contractorId: 77, createdBy: 5` idą przez `dispatch`, nie przez HTTP;
- `testCreateAndApproveThroughHttp` asertuje wyłącznie `type` i `parent_quote_id`.

---

## 3. Jak naprawiłem — krok po kroku

### Krok 1 — usunięcie helpera, bezpośrednie mapowanie

`src/Controller/SalesDocumentController.php`:

```php
// przed:
$ids = $this->resolveDocumentOwnership($payload);
$envelope = $this->commandBus->dispatch(new CreateSalesDocument(
    contractorId: $ids['contractorId'],
    createdBy: $ids['createdBy'],
));

// po (helper skasowany w całości):
$envelope = $this->commandBus->dispatch(new CreateSalesDocument(
    contractorId: (int) $payload['contractor_id'],
    createdBy: (int) $payload['created_by'],
));
```

Nazwane argumenty komendy + nazwy pól payloadu czytają się teraz 1:1 — nie ma gdzie schować
zamiany.

### Krok 2 — test regresyjny (round‑trip HTTP)

`tests/Functional/SalesDocumentControllerTest.php`:

```php
public function testCreatedDocumentKeepsThePayloadOwnership(): void
{
    $this->client->request('POST', '/sales-documents',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['contractor_id' => 111, 'created_by' => 222]));
    self::assertResponseStatusCodeSame(201);
    $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

    /** @var SalesDocumentRepository $repository */
    $repository = self::getContainer()->get(SalesDocumentRepository::class);
    $document = $repository->find($id);

    self::assertSame(111, $document->getContractorId());
    self::assertSame(222, $document->getCreatedBy());
}
```

Wartości `111 != 222` — gdyby swap wrócił, test od razu czerwony. Test celowo idzie **przez
HTTP** (a nie przez `dispatch`), bo tylko ta ścieżka była wadliwa.

### Krok 3 — weryfikacja

```
✔ Created document keeps the payload ownership
```

HTTP po poprawce:

```
POST /sales-documents  {"contractor_id":111,"created_by":222}
 id | contractor_id | created_by
----+---------------+------------
  1 |           111 |        222
```

---

## 4. Pliki zmienione w ramach problemu 3

| Plik | Zmiana |
|---|---|
| `src/Controller/SalesDocumentController.php` | usunięty `resolveDocumentOwnership()`; payload → komenda mapowane wprost (`contractor_id` → `contractorId`, `created_by` → `createdBy`) |
| `tests/Functional/SalesDocumentControllerTest.php` | dodany `testCreatedDocumentKeepsThePayloadOwnership` |

Ten sam plik kontrolera i testu niesie też poprawkę problemu 2 — patrz `TASK-2.md`.
