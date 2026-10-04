<template>
	<div class="vbh-claimdetail">
		<dl class="vbh-cd-facts">
			<div>
				<dt>{{ t('Fällig am') }}</dt>
				<dd>{{ formatDate(claim.dueDate) || '–' }}</dd>
			</div>
			<div v-if="claim.periodStart && claim.periodEnd">
				<dt>{{ t('Zeitraum') }}</dt>
				<dd>{{ formatDate(claim.periodStart) }} – {{ formatDate(claim.periodEnd) }}</dd>
			</div>
			<div>
				<dt>{{ t('Art') }}</dt>
				<dd>{{ claimTypeLabel(claim.type) }}</dd>
			</div>
			<div>
				<dt>{{ t('Betrag') }}</dt>
				<dd>{{ formatMoney(claim.amount) }}</dd>
			</div>
			<div>
				<dt>{{ t('Zustand') }}</dt>
				<dd>
					<DebitStatusTag kind="claim" :value="claim.state" :settlementType="claim.settlementType" />
					<span v-if="claim.deferred" class="vbh-typetag">{{ t('gestundet bis {datum}', { datum: formatDate(claim.deferredUntil) }) }}</span>
				</dd>
			</div>
			<div>
				<dt>{{ t('Mitglied') }}</dt>
				<dd>{{ claim.memberDisplayName }}</dd>
			</div>
		</dl>

		<!-- ============ Störfälle ============ -->
		<section v-if="claim.issues.length" class="vbh-cd-section" :aria-label="t('Störfälle')">
			<h5>{{ t('Störfälle') }}</h5>
			<ul class="vbh-cd-issues">
				<li v-for="(issue, index) in claim.issues" :key="index">
					<DebitStatusTag kind="severity" :value="issue.severity" />
					<span>{{ issue.message }}</span>
					<span v-if="issue.scope === 'member'" class="vbh-hint">{{ t('(betrifft das Mitglied)') }}</span>
				</li>
			</ul>
			<NcButton
				v-if="canWrite"
				size="small"
				variant="secondary"
				@click="$emit('openMember', claim.memberId)">
				{{ t('Mitglieder-Akte öffnen') }}
			</NcButton>
			<p class="vbh-hint">
				{{ t('Störfälle blockieren nichts und müssen nicht quittiert werden – sie verschwinden von selbst, sobald ihre Ursache behoben ist.') }}
			</p>
		</section>

		<!-- ============ Einzug und Rücklastschrift ============ -->
		<section v-if="claim.debit || claim.returned" class="vbh-cd-section" :aria-label="t('Einzug')">
			<h5>{{ t('Einzug') }}</h5>
			<p v-if="claim.debit" class="vbh-cd-line">
				{{ t('Im Lauf zum Einzug am {datum} ({status}).', { datum: formatDate(claim.debit.dueDate), status: batchStatusLabel(claim.debit.status) }) }}
			</p>
			<template v-if="claim.returned">
				<p class="vbh-cd-line">
					<strong>{{ t('Rücklastschrift vom {datum}.', { datum: formatDate(claim.returned.receivedAt) }) }}</strong>
				</p>
				<!-- Der Grund steht in Klartext, nie als Code (Spec §3.6): der Text kommt fertig vom Server. -->
				<p class="vbh-cd-line">
					{{ claim.returned.reason }}
				</p>
				<!-- Den Rückgabecode liefert der Server nur an Buchhalter/Verwalter (Schlüssel fehlt sonst ganz). -->
				<p v-if="claim.returned.reasonCode !== undefined" class="vbh-cd-line vbh-cd-code">
					{{ t('Rückgabecode (nur für die Buchhaltung sichtbar):') }}
					<code>{{ claim.returned.reasonCode || '–' }}</code>
					<span v-if="claim.returned.reasonText">{{ claim.returned.reasonText }}</span>
				</p>
				<p class="vbh-hint">
					{{ t('Die Forderung wird nicht erneut eingezogen (kein Wiedereinzug). Sie bleibt offen, bis sie bezahlt, erlassen oder gestundet wird; die Zahlungsaufforderung geht je nach Grund automatisch an das Mitglied.') }}
				</p>
			</template>
		</section>

		<!-- ============ Mahnstand ============ -->
		<section class="vbh-cd-section" :aria-label="t('Mahnstand')">
			<h5>{{ t('Mahnstand') }}</h5>
			<ol class="vbh-dunsteps">
				<li v-for="step in steps" :key="step.stage" :class="`vbh-dunstep vbh-dunstep--${step.status}`">
					<span class="vbh-dunstep-label">{{ step.label }}</span>
					<span class="vbh-dunstep-text">{{ stepText(step) }}</span>
				</li>
			</ol>
			<p v-if="claim.deferred" class="vbh-hint vbh-hint--info">
				{{ t('Gestundet bis {datum}: Zahlungserinnerung, Mahnung und Eskalation pausieren bis dahin; danach läuft die Mahnuhr von der zuletzt erreichten Stufe weiter.', { datum: formatDate(claim.deferredUntil) }) }}
			</p>
			<p v-if="noDunningExpected" class="vbh-hint">
				{{ t('Bisher wurde nichts versandt, und es ist auch nichts angekündigt. Bei Lastschrift-Forderungen beginnt die Mahnreihe erst nach einer Rücklastschrift oder einem Widerruf des Mandats; Überweiser-Forderungen erhalten die Zahlungsaufforderung kurz vor der Fälligkeit.') }}
			</p>
			<p v-if="dunningIntervalDays" class="vbh-hint">
				{{ t('Mahnabstand: {tage} Tage (Einstellung der Verwaltung).', { tage: dunningIntervalDays }) }}
			</p>
		</section>

		<!-- ============ Stundung, Erledigung, Storno ============ -->
		<section v-if="claim.deferredUntil" class="vbh-cd-section" :aria-label="t('Stundung')">
			<h5>{{ t('Stundung') }}</h5>
			<p class="vbh-cd-line">
				{{ claim.deferred ? t('Gestundet bis {datum}.', { datum: formatDate(claim.deferredUntil) }) : t('Die Stundung bis {datum} ist abgelaufen.', { datum: formatDate(claim.deferredUntil) }) }}
			</p>
			<!-- Freier Text der Buchhaltung: bewusst nicht in einer t()-Variable (die würde ihn HTML-escapen). -->
			<p class="vbh-cd-line">
				<strong>{{ t('Begründung:') }}</strong> {{ claim.deferredReason || '–' }}
				<span class="vbh-hint">({{ whoWhen(claim.deferredByName || claim.deferredBy, claim.deferredAt) }})</span>
			</p>
		</section>

		<section v-if="claim.state === 'erledigt'" class="vbh-cd-section" :aria-label="t('Erledigungsvermerk')">
			<h5>{{ claim.settlementType === 'waived' ? t('Erlassen') : t('Als bezahlt vermerkt') }}</h5>
			<p class="vbh-cd-line">
				{{ whoWhen(claim.settledByName || claim.settledBy, claim.settledAt) }}
			</p>
			<p v-if="claim.settlementNote" class="vbh-cd-line">
				<strong>{{ claim.settlementType === 'waived' ? t('Begründung:') : t('Notiz:') }}</strong> {{ claim.settlementNote }}
			</p>
		</section>

		<section v-if="claim.state === 'storniert'" class="vbh-cd-section" :aria-label="t('Storno')">
			<h5>{{ t('Storniert') }}</h5>
			<p class="vbh-cd-line">
				{{ formatStamp(claim.cancelledAt) }}
			</p>
			<p class="vbh-cd-line">
				<strong>{{ t('Begründung:') }}</strong> {{ claim.cancelledReason || '–' }}
			</p>
		</section>

		<!-- ============ Aktionen (ab Buchhalter) ============ -->
		<template v-if="canWrite && actions.anyAction">
			<div v-if="!action" class="vbh-cd-actions">
				<NcButton
					v-if="actions.settle"
					variant="primary"
					size="small"
					@click="action = 'paid'">
					{{ t('Als bezahlt markieren') }}
				</NcButton>
				<NcButton
					v-if="actions.waive"
					variant="secondary"
					size="small"
					@click="action = 'waive'">
					{{ t('Erlassen') }}
				</NcButton>
				<NcButton
					v-if="actions.defer"
					variant="secondary"
					size="small"
					@click="action = 'defer'">
					{{ t('Stunden') }}
				</NcButton>
				<NcButton
					v-if="actions.undefer"
					variant="secondary"
					size="small"
					:disabled="busy"
					@click="undefer">
					{{ t('Stundung aufheben') }}
				</NcButton>
				<NcButton
					v-if="actions.cancel"
					variant="tertiary"
					size="small"
					@click="action = 'cancel'">
					{{ t('Stornieren') }}
				</NcButton>
			</div>
			<p v-if="actions.cancelBlock && !action" class="vbh-hint">
				{{ cancelBlockText(actions.cancelBlock) }}
			</p>

			<ClaimActionForm
				v-if="action"
				:key="action"
				:mode="action"
				:today="today"
				:warning="warning"
				:busy="busy"
				@cancel="action = null"
				@submit="submit" />
		</template>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import ClaimActionForm from './ClaimActionForm.vue'
import DebitStatusTag from './DebitStatusTag.vue'
import api from '../api.js'
import { useConfirm } from '../composables/useConfirm.js'
import { cancelBlockText, claimActions, claimTypeLabel, debitWarning, dunningSteps, dunningStepText } from '../lib/claims.js'
import { batchStatusLabel, formatStamp } from '../lib/debitRun.js'
import { errMsg, formatDate, formatMoney } from '../lib/format.js'

/**
 * Detail einer Forderung im Segment „Forderungen“ (Issue #104, Spec §3.6): was
 * zu ihr gehört – Störfälle, Einzug und Rücklastschrift (Grund in Klartext, der
 * Code nur für die Buchhaltung), Mahnstand als Reihe der vier Stufen,
 * Stundung, Erledigungsvermerk bzw. Storno – und, ab `buchhalter`, die
 * Aktionen: als bezahlt vermerken, erlassen, stunden bzw. Stundung aufheben,
 * stornieren. Welche davon zulässig sind, entscheidet lib/claims.js::claimActions;
 * die Rollenprüfung steht noch einmal im Backend.
 *
 * Nach jeder Aktion meldet die Komponente `changed`, damit das Segment neu lädt;
 * der Sprung in die Mitglieder-Akte läuft über `openMember`.
 */
export default {
	name: 'ClaimDetail',
	components: { ClaimActionForm, DebitStatusTag, NcButton },
	props: {
		// Eine Zeile aus GET /claims/overview
		claim: { type: Object, required: true },
		// Stichtag vom Server (dieselbe Uhr wie Mahnlauf und Aufgabenliste)
		today: { type: String, required: true },
		dunningIntervalDays: { type: Number, default: null },
		canWrite: { type: Boolean, default: false },
	},

	emits: ['changed', 'openMember'],

	setup() {
		const { askConfirm } = useConfirm()
		return { askConfirm }
	},

	data() {
		return { action: null, busy: false }
	},

	computed: {
		actions() {
			const actions = claimActions(this.claim)
			return { ...actions, anyAction: actions.settle || actions.waive || actions.defer || actions.undefer || actions.cancel || actions.cancelBlock !== null }
		},

		steps() { return dunningSteps(this.claim.dunning) },

		// Weder etwas versandt noch etwas angekündigt – und die Forderung ist noch offen.
		noDunningExpected() {
			const { stage, nextStage } = this.claim.dunning
			return stage === null && nextStage === null && this.actions.settle
		},

		warning() { return debitWarning(this.claim, this.today) },
	},

	watch: {
		// Nach einem Neuladen kann die Forderung eine andere sein (oder erledigt): ein offenes Formular passt dann nicht mehr.
		'claim.id': {
			handler() { this.action = null },
		},

		'claim.state': {
			handler() { this.action = null },
		},
	},

	methods: {
		formatDate,
		formatMoney,
		formatStamp,
		batchStatusLabel,
		claimTypeLabel,
		cancelBlockText,

		stepText(step) { return dunningStepText(step, this.today) },

		/** „Katrin Kassenwart, 04.10.2026 12:30“ – der Name kommt vom Server (Anzeigename, sonst die uid). */
		whoWhen(who, when) {
			return [who, formatStamp(when)].filter(Boolean).join(', ')
		},

		async submit({ note, until }) {
			const id = this.claim.id
			const mode = this.action
			this.busy = true
			try {
				if (mode === 'paid') { await api.settleClaim(id, 'paid', note) }
				if (mode === 'waive') { await api.settleClaim(id, 'waived', note) }
				if (mode === 'defer') { await api.deferClaim(id, until, note) }
				if (mode === 'cancel') { await api.cancelClaim(id, note) }
				this.action = null
				showSuccess({
					paid: this.t('Forderung als bezahlt vermerkt.'),
					waive: this.t('Erlass vermerkt.'),
					defer: this.t('Stundung gesetzt.'),
					cancel: this.t('Forderung storniert.'),
				}[mode])
				this.$emit('changed')
			} catch (e) {
				showError(errMsg(e, {
					paid: this.t('Der Vermerk konnte nicht gespeichert werden.'),
					waive: this.t('Der Erlass konnte nicht gespeichert werden.'),
					defer: this.t('Die Stundung konnte nicht gespeichert werden.'),
					cancel: this.t('Die Forderung konnte nicht storniert werden.'),
				}[mode]))
			} finally { this.busy = false }
		},

		async undefer() {
			const ok = await this.askConfirm(
				this.t('Stundung aufheben'),
				this.t('Die Stundung endet sofort. Das Mahnwesen läuft von der zuletzt erreichten Stufe weiter, der nächste Schritt kann damit schon am nächsten Tag anstehen.'),
				this.t('Stundung aufheben'),
				'primary',
			)
			if (!ok) { return }
			this.busy = true
			try {
				await api.undeferClaim(this.claim.id)
				showSuccess(this.t('Stundung aufgehoben.'))
				this.$emit('changed')
			} catch (e) { showError(errMsg(e, this.t('Die Stundung konnte nicht aufgehoben werden.'))) } finally { this.busy = false }
		},
	},
}
</script>

<style scoped>
.vbh-claimdetail {
	padding: 4px 0;
}

.vbh-cd-facts {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
	gap: 8px 24px;
	margin: 0 0 8px;
}

.vbh-cd-facts > div {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

/* Nextcloud gibt dt/dd Innenabstand und setzt dt rechtsbündig. */
.vbh-cd-facts dt {
	margin: 0;
	padding: 0;
	text-align: start;
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
}

.vbh-cd-facts dd {
	margin: 0;
	padding: 0;
	overflow-wrap: anywhere;
}

.vbh-cd-section {
	margin-top: 12px;
}

.vbh-cd-section h5 {
	margin: 0 0 4px;
}

.vbh-cd-line {
	margin: 2px 0;
	overflow-wrap: anywhere;
}

.vbh-cd-code code {
	padding: 1px 6px;
	border-radius: 4px;
	background-color: var(--color-background-dark);
	font-size: 0.9em;
}

.vbh-cd-issues {
	margin: 0 0 8px;
	padding: 0;
	list-style: none;
}

.vbh-cd-issues li {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 4px 8px;
	margin: 4px 0;
}

.vbh-dunsteps {
	display: grid;
	gap: 4px;
	margin: 0 0 8px;
	padding: 0;
	list-style: none;
}

.vbh-dunstep {
	display: grid;
	grid-template-columns: minmax(150px, max-content) 1fr;
	align-items: baseline;
	gap: 4px 16px;
	padding: 4px 10px;
	border-inline-start: 3px solid var(--color-border-dark);
}

.vbh-dunstep-label {
	font-weight: 600;
}

.vbh-dunstep--ausstehend {
	color: var(--color-text-maxcontrast);
}

.vbh-dunstep--versandt {
	border-inline-start-color: var(--color-element-success);
}

.vbh-dunstep--naechste {
	border-inline-start-color: var(--color-primary-element);
}

.vbh-dunstep--eskaliert {
	border-inline-start-color: var(--color-element-error);
}

.vbh-cd-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 14px 0 4px;
}

@media (max-width: 600px) {
	.vbh-dunstep {
		grid-template-columns: 1fr;
	}
}
</style>
