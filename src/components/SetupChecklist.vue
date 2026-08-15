<template>
	<div v-if="!dismissed && remaining.length" class="vbh-card vbh-setupcard">
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

export default {
	name: 'SetupChecklist',
	components: { NcButton, NcIconSvgWrapper },
	props: {
		accounts: { type: Array, required: true },
		permissions: { type: Array, required: true },
		journalCount: { type: Number, required: true },
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
			return [
				{ id: 'club', label: this.t('Verein benennen'), action: 'settings:verein', done: !!this.clubName },
				{ id: 'accounts', label: this.t('Kontenrahmen anlegen'), action: 'accounts', done: this.accounts.length > 0 },
				// xbuc-Importe setzen openingDate nicht (Anfangsbestand steckt in der
				// EB-Buchung selbst) – sobald überhaupt gebucht wurde, ist der Punkt
				// gegenstandslos, sonst würde er bei aktiven, importierten Vereinen nie erledigt sein.
				{ id: 'opening', label: this.t('Geldkonto mit Anfangsbestand eintragen'), action: 'accounts', done: this.journalCount > 0 || this.accounts.some((a) => a.isBank && a.openingDate) },
				{ id: 'permissions', label: this.t('Berechtigungen vergeben'), action: 'settings:berechtigungen', done: this.permissions.length > 0 },
				{ id: 'booking', label: this.t('Erste Buchung erfassen'), action: 'booking', done: this.journalCount > 0 },
				// Entspricht Account::isResultRelevant() im Backend (alles außer Geldkonten/Eigenkapital).
				// Zuordnung selbst steht seit NAVIGATION-KONZEPT.md Abschnitt 4 im
				// Bericht „Sphären", nicht mehr im Zahnrad.
				{ id: 'spheres', label: this.t('Sphären zuordnen (steuerlich)'), action: 'reports:spheres', done: this.accounts.filter((a) => a.type !== 'equity' && !a.isBank).every((a) => a.sphere) },
			]
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
