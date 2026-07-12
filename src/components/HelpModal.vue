<template>
	<NcModal :show="show" name="Hilfe" size="normal" @close="$emit('close')" @update:show="$emit('update:show', $event)">
		<div class="vbh-help">
			<nav class="vbh-help-nav">
				<button
					v-for="t in topics" :key="t.id"
					:class="{ active: t.id === currentTopic }"
					@click="currentTopic = t.id">
					{{ t.label }}
				</button>
			</nav>
			<div class="vbh-help-body">
				<h4>{{ current.label }}</h4>
				<ul>
					<li v-for="(b, i) in current.bullets" :key="i">{{ b }}</li>
				</ul>
				<a :href="handbuchLink" target="_blank" rel="noopener noreferrer" class="vbh-help-full">
					Vollständiges Handbuch öffnen ↗
				</a>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal } from '@nextcloud/vue'
import api from '../api.js'

// Kurzfassungen je App-Bereich, kein Volltext des Handbuchs – Anker verweisen
// auf das passende Kapitel in HANDBUCH.md (Slugs siehe HelpController::slugify).
const TOPICS = [
	{
		id: 'setup',
		label: 'Ersteinrichtung',
		anchor: '2-ersteinrichtung-einmalig',
		bullets: [
			'Zahnrad → Berechtigungen: Nutzer/Gruppen als Verwalter, Buchhalter oder Revisor eintragen.',
			'Tab Konten → „Standard-Kontenrahmen anlegen" – oder Einstellungen → „Aus zero Buchhaltung importieren", falls vorhanden.',
			'Je Geldkonto (Bank/Kasse) einen Anfangsbestand als Eröffnungssaldo eintragen.',
			'Einstellungen → Belegablage und Verein: Speicherort für Belege und Vereinsnamen festlegen.',
		],
	},
	{
		id: 'bookings',
		label: 'Buchen & zuordnen',
		anchor: '4-die-laufende-arbeit-buchen-und-zuordnen',
		bullets: [
			'Kontoumsätze importieren: Tab Buchungen → CSV-Datei der Bank hochladen (Dubletten werden automatisch erkannt).',
			'Tab „Zuzuordnen": jede offene Bankbuchung bekommt ein Gegenkonto – Vorschläge und Regeln übernehmen das oft automatisch.',
			'Manuelle Buchung: Button „+ Buchung" – im Einfach-Modus reicht Einnahme/Ausgabe, Kategorie und Geldkonto.',
			'Belege lassen sich direkt an jede Buchung anhängen (Foto oder Datei).',
		],
	},
	{
		id: 'accounts',
		label: 'Konten',
		anchor: '2-2-kontenrahmen-anlegen',
		bullets: [
			'Jedes Konto hat eine Nummer, einen Namen und einen Typ (Einnahmen, Ausgaben, Anlage/Umlauf, Verbindlichkeit, Eigenkapital).',
			'Das Flag „Bankkonto" markiert Geldkonten (Bank/Kasse) – nur diese führen einen Kontostand über die Jahresgrenze fort.',
			'Konten lassen sich über- und unterordnen (Feld „Übergeordnet") für eine Baumstruktur.',
			'Auf ein Konto klicken zeigt den Kontoauszug mit laufendem Saldo.',
		],
	},
	{
		id: 'reports',
		label: 'Berichte',
		anchor: '5-auswertungen-verstehen',
		bullets: [
			'Dashboard: Einnahmen/Ausgaben/Ergebnis des gewählten Jahres mit Vorjahresvergleich.',
			'Saldenliste: alle Konten mit Soll, Haben und Saldo, optional inklusive Unterkonten.',
			'Kassenbericht (Berichte → Auswertung): druckfertige Zusammenfassung für die Mitgliederversammlung.',
			'Prüfleitfaden (Berichte → Auswertung): druckfertige Kurzanleitung für Kassenprüfer/innen.',
			'Kostenstellen und Finanzplan: Auswertung je Projekt bzw. Soll-Ist-Vergleich.',
		],
	},
]

export default {
	name: 'HelpModal',
	components: { NcModal },
	props: {
		show: { type: Boolean, default: false },
		// Gewünschtes Kapitel beim Öffnen; der Nutzer kann innerhalb der Hilfe frei weiterklicken.
		topic: { type: String, default: 'setup' },
	},
	data() {
		return {
			topics: TOPICS,
			currentTopic: this.topic,
		}
	},
	computed: {
		current() {
			return this.topics.find(t => t.id === this.currentTopic) || this.topics[0]
		},
		handbuchLink() {
			return api.handbuchUrl(this.current.anchor)
		},
	},
	watch: {
		show(open) {
			if (open) this.currentTopic = this.topic
		},
	},
}
</script>
