<template>
	<NcModal :show="show"
		:name="t('Willkommen')"
		size="normal"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-wizard">
			<h3>{{ t('Willkommen bei der Vereinsbuchhaltung!') }}</h3>
			<p class="vbh-hint">
				{{ t('Womit möchtest du starten? Das lässt sich jederzeit später ändern.') }}
			</p>

			<div class="vbh-wizard-choices">
				<button class="vbh-wizard-choice" @click="$emit('choose', 'xbuc')">
					<NcIconSvgWrapper :path="mdiDatabaseImportOutline" :size="28" />
					<strong>{{ t('Ich habe Daten aus „zero Buchhaltung"') }}</strong>
					<span>{{ t('Kontenrahmen und bisherige Buchungen per .xbuc-Datei übernehmen.') }}</span>
				</button>
				<button class="vbh-wizard-choice" @click="$emit('choose', 'fresh')">
					<NcIconSvgWrapper :path="mdiPlusBoxOutline" :size="28" />
					<strong>{{ t('Ich fange neu an') }}</strong>
					<span>{{ t('Erprobten Standard-Kontenrahmen anlegen und direkt loslegen.') }}</span>
				</button>
				<button class="vbh-wizard-choice" @click="$emit('choose', 'demo')">
					<NcIconSvgWrapper :path="mdiFlaskOutline" :size="28" />
					<strong>{{ t('Erst mit Beispieldaten ausprobieren') }}</strong>
					<span>{{ t('Ein kleiner Beispielverein zum Kennenlernen – später mit einem Klick zurücksetzbar.') }}</span>
				</button>
			</div>

			<button class="vbh-wizard-skip" @click="$emit('close')">
				{{ t('Überspringen, ich schaue mich selbst um') }}
			</button>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiDatabaseImportOutline, mdiPlusBoxOutline, mdiFlaskOutline } from '@mdi/js'

export default {
	name: 'SetupWizard',
	components: { NcModal, NcIconSvgWrapper },
	props: {
		show: { type: Boolean, default: false },
	},
	data() {
		return { mdiDatabaseImportOutline, mdiPlusBoxOutline, mdiFlaskOutline }
	},
}
</script>
