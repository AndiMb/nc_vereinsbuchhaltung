# Aufräumen der Navigation: Konzept und Umsetzungsplan

> **Umgesetzt am 14.08.2026** auf Branch `feature/navigation` (abgezweigt von
> `main`, nachdem `feature/sepa-lastschrift` dorthin vorgespult wurde – siehe
> Schritt 0). Alle vier Schritte sind fertig; Details siehe CHANGELOG.md
> (Abschnitt „Unreleased" → „Navigation aufgeräumt"). Diese Datei bleibt als
> Begründung der Entscheidungen (Abschnitt 7) und als Nachschlagewerk stehen,
> darf aber jederzeit gelöscht werden, wenn sie nicht mehr gebraucht wird.

## 1. Der Befund

Das Zahnrad heißt „Einstellungen & Import" und enthält heute **16 Abschnitte**
in einem einzigen `NcModal` (`src/App.vue`, Zeilen 196–265). Sie stehen in der
Reihenfolge, in der sie entstanden sind – nicht in der Reihenfolge, in der
jemand sie braucht.

Das eigentliche Problem ist nicht die Länge, sondern dass dort **zwei
grundverschiedene Arten von Dingen** liegen:

* **Einrichtung** – einmal beim Start, danach jahrelang nie wieder:
  Berechtigungen, Belegablage, Vereinsname, Corporate Design, Wachordner,
  Kostenstellen-Modus, Gläubiger-ID, xbuc-Import.
* **Laufende Arbeit** – regelmäßig, von der finanzverantwortlichen Person:
  Mitglieder und Beiträge, SEPA-Sammeleinzug, Regeln, Jahresabschluss.

Konkret fällt das so auf:

* Der **Sammeleinzug** ist ein mehrstufiger Vorgang (Vorschau prüfen → Einzug
  erzeugen → XML einreichen → als ausgeführt verbuchen → Rücklastschriften
  behandeln). Er steckt hinter einem Zahnrad, das „Einstellungen" heißt. Das
  ist kein Einstellen, das ist die Kernaufgabe eines Kassenwarts im Frühjahr.
* Die **Mitgliederliste** (`SettingsMembers.vue`, 704 Zeilen) ist die längste
  Tabelle der App – Suche, Filter, Inline-Bearbeitung, fünf Zeilenaktionen –
  und lebt in einem Modal, über dem noch zwei Formulare (Aufnahme, CSV-Import)
  stehen. Bei dreistelliger Mitgliederzahl scrollt man an allem vorbei.
* Der **erste Abschnitt des Modals ist ein Wegweiser**: „Der CSV-Import ist
  direkt im Tab Buchungen erreichbar" mit einem Knopf, der das Modal schließt
  und den Dialog öffnet. Ein Menüpunkt, dessen Inhalt lautet „ich bin woanders".
* **Kostenstellen lassen sich an zwei Stellen bearbeiten**: Umbenennen im
  Bericht (`ReportsTab.vue`, `saveRename`), Anlegen und Zuordnen im Modal.
* Die **Hilfe muss Wege beschreiben** statt Orte zu benennen: „Einstellungen →
  SEPA-Sammeleinzug", „Einstellungen → Mitgliedsbeiträge" (`HelpModal.vue`).
  Ein guter Test: wo eine Hilfe einen Pfad erklären muss, stimmt die Struktur
  nicht.
* Alle Abschnitte **rendern gleichzeitig**. `SettingsMembers` lädt Mandate und
  Beiträge bei jedem Öffnen des Zahnrads, auch wenn jemand nur den Vereinsnamen
  ändern will.

## 2. Das Ordnungsprinzip

Drei Fragen an jeden Abschnitt: **Wie oft? Woran arbeitet man dabei? Wer darf
es?** Daraus die Regel, nach der alles Weitere entschieden wird:

> **Was regelmäßig getan wird, gehört in die Hauptnavigation.
> Was zu einem Gegenstand gehört, gehört dorthin, wo dieser Gegenstand zu sehen
> ist.
> Im Zahnrad bleibt nur, was den Verein als Ganzes einstellt.**

Die App macht das an drei Stellen bereits richtig – die Regel ist also keine
neue Erfindung, sondern die konsequente Fortsetzung dessen, was schon da ist:
der CSV-Import wanderte in 0.19 in den Tab „Buchungen", die Sphäre steht im
Konto-Dialog, das Umbenennen einer Kostenstelle steht im Kostenstellen-Bericht.

## 3. Zielstruktur

```
Übersicht │ Buchungen │ Konten │ Berichte │ Beiträge*        [⚙]
                │                    │           │
                ├ Alle Buchungen     ├ Auswertung        ├ Mitglieder
                ├ Zuzuordnen         ├ Kostenstellen ←   ├ Einzug**
                ├ Offene Posten      ├ Sphären       ←
                └ Regeln         ←   ├ Rücklagen
                                     ├ Finanzplan
                                     └ Protokoll

 *  nur sichtbar, wenn die Beitragsverwaltung genutzt wird (siehe D2)
 ** nur sichtbar, wenn Gläubiger-ID und einziehendes Konto hinterlegt sind
 ←  neu hinzugekommene Pflege-Bereiche
```

Das Zahnrad bleibt ein `NcModal` (der geplante `NcAppSettingsDialog` mit
Seitennavigation erwies sich im Browser-Test als mit Vue 2.7 nicht
kompatibel, siehe Abschnitt 5), fasst aber nur noch **sieben kurze
Abschnitte** statt 16.

### Wohin welcher Abschnitt geht

| Heute im Zahnrad | Künftig | Begründung |
|---|---|---|
| Kontoumsätze importieren | **entfällt** | Reiner Wegweiser; der Knopf steht seit 0.19 im Tab Buchungen |
| Mitglieder und Beiträge | **Beiträge → Mitglieder** | Laufende Arbeit, größte Tabelle der App |
| SEPA-Sammeleinzug | **Beiträge → Einzug** | Mehrstufiger Vorgang, keine Einstellung |
| Automatische Zuordnung (Regeln) | **Buchungen → Regeln** | Regeln entstehen beim Zuordnen; „Regel anlegen" gibt es dort bereits |
| Steuerliche Sphären | **Berichte → Sphären** | Wo die Lücke sichtbar wird, wird sie geschlossen |
| Kostenstellen | **Berichte → Kostenstellen** | Umbenennen ist dort schon möglich – Anlegen und Zuordnen gehören daneben |
| Kostenstellen-Modus | **Berichte → Kostenstellen** | Der Modus entscheidet, was der Bericht überhaupt zeigt |
| Verein | Zahnrad → **Verein** | Einrichtung |
| Corporate Design | Zahnrad → **Verein** (dieselbe Seite) | Gehört zum Auftritt des Vereins |
| Belegablage | Zahnrad → **Belege** | Einrichtung |
| Kontoauszüge automatisch einlesen | Zahnrad → **Bankdaten** | Einrichtung |
| SEPA-Lastschrift (Gläubiger-ID, Konto) | Zahnrad → **Beiträge & SEPA** | Einmalige Stammdaten, dazu der Modulschalter |
| Berechtigungen | Zahnrad → **Berechtigungen** | Einrichtung |
| Jahresabschluss | Zahnrad → **Jahresabschluss** | Jährlich, selten, heikel – plus Verweis aus Berichte → Auswertung |
| Aus „zero Buchhaltung" (.xbuc) | Zahnrad → **Daten** | Einmalige Migration beim Umstieg |
| Alle Daten löschen | Zahnrad → **Daten** (Gefahrenbereich) | Beide betreffen den Datenbestand als Ganzes |

## 4. Der neue Reiter „Beiträge"

**Aufbau** nach dem Muster von `BookingsTab.vue`: `vbh-sectiontop` mit
`vbh-subtabs` links und Aktionen rechts, darunter `vbh-sectionbody`.

* **Unterreiter „Mitglieder"** – Suche, Filter „nur Auffälligkeiten", Tabelle
  mit Zeilenaktionen. Die beiden Formulare, die heute darüber stehen, werden zu
  Knöpfen in der Kopfzeile: **„＋ Mitglied"** öffnet einen Dialog
  (Muster `AccountDialog.vue`), **„Liste einlesen"** öffnet den CSV-Prüflauf
  (Muster `ImportDialog.vue`). Die Liste beginnt damit oben am Fenster.
* **Unterreiter „Einzug"** – unverändert der Inhalt von
  `SettingsSepaExport.vue`: Vorschau, Erzeugen, Einzugsliste mit Zeilen,
  Verbuchen, Rücklastschriften.
* **Kennzahl im Reiter**: rote Zahl wie bei „Zuzuordnen", sobald Beiträge im
  Rückstand sind (`fee.dueCount > 0` ist bereits vorhanden). Das ist der Grund,
  warum jemand den Reiter überhaupt öffnen soll.

**Sichtbarkeit.** Die Beitragsverwaltung ist ein optionales Zusatzmodul; ein
Verein ohne Lastschriften darf keinen fünften Reiter bekommen. Der Reiter ist
sichtbar, wenn `membership_active` wahr ist. Das Backend berechnet den Wert in
`SettingsController::index()` als

```
membership_enabled === '1'  ||  Anzahl Mandate > 0  ||  Anzahl Beiträge > 0
```

Damit ist bei bestehenden Installationen **keine Migration nötig** – wer bereits
Mitglieder angelegt hat, sieht den Reiter sofort. Eingeschaltet wird das Modul
über einen Schalter auf der Zahnrad-Seite „Beiträge & SEPA"
(`membership_enabled`), wo auch die Gläubiger-ID steht.

**Datenladen.** `MembersList` lädt heute in `mounted()`. Als Tab-Inhalt darf das
nicht bei jedem Reiterwechsel erneut passieren: das Laden gehört in
`App.vue::loadTab()` und `refreshAfterRemoteChange()`, wie bei allen anderen
Reitern. Die Composables (`useMembershipFees`, `useSepaMandates`,
`useSepaBatches`) sind bereits Modul-Singletons – am Zustand ändert der Umzug
nichts.

## 5. Der neue Einstellungsdialog

**Ursprünglich geplant war `NcAppSettingsDialog`** (`:open.sync`,
`show-navigation`) mit je einer `NcAppSettingsSection` pro Abschnitt – eine
Seitennavigation hätte sich daraus von selbst ergeben. Der Browser-Test gegen
die lokale Docker-Testinstanz (headless Chromium per CDP, siehe
Projekt-Memory) zeigte aber: die Komponente **rendert unter Vue 2.7 nicht**.
Sie ist intern als `<script setup>` gegen Vue 3 gebaut; ihr `open`-Prop kommt
zwar korrekt an (per Vue-Instanz-Inspektion verifiziert – `$props.open` wird
`true`), aber `useVModel()` aus `@vueuse/core` hält die daraus abgeleitete
lokale Reaktivität nicht synchron, wodurch das innere `NcDialog v-if="open"`
nie mountet. Keine Fehlermeldung, keine Exception – nur ein leerer
Kommentarknoten anstelle des Dialogs. Da dieses Projekt auf Vue 2.7 pinnt
(`package.json`), ist `NcAppSettingsDialog`/`NcAppSettingsSection` damit für
diese App **nicht nutzbar**, unabhängig von der Verwendung.

Umgesetzt wurde stattdessen: `NcModal` bleibt wie zuvor, aber mit denselben
sieben Abschnitten statt der vorherigen 16, jeder mit eigener
`<h3 class="vbh-section-divider">`-Überschrift und einer
`id="settings-section_<id>"` auf dem Wrapper-Div als Sprunganker (siehe
`openSettings(section)` in `App.vue`). Eine Seitennavigation gibt es dadurch
nicht, aber sieben statt 16 Abschnitte in einem `NcModal` sind bereits der
weitaus größte Teil der ursprünglichen Verbesserung.

Sieben Abschnitte: **Verein · Belege · Bankdaten · Beiträge & SEPA ·
Berechtigungen · Jahresabschluss · Daten**. Der Titel wechselt von
„Einstellungen & Import" auf „Einstellungen" – der Import ist dann keiner
mehr von hier.

Ein Punkt zur Technik: `SettingsGeneral.vue` (230 Zeilen) enthielt vier
unzusammenhängende Themen und wurde in `SettingsClub.vue`,
`SettingsAttachments.vue` und `SettingsStatementWatch.vue` zerlegt; der
Kostenstellen-Modus ging in Schritt 2 zu den Berichten.

## 6. Was bewusst nicht passiert

* **Keine Änderung an den Rollen.** Alle Mandats-, Beitrags- und Einzugs-Endpunkte
  sind heute `RequiresRole(ROLE_ADMIN)`. Das bleibt so (siehe D3) – ein
  Umbau der Navigation ist der falsche Anlass, IBANs für eine weitere Rolle zu
  öffnen.
* **Keine eigene Mitgliedertabelle.** Der Architekturschnitt aus `SEPA-STAND.md`
  (Zahler = Nextcloud-Konto *oder* Freitextname) bleibt unangetastet.
* **Offene Posten bleiben unter Buchungen.** Sie betreffen auch Vereine ohne
  SEPA. Aus „Beiträge → Mitglieder" wird lediglich dorthin verlinkt.
* **Kein Router, keine URL-Zustände.** Die App hält den Reiter in `activeTab`;
  das bleibt. (Randnotiz: `vue-router` steht als Abhängigkeit in `package.json`,
  wird aber nirgends benutzt – bei der Gelegenheit entfernbar.)
* **Keine neuen Funktionen.** Jeder Abschnitt behält seinen Inhalt; es ändert
  sich, wo er steht.

## 7. Offene Entscheidungen

| | Frage | Empfehlung |
|---|---|---|
| **D1** | Name des Reiters: „Beiträge", „Mitglieder" oder „Beiträge & Einzug"? | **„Beiträge"** – „Mitglieder" verspricht eine Mitgliederverwaltung, die die App bewusst nicht führt |
| **D2** | Reiter immer für Verwalter zeigen (mit Leerzustand) oder nur bei aktivem Modul? | **Nur bei aktivem Modul**, Schalter im Zahnrad. Die Mehrheit der Vereine zieht nichts ein |
| **D3** | Bleibt der Bereich Verwalter-only, oder wird er für Buchhalter geöffnet? | **Unverändert Verwalter.** Wenn in einem Verein der Kassenwart „Buchhalter" ist, ist das eine eigene, bewusst zu treffende Datenschutz-Entscheidung. *Nachtrag 15.08.2026: genau diese Entscheidung wurde im Usability-Review aufgeworfen und zugunsten voller Buchhalter-Rechte geändert (Version 0.22.2) – siehe `BEITRAEGE-REVIEW.md`.* |
| **D4** | Sphären-/Kostenstellen-Pflege bei den Berichten oder im Reiter Konten? | **Bei den Berichten** – dort wird die fehlende Zuordnung sichtbar, und das Umbenennen liegt schon dort. *Nachtrag 15.08.2026: die Pflege stand zunächst als zweiter Block unter der Split-Ansicht im selben Scrollcontainer (`ReportsTab.vue`) – dadurch bekam der Bericht selbst kaum Platz. Umgestellt auf ein `NcModal`, das über „Kostenstellen verwalten"/„Sphären zuordnen" in der Kopfzeile sowie über „Konten zuordnen" bei der Zeile „nicht zugeordnet" geöffnet wird; der Kostenstellen-Modus zog dafür als Auswahlfeld in dieselbe Kopfzeile.* |
| **D5** | Erst `feature/sepa-lastschrift` nach `main` bringen? | **Ja.** Schritt 1 fasst genau diesen Code an; ein ungetaggter Branch unter einem Umbau wird unübersichtlich |

## 8. Umsetzungsplan

Vier Schritte, jeder für sich lauffähig, testbar und veröffentlichbar. Reihenfolge
nach Nutzen: der größte Schmerz zuerst.

### Schritt 0 – Vorbereitung (klein)

`feature/sepa-lastschrift` prüfen, taggen, nach `main` bringen (D5). Neuen Branch
`feature/navigation` davon abzweigen.

### Schritt 1 – Der Reiter „Beiträge" (groß, ~1 Arbeitstag)

*Neu*
* `src/components/ContributionsTab.vue` – Unterreiter und Kopfzeile
* `src/components/MemberDialog.vue` – „Mitglied aufnehmen", aus `SettingsMembers` gelöst
* `src/components/MemberImportDialog.vue` – CSV-Prüflauf und Übernahme, ebenso

*Umbenannt und entschlackt*
* `SettingsMembers.vue` → `MembersList.vue` (rund 704 → ~380 Zeilen)
* `SettingsSepaExport.vue` → `SepaBatchPanel.vue` (nur Überschrift entfällt)

*Geändert*
* `src/App.vue` – `allTabs` um `{ id: 'contributions', icon: mdiAccountCashOutline }`,
  `visibleTabs`, `loadTab()`, `refreshAfterRemoteChange()`, `helpTopic`-Zuordnung
  `contributions → 'sepa'`; die beiden `<Settings…>` aus dem Modal entfernen
* `lib/Controller/SettingsController.php` – `membership_enabled` schreiben,
  `membership_active` berechnet ausliefern (zwei Zählabfragen, keine Migration)
* `src/styles.css` – Bottom-Nav auf fünf Einträge prüfen

*Prüfpunkte*: Reiter erscheint/verschwindet mit dem Schalter; Mitglied anlegen,
bearbeiten, Bankverbindung wechseln, Mandat widerrufen; Einzug erzeugen,
verbuchen, verwerfen; Rückstands-Kennzahl stimmt; Mobilansicht mit fünf Reitern.

### Schritt 2 – Kontextnahe Pflege (mittel, ~½ Tag)

* `SettingsRules.vue` → `RulesPanel.vue`, als vierter Unterreiter in
  `BookingsTab.vue` (`bookingView === 'rules'`)
* `SettingsSpheres.vue` → `SphereAssignPanel.vue`, in `ReportsTab.vue` unter
  „Sphären", `v-if="canWrite"`
* `SettingsCostCenters.vue` → `CostCenterPanel.vue`, in `ReportsTab.vue` unter
  „Kostenstellen", zusammen mit dem Moduswähler
* **Neu** `src/composables/useRules.js` – `rules` wird heute in `App.vue`
  gehalten und als Prop weitergereicht; `computeSuggestion()` in `App.vue`
  braucht sie weiterhin. Ein Singleton wie `useAccounts` löst das sauber,
  statt Props durch zwei Ebenen zu schieben.

*Prüfpunkte*: Regel anlegen/bearbeiten/löschen und Wirkung beim Zuordnen;
Sphären-Massenzuweisung samt Vorschlägen; Kostenstelle anlegen, Konten
zuweisen, Modus umstellen und Bericht danach.

### Schritt 3 – Der Einstellungsdialog (mittel, ~½–1 Tag)

* Geplant: `NcModal` → `NcAppSettingsDialog` + `NcAppSettingsSection` in
  `App.vue`. Umgesetzt: `NcModal` bleibt (Vue-2.7-Inkompatibilität von
  `NcAppSettingsDialog`, siehe Abschnitt 5), nur die sieben Abschnitte statt
  16 sind neu.
* `SettingsGeneral.vue` aufteilen (siehe 5.)
* `SettingsYearClose.vue`: „Alle Daten löschen" heraus, zusammen mit
  `SettingsXbucImport.vue` in eine Seite „Daten"
* Karte „Kontoumsätze importieren" ersatzlos streichen, Titel auf
  „Einstellungen" ändern
* `styles.css` – `.vbh-modal-inner`, `.vbh-section-divider` aufräumen

*Prüfpunkte*: Speichern aus jeder Seite schreibt weiterhin den **gesamten**
Einstellungssatz korrekt (siehe R3); Sprungziel des Setup-Assistenten; Dialog
auf schmalem Fenster und mobil; Tastaturkürzel `N` und `/` bleiben blockiert,
solange der Dialog offen ist.

### Schritt 4 – Nachziehen (klein–mittel, ~½ Tag)

* `HelpModal.vue` – Wege umschreiben („Einstellungen → SEPA-Sammeleinzug"
  → „Reiter Beiträge → Einzug"), Kapitelzuordnung für den neuen Reiter
* `SetupChecklist.vue` – `action: 'settings'` zielgenau machen
* `HANDBUCH.md` – Kapitel 2.4/2.5, 3.1, 5.4, 5.6, 8, 12.1 und vor allem 13
* `README.md`, `CHANGELOG.md` (Version 0.22.0), `l10n/de.json`, `l10n/en.json`
* `SEPA-STAND.md` und diese Datei löschen oder als erledigt markieren

## 9. Risiken

| | Risiko | Umgang |
|---|---|---|
| **R1** | Fünf Einträge in der mobilen Bottom-Nav werden bei 360 px eng | Bei ≥5 Reitern Labels kürzen oder Schriftgröße senken; früh am Gerät messen |
| **R2** | ~~`NcAppSettingsDialog` hat in `@nextcloud/vue` 8 den Vorgabewert `legacy: true`, in 9 kippt er~~ – gegenstandslos: die Komponente rendert unter Vue 2.7 gar nicht erst (siehe Abschnitt 5), `NcModal` wurde beibehalten | entfällt |
| **R3** | `SettingsController::update()` schreibt **immer alle** Werte auf einmal | Beim Aufteilen darf keine neue Komponente einen Wert nur lokal halten – der vollständige Satz bleibt in `App.vue` |
| **R4** | Nutzer suchen Vertrautes an alter Stelle | Einmaliger Hinweis im Zahnrad („Mitglieder und Sammeleinzug stehen jetzt im Reiter Beiträge"), nach Bestätigung dauerhaft ausgeblendet – wie beim Revisor-Hinweis |
| **R5** | Nachladen bei jedem Reiterwechsel | Laden ausschließlich über `loadTab()`, nicht im `mounted()` der Panels |

## 10. Fertig ist es, wenn

* ✅ `npm run lint`, `npm test`, `npm run build`, PHPUnit und PHPStan sauber
  sind (PHPStan-Fehlerzahl unverändert gegenüber dem Stand vor dieser
  Änderung, per Worktree-Vergleich verifiziert)
* jede der drei Rollen (Verwalter, Buchhalter, Revisor) einmal komplett
  durchgeklickt wurde – auch mit ausgeschaltetem Beitragsmodul (bislang nur
  Verwalter im Browser getestet, siehe unten)
* ✅ die Oberfläche **im Browser** gesehen wurde, nicht nur im Build (offener
  Punkt 1 aus `SEPA-STAND.md`) – am 14.08.2026 gegen die lokale
  Docker-Testinstanz per headless Chromium (CDP) verifiziert: Einstellungen-
  Dialog (alle sieben Abschnitte inkl. Schalter „Beiträge"-Reiter),
  ContributionsTab (Mitglieder-Leerzustand, MemberDialog, Einzug-Vorschau),
  RulesPanel, CostCenterPanel (inkl. Modus-Umschalter), SphereAssignPanel und
  mobile Bottom-Nav mit fünf Reitern rendern korrekt, keine
  Konsolenfehler. Details zum Vorgehen (inkl. eines dabei gefundenen und
  behobenen Bugs) siehe Projekt-Memory.
* keine Hilfe-, Handbuch- oder Checklisten-Stelle mehr einen Weg beschreibt,
  den es nicht mehr gibt
