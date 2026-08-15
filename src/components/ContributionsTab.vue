<template>
	<div style="display: contents;">
		<div class="vbh-sectiontop">
			<div class="vbh-subtabs">
				<button :class="{ active: contribView === 'members' }" @click="$emit('update:contrib-view', 'members')">
					{{ t('Mitglieder') }}
					<span v-if="overdueMembershipCount > 0" class="vbh-badge vbh-badge--alert">{{ overdueMembershipCount }}</span>
				</button>
				<button :class="{ active: contribView === 'batch' }" @click="$emit('update:contrib-view', 'batch')">
					{{ t('Einzug') }}
				</button>
			</div>
			<div v-if="contribView === 'members'" class="vbh-sectiontop-actions">
				<NcButton variant="secondary" @click="$refs.membersList.openImportDialog()">
					{{ t('Liste einlesen') }}
				</NcButton>
				<NcButton variant="primary" @click="$refs.membersList.openMemberDialog()">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" :size="20" />
					</template>
					{{ t('Mitglied') }}
				</NcButton>
			</div>
		</div>

		<div class="vbh-sectionbody">
			<MembersList
				v-show="contribView === 'members'"
				ref="membersList"
				:isMobile="isMobile"
				:defaultFeeAmount="defaultFeeAmount"
				:defaultFeeFrequency="defaultFeeFrequency" />
			<SepaBatchPanel v-show="contribView === 'batch'" :isMobile="isMobile" />
		</div>
	</div>
</template>

<script>
import { mdiPlus } from '@mdi/js'
import { NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import { toRefs } from 'vue'
import MembersList from './MembersList.vue'
import SepaBatchPanel from './SepaBatchPanel.vue'
import { useMembershipFees } from '../composables/useMembershipFees.js'

/**
 * Reiter „Beiträge": Mitgliederpflege und SEPA-Sammeleinzug, vorher zwei
 * Abschnitte im Einstellungen-Modal (SettingsMembers.vue,
 * SettingsSepaExport.vue). Siehe NAVIGATION-KONZEPT.md Abschnitt 4 – das ist
 * laufende Arbeit einer fuer Finanzen verantwortlichen Person, keine
 * Einstellung, und gehoert deshalb in die Hauptnavigation statt hinters
 * Zahnrad. Nur sichtbar, wenn das Beitragsmodul genutzt wird (App.vue,
 * membershipActive) – Sichtbarkeit der beiden Kindkomponenten selbst ist an
 * mindestens die Rolle Buchhalter gebunden (Backend-Gate in den jeweiligen
 * Controllern), Rechtevergabe bleibt trotzdem Verwaltern vorbehalten.
 */
export default {
	name: 'ContributionsTab',
	components: { NcButton, NcIconSvgWrapper, MembersList, SepaBatchPanel },
	props: {
		contribView: { type: String, required: true },
		isMobile: { type: Boolean, default: false },
		defaultFeeAmount: { type: [Number, String], default: '' },
		defaultFeeFrequency: { type: String, default: 'yearly' },
	},

	emits: ['update:contrib-view'],

	setup() {
		const membershipFees = useMembershipFees()
		return {
			...toRefs(membershipFees.state),
			overdueMembershipCount: membershipFees.overdueCount,
		}
	},

	data() {
		return { mdiPlus }
	},
}
</script>
