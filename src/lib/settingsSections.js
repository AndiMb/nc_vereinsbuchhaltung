import { t } from './l10n.js'

/**
 * Abschnitte der Einstellungsseite (SettingsApp.vue) in der Reihenfolge, in
 * der sie dort stehen – eine Quelle für Überschrift, Handbuch-Kapitel und
 * Inhaltsverzeichnis, damit die drei nicht auseinanderlaufen.
 *
 * Die Reihenfolge folgt der Aufgabe statt der Entstehungsgeschichte: zuerst
 * was jeder Verein einmal einrichtet (Verein, Berechtigungen, Ablage,
 * Geschäftsjahr), dann zwei kleine vereinsweite Anzeigevorgaben, dann die
 * optionalen Module mit allem, was zu ihnen gehört, zuletzt Datenübernahme,
 * Sicherung und die Löschwege nebeneinander.
 *
 * `id` ist der Anker `settings-section_<id>`. Die IDs sind Schnittstelle:
 * Checkliste (setupSteps.js), Prüfliste (invoices.js), Dashboard,
 * Terminplan-Dialog, der Start-Assistent in App.vue und die E2E-Tests
 * springen dorthin. Nie umbenennen, nur neue hinzufügen.
 *
 * `help` ist der stabile Kapitel-Anker im Handbuch (HandbookMarkdownRenderer,
 * "section-<Kapitel>"), sprachunabhängig.
 *
 * Als Funktion statt Konstante: t() erst beim Aufruf auswerten, wenn die
 * Übersetzungen sicher geladen sind (siehe HelpModal.vue).
 *
 * @return {{id: string, group: string, name: string, help: string}[]}
 */
export function settingsSections() {
	return [
		{ id: 'verein', group: 'basics', name: t('Verein'), help: 'section-2-5' },
		{ id: 'berechtigungen', group: 'basics', name: t('Berechtigungen'), help: 'section-2-1' },
		{ id: 'belege', group: 'basics', name: t('Belege'), help: 'section-2-4' },
		{ id: 'bankdaten', group: 'basics', name: t('Kontoauszüge automatisch einlesen'), help: 'section-3-3' },
		{ id: 'geschaeftsjahr', group: 'basics', name: t('Geschäftsjahr'), help: 'section-8-1' },
		{ id: 'darstellung', group: 'display', name: t('Darstellung'), help: 'section-2' },
		{ id: 'ampel', group: 'display', name: t('Ampel im Gliederungsbericht'), help: 'section-7-5' },
		{ id: 'beitraege-sepa', group: 'modules', name: t('Beiträge & SEPA'), help: 'section-13-1' },
		{ id: 'mandats-rechtstext', group: 'modules', name: t('Mandats-Rechtstext'), help: 'section-13-2' },
		{ id: 'willkommens-mail', group: 'modules', name: t('Willkommens-Mail'), help: 'section-13-2' },
		{ id: 'zuwendungen', group: 'modules', name: t('Zuwendungsbestätigungen'), help: 'section-13-12' },
		{ id: 'rechnungen', group: 'modules', name: t('Rechnungen'), help: 'section-15-1' },
		{ id: 'auslagen', group: 'modules', name: t('Auslagenerstattung'), help: 'section-4-6' },
		{ id: 'kostenstellen', group: 'modules', name: t('Kostenstellen'), help: 'section-5-8' },
		{ id: 'daten', group: 'data', name: t('Datenübernahme'), help: 'section-3-1' },
		{ id: 'sicherung', group: 'data', name: t('Sicherung und Umzug'), help: 'section-12-3' },
		{ id: 'zuruecksetzen', group: 'data', name: t('Zurücksetzen'), help: 'section-12-1' },
	]
}

/**
 * Gruppen des Inhaltsverzeichnisses, je mit ihren Abschnitten.
 *
 * Ein ausgeschaltetes Modul bleibt im Verzeichnis, mit dem Vermerk
 * „ausgeschaltet“ (`off`): man soll es finden, um es einzuschalten.
 * Abschnitte, die die Seite gerade gar nicht zeigt (`hidden`), fehlen.
 *
 * @param {{id: string, group: string, name: string}[]} sections aus settingsSections()
 * @param {{off?: Set<string>, hidden?: Set<string>}} [state] Zustand der Module
 * @return {{name: string, entries: {id: string, name: string, off: boolean}[]}[]}
 */
export function tocGroups(sections, { off = new Set(), hidden = new Set() } = {}) {
	const names = {
		basics: t('Grundlagen'),
		display: t('Listen und Berichte'),
		modules: t('Module'),
		data: t('Daten und Sicherung'),
	}
	return Object.keys(names)
		.map((group) => ({
			name: names[group],
			entries: sections
				.filter((s) => s.group === group && !hidden.has(s.id))
				.map(({ id, name }) => ({ id, name, off: off.has(id) })),
		}))
		.filter((g) => g.entries.length > 0)
}
