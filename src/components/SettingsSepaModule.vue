<template>
	<div>
		<div v-if="loadFailed" class="vbh-card">
			<p class="vbh-hint vbh-hint--error" role="alert">
				{{ t('Die weiteren Einstellungen des Beitragsmoduls konnten nicht geladen werden.') }}
			</p>
			<NcButton @click="loadSepaSettings">
				{{ t('Erneut versuchen') }}
			</NcButton>
		</div>
		<NcLoadingIcon v-else-if="!loaded" :size="24" />
		<template v-else>
			<SettingsSepaCycle />
			<SettingsSepaXmlStorage :storageUser="storageUser" />
			<SettingsSepaMandates :storageUser="storageUser" />
			<SettingsSepaReturns />
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { toRefs } from 'vue'
import SettingsSepaCycle from './SettingsSepaCycle.vue'
import SettingsSepaMandates from './SettingsSepaMandates.vue'
import SettingsSepaReturns from './SettingsSepaReturns.vue'
import SettingsSepaXmlStorage from './SettingsSepaXmlStorage.vue'
import { useSepaSettings } from '../composables/useSepaSettings.js'

/**
 * Die übrigen Einstellungen des Beitrags-/SEPA-Moduls (Spec §4 „Neue
 * Einstellungen", Issue #101) unterhalb von SettingsSepaBasics.vue:
 * Beitragsjahr und Einzugszyklus, XML-Ablage, Mandate, Rücklastschriften und
 * Mahnwesen. Lädt den gespeicherten Stand einmal, bevor die Karten mounten -
 * sie legen ihre Entwürfe beim Aufbau aus diesem Stand an.
 *
 * Alles hier erreicht nur ein App-Verwalter (die Einstellungsseite selbst und
 * jeder der Endpunkte verlangt `verwalter`).
 */
export default {
	name: 'SettingsSepaModule',
	components: { NcButton, NcLoadingIcon, SettingsSepaCycle, SettingsSepaMandates, SettingsSepaReturns, SettingsSepaXmlStorage },
	props: {
		// Nutzer der Belegablage im Nextcloud-Dateibaum (SettingsApp.vue); die Ordner
		// der XML-Ablage und der Mandats-Nachweise liegen in seinem Home
		storageUser: { type: String, default: '' },
	},

	setup() {
		const { state, loadSepaSettings } = useSepaSettings()
		return { ...toRefs(state), loadSepaSettings }
	},

	mounted() {
		this.loadSepaSettings()
	},
}
</script>
