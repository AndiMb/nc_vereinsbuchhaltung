# ADR-0001: `@nextcloud/vue` nur je Komponente importieren

**Status:** angenommen (2026-10-25, Release 0.42.0)

## Kontext

`@nextcloud/vue` (Version 9) bietet zwei Importwege:

```js
import { NcButton, NcModal } from '@nextcloud/vue'                // Sammelmodul
import NcButton from '@nextcloud/vue/components/NcButton'          // je Komponente
```

Das Paket deklariert kein `sideEffects`-Feld in seiner `package.json`. Ein
Bundler muss deshalb annehmen, dass jedes Modul des Pakets Seiteneffekte hat
(die Komponenten registrieren CSS), und darf beim Sammelimport nichts
weglassen. Mit dem Sammelmodul landete darum die komplette Bibliothek im
Bundle, darunter Teile, die die App nie nutzt: der Emoji-Picker mit 870 KiB
CSS, NcRichText samt highlight.js, NcAppSidebar und das CSS des Date-Pickers.

Gemessen am 25.10.2026 (minifiziert, ohne gzip):

| Bundle | Sammelimport | Einzelimporte |
|---|---|---|
| `js/vereinsbuchhaltung-main.js` | 5,38 MiB | 2,81 MiB |
| `js/vereinsbuchhaltung-settings.js` | 4,02 MiB | 1,38 MiB |

Über die Leitung (gzip) schrumpfte main.js von 1,15 MiB auf 0,68 MiB.

## Entscheidung

Komponenten, Composables, Direktiven und Funktionen aus `@nextcloud/vue`
werden ausschließlich über ihren Unterpfad importiert:

```js
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import { useIsMobile } from '@nextcloud/vue/composables/useIsMobile'
```

Welche Unterpfade es gibt, steht in `node_modules/@nextcloud/vue/package.json`
unter `exports` (`./components/*`, `./composables/*`, `./directives/*`,
`./functions/*`) und als Verzeichnisse unter `node_modules/@nextcloud/vue/dist/`.

Der Sammelimport `from '@nextcloud/vue'` ist per ESLint verboten
(`no-restricted-imports` in `eslint.config.mjs`); `npm run lint` schlägt
damit fehl, bevor der Fehler ins Bundle wandert.

## Konsequenzen

- Jede neue Komponente braucht eine eigene Importzeile. ESLint sortiert sie
  (`perfectionist/sort-imports`, per `npx eslint src/ --fix`).
- Die Bundle-Größe bleibt bei dem, was die App tatsächlich nutzt. Webpacks
  Warnung zur 244-KiB-Grenze bleibt trotzdem; sie ist der generische
  Webpack-Default und für Nextcloud-Apps nicht erreichbar.
- Wer prüfen will, was im Bundle steckt: `npm run build`, dann
  `npx --yes source-map-explorer js/vereinsbuchhaltung-main.js --json --no-border-checks`
  und nach Paketen aufsummieren.
- Ein Umstieg auf Vite würde an dieser Regel nichts ändern: Das Problem ist
  der Sammelimport ohne `sideEffects`-Angabe, nicht der Bundler.
