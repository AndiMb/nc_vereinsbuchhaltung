import { vi } from 'vitest'

// Die Tests der Anzeigehelfer erwarten die deutsche Schreibweise (31.01.2026). Ohne
// Nextcloud-Seite liefert getLocale() Englisch; hier gilt wie in der Instanz de_DE.
vi.mock('@nextcloud/l10n', async (importOriginal) => ({ ...(await importOriginal()), getLocale: () => 'de_DE' }))
