<template>
	<NcActions :forceMenu="true">
		<NcActionButton
			closeAfterClick
			:description="t('Stammdaten, Konto, Austritt')"
			@click="$emit('open-member', '')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiAccountEdit" :size="20" />
			</template>
			{{ t('Mitglied bearbeiten') }}
		</NcActionButton>
		<NcActionButton
			closeAfterClick
			:description="t('Sperren, IBAN ändern, widerrufen')"
			@click="$emit('open-member', 'mandate')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiFileSign" :size="20" />
			</template>
			{{ t('Mandat verwalten') }}
		</NcActionButton>
		<NcActionButton
			closeAfterClick
			:description="t('Gruppe, Betrag und Turnus')"
			@click="$emit('manage-assignments')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiCashEdit" :size="20" />
			</template>
			{{ row.fee ? t('Beitrag verwalten') : t('Beitrag zuweisen') }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import { mdiAccountEdit, mdiCashEdit, mdiFileSign } from '@mdi/js'
import { NcActionButton, NcActions, NcIconSvgWrapper } from '@nextcloud/vue'

/**
 * Das Zeilenmenü (⋯) eines Mitglieds in der Liste und auf der Karte: alles, was sich
 * zu einem Mitglied tun lässt, mit Namen statt Symbolen – die Akte (Stammdaten),
 * das Mandat (springt in der Akte zum Mandat-Bereich) und der Beitrag (Zuweisung,
 * liegt bei den Beitragsgruppen).
 */
export default {
	name: 'MemberRowMenu',
	components: { NcActions, NcActionButton, NcIconSvgWrapper },

	props: {
		/** Zeile aus lib/memberRow.js; gebraucht wird nur, ob es schon einen Beitrag (`fee`) gibt. */
		row: { type: Object, required: true },
	},

	emits: ['open-member', 'manage-assignments'],

	setup() {
		return { mdiAccountEdit, mdiCashEdit, mdiFileSign }
	},
}
</script>
