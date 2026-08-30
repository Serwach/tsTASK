# Problem 1 — „zatwierdzenie wygląda na nieudane (500), a w bazie dokument jest zatwierdzony"

## TL;DR

`ApproveSalesDocumentHandler` najpierw commitował transakcję zapisu, a **dopiero potem**
wysyłał powiadomienia. Gdy kanał powiadomień zawiódł, wyjątek wychodził z handlera,
Messenger opakowywał go w `HandlerFailedException`, a kontroler zwracał **HTTP 500** —
mimo że zatwierdzenie było już trwale zapisane w bazie. Naprawa: powiadomienia to
best‑effort side effect po commicie; ich błąd jest teraz łapany i logowany, nigdy nie
propaguje do wołającego. Bez drugiej szyny/kolejki asynchronicznej.

Test, który to pokrywa: `tests/Functional/ApproveSalesDocumentTest.php::testApprovalDoesNotFailTheCallerWhenTheNotificationChannelFails`
(przed zmianą czerwony, po zmianie zielony — treść asercji nietknięta).

---

## 1. Natura błędu

### Kod przed zmianą

`ApproveSalesDocumentHandler::__invoke` wykonywał dwa etapy po kolei:

```php
// ETAP A — zmiana stanu w transakcji
$approvedId = $this->entityManager->wrapInTransaction(function () use ($command) {
    $document = $this->repository->find($command->documentId);
    // walidacja: istnieje? status == Draft?
    $document->setStatus(SalesDocumentStatus::Approved);
    $document->setApprovedBy($command->approvedBy);
    $document->setApprovedAt(new \DateTimeImmutable());
    $document->setSellerSnapshot($this->buildSellerSnapshot($document));

    if ($document->getType() === SalesDocumentType::Quote) {
        // utwórz powiązany order, persist + flush
        $approvedId = $order->getId();
    }
    return $approvedId;
});
// <<< transakcja jest już ZACOMMITOWANA — stan w bazie jest TRWAŁY

// ETAP B — powiadomienia (side effect)
$approvedDocument = $this->repository->find($approvedId);
$this->notifier->notify($approvedDocument->getCreatedBy(),  "Document #… has been approved");
$this->notifier->notify($approvedDocument->getContractorId(), "Document #… has been approved");

return $approvedId;
```

### Ścieżka wywołania, gdy kanał powiadomień pada

1. `SalesDocumentController::approve()` → `commandBus->dispatch(new ApproveSalesDocument($id, $approvedBy))`.
2. `command.bus` to sync bus → `HandleMessageMiddleware` woła `ApproveSalesDocumentHandler::__invoke`.
3. **ETAP A**: `BEGIN … COMMIT`. Dokument zatwierdzony, order utworzony — **nieodwracalnie w bazie**.
4. **ETAP B**: pierwsze `notify()` rzuca wyjątek.
   - w reprodukcji: `InMemoryNotifier(failOnCallNumber: 1)`,
   - w produkcji: realny kanał (e‑mail / SMS / webhook) chwilowo niedostępny.
5. Wyjątek **nie jest łapany w handlerze** → wychodzi z `__invoke`.
6. `HandleMessageMiddleware` opakowuje go w `HandlerFailedException`
   (`Handling "App\Message\Command\ApproveSalesDocument" failed: …`).
7. Wyjątek wraca przez bus do kontrolera.
8. Kontroler: `catch (\Throwable $e) { return new JsonResponse(['error' => $e->getMessage()], 500); }`
   → **HTTP 500** z surowym komunikatem wyjątku.

### Skutki

- Klient dostaje 500 i zakłada „zatwierdzenie się nie powiodło" — a dokument JEST zatwierdzony.
- Ponowna próba zatwierdzenia strzela w walidację `status !== Draft`
  → „Document cannot be approved in its current status" → **kolejne 500**.
- Powiadomienia poszły częściowo — jeśli padło drugie `notify()`, twórca dostał wiadomość,
  kontrahent nie (stary kod przerywał pętlę na pierwszym błędzie).

### Dlaczego „akurat w tym miejscu" (pytanie z `TASK.MD`)

- Powiadomienie to **side effect wykonywany PO tym, jak transakcja biznesowa już się zacommitowała**.
  W tym momencie operacja — z punktu widzenia biznesu — już się udała.
- Wysyłka powiadomień jest **best‑effort, nietransakcyjna i zawodna**: gada z zewnętrznym
  systemem, którego nie da się „wycofać" (nie odwołasz wysłanego maila).
- Bug polega na **sprzęgnięciu sygnału sukcesu/porażki komendy z losem tego niepewnego,
  po‑commitowego kroku**.
- Nie da się tego „uatomowić": nawet gdyby wrzucić `notify()` do transakcji, wysłanie maila
  i tak nie jest transakcyjne — mail by wyszedł, a `COMMIT` mógłby potem paść, dając odwrotną
  niespójność (powiadomienie o zatwierdzeniu, którego nie ma). Powiadomienie **musi** być poza
  transakcją, a jego błąd **nie może** wychodzić na zewnątrz.

Test `testApprovalDoesNotFailTheCallerWhenTheNotificationChannelFails` koduje dokładnie ten
kontrakt: *„zatwierdzenie musi być trwałe, nawet jeśli powiadomienie padnie"* — i wywołuje
`dispatch(...)` **bez** `expectException`, więc każdy wyjątek wychodzący z handlera wywala test.

---

## 2. Jak namierzyłem — krok po kroku

Domyślnie `NotifierPort` → `LogNotifier`, który **nigdy nie rzuca**, więc przez zwykłe curl
błędu nie widać. Trzeba na chwilę podmienić notifier na rzucający.

### Krok 1 — podmiana notifiera

W `config/services.yaml`:

```yaml
# App\Notification\NotifierPort: '@App\Notification\LogNotifier'
App\Notification\NotifierPort: '@App\Notification\InMemoryNotifier'
App\Notification\InMemoryNotifier:
    arguments:
        $failOnCallNumber: 1        # pierwsze notify() w każdym żądaniu rzuca wyjątek
```

### Krok 2 — czyszczenie cache kontenera DI

```bash
docker compose exec php php bin/console cache:clear -q
```

### Krok 3 — utworzenie oferty

```bash
curl -sS -X POST http://localhost:8080/sales-documents \
  -H 'Content-Type: application/json' -d '{"contractor_id":77,"created_by":5}'
# => {"id":1}
```

### Krok 4 — zatwierdzenie (tu widać błąd)

```bash
curl -sS -i -X POST http://localhost:8080/sales-documents/1/approve \
  -H 'Content-Type: application/json' -d '{"approved_by":9}'
```

```
HTTP/1.1 500 Internal Server Error
{"error":"Handling \"App\\Message\\Command\\ApproveSalesDocument\" failed: Simulated notification channel outage (call #1)"}
```

### Krok 5 — sprawdzenie, co NAPRAWDĘ się stało w bazie `app`

```bash
docker compose exec database psql -U app -d app \
  -c "SELECT id,type,status,approved_by FROM sales_document ORDER BY id"
```

```
 id | type  |  status  | approved_by
----+-------+----------+-------------
  1 | quote | approved |           9      <-- oferta ZATWIERDZONA mimo HTTP 500
  2 | order | approved |           9      <-- order i tak powstał
```

Rozbieżność „HTTP 500 vs. baza pokazuje sukces" = potwierdzony błąd 1. Przy okazji widać
błąd 2 (surowy komunikat wyjątku w odpowiedzi + kod 500 zamiast czegoś sensownego).

### Krok 6 — potwierdzenie testem jednostkowym / diagnoza w kodzie

```bash
docker compose exec php php bin/phpunit \
  --filter testApprovalDoesNotFailTheCallerWhenTheNotificationChannelFails
```

Stack trace pokazuje jednoznacznie: `RuntimeException: Simulated notification channel outage`
→ `InMemoryNotifier.php:23` → `ApproveSalesDocumentHandler.php` (linia z `notify()`, **poza**
`wrapInTransaction`) → `HandleMessageMiddleware` → bus → test. Czyli wyjątek z kroku
po‑commitowego wychodzi całą drogą do wołającego.

### Krok 7 — przywrócenie configu

`config/services.yaml` z powrotem na `LogNotifier` + `cache:clear`.

---

## 3. Jak naprawiłem — krok po kroku

Ograniczenie z `TASK.MD`: **bez drugiej szyny/kolejki asynchronicznej**.
Rozwiązanie: **odizolować side effect powiadomień tak, żeby jego błąd był kontenerowany**
(zalogowany, nie propagowany).

### Krok 1 — nowa, dedykowana usługa `src/Notification/ApprovalNotifier.php`

Jej jedyna odpowiedzialność: „ogłoś zatwierdzenie, best‑effort".

```php
<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\SalesDocument;
use Psr\Log\LoggerInterface;

/**
 * Announces that a sales document was approved.
 *
 * Notifications are a best-effort side effect that runs *after* the approval is
 * already committed and durable. A failing notification channel must never turn
 * a successful approval into a failed command, so every delivery is isolated:
 * one recipient failing is logged and does not stop the others.
 */
final class ApprovalNotifier
{
    public function __construct(
        private readonly NotifierPort $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function documentApproved(SalesDocument $document): void
    {
        $message = "Document #{$document->getId()} has been approved";

        $this->safeNotify($document->getCreatedBy(), $message, $document);
        $this->safeNotify($document->getContractorId(), $message, $document);
    }

    private function safeNotify(int $userId, string $message, SalesDocument $document): void
    {
        try {
            $this->notifier->notify($userId, $message);
        } catch (\Throwable $e) {
            $this->logger->error('Approval notification failed', [
                'documentId' => $document->getId(),
                'userId' => $userId,
                'exception' => $e,
            ]);
        }
    }
}
```

Co to daje:

- błąd `notify()` jest **łapany i logowany**, nie leci dalej;
- każdy odbiorca izolowany osobno — padnięcie powiadomienia do twórcy **nie blokuje**
  powiadomienia do kontrahenta (poprawka gratis — stary kod przy błędzie 1 nigdy nie
  wywoływał drugiego `notify()`);
- polityka „powiadomienia są best‑effort" w jednym miejscu, reużywalna (np. dla przyszłego
  `reject`), testowalna osobno;
- handler wraca do bycia czystym orkiestratorem zmiany stanu.

### Krok 2 — podpięcie w handlerze `src/MessageHandler/ApproveSalesDocumentHandler.php`

Zmiana konstruktora: `NotifierPort $notifier` → `ApprovalNotifier $approvalNotifier`.
Blok dwóch `notify()` → jedno wywołanie:

```php
// przed:
$approvedDocument = $this->repository->find($approvedId);

$this->notifier->notify(
    $approvedDocument->getCreatedBy(),
    "Document #{$approvedDocument->getId()} has been approved",
);
$this->notifier->notify(
    $approvedDocument->getContractorId(),
    "Document #{$approvedDocument->getId()} has been approved",
);

return $approvedId;
```

```php
// po:
// The approval is committed and durable from here on. Notifying the
// parties is a best-effort side effect: its failure must not propagate
// out of the handler and be reported to the caller as a failed approval.
$approvedDocument = $this->repository->find($approvedId);
$this->approvalNotifier->documentApproved($approvedDocument);

return $approvedId;
```

`ApprovalNotifier` rejestruje się sam (glob `App\:` w `services.yaml`), `LoggerInterface`
autowire'uje framework‑bundle — zero dodatkowej konfiguracji. Nie dodano żadnego transportu
ani kolejki.

### Krok 3 — weryfikacja testami

```
✔ Approving a quote spawns a linked order and notifies both parties   (happy-path — nadal zielony)
✔ Approval does not fail the caller when the notification channel fails (błąd 1 — naprawiony)
✔ Create and approve through http                                      (happy-path HTTP — nadal zielony)
```

Assertions w testach nietknięte. Happy‑path `assertCount(2, $notifier->sent)` dalej
przechodzi, bo przy sprawnym notifierze `ApprovalNotifier` woła `notify()` dokładnie 2× —
co potwierdza, że podmiana `NotifierPort` w teście przez `getContainer()->set()` działa też
przez nową warstwę (kontener nie inline'uje usługi).

### Krok 4 — weryfikacja przez HTTP (z wpiętym `failOnCallNumber: 1`)

`POST /sales-documents/1/approve` zwraca teraz **HTTP 200**, a w logu ląduje wpis
`Approval notification failed` z kontekstem (`documentId`, `userId`, `exception`).

---

## 4. Dlaczego nie „druga kolejka" + kierunek na przyszłość

`TASK.MD` wprost odradza wprowadzanie drugiej szyny/kolejki asynchronicznej dla tego problemu
i słusznie — istotą buga jest *sprzęgnięcie* sygnału błędu, a nie *brak asynchroniczności*.
Rozwiązanie synchroniczne z izolacją błędu jest wystarczające i najprostsze.

Gdyby powiadomień miało przybyć (wiele kanałów, retry, szablony), naturalny następny krok to:

1. emisja zdarzenia domenowego `SalesDocumentApproved` po commicie,
2. obsługa w listenerze,
3. docelowo przeniesienie *samej wysyłki* na transport async (`messenger:consume`), gdzie
   retry / backoff / dead‑letter robi za nas framework.

Na potrzeby tego zadania byłoby to nadmiarowe i sprzeczne z podpowiedzią z `TASK.MD`.

---

## 5. Pliki zmienione w ramach problemu 1

| Plik | Zmiana |
|---|---|
| `src/Notification/ApprovalNotifier.php` | **nowy** — best‑effort ogłaszanie zatwierdzenia, łapie i loguje błędy `notify()` |
| `src/MessageHandler/ApproveSalesDocumentHandler.php` | konstruktor `NotifierPort` → `ApprovalNotifier`; blok `notify()` → `approvalNotifier->documentApproved()` |

Testy: bez zmian (asercje nietknięte, zgodnie z `TASK.MD`).
