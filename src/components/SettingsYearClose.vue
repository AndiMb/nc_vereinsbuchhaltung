<template>
	<div>
		<h3 class="vbh-section-divider">
			{{ t('Jahresabschluss') }}
		</h3>
		<div class="vbh-card">
			<p class="vbh-hint">
				{{ t('Ein abgeschlossenes Geschäftsjahr ist') }} <strong>{{ t('festgeschrieben') }}</strong>{{ t(': Buchungen, Belege und Zuordnungen dieses Jahres können nicht mehr geändert oder gelöscht werden – z. B. nach der Kassenprüfung und Entlastung. Nur Verwalter können ein Jahr abschließen oder wiedereröffnen; beides wird im Protokoll (Berichte → Protokoll) festgehalten.') }}
			</p>
			<div v-if="years.length" class="vbh-tablecard">
				<table class="vbh-table">
					<thead><tr><th>{{ t('Jahr') }}</th><th>{{ t('Status') }}</th><th class="right" /></tr></thead>
					<tbody>
						<tr v-for="y in years" :key="'yc' + y">
							<td class="strong">
								{{ y }}
							</td>
							<td>
								<template v-if="closedYearSet[y]">
									{{ t('🔒 abgeschlossen am {date} von {who}', { date: formatDate(String(closedYearSet[y].closedAt).slice(0, 10)), who: closedYearSet[y].closedBy }) }}
								</template>
								<template v-else>
									{{ t('offen') }}
								</template>
							</td>
							<td class="right">
								<NcButton v-if="!closedYearSet[y]"
									variant="primary"
									size="small"
									@click="closeYear(y)">
									{{ t('Abschließen') }}
								</NcButton>
								<NcButton v-else
									variant="tertiary"
									size="small"
									@click="reopenYear(y)">
									{{ t('Wiedereröffnen') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<p v-else class="vbh-hint">
				{{ t('Noch keine Geschäftsjahre mit Buchungen vorhanden.') }}
			</p>
		</div>

		<div class="vbh-card vbh-card--danger">
			<h4>{{ t('Alle Daten löschen') }}</h4>
			<p class="vbh-hint">
				{{ t('Löscht alle Konten, Buchungen und Importe dieses Kontos unwiderruflich.') }}
			</p>
			<NcButton variant="error" :disabled="busy" @click="resetAll">
				{{ t('Alle Daten löschen') }}
			</NcButton>
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
import { useConfirm } from '../composables/useConfirm.js'

export default {
	name: 'SettingsYearClose',
	components: { NcButton },
	props: {
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
			askConfirm: useConfirm().askConfirm,
		}
	},
	methods: {
		formatDate,
		errMsg,
		async closeYear(year) {
			if (!await this.askConfirm(
				this.t('Jahr {year} abschließen', { year }),
				this.t('Das Geschäftsjahr {year} wird festgeschrieben: Buchungen, Belege und Zuordnungen dieses Jahres können danach nicht mehr geändert werden. Ein Verwalter kann das Jahr bei Bedarf wiedereröffnen.', { year }),
				this.t('Abschließen'), 'primary')) return
			try {
				await api.closeYear(year)
				await this.loadClosedYears()
				showSuccess(this.t('Geschäftsjahr {year} abgeschlossen.', { year }))
			} catch (e) { showError(this.errMsg(e, this.t('Abschließen fehlgeschlagen'))) }
		},
		async reopenYear(year) {
			if (!await this.askConfirm(
				this.t('Jahr {year} wiedereröffnen', { year }),
				this.t('Das Geschäftsjahr {year} wird wieder änderbar. Das sollte nur in Ausnahmefällen geschehen (z. B. Korrektur vor der Kassenprüfung) und wird protokolliert.', { year }),
				this.t('Wiedereröffnen'), 'error')) return
			try {
				await api.reopenYear(year)
				await this.loadClosedYears()
				showSuccess(this.t('Geschäftsjahr {year} wiedereröffnet.', { year }))
			} catch (e) { showError(this.errMsg(e, this.t('Wiedereröffnen fehlgeschlagen'))) }
		},
	},
}
</script>
