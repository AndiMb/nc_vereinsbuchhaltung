<template>
	<!-- Ein verworfener Lauf hat nur noch seine Historie: dann bleibt die Komponente leer, und der Slot-Rahmen des Lauf-Details blendet sich aus (:empty). -->
	<div v-if="actions.download" class="vbh-run-actions">
		<!-- freigegeben: Schritt 2 steht aus -->
		<template v-if="run.status === 'freigegeben'">
			<p v-if="urgencyText" class="vbh-hint vbh-hint--warning" role="status">
				{{ urgencyText }}
			</p>

			<!-- Weg aus der Drift-Warnung (die Meldung selbst steht darüber im Lauf-Detail): verwerfen und neu freigeben. -->
			<div v-if="run.driftWarning" class="vbh-hint vbh-hint--warning vbh-run-drift">
				<p role="status">
					{{ run.driftWarning }}
					{{ t('Die Datei enthält noch die Daten vom Tag der Freigabe. Reichen Sie sie nur ein, wenn diese Abweichungen gewollt sind. Sonst verwerfen Sie den Lauf und geben den Termin neu frei: Die neue Datei enthält die aktuellen Mandatsdaten.') }}
				</p>
				<NcButton
					variant="secondary"
					size="small"
					:disabled="busy"
					@click="openDiscard(true)">
					{{ t('Verwerfen und neu freigeben') }}
				</NcButton>
			</div>

			<p class="vbh-hint vbh-hint--info">
				{{ t('Schritt 2 von 2: Die Datei ist erzeugt, bei der Bank liegt sie noch nicht. Laden Sie sie herunter, reichen Sie sie dort ein und bestätigen Sie das hier.') }}
			</p>
		</template>

		<!-- eingereicht: kein Storno mehr (Spec §3.5) -->
		<p v-else class="vbh-hint vbh-hint--info">
			{{ t('Die Datei ist bei der Bank eingereicht. Einen Storno gibt es nicht mehr: Der Lauf lässt sich weder verwerfen noch verschieben.') }}
		</p>

		<!-- Der Ordner ist ein Name aus den Einstellungen und steht deshalb nicht in einer t()-Variable (HTML-Escaping). -->
		<p v-if="run.xmlStoragePath" class="vbh-hint">
			{{ t('Die XML-Ablage ist eingeschaltet: Eine Kopie der Datei wird im Nextcloud-Ordner abgelegt:') }}
			<strong class="vbh-run-folder">{{ run.xmlStoragePath }}</strong>
		</p>

		<div class="vbh-run-buttons">
			<NcButton
				variant="secondary"
				:href="xmlUrl"
				target="_blank">
				<template #icon>
					<NcIconSvgWrapper :path="mdiDownload" :size="20" />
				</template>
				{{ t('XML herunterladen') }}
			</NcButton>
			<NcButton
				v-if="actions.submit"
				variant="primary"
				:disabled="busy"
				@click="submit">
				{{ t('Datei ist bei der Bank eingereicht') }}
			</NcButton>
			<NcButton
				v-if="actions.reschedule"
				variant="secondary"
				:disabled="busy"
				@click="openReschedule">
				{{ t('Termin verschieben') }}
			</NcButton>
			<NcButton
				v-if="actions.discard"
				variant="error"
				:disabled="busy"
				@click="openDiscard(false)">
				{{ t('Lauf verwerfen') }}
			</NcButton>
		</div>

		<DebitRescheduleDialog
			v-if="actions.reschedule"
			:show="rescheduleOpen"
			:dueDate="run.dueDate"
			:saving="busy"
			:error="dialogError"
			@close="closeReschedule"
			@update:show="rescheduleOpen = $event"
			@confirm="reschedule" />
		<DebitDiscardDialog
			v-if="actions.discard"
			:show="discardOpen"
			:dueDate="run.dueDate"
			:itemCount="run.itemCount"
			:sumCents="run.sumCents"
			:drift="discardForDrift"
			:saving="busy"
			:error="dialogError"
			@close="closeDiscard"
			@update:show="discardOpen = $event"
			@confirm="discard" />
	</div>
</template>

<script>
import { mdiDownload } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import DebitDiscardDialog from './DebitDiscardDialog.vue'
import DebitRescheduleDialog from './DebitRescheduleDialog.vue'
import api from '../api.js'
import { useConfirm } from '../composables/useConfirm.js'
import { useDebitRunActions } from '../composables/useDebitRunActions.js'
import { runActionsFor, submissionUrgency, submissionUrgencyText } from '../lib/debitRunActions.js'
import { errMsg, formatDate, formatMoney } from '../lib/format.js'

/**
 * Die Aktionen eines Laufs im Detail (Issue #103, Spec §3.5), eingehängt über
 * den Slot `actions` von DebitRunDetail. Nach Status:
 *
 * - freigegeben: XML herunterladen, „Datei ist bei der Bank eingereicht“
 *   (Schritt 2, eigene bewusste Aktion mit Rückfrage), Termin nach hinten
 *   verschieben, Lauf verwerfen (Pflicht-Begründung). Dazu der eskalierende
 *   Hinweis, wenn die Einreichung überfällig ist (blockiert nichts), und bei
 *   Drift der Weg „Verwerfen und neu freigeben“.
 * - eingereicht: nur noch der Download – kein Storno, keine Verschiebung.
 * - verworfen: nichts, die Historie genügt.
 *
 * Nur ab `buchhalter`; für `revisor` hängt EinzugPanel den Slot gar nicht erst
 * ein. Das Backend prüft die Rolle ohnehin (RequiresRole), das hier ist
 * Komfort, keine Sicherung.
 *
 * Der XML-Download ist ein per Link geöffneter GET in einem neuen Tab (die
 * Antwort ist ein Anhang, der Tab schließt sich von selbst); der Endpunkt
 * trägt dafür #[NoCSRFRequired].
 */
export default {
	name: 'DebitRunActions',
	components: { DebitDiscardDialog, DebitRescheduleDialog, NcButton, NcIconSvgWrapper },
	props: {
		// Der Lauf wie das Lauf-Detail ihn bekommt (mit driftWarning, itemCount, sumCents)
		run: { type: Object, required: true },
		// Stichtag und Freigabe-Vorlauf des Servers (Antwort des Zeitstrahls)
		today: { type: String, required: true },
		releaseLeadDays: { type: Number, required: true },
	},

	setup() {
		return { runActions: useDebitRunActions(), askConfirm: useConfirm().askConfirm }
	},

	data() {
		return {
			mdiDownload,
			busy: false,
			rescheduleOpen: false,
			discardOpen: false,
			// Ob „Verwerfen“ aus der Drift-Warnung kam (andere Überschrift, Begründung vorgeschlagen)
			discardForDrift: false,
			// Fehler des Servers im offenen Dialog
			dialogError: null,
		}
	},

	computed: {
		actions() { return runActionsFor(this.run.status) },
		xmlUrl() { return api.debitBatchXmlUrl(this.run.id) },

		urgencyText() {
			return submissionUrgencyText(submissionUrgency(this.run, this.today, this.releaseLeadDays), this.run, this.releaseLeadDays)
		},
	},

	methods: {
		/**
		 * Schritt 2. Rückfrage mit dem, was danach gilt – bewusst kein bloßes „Sind Sie
		 * sicher?“: der Kassenwart bestätigt eine Tatsache (die Datei liegt bei der Bank),
		 * nicht nur einen Klick.
		 */
		async submit() {
			// Die Meldung des Servers steht außerhalb von t() (HTML-Escaping), deshalb angehängt statt eingesetzt.
			let message = this.t('Bestätigen Sie das nur, wenn die Datei für den Einzug am {datum} ({n} Posten, {summe}) tatsächlich bei der Bank eingereicht ist. Danach gibt es keinen Storno mehr: Der Lauf lässt sich weder verwerfen noch verschieben, und an den Mandaten gilt dieser Einzug als vorgelegt.', {
				datum: formatDate(this.run.dueDate),
				n: this.run.itemCount,
				summe: formatMoney(this.run.sumCents / 100),
			})
			if (this.run.driftWarning) {
				message += ' ' + this.run.driftWarning + ' ' + this.t('Die Datei enthält noch die alten Daten.')
			}
			if (!await this.askConfirm(this.t('Datei als eingereicht bestätigen'), message, this.t('Ja, eingereicht'), 'primary')) { return }

			this.busy = true
			try {
				await this.runActions.submitRun(this.run, () => showSuccess(this.t('Der Lauf gilt als bei der Bank eingereicht.')))
			} catch (e) {
				showError(errMsg(e, this.t('Die Einreichung konnte nicht vermerkt werden.')))
			} finally {
				this.busy = false
			}
		},

		openDiscard(forDrift) {
			this.dialogError = null
			this.discardForDrift = forDrift
			this.discardOpen = true
		},

		closeDiscard() {
			if (!this.busy) { this.discardOpen = false }
		},

		async discard(reason) {
			this.busy = true
			this.dialogError = null
			try {
				// Der Dialog schließt VOR dem Nachladen: danach ist der Lauf verworfen, und dieser Aktionsblock verschwindet samt Dialog.
				await this.runActions.discardRun(this.run, reason, () => {
					this.discardOpen = false
					showSuccess(this.discardForDrift
						? this.t('Lauf verworfen. Die Forderungen sind wieder frei: Geben Sie den Termin jetzt neu frei.')
						: this.t('Lauf verworfen. Die Forderungen sind wieder frei.'))
				})
				// Die Geisterkarte des Termins zeigt die freien Forderungen und das „Freigeben“: dorthin rollen.
				await this.$nextTick()
				document.querySelector('.vbh-ghost')?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' })
			} catch (e) {
				this.dialogError = errMsg(e, this.t('Der Lauf konnte nicht verworfen werden.'))
			} finally {
				this.busy = false
			}
		},

		openReschedule() {
			this.dialogError = null
			this.rescheduleOpen = true
		},

		closeReschedule() {
			if (!this.busy) { this.rescheduleOpen = false }
		},

		async reschedule(newDate) {
			this.busy = true
			this.dialogError = null
			try {
				await this.runActions.rescheduleRun(this.run, newDate, () => {
					this.rescheduleOpen = false
					showSuccess(this.t('Einzugstermin auf den {datum} verschoben. Die Datei hat sich geändert: Bitte laden Sie sie neu herunter.', { datum: formatDate(newDate) }))
				})
			} catch (e) {
				this.dialogError = errMsg(e, this.t('Der Termin konnte nicht verschoben werden.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-run-actions {
	flex: 1 1 100%;
	min-width: 0;
}

.vbh-run-actions .vbh-hint {
	margin: 6px 0;
}

.vbh-run-drift p {
	margin: 0 0 8px;
}

.vbh-run-folder {
	font-family: var(--font-family-monospace, monospace);
	overflow-wrap: anywhere;
}

.vbh-run-buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 8px;
}
</style>
