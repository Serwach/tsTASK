# Sales Documents — rozwiązanie zadania

CQRS (Command → Handler przez `command.bus`, Symfony Messenger sync). Poniżej: jak uruchomić,
natura i naprawa czterech problemów z `TASK.MD`, oraz świadome kompromisy. Szczegóły diagnozy
i decyzji są w treści commitów `problem 1` … `problem 4`.

## Uruchomienie

Wymagany tylko **Docker + Docker Compose** (PHP/Composer są w kontenerze).

```bash
make build && make up      # aplikacja: http://localhost:8080
make test                  # pełny zestaw testów
```

Entrypoint kontenera `php` przy starcie sam robi: `composer install`, utworzenie baz `app` i
`app_test`, migracje (`dev` i `test`).

Środowisko dodane w `docker/` + `compose.yaml` (`database` postgres:16 · `php` php-fpm ·
`nginx` :8080). Xdebug jest w obrazie, domyślnie wyłączony (`XDEBUG_MODE=off`) — włączenie:
`XDEBUG_MODE=debug docker compose up -d`.

## Wersje

- **PHP 8.4.** `composer.json` deklaruje `>=8.2`, ale `composer.lock` przypina `phpunit` 13 /
  `sebastian/*` / `doctrine/instantiator` wymagające **≥ 8.4** — `composer install` na 8.2/8.3
  się nie powiedzie. To niespójność w skeletonie; nie ruszałem `composer.json`/`.lock`, obraz
  dostarcza 8.4.
- **Symfony 7.4 — zostaję.** Jest już 8.0, ale 7.4 to LTS (wsparcie ~3–4 lata), nic z 8.0 nie
  jest w tym zadaniu potrzebne, a aktualizacja to koszt i ryzyko regresji bez korzyści.

## Problem 1 — zatwierdzenie zapisane w bazie, klient dostaje 500

Handler commitował transakcję zatwierdzenia, a **potem** wysyłał powiadomienia. Awaria
nietransakcyjnego kanału powiadomień wychodziła z handlera → `HandlerFailedException` → 500,
mimo że dokument (i spawnowany order) były już trwale zapisane. Klient ponawiał → guard
„status ≠ Draft" → kolejne 500.

**Naprawa** (bez drugiej kolejki, zgodnie z `TASK.MD`): `App\Notification\ApprovalNotifier` —
wysyła powiadomienia po commicie, każde w osobnym `try/catch` z logowaniem. Awaria kanału jest
kontenerowana; awaria powiadomienia do jednej strony nie blokuje drugiej.
`testApprovalDoesNotFailTheCallerWhenTheNotificationChannelFails` — zielony, asercje nietknięte.

## Problem 2 — kontroler mapuje każdy błąd na 500 i omija repozytorium

`approve()` robił `catch (\Throwable)` → 500 + surowa treść wyjątku dla wszystkiego (złe ID,
zły stan, realna awaria). Czytał też dokument ręcznym `SELECT`-em zamiast przez repozytorium.

**Naprawa:**

- wyjątki domenowe `App\Exception\SalesDocumentNotFound` (→ 404) i
  `SalesDocumentTransitionNotAllowed` (→ 409); handler rzuca je zamiast gołego `\RuntimeException`;
- ręczny SQL → `SalesDocumentRepository::find()` + serializacja encji;
- walidacja wejścia: brak / niepoprawny `approved_by` (oraz `contractor_id` / `created_by`) →
  **400**, zamiast po cichu zapisywać `0`;
- **nierozpoznany błąd nie jest re-rzucany** — kontroler łapie go jawnie (`catch (\Throwable)`
  jako siatka bezpieczeństwa obok `catch (HandlerFailedException)`), loguje z kontekstem
  (`operation`, `documentId`, `exception`) i odpowiada generycznym `{"error": "Internal server
  error"}` (500). Wcześniejsza wersja tego re-rzucała, licząc na domyślne zachowanie frameworka —
  ale `APP_ENV=dev` w tym projekcie oznacza `APP_DEBUG=1`, więc realnie wyciekała pełna strona
  debugowa Symfony ze stack trace'em (zweryfikowane curlem: `POST
  /sales-documents/99999999999999999999999/approve`). Pokryte testem
  `testAnUnexpectedFailureFromTheCommandBusReturnsASafeGenericErrorAndIsLogged` (mockuje
  `MessageBusInterface::dispatch()` rzucający wyjątek, asertuje treść odpowiedzi i wywołanie loggera);
- ten sam segment URL-a (`{id}`) ujawnił drugi wariant tego problemu: `int $id` w sygnaturze
  metody powodował `TypeError` (i tę samą wyciekającą stronę debugową) dla ID, które nie mieści
  się w PHP `int` — ten błąd powstaje w resolverze argumentów Symfony, **zanim** kod kontrolera
  w ogóle się wykona, więc żaden `try/catch` wewnątrz metody go nie złapie. Naprawione zmianą
  sygnatury na `string $id` i jawnym parsowaniem przez `readId()` (który teraz używa
  `FILTER_VALIDATE_INT`, więc odrzuca też przepełnienie) — błędny/zbyt duży `id` traktowany jest
  jako "dokument nie istnieje" → 404. Pokryte testem
  `testApprovingWithAnOverflowingIdReturns404InsteadOfLeakingAStackTrace`.

Test `…CurrentlyReturns500` → `…Returns404`; dodane testy 409, 400, oraz oba testy bezpieczeństwa
opisane wyżej.

## Problem 3 — zamienione `contractor_id` i `created_by`

**Przyczyna:** helper `SalesDocumentController::resolveDocumentOwnership()` mapował payload z
odwróconymi polami właściciela.

**Trop diagnozy:**

1. Zgłoszenie: „nowo utworzone dokumenty", „nie widać w testach". Testy handlerowe
   (`dispatch(new CreateSalesDocument(...))`) są zielone → handler i encja OK → problem w
   warstwie HTTP.
2. `curl POST /sales-documents {"contractor_id":111,"created_by":222}` → w bazie
   `contractor_id=222, created_by=111`. Zamiana między requestem a zapisem.
3. Xdebug: breakpoint w `create()`, step into `resolveDocumentOwnership()` → payload poprawny,
   wartość zwracana zamieniona.
4. Dlaczego „nie za każdym razem": tylko ścieżka HTTP create; jedyny test HTTP nie asertował
   pól właściciela; spawnowany order kopiuje `contractorId` z już-zamienionej oferty; przy
   `contractor_id == created_by` zamiana jest niewidoczna.

**Naprawa:** helper (który tylko przepakowywał i zamieniał) usunięty; payload → komenda wprost.
Test regresyjny `testCreatedDocumentKeepsThePayloadOwnership` (`111 ≠ 222`).

## Problem 4 — operacja `RejectSalesDocument`

Zaimplementowane z `tests/Functional/RejectSalesDocumentHandlerTest.php`, w tym samym stylu co
`ApproveSalesDocument`:

- `RejectSalesDocument(documentId, rejectedBy)` + `RejectSalesDocumentHandler` na `command.bus`
  — `wrapInTransaction`, guardy „nie znaleziono" i „nie Draft" reużywają wyjątków z problemu 2;
- `SalesDocumentStatus::Rejected` (kolumna `VARCHAR` — bez migracji dla samego enuma);
- pola audytu `rejected_by` / `rejected_at` (symetrycznie do `approved_by/at`) + migracja
  `Version20260830000000` (kolumny `nullable`);
- endpoint `POST /sales-documents/{id}/reject` z tym samym mapowaniem błędów (400/404/409).

## Świadome kompromisy / poza zakresem

Zakres zadania to cztery konkretne bugfixy w małym serwisie CQRS — poniższe są znane i
świadomie odłożone:

- **Współbieżność.** Dwa równoległe `approve` mogą oba odczytać `Draft` i utworzyć po jednym
  zamówieniu; wyścig approve–reject może zostawić w rekordzie dane obu operacji. Sensowne
  minimum na przyszłość: optimistic lock (`#[ORM\Version]`) + unikalny constraint na
  `parent_quote_id`, ewentualnie warunkowy `UPDATE ... WHERE status='draft'`. Nie dokładam
  tego „na siłę" — to zmiana wykraczająca poza treść zadania.
- **Idempotencja endpointów** (retry klienta nie tworzy drugiego zamówienia) — jw.
- **Gwarantowana dostawa powiadomień** — jeśli powiadomienie jest wymaganiem biznesowym, samo
  logowanie nie wystarczy; wtedy outbox + konsumer. Tu traktuję je jako best-effort (zgodnie z
  podpowiedzią „bez drugiej kolejki").
- **Centralny exception listener** zamiast `match` w kontrolerze — sensowne przy większym API,
  przy trzech endpointach `match` jest czytelniejszy.
- **`ClockInterface`** zamiast `new \DateTimeImmutable()` — deterministyczny czas w testach;
  nieistotne przy tym zakresie.
- **PHPStan / CI** — nie dokładam pipeline'u do zadania rekrutacyjnego; kontrakty nullowalności
  w miejscach po `find()` są pilnowane `assert()`.

## Testy

```
OK (17 tests, 47 assertions)
```

Dostarczone testy (`ApproveSalesDocumentTest`, `RejectSalesDocumentHandlerTest`) — bez zmian.
Happy-path `testCreateAndApproveThroughHttp` i `testApprovingAQuoteSpawnsALinkedOrder…` —
zielone, asercje nietknięte. `testApprovingMissingDocument…` zaktualizowany do 404 zgodnie z
`TASK.MD`. Dodane: 409 (approve i reject), 400 (walidacja), round-trip `/reject` z asercją pól
audytu, regresja zamiany właścicieli, jednostkowy `ApprovalNotifierTest`, oraz dwa testy
bezpieczeństwa błędów opisane w sekcji „Problem 2" (generic 500 przy nieoczekiwanym wyjątku z
`command.bus` + logowanie, 404 dla ID przepełniającego `int`).

Linia `[error] Approval notification failed` na stderr podczas testów to celowo wywołana
awaria kanału w teście flaky — nie błąd (`OK`).

## Mapa zmian

Nowe: `src/Notification/ApprovalNotifier.php`, `src/Exception/SalesDocument{NotFound,TransitionNotAllowed}.php`,
`src/Message/Command/RejectSalesDocument.php`, `src/MessageHandler/RejectSalesDocumentHandler.php`,
`migrations/Version20260830000000.php`, `tests/Unit/Notification/ApprovalNotifierTest.php`.

Zmienione: `SalesDocumentController.php` (P2 mapowanie + walidacja, P3 brak swapa, P4 endpoint),
`ApproveSalesDocumentHandler.php` (P1 notifier, P2 wyjątki), `SalesDocumentStatus.php` (+`Rejected`),
`SalesDocument.php` (+`rejectedBy/At`), `SalesDocumentControllerTest.php`.

Historia commitów uporządkowana pod review: jeden problem = jeden commit.
