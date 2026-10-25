## Agent skills

### Issue tracker

Issues leben als GitHub Issues in `AndiMb/nc_vereinsbuchhaltung`. Siehe `docs/agents/issue-tracker.md`.

### Triage labels

Standard-Triage-Labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). Siehe `docs/agents/triage-labels.md`.

### Domain docs

Single-Context: `CONTEXT.md` + `docs/adr/` im Repo-Root. Siehe `docs/agents/domain.md`.

## Frontend-Konventionen

### `@nextcloud/vue` je Komponente importieren

Nie `import { NcButton } from '@nextcloud/vue'`, immer
`import NcButton from '@nextcloud/vue/components/NcButton'` (analog
`composables/*`, `directives/*`, `functions/*`). Der Sammelimport zieht die
ganze Bibliothek ins Bundle; ESLint verbietet ihn. Begründung und Messung:
`docs/adr/0001-nextcloud-vue-einzelimporte.md`.
