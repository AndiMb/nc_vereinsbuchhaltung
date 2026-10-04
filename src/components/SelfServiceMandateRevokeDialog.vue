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
				<p v-if="staff">
					{{ t('Der Widerruf ist endgültig – ein widerrufenes Mandat lässt sich nicht wieder aktivieren. Für künftige Einzüge braucht {name} danach ein neues Mandat mit neuer Unterschrift.', { name: memberName }) }}
				</p>
				<p v-else>
					{{ t('Der Widerruf ist endgültig – ein widerrufenes Mandat lässt sich nicht wieder aktivieren. Für künftige Einzüge brauchen Sie danach ein neues Mandat.') }}
				</p>
				<p v-if="openClaimsTotalCents > 0">
					{{ t('Noch offen: {betrag}', { betrag: formatMoney(openClaimsTotalCents / 100) }) }}
					<template v-if="staff">
						{{ t('– dafür geht dem Mitglied eine Zahlungsaufforderung zu.') }}
					</template>
				</p>
			</div>

			<template v-if="!ibanChangeBlocked">
				<p class="vbh-hint">
					{{ t('Nur ein neues Konto? Dafür reicht die IBAN-Änderung – ohne Widerruf, ohne neues Mandat.') }}
				</p>
				<div class="vbh-modal-actions">
					<NcButton variant="primary" @click="$emit('switch-to-iban')">
						{{ t('Ich habe nur ein neues Konto → IBAN ändern') }}
					</NcButton>
				</div>
			</template>
			<!-- Die Verwaltung kann ein ausgesetztes Mandat entsperren, das Mitglied nicht (Spec §3.4: „Mandat aktivieren/sperren“ ist nicht im Aktionskatalog). -->
			<p v-else-if="staff" class="vbh-hint">
				{{ t('Nur ein neues Konto? Entsperren Sie das Mandat zuerst – die IBAN lässt sich nur bei einem aktiven Mandat ändern, ganz ohne Widerruf und neues Mandat.') }}
			</p>
			<p v-else class="vbh-hint">
				{{ t('Nur ein neues Konto? Solange Ihr Mandat ausgesetzt ist, lässt sich die IBAN nicht ändern – wenden Sie sich an Ihren Verein. Ist das Mandat wieder aktiv, geht das ganz ohne Widerruf und neues Mandat.') }}
			</p>

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
 * aufrufende Seite (SelfServiceTab.vue, MandatePanel.vue) diesen Dialog
 * schließen und den Konto-Dialog im IBAN-Modus öffnen.
 */
export default {
	name: 'SelfServiceMandateRevokeDialog',
	components: { NcModal, NcButton },
	props: {
		show: { type: Boolean, default: false },
		openClaimsTotalCents: { type: Number, default: 0 },
		saving: { type: Boolean, default: false },
		/**
		 * Verwaltungssicht (Mitglieder-Akte, MandatePanel.vue, Issue #100): der
		 * Text spricht über das Mitglied statt zu ihm. Derselbe Dialog, damit
		 * Endgültigkeit/offene Summe/Ausweg-als-Primäraktion (Spec §3.4) an
		 * einer Stelle stehen.
		 */
		staff: { type: Boolean, default: false },
		memberName: { type: String, default: '' },
		/**
		 * Ein ausgesetztes Mandat lässt sich nicht per Amendment ändern – dort entfällt der Ausweg-Knopf
		 * (Akte: MandatePanel.vue, Mein Beitrag: SelfServiceTab.vue, Issue #118).
		 */
		ibanChangeBlocked: { type: Boolean, default: false },
	},

	emits: ['close', 'save', 'switch-to-iban', 'update:show'],

	methods: { formatMoney },
}
</script>
