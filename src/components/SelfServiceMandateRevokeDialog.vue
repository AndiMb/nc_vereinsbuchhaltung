<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-mandate-revoke"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-mandate-revoke" class="vbh-modal-title">
				{{ t('Mandat widerrufen') }}
			</h2>

			<div class="vbh-card vbh-card--danger">
				<p>
					{{ t('Der Widerruf ist endgültig – ein widerrufenes Mandat lässt sich nicht wieder aktivieren. Für künftige Einzüge brauchen Sie danach ein neues Mandat.') }}
				</p>
				<p v-if="openClaimsTotalCents > 0">
					{{ t('Noch offen: {betrag}', { betrag: formatMoney(openClaimsTotalCents / 100) }) }}
				</p>
			</div>

			<p class="vbh-hint">
				{{ t('Nur ein neues Konto? Dafür reicht die IBAN-Änderung – ohne Widerruf, ohne neues Mandat.') }}
			</p>
			<div class="vbh-modal-actions">
				<NcButton variant="primary" @click="$emit('switch-to-iban')">
					{{ t('Ich habe nur ein neues Konto → IBAN ändern') }}
				</NcButton>
			</div>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="error" :disabled="saving" @click="$emit('save')">
					{{ t('Mandat endgültig widerrufen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'
import { formatMoney } from '../lib/format.js'

/**
 * Reibungsdialog Widerruf (Spec §3.4): Endgültigkeit + offene Summe zeigen,
 * Ausweg ("Ich habe nur ein neues Konto") als PRIMÄRAKTION - der eigentliche
 * Widerruf bleibt bewusst `variant="error"`, nicht `"primary"`. Keine
 * Zweitfaktor-Bestätigung (Widerruf ist ein Recht, kein Anlass für ein
 * zusätzliches Tippen-Sie-'löschen'-Feld). `switch-to-iban` lässt die
 * aufrufende Seite (SelfServiceTab.vue) diesen Dialog schließen und den
 * Konto-Dialog im IBAN-Modus öffnen.
 */
export default {
	name: 'SelfServiceMandateRevokeDialog',
	components: { NcModal, NcButton },
	props: {
		show: { type: Boolean, default: false },
		openClaimsTotalCents: { type: Number, default: 0 },
		saving: { type: Boolean, default: false },
	},

	emits: ['close', 'save', 'switch-to-iban', 'update:show'],

	methods: { formatMoney },
}
</script>
