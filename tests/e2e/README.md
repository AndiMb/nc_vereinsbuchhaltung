# E2E-Tests

Playwright-Tests gegen eine echte Nextcloud im Docker-Container – dieselben
Bundles, dieselbe API, dieselbe Rechteprüfung wie im Betrieb.

## Ausführen

```bash
npm run test:e2e:install      # einmalig: Chromium für Playwright
npm run test:e2e:server       # Docker-Nextcloud (stable34) starten und einrichten
npm run test:e2e:run          # Tests ausführen (beliebig oft wiederholbar)
npm run test:e2e:server-stop  # Container wieder abräumen
```

Docker muss laufen. `test:e2e` fasst Serverstart und Testlauf zusammen; für
die Fehlersuche gibt es `test:e2e:ui`, `test:e2e:headed` und `test:e2e:debug`.

## Aufbau

Der Serverstart (`setup/server.mjs`) richtet die Instanz einmalig ein –
Sprache Deutsch, Testnutzer, App-Rollen – und friert diesen Zustand als
Datenbank-Snapshot „init" ein. Das Global-Setup spielt den Snapshot vor
jedem Testlauf zurück, die Tests selbst starten also immer vom selben Stand.

| Nutzer | Passwort | Rolle |
|---|---|---|
| admin | admin | Nextcloud-Admin (= App-Verwalter) |
| test1 | test1 | Verwalter |
| test2 | test2 | Buchhalter |
| test3 | test3 | Revisor |
| test4 | test4 | keine Rolle |
| test5 | test5 | startet ohne Rolle, Oberfläche auf Englisch – 16-l10n vergibt sich die Revisor-Rolle selbst |

## Spielregeln für neue Tests

- **Ein Worker, keine Parallelität** (playwright.config.mjs): alle Nutzer
  teilen sich EINEN Buchungsbestand (`Application::BOOK`). Parallele Specs
  würden sich gegenseitig die Daten unter den Füßen wegändern.
- **Jede Spec-Datei setzt sich ihren Bestand selbst auf**: `api.resetBook()`
  im `beforeAll`, dann eigenes Seeding über die API-Helfer aus
  `fixtures/nextcloud.mjs`. Geprüft wird in der Oberfläche, aufgebaut über
  die API – das hält die Läufe schnell.
- **Kein Test verlässt sich stillschweigend auf seine Vorgänger**: schlägt
  ein Test fehl, startet Playwright den Worker neu und `beforeAll` läuft
  erneut (setzt also zurück!). Wer Daten aus einem früheren Test braucht,
  stellt sie selbst sicher (siehe `ensureMemberWithFee` in
  11-contributions oder die Import-Aufrufe in 04-import).
- **Sichtbare Abschnitte scopen**: die App hält alle Tabs per `v-show`
  gleichzeitig im DOM. Textsuchen immer über `visibleSection(page)` laufen
  lassen, sonst trifft der Locator versteckte Duplikate.
