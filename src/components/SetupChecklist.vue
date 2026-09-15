<template>
	<div v-if="ready && !dismissed && remaining.length" class="vbh-card vbh-setupcard">
		<div class="vbh-setuphead">
			<h4>{{ t('Erste Schritte ({done} von {total} erledigt)', { done: steps.length - remaining.length, total: steps.length }) }}</h4>
			<NcButton
				variant="tertiary"
				:aria-label="t('Ausblenden')"
				:title="t('Ausblenden')"
				@click="dismiss">
				<template #icon>
					<NcIconSvgWrapper :path="mdiClose" :size="18" />
				</template>
			</NcButton>
		</div>
		<ul class="vbh-setuplist">
			<li v-for="s in steps" :key="s.id" :class="{ done: s.done }">
				<NcIconSvgWrapper :path="s.done ? mdiCheckCircle : mdiCircleOutline" :size="18" />
				<button v-if="!s.done" class="vbh-setupstep" @click="$emit('navigate', s.action)">
					{{ s.label }}
				</button>
				<span v-else class="vbh-setupstep vbh-setupstep--done">{{ s.label }}</span>
			</li>
		</ul>
		<button v-if="accounts.length === 0" class="vbh-setupstep vbh-setupwizardlink" @click="$emit('open-wizard')">
			{{ t('Setup-Assistenten öffnen') }}
		</button>
	</div>
</template>

<script>
import { mdiCheckCircle, mdiCircleOutline, mdiClose } from '@mdi/js'
import { NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import { buildSetupSteps } from '../lib/setupSteps.js'

export default {
	name: 'SetupChecklist',
	components: { NcButton, NcIconSvgWrapper },
	props: {
		// true, sobald Konten/Buchungen/Berechtigungen initial geladen sind - vorher
		// waeren die "erledigt"-Haekchen falsch (alles wirkt unerledigt) und die
		// Karte wuerde kurz aufblitzen, siehe App.vue mounted().
		ready: { type: Boolean, required: true },
		accounts: { type: Array, required: true },
		permissions: { type: Array, required: true },
		// Gibt es ueberhaupt eine Buchung? Bewusst ein Boolean ueber ALLE Zeitraeume
		// und keine Anzahl des gewaehlten: die Karte beschreibt den Stand des
		// Vereins, nicht des Geschaeftsjahres (Issue #60).
		hasAnyBooking: { type: Boolean, required: true },
		clubName: { type: String, default: '' },
	},

	emits: ['navigate', 'open-wizard'],

	data() {
		return {
			mdiCheckCircle,
			mdiCircleOutline,
			mdiClose,
			dismissed: false,
		}
	},

	computed: {
		steps() {
			return buildSetupSteps({
				clubName: this.clubName,
				accounts: this.accounts,
				permissions: this.permissions,
				hasAnyBooking: this.hasAnyBooking,
				t: this.t,
			})
		},

		remaining() {
			return this.steps.filter((s) => !s.done)
		},
	},

	mounted() {
		try { this.dismissed = localStorage.getItem('vbh_setup_dismissed') === '1' } catch { this.dismissed = false }
	},

	methods: {
		dismiss() {
			this.dismissed = true
			try { localStorage.setItem('vbh_setup_dismissed', '1') } catch { /* voll/gesperrt – dann eben ohne */ }
		},
	},
}
</script>
