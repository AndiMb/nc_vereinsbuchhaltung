<template>
	<span v-if="member.ncUserId && member.ncLinkConfirmed" :title="linkedAccountText(member.ncUserId)">{{ t('Ja') }}</span>
	<span v-else-if="member.ncUserId" :title="pendingAccountText(member.ncUserId)">{{ t('Bestätigung ausstehend') }}</span>
	<button
		v-else-if="suggestions.length === 1"
		type="button"
		class="vbh-linkbtn vbh-linkbtn--plain"
		:disabled="busy"
		@click="$emit('link')">
		{{ tRaw('Vorschlag {uid} verknüpfen', { uid: suggestions[0].uid }) }}
	</button>
	<button
		v-else-if="suggestions.length > 1"
		type="button"
		class="vbh-linkbtn vbh-linkbtn--plain"
		@click="$emit('open')">
		{{ t('{count} Vorschläge prüfen', { count: suggestions.length }) }}
	</button>
	<span v-else>{{ t('Nein') }}</span>
</template>

<script>
import { linkedAccountText, pendingAccountText } from '../lib/memberAccount.js'

/**
 * Spalte „Nextcloud-Konto?“ der Mitgliederliste (Tabelle und Karte): „Ja“, wenn ein Konto
 * verknüpft und die Verknüpfung vom Mitglied bestätigt ist, „Bestätigung ausstehend“ davor (#295); sonst der Vorschlag zum Verknüpfen (genau ein Konto mit der Mailadresse des
 * Mitglieds), der Weg in die Akte bei mehreren Treffern, oder „Nein“.
 */
export default {
	name: 'MemberAccountCell',
	props: {
		/** Das Mitglied der Liste (`ncUserId`, `email`). */
		member: { type: Object, required: true },
		/** Die Konten, die zur Mailadresse des Mitglieds passen und noch frei sind (`GET /member-link-suggestions`). */
		suggestions: { type: Array, default: () => [] },
		/** Während die Verknüpfung läuft, ist der Vorschlag gesperrt. */
		busy: { type: Boolean, default: false },
	},

	emits: ['link', 'open'],

	methods: { linkedAccountText, pendingAccountText },
}
</script>
