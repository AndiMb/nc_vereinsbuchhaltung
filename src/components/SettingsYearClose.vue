<template>
	<div>
		<h3 class="vbh-section-divider">Jahresabschluss</h3>
		<div class="vbh-card">
			<p class="vbh-hint">
				Ein abgeschlossenes Geschäftsjahr ist <strong>festgeschrieben</strong>: Buchungen, Belege und
				Zuordnungen dieses Jahres können nicht mehr geändert oder gelöscht werden – z. B. nach der
				Kassenprüfung und Entlastung. Nur Verwalter können ein Jahr abschließen oder wiedereröffnen;
				beides wird im Protokoll (Berichte → Protokoll) festgehalten.
			</p>
			<div v-if="years.length" class="vbh-tablecard">
				<table class="vbh-table">
					<thead><tr><th>Jahr</th><th>Status</th><th class="right"></th></tr></thead>
					<tbody>
						<tr v-for="y in years" :key="'yc' + y">
							<td class="strong">{{ y }}</td>
							<td>
								<template v-if="closedYearSet[y]">
									🔒 abgeschlossen am {{ formatDate(String(closedYearSet[y].closedAt).slice(0, 10)) }}
									von {{ closedYearSet[y].closedBy }}
								</template>
								<template v-else>offen</template>
							</td>
							<td class="right">
								<NcButton v-if="!closedYearSet[y]" variant="primary" size="small" @click="closeYear(y)">Abschließen</NcButton>
								<NcButton v-else variant="tertiary" size="small" @click="reopenYear(y)">Wiedereröffnen</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<p v-else class="vbh-hint">Noch keine Geschäftsjahre mit Buchungen vorhanden.</p>
		</div>

		<div class="vbh-card vbh-card--danger">
			<h4>Alle Daten löschen</h4>
			<p class="vbh-hint">Löscht alle Konten, Buchungen und Importe dieses Kontos unwiderruflich.</p>
			<NcButton variant="error" :disabled="busy" @click="resetAll">Alle Daten löschen</NcButton>
		</div>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../api.js'
import { formatDate, errMsg } from '../lib/format.js'
import { useYears } from '../composables/useYears.js'

export default {
	name: 'SettingsYearClose',
	components: { NcButton },
	props: {
		askConfirm: { type: Function, required: true },
		// gemeinsames App-weites Ladeflag (auch vom Demo-Banner ausserhalb des
		// Einstellungen-Modals verwendet)
		busy: { type: Boolean, required: true },
		// resetAll bleibt in App.vue, da auch der Demo-Banner ausserhalb des
		// Einstellungen-Modals denselben Reset-Button anbietet
		resetAll: { type: Function, required: true },
	},
	setup() {
		// years/closedYearSet/loadClosedYears kommen direkt aus dem useYears-
		// Singleton (gleicher geteilter Zustand wie in App.vue).
		const years = useYears()
		return {
			...toRefs(years.state),
			closedYearSet: years.closedYearSet,
			loadClosedYears: years.loadClosedYears,
		}
	},
	methods: {
		formatDate,
		errMsg,
		async closeYear(year) {
			if (!await this.askConfirm(
				`Jahr ${year} abschließen`,
				`Das Geschäftsjahr ${year} wird festgeschrieben: Buchungen, Belege und Zuordnungen dieses Jahres können danach nicht mehr geändert werden. Ein Verwalter kann das Jahr bei Bedarf wiedereröffnen.`,
				'Abschließen', 'primary')) return
			try {
				await api.closeYear(year)
				await this.loadClosedYears()
				showSuccess(`Geschäftsjahr ${year} abgeschlossen.`)
			} catch (e) { showError(this.errMsg(e, 'Abschließen fehlgeschlagen')) }
		},
		async reopenYear(year) {
			if (!await this.askConfirm(
				`Jahr ${year} wiedereröffnen`,
				`Das Geschäftsjahr ${year} wird wieder änderbar. Das sollte nur in Ausnahmefällen geschehen (z. B. Korrektur vor der Kassenprüfung) und wird protokolliert.`,
				'Wiedereröffnen', 'error')) return
			try {
				await api.reopenYear(year)
				await this.loadClosedYears()
				showSuccess(`Geschäftsjahr ${year} wiedereröffnet.`)
			} catch (e) { showError(this.errMsg(e, 'Wiedereröffnen fehlgeschlagen')) }
		},
	},
}
</script>
