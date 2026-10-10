<template>
	<NcPopover
		:shown="open"
		popupRole="dialog"
		placement="bottom"
		@update:shown="open = $event">
		<template #trigger>
			<NcButton
				class="vbh-infohint"
				variant="tertiary"
				size="small"
				:aria-label="label"
				:title="label">
				<template #icon>
					<NcIconSvgWrapper :path="mdiInformationOutline" :size="16" />
				</template>
			</NcButton>
		</template>
		<div class="vbh-infohint-body">
			<strong v-if="title" class="vbh-infohint-title">{{ title }}</strong>
			<slot />
		</div>
	</NcPopover>
</template>

<script>
import { mdiInformationOutline } from '@mdi/js'
import { NcButton, NcIconSvgWrapper, NcPopover } from '@nextcloud/vue'

/**
 * Kleines Info-Symbol, das beim Anklicken (oder per Tastatur) eine Erklärung
 * einblendet. Für Begriffe, die ein Verein nicht aus dem Stegreif kennt – etwa
 * die Phasen des Einzugs –, damit die Erklärung nicht als Absatz dauerhaft auf
 * der Seite steht. Der Inhalt kommt über den Standard-Slot, `label` ist der
 * Name des Knopfs für Bildschirmleser und Tooltip.
 */
export default {
	name: 'InfoHint',
	components: { NcButton, NcIconSvgWrapper, NcPopover },
	props: {
		// Name des Knopfs („Was bedeutet Vorabinfo?“)
		label: { type: String, required: true },
		// Optionale fette Überschrift im Popover
		title: { type: String, default: '' },
	},

	data() {
		return { mdiInformationOutline, open: false }
	},

	watch: {
		// Das Popover schließt auf Escape nur, wenn der Fokus darin liegt (floating-vue
		// hört auf keyup am Popover). Nach einem Klick auf das Symbol sitzt er noch am
		// Knopf, und beim Scrollen bliebe die Erklärung ohne ihr Symbol im Bild stehen –
		// deshalb schließt beides hier, solange sie offen ist (wie im Aufgaben-Flyout).
		open: {
			handler(open) {
				const method = open ? 'addEventListener' : 'removeEventListener'
				document[method]('keydown', this.onKeydown)
				document[method]('scroll', this.close, true)
			},

			flush: 'sync',
		},
	},

	beforeUnmount() {
		document.removeEventListener('keydown', this.onKeydown)
		document.removeEventListener('scroll', this.close, true)
	},

	methods: {
		onKeydown(event) {
			if (event.key === 'Escape') { this.open = false }
		},

		close() {
			this.open = false
		},
	},
}
</script>

<style scoped>
/* Der Knopf soll neben einer Beschriftung nicht auffallen: klein, ohne eigene Fläche. */
.vbh-infohint.vbh-infohint {
	min-width: 24px;
	min-height: 24px;
	width: 24px;
	height: 24px;
	padding: 0;
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.vbh-infohint.vbh-infohint:hover,
.vbh-infohint.vbh-infohint:focus-visible {
	color: var(--color-main-text);
}

.vbh-infohint-body {
	display: flex;
	flex-direction: column;
	gap: 6px;
	box-sizing: border-box;
	max-width: 340px;
	padding: 12px 14px;
	font-size: 0.9em;
	line-height: 1.45;
}

.vbh-infohint-body :deep(p) {
	margin: 0;
}

.vbh-infohint-title {
	font-size: 1.05em;
}
</style>
