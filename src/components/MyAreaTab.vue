<template>
	<div class="vbh-myarea">
		<!-- Gibt es nur einen der beiden Inhalte, braucht es keine Unterreiter. -->
		<div v-if="showBeitrag && showAuslagen" class="vbh-sectiontop">
			<div class="vbh-subtabs">
				<button :class="{ active: view === 'beitrag' }" @click="select('beitrag')">
					{{ t('Beitrag') }}
				</button>
				<button :class="{ active: view === 'auslagen' }" @click="select('auslagen')">
					{{ t('Auslagen') }}
				</button>
			</div>
		</div>
		<SelfServiceTab v-if="showBeitrag" v-show="view === 'beitrag'" />
		<SelfReimbursementPanel v-if="showAuslagen && (view === 'auslagen' || !showBeitrag)" />
	</div>
</template>

<script>
import SelfReimbursementPanel from './SelfReimbursementPanel.vue'
import SelfServiceTab from './SelfServiceTab.vue'

/**
 * „Mein Bereich“ (vorher „Mein Beitrag“): die Mitgliedersicht mit den
 * Unterreitern „Beitrag“ (Stammdaten, Beitrag, Mandat; nur mit
 * `self_service_enabled`) und „Auslagen“ (nur mit der Auslagen-Einstellung).
 * Ist nur einer der beiden verfügbar, entfällt die Leiste und der Inhalt
 * steht allein da (docs/reimbursement-design.md §10).
 */
export default {
	name: 'MyAreaTab',
	components: { SelfReimbursementPanel, SelfServiceTab },

	props: {
		showBeitrag: { type: Boolean, default: false },
		showAuslagen: { type: Boolean, default: false },
		/** 'beitrag' oder 'auslagen', aus der Route. */
		selfView: { type: String, default: 'beitrag' },
	},

	emits: ['update:selfView'],

	computed: {
		view() {
			if (!this.showBeitrag) { return 'auslagen' }
			if (!this.showAuslagen) { return 'beitrag' }
			return this.selfView === 'auslagen' ? 'auslagen' : 'beitrag'
		},
	},

	methods: {
		select(view) { this.$emit('update:selfView', view) },
	},
}
</script>
