<template>
	<div class="vbh-mcard vbh-membercard">
		<div class="vbh-mcard-top">
			<span class="vbh-mcard-title vbh-namecell">
				<!-- Der Name öffnet die Akte; das Menü (⋯) bietet dasselbe mit Namen an, dazu Mandat und Beitrag. -->
				<button
					type="button"
					class="vbh-linkbtn"
					:aria-label="`${t('Akte öffnen')}: ${row.displayName}`"
					@click="$emit('open-member', '')">
					{{ row.displayName }}
				</button>
				<span v-if="!row.member.active" class="vbh-pill vbh-pill--muted">{{ t('ausgetreten') }}</span>
				<span v-if="!row.email" class="vbh-pill vbh-pill--quiet" :title="t('keine E-Mail – keine Vorankündigung möglich')">
					<NcIconSvgWrapper :path="mdiEmailOffOutline" :size="14" inline />
					{{ t('keine E-Mail') }}
				</span>
			</span>
			<span v-if="row.fee" class="vbh-mcard-amount">{{ row.fee.free ? t('beitragsfrei') : formatMoney(row.fee.amount) }}</span>
		</div>
		<div class="vbh-mcard-bottom">
			<span class="vbh-mcard-accounts">
				<template v-if="row.mandate">
					{{ row.mandate.iban }}
					<span v-if="row.mandate.statusTag" class="vbh-pill vbh-pill--warning">{{ row.mandate.statusTag }}</span>
				</template>
				<span v-else-if="row.fee && row.fee.free">–</span>
				<span v-else-if="row.fee && !row.fee.needsMandate">{{ t('Überweisung') }}</span>
				<span v-else>{{ t('kein Mandat') }}</span>
			</span>
		</div>
		<div v-if="row.fee || row.nextDueDate" class="vbh-mcard-bottom">
			<span class="vbh-mcard-accounts">
				<template v-if="row.fee">{{ row.fee.frequencyLabel }}</template>
				<template v-if="row.fee && row.nextDueDate"> · </template>
				<template v-if="row.nextDueDate">{{ t('fällig {date}', { date: formatDate(row.nextDueDate) }) }}</template>
			</span>
			<span v-if="row.fee" class="vbh-status" :class="`vbh-status--${row.fee.statusTone}`">{{ row.fee.statusLabel }}</span>
		</div>
		<p v-if="row.moreFees > 0" class="vbh-hint">
			{{ n('+ %n weitere Zuweisung', '+ %n weitere Zuweisungen', row.moreFees) }}
		</p>
		<div class="vbh-mcard-actions">
			<!-- Dasselbe Menü wie in der Desktop-Tabelle (MembersList.vue): Mitglied, Mandat, Beitrag. -->
			<MemberRowMenu
				:row="row"
				@openMember="(section) => $emit('open-member', section)"
				@manageAssignments="(mode) => $emit('manage-assignments', mode)" />
		</div>
	</div>
</template>

<script>
import { mdiEmailOffOutline } from '@mdi/js'
import { NcIconSvgWrapper } from '@nextcloud/vue'
import MemberRowMenu from './MemberRowMenu.vue'
import { formatDate, formatMoney } from '../lib/format.js'

/**
 * Mobile Kartendarstellung einer Mitgliederzeile (MembersList.vue): dieselben
 * Angaben und Aktionen wie die Desktop-Tabellenzeile, nur gestapelt statt in
 * sieben nebeneinanderliegenden Spalten - eine Tabelle mit so vielen Spalten
 * lief auf schmalen Bildschirmen sonst auf Ein-Zeichen-pro-Zeile-Zeilenumbruch
 * hinaus (table-layout: fixed + zu wenig Platz je Spalte).
 */
export default {
	name: 'MemberCard',
	components: { NcIconSvgWrapper, MemberRowMenu },
	props: {
		row: { type: Object, required: true },
	},

	emits: ['manage-assignments', 'open-member'],

	data() {
		return { mdiEmailOffOutline }
	},

	methods: { formatDate, formatMoney },
}
</script>
