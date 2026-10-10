<template>
	<div style="display: contents;">
		<div class="vbh-sectiontop">
			<div class="vbh-subtabs">
				<button v-if="canWrite" :class="{ active: contribView === 'members' }" @click="$emit('update:contrib-view', 'members')">
					{{ t('Mitglieder') }}
				</button>
				<button :class="{ active: contribView === 'batch' }" @click="$emit('update:contrib-view', 'batch')">
					{{ t('Einzug') }}
				</button>
				<button v-if="canWrite" :class="{ active: contribView === 'groups' }" @click="$emit('update:contrib-view', 'groups')">
					{{ t('Beitragsgruppen') }}
				</button>
			</div>
			<div v-if="canWrite && contribView === 'members'" class="vbh-sectiontop-actions">
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
			<!-- Mitglieder (Personenakte, unmaskierte IBAN) und Beitragsgruppen bleiben ab Buchhalter,
			     deshalb v-if statt v-show: für einen Revisor sollen sie gar nicht erst laden (und 403en). -->
			<MembersList
				v-if="canWrite"
				v-show="contribView === 'members'"
				ref="membersList"
				:isMobile="isMobile"
				:defaultFeeAmount="defaultFeeAmount"
				@manageAssignments="$emit('update:contrib-view', 'groups')" />
			<EinzugPanel
				v-show="contribView === 'batch'"
				:isMobile="isMobile"
				:canWrite="canWrite"
				:active="contribView === 'batch'" />
			<ContributionGroupsPanel v-if="canWrite" v-show="contribView === 'groups'" />
		</div>
	</div>
</template>

<script>
import { mdiPlus } from '@mdi/js'
import { NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import ContributionGroupsPanel from './ContributionGroupsPanel.vue'
import EinzugPanel from './EinzugPanel.vue'
import MembersList from './MembersList.vue'

/**
 * Reiter „Beiträge": Mitgliederpflege und SEPA-Sammeleinzug, vorher zwei
 * Abschnitte im Einstellungen-Modal (SettingsMembers.vue,
 * SettingsSepaExport.vue). Siehe NAVIGATION-KONZEPT.md Abschnitt 4 – das ist
 * laufende Arbeit einer fuer Finanzen verantwortlichen Person, keine
 * Einstellung, und gehoert deshalb in die Hauptnavigation statt hinters
 * Zahnrad. Nur sichtbar, wenn das Beitragsmodul genutzt wird (App.vue,
 * membershipActive).
 *
 * Rollen (Spec §3.9): der Einzug-Unterreiter ist ab Revisor lesend sichtbar
 * (IBAN maskiert, Issue #102 – schließt die in Spec §6 notierte Lücke „revisor
 * sieht die Beiträge-Sektion gar nicht“), Mitglieder und Beitragsgruppen
 * bleiben ab Buchhalter (Backend-Gate in den jeweiligen Controllern); ein
 * Revisor bekommt deshalb nur „Einzug“. Rechtevergabe bleibt Verwaltern
 * vorbehalten.
 */
export default {
	name: 'ContributionsTab',
	components: { NcButton, NcIconSvgWrapper, MembersList, EinzugPanel, ContributionGroupsPanel },
	props: {
		contribView: { type: String, required: true },
		isMobile: { type: Boolean, default: false },
		canWrite: { type: Boolean, default: false },
		defaultFeeAmount: { type: [Number, String], default: '' },
	},

	emits: ['update:contrib-view'],

	data() {
		return { mdiPlus }
	},

	watch: {
		// Ohne Schreibrecht gibt es nur den Einzug-Unterreiter: eine Adresse
		// wie /contributions (Vorgabe „Mitglieder“) oder /contributions/groups
		// führt dort hin, statt in eine leere Fläche.
		contribView: {
			immediate: true,
			handler(view) {
				if (!this.canWrite && view !== 'batch') { this.$emit('update:contrib-view', 'batch') }
			},
		},
	},
}
</script>
