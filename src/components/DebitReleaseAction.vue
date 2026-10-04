<template>
	<!-- Ohne freizugebende Forderungen bleibt die Komponente leer: der Slot-Rahmen der Geisterkarte blendet sich dann aus (:empty). -->
	<div v-if="summary.count > 0" class="vbh-release-action">
		<NcButton variant="primary" :disabled="!preview || saving" @click="openDialog">
			<template #icon>
				<NcIconSvgWrapper :path="mdiFileSendOutline" :size="20" />
			</template>
			{{ t('Freigeben & Datei erzeugen') }}
		</NcButton>
		<span class="vbh-hint vbh-release-action-note">
			{{ t('Schritt 1 von 2: Die Datei ist danach erzeugt, aber noch nicht bei der Bank eingereicht.') }}
		</span>

		<DebitReleaseDialog
			:show="dialogOpen"
			:dueDate="entry.dueDate"
			:summary="summary"
			:issues="issues"
			:late="late"
			:saving="saving"
			:error="error"
			@close="closeDialog"
			@update:show="dialogOpen = $event"
			@confirm="release" />
	</div>
</template>

<script>
import { mdiFileSendOutline } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import DebitReleaseDialog from './DebitReleaseDialog.vue'
import { useDebitRunActions } from '../composables/useDebitRunActions.js'
import { errMsg, formatMoney } from '../lib/format.js'

/**
 * Schaltfläche „Freigeben & Datei erzeugen“ der Geisterkarte samt Bestätigungs-
 * dialog (Issue #103, Spec §3.5 Schritt 1). Hängt über den Slot `actions` der
 * Geisterkarte ein; die Karte selbst bleibt rein darstellend.
 *
 * Nach der Freigabe schließt der Dialog, der Unterreiter lädt neu und der neue
 * Lauf steht aufgeklappt in der Läufe-Liste – dort liegen Download und
 * Einreichung (DebitRunActions.vue). Die Geisterkarte verschwindet, sobald
 * nichts mehr freizugeben ist; deshalb hängt hier keine „Fertig“-Seite im
 * Dialog, die das Nachladen nicht überleben würde. Statt dessen sagt die
 * Erfolgsmeldung, was als Nächstes kommt, und die Läufe-Liste rollt ins Bild.
 *
 * Der Server prüft bei der Freigabe alles erneut (frische Vorschau,
 * Mandatsprüfung); die Zahlen im Dialog sind der Stand der Vorschau. Die
 * Erfolgsmeldung nennt deshalb, was WIRKLICH freigegeben wurde.
 */
export default {
	name: 'DebitReleaseAction',
	components: { DebitReleaseDialog, NcButton, NcIconSvgWrapper },
	props: {
		// Eintrag des Termins aus dem Zeitstrahl
		entry: { type: Object, required: true },
		// Antwort von GET /debit-batches/preview; null, solange sie noch lädt
		preview: { type: Object, default: null },
		// Stichtag des Servers
		today: { type: String, required: true },
	},

	setup() {
		return { actions: useDebitRunActions() }
	},

	data() {
		return { mdiFileSendOutline, dialogOpen: false, saving: false, error: null }
	},

	computed: {
		// Die frische Vorschau gewinnt, bis sie da ist trägt die Zusammenfassung aus dem Zeitstrahl.
		summary() { return this.preview?.summary || this.entry.preview },

		// Handlungsbedarf zuerst, wie in der Geisterkarte
		issues() {
			const rank = (i) => (i.severity === 'handlungsbedarf' ? 0 : 1)
			return [...(this.preview?.issues || [])].sort((a, b) => rank(a) - rank(b))
		},

		late() { return this.entry.dueDate < this.today },
	},

	methods: {
		openDialog() {
			this.error = null
			this.dialogOpen = true
		},

		closeDialog() {
			if (!this.saving) { this.dialogOpen = false }
		},

		async release() {
			if (this.saving) { return }
			this.saving = true
			this.error = null
			try {
				// Der Dialog schließt, sobald der Server die Freigabe bestätigt hat und BEVOR der Unterreiter neu lädt:
				// das Nachladen kann diese Komponente samt Geisterkarte wegräumen.
				const batch = await this.actions.releaseRun(this.entry.dueDate, (released) => {
					this.dialogOpen = false
					showSuccess(this.t('Lauf freigegeben, Datei erzeugt ({n} Posten, {summe}). Als Nächstes: Datei herunterladen und bei der Bank einreichen.', {
						n: released.itemCount,
						summe: formatMoney(released.sumCents / 100),
					}))
				})
				// Der neue Lauf steht nach dem Nachladen aufgeklappt in der Liste: dorthin rollen.
				await this.$nextTick()
				document.getElementById(`vbh-run-detail-${batch.id}`)?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' })
			} catch (e) {
				this.error = errMsg(e, this.t('Der Lauf konnte nicht freigegeben werden.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-release-action {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 12px;
}

.vbh-release-action-note {
	margin: 0;
	font-size: 0.85em;
}
</style>
