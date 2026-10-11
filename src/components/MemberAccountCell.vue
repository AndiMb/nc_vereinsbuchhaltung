<template>
	<span class="vbh-account-cell">
		<span v-if="member.ncUserId" :title="linkedAccountText(member.ncUserId)">{{ t('Ja') }}</span>
		<button
			v-else-if="member.ncAccountSuggestion"
			type="button"
			class="vbh-linkbtn vbh-linkbtn--plain"
			:disabled="busy"
			@click="$emit('link')">
			{{ tRaw('Vorschlag {email} verknüpfen', { email: member.ncAccountSuggestion.email || member.email || '' }) }}
		</button>
		<button
			v-else-if="member.ncAccountCandidates > 1"
			type="button"
			class="vbh-linkbtn vbh-linkbtn--plain"
			@click="$emit('open')">
			{{ t('{count} Vorschläge prüfen', { count: member.ncAccountCandidates }) }}
		</button>
		<span v-else>{{ t('Nein') }}</span>
	</span>
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
		/** Das Mitglied der Liste (`ncUserId`, `ncAccountSuggestion`, `ncAccountCandidates`, `email`). */
		member: { type: Object, required: true },
		/** Während die Verknüpfung läuft, ist der Vorschlag gesperrt. */
		busy: { type: Boolean, default: false },
	},

	emits: ['link', 'open'],

	methods: { linkedAccountText },
}
</script>
