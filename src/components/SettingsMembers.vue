<template>
	<div>
		<h3 class="vbh-section-divider">
			{{ t('Mitglieder und Beiträge') }}
		</h3>
		<p class="vbh-hint">
			{{ t('Ein Mitglied besteht hier aus zwei Angaben: seiner Bankverbindung (dem SEPA-Mandat) und seinem Beitrag. Beides wird in einem Schritt angelegt. Eine eigene Mitgliederverwaltung führt die App bewusst nicht – wer keine Beiträge einzieht, braucht diesen Abschnitt nicht.') }}
		</p>

		<div class="vbh-card">
			<h4>{{ t('Mitglied aufnehmen') }}</h4>
			<div class="vbh-form">
				<label>{{ t('Zahler') }}
					<select v-model="form.memberKind">
						<option value="label">{{ t('Freier Zahlername') }}</option>
						<option value="user">{{ t('Nextcloud-Nutzer') }}</option>
					</select>
				</label>
				<label v-if="form.memberKind === 'user'" class="vbh-grow">{{ t('Nutzer') }}
					<NcSelect v-model="formMemberOption"
						:options="userOptions"
						label="label"
						:placeholder="t('– Nutzer wählen –')" />
				</label>
				<label v-else class="vbh-grow">{{ t('Name') }}
					<input v-model="form.memberLabel" :placeholder="t('z. B. Katrin Brunner')">
				</label>
				<label class="vbh-grow">{{ t('E-Mail') }}
					<input v-model="form.email" type="email" :placeholder="t('für die Vorankündigung')">
				</label>
			</div>

			<div class="vbh-form">
				<label class="vbh-grow">{{ t('IBAN') }}
					<input v-model="form.iban" placeholder="DE12 5001 0517 0648 4898 90">
				</label>
				<label>{{ t('BIC') }}
					<input v-model="form.bic" class="vbh-short" :placeholder="t('optional')">
				</label>
				<label>{{ t('Mandat unterschrieben am') }}
					<input v-model="form.signedDate" type="date">
				</label>
			</div>

			<div class="vbh-form">
				<label>{{ t('Betrag (€)') }}
					<input v-model="form.amount"
						type="number"
						step="0.01"
						min="0"
						class="vbh-short">
				</label>
				<label>{{ t('Frequenz') }}
					<select v-model="form.frequency">
						<option v-for="f in frequencies" :key="f.value" :value="f.value">
							{{ f.label }}
						</option>
					</select>
				</label>
				<label>{{ t('Erste Fälligkeit') }}
					<input v-model="form.startDate" type="date">
				</label>
				<label class="vbh-grow">{{ t('Ertragskonto') }}
					<select v-model="form.accountId">
						<option :value="null">
							{{ t('– optional –') }}
						</option>
						<option v-for="a in incomeAccounts" :key="a.id" :value="a.id">
							{{ a.number }} · {{ a.name }}
						</option>
					</select>
				</label>
				<NcButton variant="primary" :disabled="!canSave || saving" @click="createMember">
					{{ t('Aufnehmen') }}
				</NcButton>
			</div>
			<p class="vbh-hint">
				{{ t('Beides ist einzeln möglich: ohne IBAN entsteht nur ein Beitrag (etwa für Überweiser), ohne Betrag nur ein Mandat.') }}
			</p>
		</div>

		<div class="vbh-card">
			<h4>{{ t('Mitgliederliste einlesen') }}</h4>
			<p class="vbh-hint">
				{{ t('Für die erstmalige Aufnahme vieler Mitglieder: eine CSV-Datei mit den Spalten Name, E-Mail, IBAN, Mandat am, Betrag, Frequenz und Start. Die Reihenfolge und die Schreibweise der Überschriften sind egal, zusätzliche Spalten werden übergangen. Vor dem Anlegen sehen Sie zuerst, was entstehen würde.') }}
			</p>
			<div class="vbh-form">
				<input ref="csvInput"
					type="file"
					accept=".csv,text/csv"
					@change="onFileChosen">
				<NcButton :disabled="!importCsv || importing" @click="previewImport">
					{{ t('Prüfen') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="!importPreview || importSummary.ok === 0 || importing"
					@click="runImport">
					{{ n('%n Zeile übernehmen', '%n Zeilen übernehmen', importSummary.ok) }}
				</NcButton>
				<NcButton v-if="importPreview" variant="tertiary" @click="resetImport">
					{{ t('Abbrechen') }}
				</NcButton>
				<a :href="beispielCsv" download="mitglieder-vorlage.csv" class="vbh-export-btn">{{ t('Vorlage herunterladen') }}</a>
			</div>

			<p v-if="importError" class="vbh-hint vbh-hint--warning">
				{{ importError }}
			</p>

			<template v-if="importPreview">
				<p class="vbh-hint" :class="importSummary.failed ? 'vbh-hint--warning' : 'vbh-hint--info'">
					{{ t('{ok} von {total} Zeilen sind in Ordnung: {mandate} Mandate und {beitraege} Beiträge würden angelegt. {fehler} Zeilen werden übersprungen.', {
						ok: importSummary.ok,
						total: importPreview.length,
						mandate: importSummary.mandates,
						beitraege: importSummary.fees,
						fehler: importSummary.failed,
					}) }}
				</p>
				<div class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th>{{ t('Zeile') }}</th>
								<th>{{ t('Zahler') }}</th>
								<th>{{ t('IBAN') }}</th>
								<th class="num">
									{{ t('Betrag') }}
								</th>
								<th>{{ t('Ergebnis') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in importPreview" :key="row.line">
								<td>{{ row.line }}</td>
								<td>{{ row.name || '–' }}</td>
								<td class="nowrap">
									{{ row.iban || '–' }}
								</td>
								<td class="num nowrap">
									{{ row.amount === null ? '–' : formatMoney(row.amount) }}
								</td>
								<td>
									<span v-if="row.errors.length" class="vbh-hint vbh-hint--warning">{{ row.errors.join(' ') }}</span>
									<span v-else-if="row.mandateId || row.feeId" class="vbh-typetag">{{ t('angelegt') }}</span>
									<span v-else class="vbh-typetag">{{ importLabel(row) }}</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</template>
		</div>

		<div class="vbh-form">
			<label class="vbh-grow">{{ t('Suchen') }}
				<input v-model="search" type="search" :placeholder="t('Name, IBAN oder E-Mail')">
			</label>
			<label>
				<input v-model="onlyProblems" type="checkbox">
				{{ t('nur Auffälligkeiten') }}
			</label>
		</div>

		<p v-if="rows.length" class="vbh-hint">
			{{ t('{gezeigt} von {gesamt} Einträgen · {mitMandat} mit Mandat · Beitragsaufkommen {summe} im Jahr', {
				gezeigt: filteredRows.length,
				gesamt: rows.length,
				mitMandat: rows.filter(r => r.mandate).length,
				summe: formatMoney(jahresSumme),
			}) }}
		</p>

		<div v-if="filteredRows.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Zahler') }}</th>
						<th>{{ t('Bankverbindung') }}</th>
						<th class="num">
							{{ t('Betrag') }}
						</th>
						<th>{{ t('Frequenz') }}</th>
						<th>{{ t('Nächste Fälligkeit') }}</th>
						<th>{{ t('Aktiv') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in filteredRows" :key="row.key">
						<td>
							{{ row.displayName }}
							<span v-if="!row.email" class="vbh-hint">{{ t('keine E-Mail – keine Vorankündigung möglich') }}</span>
						</td>
						<td class="nowrap">
							<template v-if="row.mandate">
								{{ row.mandate.iban }}
								<span v-if="row.mandate.status !== 'active'" class="vbh-typetag">{{ t('widerrufen') }}</span>
							</template>
							<span v-else class="vbh-hint">{{ t('kein Mandat') }}</span>
						</td>

						<template v-if="editing && row.fee && editing.id === row.fee.id">
							<td class="num">
								<input v-model="editing.amount"
									type="number"
									step="0.01"
									min="0"
									class="vbh-short">
							</td>
							<td>
								<select v-model="editing.frequency">
									<option v-for="f in frequencies" :key="f.value" :value="f.value">
										{{ f.label }}
									</option>
								</select>
							</td>
							<td><input v-model="editing.nextDueDate" type="date"></td>
							<td><input v-model="editing.active" type="checkbox"></td>
							<td class="nowrap right">
								<NcButton variant="primary"
									size="small"
									:disabled="saving"
									@click="saveEdit">
									{{ t('Speichern') }}
								</NcButton>
								<NcButton variant="tertiary" size="small" @click="editing = null">
									{{ t('Abbrechen') }}
								</NcButton>
							</td>
						</template>

						<template v-else>
							<td class="num nowrap">
								{{ row.fee ? formatMoney(row.fee.amount) : '–' }}
							</td>
							<td>{{ row.fee ? frequencyLabel(row.fee.frequency) : '–' }}</td>
							<td class="nowrap">
								{{ row.fee ? row.fee.nextDueDate : '–' }}
								<span v-if="row.fee && row.fee.dueCount > 0" class="vbh-hint vbh-hint--warning">
									{{ n('%n Periode im Rückstand', '%n Perioden im Rückstand', row.fee.dueCount) }}
								</span>
							</td>
							<td>
								<input v-if="row.fee"
									type="checkbox"
									:checked="row.fee.active"
									@change="toggleActive(row.fee, $event.target.checked)">
								<span v-else>–</span>
							</td>
							<td class="nowrap right">
								<NcButton v-if="row.fee && row.fee.dueCount > 0"
									variant="secondary"
									size="small"
									@click="catchUp(row.fee)">
									{{ t('Nachholen') }}
								</NcButton>
								<NcButton v-if="row.fee"
									variant="tertiary"
									size="small"
									@click="startEdit(row.fee)">
									{{ t('Bearbeiten') }}
								</NcButton>
								<NcButton v-if="row.mandate && row.mandate.status === 'active'"
									variant="tertiary"
									size="small"
									@click="revokeMandate(row.mandate)">
									{{ t('Mandat widerrufen') }}
								</NcButton>
								<NcButton v-if="row.fee"
									variant="error"
									size="small"
									:aria-label="t('Beitrag löschen')"
									@click="removeFee(row.fee)">
									{{ t('Löschen') }}
								</NcButton>
								<NcButton v-else-if="row.mandate && !isUsed(row.mandate)"
									variant="error"
									size="small"
									:aria-label="t('Mandat löschen')"
									@click="removeMandate(row.mandate)">
									{{ t('Löschen') }}
								</NcButton>
							</td>
						</template>
					</tr>
				</tbody>
			</table>
		</div>
		<p v-else class="vbh-hint">
			{{ rows.length ? t('Kein Eintrag passt zur Suche.') : t('Noch kein Mitglied aufgenommen.') }}
		</p>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import { NcButton, NcSelect } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { useMembershipFees } from '../composables/useMembershipFees.js'
import { useSepaMandates } from '../composables/useSepaMandates.js'
import { usePermissions } from '../composables/usePermissions.js'
import { useAccounts } from '../composables/useAccounts.js'
import { useConfirm } from '../composables/useConfirm.js'
import { t } from '../lib/l10n.js'

/** Monate je Frequenz – für die Hochrechnung aufs Jahr. */
const MONTHS = { monthly: 1, quarterly: 3, semiannual: 6, yearly: 12 }

function emptyForm() {
	return {
		memberKind: 'label',
		memberUid: '',
		memberLabel: '',
		email: '',
		iban: '',
		bic: '',
		signedDate: new Date().toISOString().slice(0, 10),
		amount: '',
		frequency: 'yearly',
		startDate: new Date().toISOString().slice(0, 10),
		accountId: null,
	}
}

function frequencyLabels() {
	return {
		monthly: t('monatlich'),
		quarterly: t('vierteljährlich'),
		semiannual: t('halbjährlich'),
		yearly: t('jährlich'),
	}
}

/**
 * Mitglieder als eine Liste: Mandat und Beitrag gehören zusammen und werden
 * hier auch zusammen gezeigt und angelegt.
 *
 * Vorher standen beide in getrennten Abschnitten mit je eigenem Formular, in
 * denen der Zahler jeweils erneut auszuwählen war – für einen Verein mit 200
 * Mitgliedern unbrauchbar. Deshalb zusätzlich Suche, Mengenanzeige und der
 * CSV-Import für die erstmalige Aufnahme.
 *
 * Nur für Verwalter erreichbar (siehe SepaMandateController).
 */
export default {
	name: 'SettingsMembers',
	components: { NcButton, NcSelect },
	setup() {
		const membershipFees = useMembershipFees()
		const sepaMandates = useSepaMandates()
		const permissions = usePermissions()
		const accounts = useAccounts()
		return {
			...toRefs(membershipFees.state),
			...toRefs(sepaMandates.state),
			...toRefs(permissions.state),
			...toRefs(accounts.state),
			loadMembershipFees: membershipFees.loadMembershipFees,
			loadSepaMandates: sepaMandates.loadSepaMandates,
			askConfirm: useConfirm().askConfirm,
		}
	},
	data() {
		return {
			form: emptyForm(),
			saving: false,
			editing: null,
			search: '',
			onlyProblems: false,
			frequencies: Object.entries(frequencyLabels()).map(([value, label]) => ({ value, label })),
			importCsv: '',
			importPreview: null,
			importError: '',
			importing: false,
			importSummary: { ok: 0, failed: 0, mandates: 0, fees: 0 },
		}
	},
	computed: {
		userOptions() { return this.users.map(u => ({ id: u.id, label: `${u.displayName} (${u.id})` })) },
		formMemberOption: {
			get() { return this.userOptions.find(o => o.id === this.form.memberUid) ?? null },
			set(v) { this.form.memberUid = v ? v.id : '' },
		},
		incomeAccounts() {
			return this.accounts.filter(a => a.type === 'income' && !a.isBank)
				.slice()
				.sort((a, b) => String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
		},
		canSave() {
			const hasMember = this.form.memberKind === 'user' ? !!this.form.memberUid : !!this.form.memberLabel.trim()
			const hasMandate = !!this.form.iban.trim() && !!this.form.signedDate
			const hasFee = Number(this.form.amount) > 0 && !!this.form.startDate
			return hasMember && (hasMandate || hasFee)
		},
		/**
		 * Mandate und Beiträge zu einer Liste verschmolzen. Schlüssel ist der
		 * Zahler; hat jemand mehrere Beiträge, bekommt er je Beitrag eine Zeile.
		 */
		rows() {
			const key = x => (x.memberUid ? `u:${x.memberUid}` : `l:${x.memberLabel}`)
			const mandateFor = new Map()
			for (const m of this.sepaMandates) {
				// Ein aktives Mandat sticht ein widerrufenes: gezeigt wird das,
				// mit dem tatsaechlich eingezogen wird.
				const vorhanden = mandateFor.get(key(m))
				if (!vorhanden || (vorhanden.status !== 'active' && m.status === 'active')) mandateFor.set(key(m), m)
			}

			const zeilen = []
			const behandelt = new Set()
			for (const fee of this.membershipFees) {
				const k = key(fee)
				behandelt.add(k)
				const mandate = fee.mandateId
					? this.sepaMandates.find(m => m.id === fee.mandateId)
					: mandateFor.get(k)
				zeilen.push({
					key: `fee-${fee.id}`,
					displayName: fee.displayName,
					email: mandate?.email || null,
					mandate: mandate || null,
					fee,
				})
			}
			// Mandate ohne Beitrag duerfen nicht verschwinden - sonst faende
			// niemand mehr das Mandat, das er gerade angelegt hat.
			for (const m of this.sepaMandates) {
				if (behandelt.has(key(m))) continue
				zeilen.push({
					key: `mandate-${m.id}`,
					displayName: m.displayName,
					email: m.email || null,
					mandate: m,
					fee: null,
				})
			}
			return zeilen.sort((a, b) => a.displayName.localeCompare(b.displayName, 'de'))
		},
		filteredRows() {
			const suche = this.search.trim().toLowerCase()
			return this.rows.filter(r => {
				if (this.onlyProblems && !this.hasProblem(r)) return false
				if (!suche) return true
				return [r.displayName, r.email, r.mandate?.iban, r.mandate?.mandateReference]
					.filter(Boolean)
					.some(v => String(v).toLowerCase().includes(suche))
			})
		},
		/** Beitragsaufkommen aufs Jahr hochgerechnet – nur aktive Beiträge. */
		jahresSumme() {
			return this.rows.reduce((summe, r) => {
				if (!r.fee || !r.fee.active) return summe
				return summe + r.fee.amount * (12 / (MONTHS[r.fee.frequency] || 12))
			}, 0)
		},
		/** Vorlage als Daten-URL: kein zusätzlicher Endpunkt nötig. */
		beispielCsv() {
			const zeilen = [
				'Name;E-Mail;IBAN;BIC;Mandat am;Betrag;Frequenz;Start',
				'Katrin Brunner;k.brunner@example.org;DE02120300000000202051;;15.01.2026;42,50;monatlich;01.02.2026',
				'Hans Mertens;h.mertens@example.org;DE02120300000000202051;;15.01.2026;120,00;jährlich;01.01.2026',
			].join('\r\n')
			return 'data:text/csv;charset=utf-8,' + encodeURIComponent('﻿' + zeilen)
		},
	},
	mounted() {
		this.loadMembershipFees()
		this.loadSepaMandates()
	},
	methods: {
		errMsg,
		formatMoney,
		frequencyLabel(f) { return frequencyLabels()[f] || f },
		/** Was der Verwalter sehen sollte: fehlende Adresse, Rückstand, kein Mandat. */
		hasProblem(row) {
			if (row.fee && row.fee.dueCount > 0) return true
			if (row.fee && row.fee.active && !row.mandate) return true
			return !row.email
		},
		isUsed(m) {
			const u = m.usage || {}
			return (u.batchItems || 0) + (u.fees || 0) + (u.openItems || 0) > 0
		},
		importLabel(row) {
			if (row.willCreateMandate && row.willCreateFee) return this.t('Mandat und Beitrag')
			if (row.willCreateMandate) return this.t('nur Mandat')
			return this.t('nur Beitrag')
		},
		async reload() {
			await Promise.all([this.loadMembershipFees(), this.loadSepaMandates()])
		},

		/**
		 * Legt Mandat und Beitrag zusammen an. Schlägt der Beitrag fehl, bleibt
		 * das Mandat bestehen - deshalb sagt die Meldung ausdrücklich, was
		 * entstanden ist, statt nur „fehlgeschlagen".
		 */
		async createMember() {
			this.saving = true
			const memberUid = this.form.memberKind === 'user' ? this.form.memberUid : null
			const memberLabel = this.form.memberKind === 'label' ? this.form.memberLabel.trim() : null
			let mandateId = null
			try {
				if (this.form.iban.trim()) {
					const { data } = await api.createSepaMandate({
						memberUid,
						memberLabel,
						iban: this.form.iban.trim(),
						bic: this.form.bic.trim() || null,
						email: this.form.email.trim() || null,
						mandateType: 'RCUR',
						signedDate: this.form.signedDate,
					})
					mandateId = data.id
				}
				if (Number(this.form.amount) > 0) {
					await api.createMembershipFee({
						memberUid,
						memberLabel,
						amount: Number(this.form.amount),
						frequency: this.form.frequency,
						startDate: this.form.startDate,
						accountId: this.form.accountId,
						mandateId,
					})
				}
				this.form = emptyForm()
				await this.reload()
				showSuccess(this.t('Mitglied aufgenommen.'))
			} catch (e) {
				await this.reload()
				showError(this.errMsg(e, mandateId
					? this.t('Das Mandat wurde angelegt, der Beitrag nicht')
					: this.t('Mitglied konnte nicht aufgenommen werden')))
			} finally { this.saving = false }
		},

		startEdit(fee) {
			this.editing = {
				id: fee.id,
				amount: fee.amount,
				frequency: fee.frequency,
				nextDueDate: fee.nextDueDate,
				active: fee.active,
				accountId: fee.accountId,
				mandateId: fee.mandateId,
			}
		},
		async saveEdit() {
			this.saving = true
			try {
				await api.updateMembershipFee(this.editing.id, {
					amount: Number(this.editing.amount),
					frequency: this.editing.frequency,
					accountId: this.editing.accountId,
					mandateId: this.editing.mandateId,
					active: this.editing.active,
					nextDueDate: this.editing.nextDueDate,
				})
				this.editing = null
				await this.loadMembershipFees()
				showSuccess(this.t('Beitrag gespeichert.'))
			} catch (e) { showError(this.errMsg(e, this.t('Speichern fehlgeschlagen'))) } finally { this.saving = false }
		},
		async toggleActive(fee, active) {
			try {
				await api.updateMembershipFee(fee.id, {
					amount: fee.amount,
					frequency: fee.frequency,
					accountId: fee.accountId,
					mandateId: fee.mandateId,
					active,
					nextDueDate: fee.nextDueDate,
				})
				await this.loadMembershipFees()
			} catch (e) { showError(this.errMsg(e, this.t('Speichern fehlgeschlagen'))) }
		},
		async catchUp(fee) {
			if (!await this.askConfirm(
				this.t('Rückstand nachholen'),
				this.n(
					'Für diesen Beitrag fehlt noch %n offener Posten. Soll er jetzt erzeugt werden?',
					'Für diesen Beitrag fehlen noch %n offene Posten. Sollen sie jetzt alle erzeugt werden?',
					fee.dueCount,
				),
			)) return
			try {
				const { data } = await api.catchUpMembershipFee(fee.id)
				await this.loadMembershipFees()
				showSuccess(this.n('%n offener Posten erzeugt.', '%n offene Posten erzeugt.', data.created))
			} catch (e) { showError(this.errMsg(e, this.t('Nachholen fehlgeschlagen'))) }
		},
		async removeFee(fee) {
			if (!await this.askConfirm(this.t('Beitrag löschen'), this.t('Beitrag für „{name}" endgültig löschen? Das Mandat bleibt bestehen.', { name: fee.displayName }))) return
			try {
				await api.deleteMembershipFee(fee.id)
				await this.loadMembershipFees()
				showSuccess(this.t('Beitrag gelöscht.'))
			} catch (e) { showError(this.errMsg(e, this.t('Löschen fehlgeschlagen'))) }
		},
		async revokeMandate(mandate) {
			if (!await this.askConfirm(this.t('Mandat widerrufen'), this.t('Mandat für „{name}" widerrufen? Es wird danach nicht mehr für neue Einzüge verwendet.', { name: mandate.displayName }))) return
			try {
				await api.revokeSepaMandate(mandate.id)
				await this.loadSepaMandates()
				showSuccess(this.t('Mandat widerrufen.'))
			} catch (e) { showError(this.errMsg(e, this.t('Widerrufen fehlgeschlagen'))) }
		},
		async removeMandate(mandate) {
			if (!await this.askConfirm(this.t('Mandat löschen'), this.t('Mandat für „{name}" endgültig löschen?', { name: mandate.displayName }))) return
			try {
				await api.deleteSepaMandate(mandate.id)
				await this.loadSepaMandates()
				showSuccess(this.t('Mandat gelöscht.'))
			} catch (e) { showError(this.errMsg(e, this.t('Löschen fehlgeschlagen'))) }
		},

		onFileChosen(event) {
			const datei = event.target.files && event.target.files[0]
			this.resetImport()
			if (!datei) return
			const leser = new FileReader()
			leser.onload = () => { this.importCsv = String(leser.result || '') }
			leser.onerror = () => showError(this.t('Die Datei konnte nicht gelesen werden.'))
			// Vereinstabellen kommen oft als Windows-1252 aus Excel; UTF-8 ist
			// der Normalfall, der Rest faellt beim Pruefen als kaputte Umlaute auf.
			leser.readAsText(datei, 'utf-8')
		},
		resetImport() {
			this.importPreview = null
			this.importError = ''
			this.importSummary = { ok: 0, failed: 0, mandates: 0, fees: 0 }
		},
		async previewImport() {
			this.importing = true
			try {
				const { data } = await api.previewMemberImport(this.importCsv)
				this.importError = data.error || ''
				this.importPreview = data.error ? null : data.rows
				this.importSummary = data.summary
			} catch (e) { showError(this.errMsg(e, this.t('Prüfen fehlgeschlagen'))) } finally { this.importing = false }
		},
		async runImport() {
			if (!await this.askConfirm(
				this.t('Mitglieder übernehmen'),
				this.t('{ok} Zeilen werden jetzt angelegt ({mandate} Mandate, {beitraege} Beiträge). {fehler} fehlerhafte Zeilen werden übersprungen.', {
					ok: this.importSummary.ok,
					mandate: this.importSummary.mandates,
					beitraege: this.importSummary.fees,
					fehler: this.importSummary.failed,
				}),
			)) return
			this.importing = true
			try {
				const { data } = await api.runMemberImport(this.importCsv)
				this.importPreview = data.rows
				this.importSummary = data.summary
				this.importCsv = ''
				if (this.$refs.csvInput) this.$refs.csvInput.value = ''
				await this.reload()
				showSuccess(this.n('%n Zeile übernommen.', '%n Zeilen übernommen.', data.summary.ok))
			} catch (e) { showError(this.errMsg(e, this.t('Import fehlgeschlagen'))) } finally { this.importing = false }
		},
	},
}
</script>
