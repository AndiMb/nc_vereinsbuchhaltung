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
			:description="row.fee ? t('Betrag und Turnus') : t('Beitragsgruppe, Betrag und Turnus')"
			@click="$emit('manage-assignments', 'fee')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiCashEdit" :size="20" />
			</template>
			{{ row.fee ? t('Beitrag ändern') : t('Beitrag zuweisen') }}
		</NcActionButton>
		<NcActionButton
			v-if="row.fee"
			closeAfterClick
			:description="t('In eine andere Gruppe, mit deren Regeln')"
			@click="$emit('manage-assignments', 'group')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiAccountSwitch" :size="20" />
			</template>
			{{ t('Beitragsgruppe wechseln') }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import { mdiAccountEdit, mdiAccountSwitch, mdiCashEdit, mdiFileSign } from '@mdi/js'
import { NcActionButton, NcActions, NcIconSvgWrapper } from '@nextcloud/vue'

/**
 * Das Zeilenmenü (⋯) eines Mitglieds in der Liste und auf der Karte: alles, was sich
 * zu einem Mitglied tun lässt, mit Namen statt Symbolen – die Akte (Stammdaten),
 * das Mandat (springt in der Akte zum Mandat-Bereich) und der Beitrag (Betrag und
 * Turnus ändern, in eine andere Beitragsgruppe wechseln – beides öffnet einen Dialog
 * gleich in der Liste).
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
		return { mdiAccountEdit, mdiAccountSwitch, mdiCashEdit, mdiFileSign }
	},
}
</script>
