# Sales Documents — rozwiązanie zadania

System tworzy i zatwierdza dokumenty sprzedażowe (oferty i zamówienia) w architekturze
**CQRS** (Command → Handler przez `command.bus`, Symfony Messenger, sync).

Ten dokument opisuje naturę wszystkich czterech problemów z `TASK.MD` i sposób ich
rozwiązania. Szczegółowy, krok‑po‑kroku przebieg diagnozy i naprawy każdego z nich jest w
osobnych plikach: **`TASK-1.md`**, **`TASK-2.md`**, **`TASK-3.md`**, **`TASK-4.md`**.

---

## Uruchomienie

Wymagany tylko **Docker + Docker Compose** (PHP/Composer są w kontenerze).

```bash
make build && make up          # aplikacja: http://localhost:8080
make test                      # pełny zestaw testów
```

Bez `make`:

```bash
docker compose build
docker compose up -d
docker compose exec php php bin/phpunit
```

Entrypoint kontenera `php` sam wykonuje przy starcie: `composer install`, utworzenie baz
`app` i `app_test`, migracje (`dev` i `test`).

Przydatne:

```bash
make sh                                                   # shell w kontenerze php
docker compose exec database psql -U app -d app -c "SELECT * FROM sales_document ORDER BY id"
XDEBUG_MODE=debug docker compose up -d                    # Xdebug (domyślnie wyłączony, zero narzutu)
docker compose down -v                                    # stop + kasuje dane
```

### Środowisko Docker (dodane)

| Plik | Rola |
|---|---|
| `docker/php/Dockerfile` | `php:8.4-fpm-alpine` + `intl`, `pdo_pgsql`, `opcache`, **Xdebug**, Composer 2 |
| `docker-compose.yml` | `database` (postgres:16) + `php` (php‑fpm) + `nginx` (:8080) |
| `docker/php/entrypoint.sh` | bootstrap: composer install, bazy, migracje |
| `docker/nginx/default.conf`, `docker/php/conf.d/*`, `Makefile` | konfiguracja / skróty |

Zmiany w skeletonie: `.env` i `.env.test` — host bazy `127.0.0.1` → `database` (nazwa serwisu
w sieci Compose). Usunięty `compose.yaml` (miał tylko `database`; przy dwóch plikach Compose
i tak wygrywa `compose.yaml`, więc `docker-compose.yml` zostałby zignorowany).

---

## Decyzje techniczne

### PHP 8.4

Obraz używa **PHP 8.4**, mimo że `composer.json` deklaruje `"php": ">=8.2"`. Powód:
`composer.lock` przypina m.in. `phpunit/phpunit` 13, `sebastian/*`, `doctrine/instantiator`,
które wymagają **PHP ≥ 8.4** — `composer install` na 8.2/8.3 się nie powiedzie. To
niespójność w samym skeletonie; nie ruszałem `composer.json`/`composer.lock`, żeby nie
wywoływać przeliczenia zależności — obraz po prostu dostarcza 8.4.

### Symfony 7.4 — zostaję, bez aktualizacji

`composer.json` przypina `7.4.*`. Dostępny jest już Symfony 8.0, ale **świadomie nie
aktualizuję**:

- **7.4 to LTS** (wsparcie na błędy ~3 lata, bezpieczeństwo ~4 lata) — dłuższy horyzont niż
  standardowe wsparcie 8.x;
- żadna funkcja z 8.0 nie jest w tym zadaniu potrzebna — aktualizacja to czysty koszt i
  ryzyko regresji przy zerowej korzyści;
- mniejsza powierzchnia do review — zmiany zostają skupione na czterech problemach, a nie na
  migracji frameworka.

---

## Problem 1 — „zatwierdzenie wygląda na nieudane (500), a w bazie jest zatwierdzone"

**Natura.** `ApproveSalesDocumentHandler` commituje transakcję zapisu, a **potem** wysyła
powiadomienia (`notifier->notify()` ×2). Gdy kanał powiadomień zawiedzie, wyjątek wychodzi z
handlera → Messenger opakowuje go w `HandlerFailedException` → kontroler zwraca **500** —
mimo że zatwierdzenie (i spawnowany order) są już trwale w bazie. Klient widzi błąd i ponawia
→ trafia w guard „status ≠ Draft" → kolejne 500.

Powiadomienie to **best‑effort side effect wykonywany po commicie** — z natury nietransakcyjny
(nie da się „cofnąć" maila) i zawodny. Sprzęgnięcie sukcesu komendy z jego wynikiem to błąd.

**Naprawa** (bez drugiej szyny/kolejki — zgodnie z `TASK.MD`). Nowa usługa
`App\Notification\ApprovalNotifier`: wysyła powiadomienia po commicie, każde w osobnym
`try/catch` z logowaniem błędu. Awaria kanału jest kontenerowana, nie propaguje do wołającego;
awaria powiadomienia do jednej strony nie blokuje drugiej. Handler woła
`approvalNotifier->documentApproved($document)` zamiast dwóch `notify()`.

Test `testApprovalDoesNotFailTheCallerWhenTheNotificationChannelFails` — z czerwonego na
zielony, bez zmiany asercji.

> Szczegóły, reprodukcja przez curl, ścieżka wyjątku: **`TASK-1.md`**.

---

## Problem 2 — kontroler mapuje każdy błąd na 500 i omija repozytorium

**Natura.** `SalesDocumentController::approve()` robił `catch (\Throwable)` → `500` +
`$e->getMessage()` dla **wszystkiego**: nieistniejące ID, zła próba przejścia stanu, realny
błąd serwera. Klient nie odróżni swojej winy (4xx) od awarii serwera (5xx), a w odpowiedzi
dostaje surową treść wyjątku (wyciek szczegółów implementacji). Dodatkowo metoda czytała
dokument **surowym `SELECT`‑em**, choć `SalesDocumentRepository` jest wstrzyknięty.

**Naprawa.**

- wyjątki domenowe: `App\Exception\SalesDocumentNotFound` (→ **404**),
  `App\Exception\SalesDocumentTransitionNotAllowed` (→ **409**); handler rzuca je zamiast
  gołego `\RuntimeException`;
- kontroler łapie `HandlerFailedException`, rozpakowuje przyczynę (`getPrevious()`) i mapuje
  przez `match`; **wszystko nierozpoznane jest re‑rzucane** → framework robi 500 bez wycieku
  treści (w `prod`);
- surowy SQL → `SalesDocumentRepository::find()` + serializacja encji (spójne typy w JSON);
  `EntityManagerInterface` znika z konstruktora kontrolera.

Testy: `testApprovingMissingDocumentCurrentlyReturns500` → **`testApprovingMissingDocumentReturns404`**
(asercja 404 — zmiana oczekiwana wg `TASK.MD`); dodany
`testApprovingAnAlreadyApprovedDocumentReturns409`.

> Szczegóły: **`TASK-2.md`**.

---

## Problem 3 — „pomieszani właściciele: kontrahent i twórca zamienieni, ale nie zawsze"

**Natura.** `SalesDocumentController` miał prywatny helper `resolveDocumentOwnership()` z
**jawnie odwróconym mapowaniem**:

```php
'contractorId' => (int) $payload['created_by'],     // odwrotnie
'createdBy'    => (int) $payload['contractor_id'],   // odwrotnie
```

`CreateSalesDocumentHandler` i encja są poprawne — błąd był wyłącznie w tym helperze.

**Jak zdiagnozowałem (trop):**

1. **Zawężenie.** Dokumenty powstają dwiema drogami: HTTP `POST /sales-documents` oraz
   bezpośredni `dispatch(new CreateSalesDocument(...))`. Testy handlerowe używają tylko tej
   drugiej i są zielone → handler i encja OK → problem jest w warstwie HTTP (kontroler).
2. **Reprodukcja** rozróżnialnymi wartościami: `curl POST` z `{"contractor_id":111,"created_by":222}`
   → w bazie `contractor_id=222, created_by=111`. Zamiana zachodzi między requestem a zapisem.
3. **Xdebug** (zalecany w `TASK.MD`): breakpoint w `create()`, step into `resolveDocumentOwnership()`
   → `$payload` poprawny, wartość zwracana zamieniona. Dokładnie ta linia.
4. **Dlaczego „nie za każdym razem" i niewidoczne w testach:**
   - dotyczy **tylko ścieżki HTTP create** — testy handlerowe ją omijają;
   - jedyny test HTTP (`testCreateAndApproveThroughHttp`) nie asertuje pól właściciela;
   - przy zatwierdzeniu oferty spawnowany order kopiuje `contractorId` z już‑zamienionej
     oferty, a `createdBy = approvedBy` — dokumenty potomne są „inaczej źle";
   - gdy integracja wyśle `contractor_id == created_by`, zamiana jest niewidoczna.
5. **Weryfikacja braku innych wywołań:** `git grep resolveDocumentOwnership` → 1 definicja,
   1 użycie. Helper nie robił nic poza przepakowaniem kluczy i zamianą.

**Naprawa.** Helper usunięty; payload → komenda mapowane wprost
(`contractor_id` → `contractorId`, `created_by` → `createdBy`). Dodany test regresyjny
round‑tripu HTTP `testCreatedDocumentKeepsThePayloadOwnership` (`111 ≠ 222`).

> Szczegóły: **`TASK-3.md`**.

---

## Problem 4 — nowa operacja `RejectSalesDocument` (z samego testu)

**Natura.** `tests/Functional/RejectSalesDocumentHandlerTest.php` istniał, ale
`RejectSalesDocument` / `RejectSalesDocumentHandler` — nie.

**Implementacja, w tym samym stylu co `ApproveSalesDocument`:**

- `App\Message\Command\RejectSalesDocument(int $documentId, int $rejectedBy)`;
- `App\MessageHandler\RejectSalesDocumentHandler` `#[AsMessageHandler(bus: 'command.bus')]` —
  `wrapInTransaction`, guard „nie znaleziono" (`SalesDocumentNotFound`), guard „zły stan"
  (`SalesDocumentTransitionNotAllowed::cannotReject`, `extends \RuntimeException` → test
  `expectException(\RuntimeException::class)` przechodzi), ustawia status i zwraca `id`;
- `SalesDocumentStatus::Rejected` (kolumna `VARCHAR` — bez migracji dla samego enuma);
- kolumny audytu `rejected_by` / `rejected_at` symetrycznie do `approved_by` / `approved_at`
  + migracja `Version20260830000000` (kolumny `nullable`);
- endpoint HTTP `POST /sales-documents/{id}/reject` — to samo mapowanie błędów co `approve`
  (404 / 409).

Świadomie pominięte: powiadomienia przy odrzuceniu (test ich nie wymaga; wzorzec
`ApprovalNotifier` jest gotowy do reużycia w razie potrzeby).

> Szczegóły: **`TASK-4.md`**.

---

## Testy

```bash
make test        # albo: docker compose exec php php bin/phpunit
```

Stan końcowy — **wszystkie zielone**:

```
OK (8 tests, 20 assertions)

Approve Sales Document
 ✔ Approving a quote spawns a linked order and notifies both parties      (happy-path, asercje nietknięte)
 ✔ Approval does not fail the caller when the notification channel fails   (problem 1)
Reject Sales Document Handler
 ✔ Rejecting a draft quote marks it rejected                              (problem 4)
 ✔ Rejecting an already approved document is rejected by the domain       (problem 4)
Sales Document Controller
 ✔ Create and approve through http                                        (happy-path, asercje nietknięte)
 ✔ Approving missing document returns 404                                 (problem 2)
 ✔ Approving an already approved document returns 409                      (problem 2, dodany)
 ✔ Created document keeps the payload ownership                           (problem 3, dodany)
```

Podczas testu `…NotificationChannelFails` na stderr pojawia się linia
`[error] Approval notification failed` — to **celowo wywołana** awaria kanału powiadomień,
złapana i zalogowana przez `ApprovalNotifier`. Nie jest to błąd testu (`OK`).

### „Na co zwrócić uwagę" (z `TASK.MD`) — status

| Wymóg | Status |
|---|---|
| happy‑path `testApprovingAQuoteSpawnsALinkedOrder…` zielony, asercje nietknięte | ✔ |
| happy‑path `testCreateAndApproveThroughHttp` zielony, asercje nietknięte | ✔ |
| problem 1 bez drugiej szyny/kolejki asynchronicznej | ✔ (`try/catch` + log) |
| decyzja o wersji Symfony uzasadniona w README | ✔ (zostaję na 7.4 LTS) |
| problem 3 — projekt odpalony, użyty Xdebug, opisany trop | ✔ (sekcja wyżej + `TASK-3.md`) |
| `testApprovingMissingDocument…` zaktualizowany do poprawionego zachowania | ✔ (→ 404) |

---

## Mapa zmian w kodzie

**Nowe pliki**

```
src/Notification/ApprovalNotifier.php                 # problem 1
src/Exception/SalesDocumentNotFound.php               # problem 2
src/Exception/SalesDocumentTransitionNotAllowed.php   # problem 2
src/Message/Command/RejectSalesDocument.php           # problem 4
src/MessageHandler/RejectSalesDocumentHandler.php     # problem 4
migrations/Version20260830000000.php                  # problem 4
```

**Zmienione pliki**

```
src/MessageHandler/ApproveSalesDocumentHandler.php    # problem 1 (ApprovalNotifier) + 2 (wyjątki domenowe)
src/Controller/SalesDocumentController.php            # problem 2 (mapowanie, repozytorium) + 3 (brak swapa) + 4 (endpoint reject)
src/Enum/SalesDocumentStatus.php                      # problem 4 (case Rejected)
src/Entity/SalesDocument.php                          # problem 4 (rejectedBy / rejectedAt)
tests/Functional/SalesDocumentControllerTest.php      # problem 2 (500→404, +409) + 3 (+ownership)
```

Dostarczone testy (`ApproveSalesDocumentTest`, `RejectSalesDocumentHandlerTest`) — **bez
zmian**.
