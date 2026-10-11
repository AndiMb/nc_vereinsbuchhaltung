<template>
	<span v-if="member.ncUserId" :title="linkedAccountText(member.ncUserId)">{{ t('Ja') }}</span>
	<button
		v-else-if="suggestions.length === 1"
		type="button"
		class="vbh-linkbtn vbh-linkbtn--plain"
		:disabled="busy"
		@click="$emit('link')">
		{{ tRaw('Vorschlag {email} verknüpfen', { email: suggestions[0].email || member.email || '' }) }}
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
import { linkedAccountText } from '../lib/memberAccount.js'

/**
 * Spalte „Nextcloud-Konto?“ der Mitgliederliste (Tabelle und Karte): „Ja“, wenn ein Konto
 * verknüpft ist; sonst der Vorschlag zum Verknüpfen (genau ein Konto mit der Mailadresse des
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

	methods: { linkedAccountText },
}
</script>
