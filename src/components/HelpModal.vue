<template>
	<NcModal
		:show="show"
		:name="t('Hilfe')"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-help">
			<nav class="vbh-help-nav">
				<button
					v-for="t in topics"
					:key="t.id"
					:class="{ active: t.id === currentTopic }"
					@click="currentTopic = t.id">
					{{ t.label }}
				</button>
			</nav>
			<div class="vbh-help-body">
				<h4>{{ current.label }}</h4>
				<ul>
					<li v-for="(b, i) in current.bullets" :key="i">
						{{ b }}
					</li>
				</ul>
				<a
					:href="handbuchLink"
					target="_blank"
					rel="noopener noreferrer"
					class="vbh-help-full">
					{{ t('Vollständiges Handbuch öffnen ↗') }}
				</a>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal } from '@nextcloud/vue'
import api from '../api.js'
import { t } from '../lib/l10n.js'

// Kurzfassungen je App-Bereich, kein Volltext des Handbuchs – Anker verweisen
// auf das passende Kapitel in HANDBUCH.md (Slugs siehe HelpController::slugify).
//
// Als Funktion statt Modul-Konstante, weil t() sonst beim Import ausgewertet
// würde - noch bevor main.js die Übersetzungen geladen hat (siehe
// loadAppTranslations() in main.js). Aufgerufen wird sie erst in data(),
// wenn die Komponente instanziiert wird und die Übersetzungen schon da sind.
function buildTopics() {
	return [
		{
			id: 'setup',
			label: t('Ersteinrichtung'),
			anchor: '2-ersteinrichtung-einmalig',
			bullets: [
				t('Zahnrad → Berechtigungen: Nutzer/Gruppen als Verwalter, Buchhalter oder Revisor eintragen.'),
				t('Tab Konten → „Standard-Kontenrahmen anlegen" – oder Zahnrad → Daten → „Aus zero Buchhaltung importieren", falls vorhanden.'),
				t('Je Geldkonto (Bank/Kasse) einen Anfangsbestand als Eröffnungssaldo eintragen.'),
				t('Zahnrad → Belege und Verein: Speicherort für Belege und Vereinsnamen festlegen.'),
			],
		},
		{
			id: 'bookings',
			label: t('Buchen & zuordnen'),
			anchor: '4-die-laufende-arbeit-buchen-und-zuordnen',
			bullets: [
				t('Kontoumsätze importieren: Tab Buchungen → CSV-Datei der Bank hochladen (Dubletten werden automatisch erkannt).'),
				t('Tab „Zuzuordnen": jede offene Bankbuchung bekommt ein Gegenkonto – Vorschläge und Regeln übernehmen das oft automatisch.'),
				t('Unterreiter „Regeln" (nur Verwalter/Buchhalter): wiederkehrende Zuordnungen dauerhaft hinterlegen, statt sie jedes Mal von Hand zu setzen.'),
				t('Enthält ein Umsatz mehreres zugleich (Beitrag und Spende): „Aufteilen…" verteilt ihn auf mehrere Gegenkonten.'),
				t('Manuelle Buchung: Button „+ Buchung" – im Einfach-Modus reicht Einnahme/Ausgabe, Kategorie und Geldkonto.'),
				t('Belege lassen sich direkt an jede Buchung anhängen (Foto oder Datei).'),
			],
		},
		{
			id: 'accounts',
			label: t('Konten'),
			anchor: '2-2-kontenrahmen-anlegen',
			bullets: [
				t('Jedes Konto hat eine Nummer, einen Namen und einen Typ (Einnahmen, Ausgaben, Anlage/Umlauf, Verbindlichkeit, Eigenkapital).'),
				t('Das Flag „Bankkonto" markiert Geldkonten (Bank/Kasse) – nur diese führen einen Kontostand über die Jahresgrenze fort.'),
				t('Konten lassen sich über- und unterordnen (Feld „Übergeordnet") für eine Baumstruktur.'),
				t('Bebuchte Konten lassen sich nicht löschen, aber über den Schalter „Konto aktiv" stilllegen – sie verschwinden aus den Auswahllisten, die Historie bleibt.'),
				t('Auf ein Konto klicken zeigt den Kontoauszug mit laufendem Saldo.'),
			],
		},
		{
			id: 'reports',
			label: t('Berichte'),
			anchor: '5-auswertungen-verstehen',
			bullets: [
				t('Dashboard: Einnahmen/Ausgaben/Ergebnis des gewählten Jahres mit Vorjahresvergleich.'),
				t('Saldenliste: alle Konten mit Soll, Haben und Saldo, optional inklusive Unterkonten.'),
				t('Kassenbericht (Berichte → Auswertung): druckfertige Zusammenfassung für die Mitgliederversammlung.'),
				t('Prüfleitfaden (Berichte → Auswertung): druckfertige Kurzanleitung für Kassenprüfer/innen.'),
				t('Kostenstellen, Sphären und Finanzplan: Auswertung je Projekt, je Steuerkategorie bzw. Soll-Ist-Vergleich.'),
			],
		},
		{
			id: 'sepa',
			label: t('Beiträge & SEPA'),
			anchor: '13-mitgliedsbeitraege-und-sepa-lastschrift',
			bullets: [
				t('Optionales Zusatzmodul: wer keine Lastschriften einzieht, kann diesen Bereich ignorieren.'),
				t('Zahnrad → Beiträge & SEPA: Gläubiger-ID, einziehendes Konto und der Schalter für den Reiter „Beiträge" in der Hauptnavigation.'),
				t('Reiter „Beiträge" → Mitglieder: je Zahler ein Mandat (IBAN, Unterschriftsdatum) und/oder ein Beitrag (Betrag, Frequenz) – Zahler ist ein Nextcloud-Nutzer oder ein frei eingetragener Name. Bei Fälligkeit entsteht automatisch ein offener Posten.'),
				t('Reiter „Beiträge" → Einzug: Vorschau prüfen, Einzug erzeugen und die XML-Datei bei der Hausbank einreichen. Vor dem ersten Einzug mit dem Prüftool der Bank testen.'),
				t('Fälligkeitstermin mindestens 14 Tage in die Zukunft legen: so lange vorher muss der Zahler über Betrag und Termin informiert werden. Die App verschickt diese Ankündigung an Mitglieder mit hinterlegter E-Mail-Adresse.'),
				t('Ein Mandat wird widerrufen, nicht gelöscht – erzeugte Einreichungen müssen nachvollziehbar bleiben.'),
			],
		},
		{
			id: 'spheres',
			label: t('Sphären'),
			anchor: '5-6-steuerliche-sphaeren',
			bullets: [
				t('Vier steuerliche Sphären: ideeller Bereich, Vermögensverwaltung, Zweckbetrieb, wirtschaftlicher Geschäftsbetrieb.'),
				t('Zuordnen im Konto-Dialog (Feld „Steuerliche Sphäre") oder für mehrere Konten auf einmal über den Button „Sphären zuordnen" im Bericht „Sphären" (nur Verwalter/Buchhalter).'),
				t('Tab Berichte → „Sphären" zeigt Einnahmen/Ausgaben/Ergebnis je Sphäre; oben rechts öffnet „Sphären zuordnen" die Zuordnung zum Bearbeiten.'),
				t('Freigrenze wirtschaftlicher Geschäftsbetrieb: aktuell 45.000 € Bruttoeinnahmen/Jahr – das Dashboard warnt per Ampel, sobald es dort Einnahmen gibt. Ersetzt keine steuerliche Beratung.'),
			],
		},
	]
}

export default {
	name: 'HelpModal',
	components: { NcModal },
	props: {
		show: { type: Boolean, default: false },
		// Gewünschtes Kapitel beim Öffnen; der Nutzer kann innerhalb der Hilfe frei weiterklicken.
		topic: { type: String, default: 'setup' },
	},

	emits: ['close', 'update:show'],

	data() {
		return {
			topics: buildTopics(),
			currentTopic: this.topic,
		}
	},

	computed: {
		current() {
			return this.topics.find((t) => t.id === this.currentTopic) || this.topics[0]
		},

		handbuchLink() {
			return api.handbuchUrl(this.current.anchor)
		},
	},

	watch: {
		show(open) {
			if (open) { this.currentTopic = this.topic }
		},
	},
}
</script>
