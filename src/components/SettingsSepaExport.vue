<template>
	<div>
		<h3 class="vbh-section-divider">
			{{ t('SEPA-Sammeleinzug') }}
		</h3>
		<p class="vbh-hint">
			{{ t('Erzeugt eine SEPA-XML-Datei (pain.008) aus allen offenen Posten mit aktivem Mandat, die noch in keinem laufenden Einzug stecken. Vor dem ersten echten Einzug unbedingt mit dem Prüftool der Hausbank testen – das genaue Format kann je nach Bank leicht abweichen.') }}
		</p>

		<div v-if="sepaPreview.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Zahler') }}</th>
						<th class="num">
							{{ t('Betrag') }}
						</th>
						<th>{{ t('Mandatsreferenz') }}</th>
						<th>{{ t('Art') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in sepaPreview" :key="row.openItem.id">
						<td>{{ row.debtorName }}</td>
						<td class="num nowrap">
							{{ formatMoney(row.openItem.amount) }}
						</td>
						<td class="nowrap">
							{{ row.mandate.mandateReference }}
						</td>
						<td>{{ row.sequenceType }}</td>
					</tr>
				</tbody>
			</table>
			<div class="vbh-form">
				<label>{{ t('Fälligkeitsdatum') }}
					<input v-model="executionDate" type="date">
				</label>
				<NcButton variant="primary" :disabled="!executionDate || creating" @click="createBatch">
					{{ t('Einzug erzeugen') }}
				</NcButton>
			</div>
		</div>
		<p v-else class="vbh-hint">
			{{ t('Zurzeit keine offenen Posten mit aktivem SEPA-Mandat fällig zum Einzug.') }}
		</p>

		<div v-if="sepaBatches.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Erzeugt') }}</th>
						<th>{{ t('Fälligkeit') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="b in sepaBatches" :key="b.id">
						<td class="nowrap">
							{{ b.createdAt }}
						</td>
						<td class="nowrap">
							{{ b.executionDate }}
						</td>
						<td class="nowrap right">
							<a :href="xmlUrl(b.id)"
								target="_blank"
								rel="noopener"
								class="vbh-export-btn">{{ t('XML herunterladen') }}</a>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { useSepaBatches } from '../composables/useSepaBatches.js'

/**
 * Erzeugen und Herunterladen von SEPA-Sammeleinzügen. Nur für Verwalter
 * erreichbar (siehe SepaBatchController).
 */
export default {
	name: 'SettingsSepaExport',
	components: { NcButton },
	setup() {
		const sepaBatches = useSepaBatches()
		return {
			...toRefs(sepaBatches.state),
			loadSepaPreview: sepaBatches.loadSepaPreview,
			loadSepaBatches: sepaBatches.loadSepaBatches,
		}
	},
	data() {
		return {
			executionDate: '',
			creating: false,
		}
	},
	mounted() {
		this.loadSepaPreview()
		this.loadSepaBatches()
	},
	methods: {
		errMsg,
		formatMoney,
		xmlUrl(id) { return api.sepaBatchXmlUrl(id) },
		async createBatch() {
			this.creating = true
			try {
				await api.createSepaBatch(this.executionDate)
				this.executionDate = ''
				await Promise.all([this.loadSepaPreview(), this.loadSepaBatches()])
				showSuccess(this.t('SEPA-Einzug erzeugt.'))
			} catch (e) { showError(this.errMsg(e, this.t('Einzug konnte nicht erzeugt werden'))) } finally { this.creating = false }
		},
	},
}
</script>
