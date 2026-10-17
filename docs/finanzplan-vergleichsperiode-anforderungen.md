# Finanzplan mit Vergleichsperiode – Anforderungen (Issue #114)

Stand: 17.10.2026 · Ergebnis eines Brainstormings, noch kein Design.

## Ziel

Wer den Finanzplan einer Periode aufstellt, sieht in jeder Zeile den Wert einer
Vergleichsperiode (vorbelegt: das Vorjahr) und die Veränderung dazu, absolut und
in Prozent. Kürzungen und Erhöhungen lassen sich so direkt erkennen und in der
bestehenden Notiz begründen.

## Funktionale Anforderungen

### Auswahl
- **F1** Über der Finanzplan-Tabelle gibt es ein Dropdown *Vergleichsperiode*.
  Wählbar sind alle Perioden außer der gerade gewählten sowie „Kein Vergleich“.
- **F2** Vorbelegt ist die Periode, die unmittelbar vor der gewählten liegt
  (nach Startdatum). Gibt es keine, ist „Kein Vergleich“ vorbelegt.
- **F3** Ein Umschalter *Plan / Ist* legt fest, welcher Wert der
  Vergleichsperiode verglichen wird. Vorbelegt ist **Ist**.
- **F4** Wechselt die Periode, gilt wieder die Vorbelegung aus F2 und F3.

### Spalten
- **F5** Die bestehenden Spalten (Plan, Notiz, Ist, Differenz Plan↔Ist) bleiben
  unverändert. Neu hinzu kommen drei Spalten mit klarer Benennung, z. B.
  „Ist 2025“ bzw. „Plan 2025“, „Δ zu 2025“ und „Δ %“.
- **F6** Der Vergleichswert wird wie das Ist über alle Unterkonten summiert (Σ).
  Bei *Plan* zählt die Plansumme des Zweigs in der Vergleichsperiode, und zwar
  unabhängig davon, auf welcher Ebene damals geplant wurde.
- **F7** Δ = Plan (gewählte Periode, Σ) − Vergleichswert.
  Δ % = Δ / |Vergleichswert| · 100, auf eine Nachkommastelle gerundet.
  Beispiel: Vergleich 1.100 €, Plan 1.200 € → +100,00 € / +9,1 %.
- **F8** Ist der Vergleichswert 0, steht bei Δ % ein „—“. Δ wird trotzdem angezeigt.
- **F9** Die Einfärbung richtet sich nach der Kontoart und folgt der bestehenden
  Plan/Ist-Logik. Bei Ausgaben ist weniger grün und mehr rot, bei Einnahmen
  umgekehrt. Das Vorzeichen wird immer mit ausgegeben, damit die Aussage nicht
  allein an der Farbe hängt.
- **F10** Oberkonten, deren Plan darunter liegt, zeigen Vergleichswert und Δ
  auf Basis ihrer Plansumme. Unterkonten unter einem Planposten zeigen nur
  den Vergleichswert. Δ und Δ % stehen dort als „—“, wie bei der Differenz.
- **F11** Bei „Kein Vergleich“ werden die drei Spalten ausgeblendet.
- **F14** Die Summenkacheln über der Tabelle (Einnahmen, Ausgaben, Ergebnis)
  zeigen zusätzlich den Vergleichswert und Δ %, z. B. „Ist 2025: 12.300 € ·
  Δ +4,2 %“. Die Einfärbung folgt F9. Bei „Kein Vergleich“ entfällt diese Zeile.
- **F15** Inaktive Konten erscheinen weiterhin als Zeile. Sie zeigen den
  Vergleichswert, haben keine Planeingabe (außer bei einem vorhandenen
  Plan ≠ 0) und tragen den Hinweis „nicht mehr aktiv“. So bleiben die Summen
  nachvollziehbar. Konten, deren Typ heute nicht mehr Einnahme oder Ausgabe
  ist, kann der Planbaum nicht darstellen (siehe Design, Abschnitt 2).
- **F16** Der Filter „Nur Auffälligkeiten“ bleibt unverändert. Eine Abweichung
  zum Vorjahr gilt nicht als Auffälligkeit.

### Ausgabe
- **F12** Die Druck-/PDF-Ansicht des Finanzplans übernimmt Vergleichsperiode
  und Plan/Ist-Wahl ohne Rückfrage aus der Oberfläche und zeigt dieselben
  Spalten. Im Kopf steht die Vergleichsbasis, z. B. „Vergleich: Ist 2025“.
  Das Druckmenü bekommt keine zusätzlichen Einträge.
- **F13** Der Finanzplan-CSV-Export enthält die Vergleichsspalten mit derselben
  Wahl. Bei „Kein Vergleich“ bleibt das Format wie heute.

## Nicht-funktionale Anforderungen

- **N1** Rechte wie beim Finanzplan heute. Lesen darf, wer den Finanzplan sehen
  darf. Der Vergleich ist reine Anzeige und schreibt nichts.
- **N2** Alle neuen Texte zweisprachig (DE/EN, l10n), HANDBUCH DE+EN ergänzen.
- **N3** Auf schmalen Bildschirmen bleibt von den Vergleichsspalten nur Δ
  sichtbar. Vergleichswert und Δ % werden wie „Nr.“ und „Art“ ausgeblendet.
- **N4** Kein spürbarer Mehraufwand beim Laden. Eine zusätzliche Abfrage für die
  Vergleichsperiode ist in Ordnung.
- **N5** Rechenregeln (Σ, Δ, Δ %, Basis 0) per Unit-Test abgesichert, Anzeige per E2E.

## Akzeptanzkriterien

1. Periode 2026 gewählt, 2025 existiert → Dropdown zeigt „2025“, Umschalter „Ist“,
   Spalte „Ist 2025“ ist gefüllt.
2. Konto mit Ist 2025 = 1.100 €, Plan 2026 = 1.200 € → Δ +100,00 €, Δ % +9,1 %.
   Bei einem Ausgabenkonto ist das rot eingefärbt, bei einem Einnahmenkonto grün.
3. Umschalten auf „Plan“ → Spaltenkopf „Plan 2025“, Werte = Plansummen 2025.
4. Konto ohne Wert in 2025 → Vergleich 0,00 €, Δ = Plan, Δ % „—“.
5. Älteste Periode gewählt → „Kein Vergleich“, keine Zusatzspalten.
6. Oberkonto mit Unterkonten → Vergleichswert ist die Summe der Unterkonten.
7. Druckansicht und CSV mit aktivem Vergleich enthalten dieselben Werte wie die Tabelle.
8. Planwert ändern → Δ und Δ % aktualisieren sich nach dem Speichern.
9. Die Summenkacheln zeigen den Vergleichswert und Δ %. Bei „Kein Vergleich“
   fehlt diese Zeile.
10. Ein inaktives Konto mit Ist 2025 ≠ 0 erscheint als Zeile mit dem Hinweis
    „nicht mehr aktiv“ und ohne Planeingabe.
11. Auf dem Handy ist von den Vergleichsspalten nur Δ sichtbar.
12. Der Ausdruck nennt im Kopf die Vergleichsbasis.

## Nicht im Umfang

- Eingefrorene Plan-Stände (Snapshots) als Vergleichsbasis
- Abweichungen zum Vorjahr als Auffälligkeit im Filter
