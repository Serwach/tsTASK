To zadanie ocenia pracę w architekturze **CQRS** (Command → Handler przez
`command.bus`), nie DDD - nie oczekujemy warstw
Domain/Application/Infrastructure. Masz
swobodę w tym, jak zorganizujesz nowe/przebudowane pliki, byle było rozdzielenie
odpowiedzialności.

System tworzy i zatwierdza dokumenty sprzedażowe (oferty i zamówienia)
przez `SalesDocumentController` → `command.bus`. Kod działa i ma testy, ale zespół zgłasza kilka
niezależnych, realnych problemów:

1. Czasem zatwierdzenie "wygląda na nieudane" (klient dostaje błąd 500),
   mimo że w bazie widać poprawnie zatwierdzony dokument.
2. `SalesDocumentController::approve()` zwraca **500 z surowym
   komunikatem wyjątku** dla każdego możliwego błędu - także dla
   sytuacji, które wcale nie są błędem serwera (np. nieistniejące ID
   dokumentu powinno dać 404, nie 500). Do tego kontroler sam sobie robi
   zapytanie SQL do bazy zamiast skorzystać z istniejącego repozytorium.
3. **Zgłoszenie od supportu, którego nikt jeszcze nie zdiagnozował:**
   ```
   "część nowo utworzonych dokumentów sprzedażowych w raportach ma
   pomieszane dane właściciela - kontrahent i osoba tworząca dokument są
   jakby zamienione miejscami, ale nie za każdym razem i nie widać tego w
   żadnym z naszych dotychczasowych testów".
   ```


Dołączone testy funkcjonalne pokazują część tego:

`tests/Functional/ApproveSalesDocumentTest.php` **nie przechodzi w 1 z 2
przypadków** (problem 1), a
`tests/Functional/SalesDocumentControllerTest.php` dokumentuje obecne
(błędne) zachowanie kontrolera (`testApprovingMissingDocumentCurrentlyReturns500`) - jest to zamierzone.

### Co należy zrobić

1. **Napraw problem 1** - spraw, że drugi test w
   `ApproveSalesDocumentTest` przejdzie, bez zmiany treści jego asercji.
   Zastanów się, dlaczego akurat w tym miejscu to się dzieje.
2. **Napraw problem 2** - kontroler powinien mapować różne rodzaje błędów
   na różne, poprawne kody HTTP (404 dla "nie znaleziono", coś w rodzaju
   409/422 dla "nie można wykonać tej operacji w tym stanie" - nie
   wszystko na 500). Zaktualizuj `testApprovingMissingDocumentCurrentlyReturns500` tak,
   żeby odzwierciedlał **poprawione** zachowanie.
3. **Znajdź i napraw problem 3** (zgłoszenie supportu opisane wyżej).

4. **Dodaj własną, nową operację w tym samym stylu** - w
   `tests/Functional/RejectSalesDocumentHandlerTest.php` dostajesz test
   dla `RejectSalesDocument`/`RejectSalesDocumentHandler`, których **jeszcze
   nie ma w kodzie**. Na podstawie samego testu zaprojektuj i zaimplementuj
   brakujący kod.

### Na co zwrócić uwagę

- Oba dostarczone testy happy-path (`testApprovingAQuoteSpawnsALinkedOrder...`
  w `ApproveSalesDocumentTest` i `testCreateAndApproveThroughHttp` w
  `SalesDocumentControllerTest`) muszą zostać zielone przed i po Twoich
  zmianach, bez zmiany treści asercji (wyjątek: punkt 3 wyżej, tam zmiana
  jest oczekiwana).
- Nie musisz (i raczej nie powinieneś) wprowadzać drugiej szyny/kolejki
  asynchronicznej na potrzeby problemu 1 - da się i należy go rozwiązać
  bez tego.
- `composer.json` celowo wykorzystuje starsze wersje (Symfony 7.4).
  Jeśli zauważysz, że są dostępne nowsze wersje i uznasz, że warto zaktualizować
  projekt, możesz to zrobić - pod warunkiem, że masz ku temu konkretny powód
  i opiszesz w README, dalczego zdecydowałeś się na aktualizację

  Jeśli nie zdecydujesz się na update i postanowisz zostać przy obecnych
  wersjach, również krótko uzasadnij tę decyzję w README.
- Do problemu 3 zalecamy odpalić projekt i użyć debugera (Xdebug)

### Co dostarczasz

Repozytorium z przebudowanym kodem, zielonymi testami oraz
`README.md`, w którym opisujesz naturę wszystkich problemów (włącznie z
tym, jak zdiagnozowałeś problem 3 - jakim tropem poszedłeś)
