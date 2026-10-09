<template>
	<div class="vbh-mcard vbh-membercard">
		<div class="vbh-mcard-top">
			<span class="vbh-mcard-title vbh-namecell">
				<!-- Der Name öffnet die Akte: der eine Weg dorthin. Das Menü führt nur zum Mandat. -->
				<button
					type="button"
					class="vbh-linkbtn"
					:aria-label="`${t('Akte öffnen')}: ${row.displayName}`"
					@click="$emit('open-member')">
					{{ row.displayName }}
				</button>
				<span v-if="!row.member.active" class="vbh-pill vbh-pill--muted">{{ t('ausgetreten') }}</span>
				<span v-if="!row.email" class="vbh-pill vbh-pill--warning" :title="t('keine E-Mail – keine Vorankündigung möglich')">
					<NcIconSvgWrapper :path="mdiEmailOffOutline" :size="14" />
					{{ t('keine E-Mail') }}
				</span>
			</span>
			<span v-if="row.fee" class="vbh-mcard-amount">{{ formatMoney(row.fee.amount) }}</span>
		</div>
		<div class="vbh-mcard-bottom">
			<span class="vbh-mcard-accounts">
				<template v-if="row.mandate">
					{{ row.mandate.iban }}
					<span v-if="row.mandate.statusTag" class="vbh-pill vbh-pill--warning">{{ row.mandate.statusTag }}</span>
				</template>
				<span v-else-if="row.fee && !row.fee.needsMandate">{{ t('Überweisung') }}</span>
				<span v-else>{{ t('kein Mandat') }}</span>
			</span>
		</div>
		<div v-if="row.fee || row.nextDueDate" class="vbh-mcard-bottom">
			<span class="vbh-mcard-accounts">
				<template v-if="row.fee">{{ row.fee.frequencyLabel }}</template>
				<template v-if="row.fee && row.nextDueDate"> · </template>
				<template v-if="row.nextDueDate">{{ t('fällig {date}', { date: row.nextDueDate }) }}</template>
			</span>
			<span v-if="row.fee" class="vbh-status" :class="`vbh-status--${row.fee.statusTone}`">{{ row.fee.statusLabel }}</span>
		</div>
		<p v-if="row.moreFees > 0" class="vbh-hint">
			{{ n('+ %n weitere Zuweisung', '+ %n weitere Zuweisungen', row.moreFees) }}
		</p>
		<div class="vbh-mcard-actions">
			<!-- Zuweisungen werden nicht inline bearbeitet, siehe MembersList.vue. -->
			<NcButton
				v-if="row.fee"
				variant="tertiary"
				size="small"
				@click="$emit('manage-assignments')">
				{{ t('Zuweisung verwalten') }}
			</NcButton>
			<!-- Das Mandat führen: springt in der Akte zum Mandat-Bereich, gleiches
				Muster wie in der Desktop-Tabelle (MembersList.vue). -->
			<NcActions :forceMenu="true">
				<NcActionButton closeAfterClick @click="$emit('open-mandate')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFileSign" :size="16" />
					</template>
					{{ t('Mandat verwalten') }}
				</NcActionButton>
			</NcActions>
		</div>
	</div>
</template>

<script>
import { mdiEmailOffOutline, mdiFileSign } from '@mdi/js'
import { NcActionButton, NcActions, NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import { formatMoney } from '../lib/format.js'

/**
 * Mobile Kartendarstellung einer Mitgliederzeile (MembersList.vue): dieselben
 * Angaben und Aktionen wie die Desktop-Tabellenzeile, nur gestapelt statt in
 * sieben nebeneinanderliegenden Spalten - eine Tabelle mit so vielen Spalten
 * lief auf schmalen Bildschirmen sonst auf Ein-Zeichen-pro-Zeile-Zeilenumbruch
 * hinaus (table-layout: fixed + zu wenig Platz je Spalte).
 */
export default {
	name: 'MemberCard',
	components: { NcButton, NcActions, NcActionButton, NcIconSvgWrapper },
	props: {
		row: { type: Object, required: true },
	},

	emits: ['manage-assignments', 'open-mandate', 'open-member'],

	data() {
		return { mdiEmailOffOutline, mdiFileSign }
	},

	methods: { formatMoney },
}
</script>
