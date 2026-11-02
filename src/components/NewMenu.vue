<template>
	<NcActions
		:menuName="t('Neu')"
		variant="primary"
		forceMenu
		forceName>
		<template #icon>
			<NcIconSvgWrapper :path="mdiPlus" :size="20" />
		</template>
		<NcActionButton
			v-for="item in items"
			:key="item.id"
			closeAfterClick
			@click="$emit('select', item.id)">
			<template #icon>
				<NcIconSvgWrapper :path="item.icon" :size="20" />
			</template>
			{{ item.label }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import { mdiPlus } from '@mdi/js'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

/**
 * Das „+ Neu“ im Kopf der App: ein Menü zum Anlegen von Buchung, Mitglied oder
 * Rechnung. Tastatur, Fokus und Schließen übernimmt NcActions (#265);
 * `close-after-click` schließt das Menü, bevor der gewählte Dialog aufgeht,
 * damit dessen Fokusfalle nicht mit der des Menüs konkurriert.
 */
export default {
	name: 'NewMenu',
	components: { NcActions, NcActionButton, NcIconSvgWrapper },
	props: {
		/** Einträge: `{ id, label, icon }` (icon ist ein Pfad aus @mdi/js). */
		items: { type: Array, required: true },
	},

	emits: ['select'],

	data() {
		return { mdiPlus }
	},
}
</script>
