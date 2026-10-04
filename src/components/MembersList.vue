<template>
	<div>
		<p class="vbh-hint">
			{{ t('Ein Mitglied wird unabhängig von einer Bankverbindung geführt – SEPA-Mandat und Beitrag sind optionale Ergänzungen, die sich jederzeit über „Aufnehmen" bzw. die Akte nachtragen lassen.') }}
		</p>

		<div class="vbh-form">
			<label class="vbh-grow">{{ t('Suchen') }}
				<input v-model="search" type="search" :placeholder="t('Name, IBAN, Mitgliedsnummer oder E-Mail')">
			</label>
			<label>
				<input v-model="onlyProblems" type="checkbox">
				{{ t('nur Auffälligkeiten') }}
			</label>
		</div>

		<p v-if="rows.length" class="vbh-hint">
			{{ t('{gezeigt} von {gesamt} Mitgliedern · {mitMandat} mit Mandat · Beitragsaufkommen {summe} im Jahr', {
				gezeigt: filteredRows.length,
				gesamt: rows.length,
				mitMandat: rows.filter(r => r.mandate).length,
				summe: formatMoney(jahresSumme),
			}) }}
		</p>

		<div v-if="filteredRows.length && isMobile" class="vbh-cardlist">
			<MemberCard
				v-for="row in filteredRows"
				:key="row.key"
				:row="row"
				@manageAssignments="$emit('manage-assignments')"
				@openMember="openMemberAkte(row.member)"
				@openMandate="openMemberAkte(row.member, 'mandate')" />
		</div>
		<div v-else-if="filteredRows.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Mitglied') }}</th>
						<th>{{ t('Bankverbindung') }}</th>
						<th class="num">
							{{ t('Betrag') }}
						</th>
						<th>{{ t('Frequenz') }}</th>
						<th>{{ t('Nächste Fälligkeit') }}</th>
						<th>{{ tc('Zustand', 'Aktiv') }}</th>
						<th class="vbh-col-memberactions" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in filteredRows" :key="row.key">
						<td>
							{{ row.displayName }}
							<span v-if="row.member.memberNumber" class="vbh-hint">#{{ row.member.memberNumber }}</span>
							<span v-if="!row.member.active" class="vbh-typetag">{{ t('ausgetreten') }}</span>
							<br v-if="!row.email">
							<span v-if="!row.email" class="vbh-hint">{{ t('keine E-Mail – keine Vorankündigung möglich') }}</span>
						</td>
						<td class="nowrap">
							<template v-if="row.mandate">
								{{ row.mandate.iban }}
								<span v-if="row.mandate.statusTag" class="vbh-typetag">{{ row.mandate.statusTag }}</span>
							</template>
							<span v-else-if="row.fee && !row.fee.needsMandate" class="vbh-hint">{{ t('Überweisung') }}</span>
							<span v-else class="vbh-hint">{{ t('kein Mandat') }}</span>
						</td>
						<td class="num nowrap">
							{{ row.fee ? formatMoney(row.fee.amount) : '–' }}
						</td>
						<td>
							{{ row.fee ? row.fee.frequencyLabel : '–' }}
							<span v-if="row.moreFees > 0" class="vbh-hint">
								{{ n('+ %n weitere Zuweisung', '+ %n weitere Zuweisungen', row.moreFees) }}
							</span>
						</td>
						<td class="nowrap">
							{{ row.nextDueDate || '–' }}
						</td>
						<td>
							<span v-if="row.fee">{{ row.fee.statusLabel }}</span>
							<span v-else>–</span>
						</td>
						<td class="nowrap right">
							<div class="vbh-actions">
								<!-- Zuweisungen werden nicht inline bearbeitet: Betrag, Turnus und
									Laufzeit haben ihre Stelle bei den Beitragsgruppen. -->
								<NcButton
									v-if="row.fee"
									variant="tertiary"
									size="small"
									:aria-label="t('Zuweisung verwalten')"
									:title="t('In den Beitragsgruppen verwalten')"
									@click="$emit('manage-assignments')">
									<template #icon>
										<NcIconSvgWrapper :path="mdiPencil" :size="20" />
									</template>
								</NcButton>
								<!-- Seltener genutzte Aktionen im Menue, sonst wird die Zeile
									durch weitere Icon-Buttons zu breit (dasselbe Muster wie im
									Buchungsjournal, siehe BookingsTab.vue). -->
								<NcActions :forceMenu="true">
									<NcActionButton closeAfterClick @click="openMemberAkte(row.member)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiAccountEdit" :size="16" />
										</template>
										{{ t('Akte öffnen') }}
									</NcActionButton>
									<!-- Das Mandat führen (aktivieren, sperren, widerrufen, IBAN ändern …):
										springt in der Akte zum Mandat-Bereich (MandatePanel.vue, Issue #100). -->
									<NcActionButton closeAfterClick @click="openMemberAkte(row.member, 'mandate')">
										<template #icon>
											<NcIconSvgWrapper :path="mdiFileSign" :size="16" />
										</template>
										{{ t('Mandat verwalten') }}
									</NcActionButton>
								</NcActions>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<NcEmptyContent
			v-if="!filteredRows.length"
			:name="rows.length ? t('Kein Eintrag passt zur Suche.') : t('Noch kein Mitglied aufgenommen.')"
			:description="rows.length ? '' : t('Mit „＋ Mitglied“ oben ein erstes Mitglied anlegen, oder eine Liste als CSV einlesen.')" />

		<MemberDialog
			:show="memberDialogOpen"
			:saving="saving"
			:member="editingMember"
			:defaultFeeAmount="defaultFeeAmount"
			:section="akteSection"
			@update:show="memberDialogOpen = $event"
			@close="memberDialogOpen = false"
			@save="saveMember"
			@changed="reload" />

		<MemberImportDialog
			:show="importDialogOpen"
			:defaultFeeAmount="defaultFeeAmount"
			@update:show="importDialogOpen = $event"
			@close="importDialogOpen = false"
			@imported="reload" />
	</div>
</template>

<script>
import { mdiAccountEdit, mdiFileSign, mdiPencil } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcActionButton, NcActions, NcButton, NcEmptyContent, NcIconSvgWrapper } from '@nextcloud/vue'
import { toRefs } from 'vue'
import MemberCard from './MemberCard.vue'
import MemberDialog from './MemberDialog.vue'
import MemberImportDialog from './MemberImportDialog.vue'
import api from '../api.js'
import { useAssignments } from '../composables/useAssignments.js'
import { useClaimOverview } from '../composables/useClaimOverview.js'
import { useMandates } from '../composables/useMandates.js'
import { useMemberAkteRequest } from '../composables/useMemberAkteRequest.js'
import { useMembers } from '../composables/useMembers.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { createMandateForMember } from '../lib/mandateCreate.js'
import { buildMemberRow, nextDueDates } from '../lib/memberRow.js'

/**
 * Mitgliederliste (Spec §2.2/§3.1, docs/beitraege-sepa-modul-spec.md): jede
 * Zeile ist ein Mitglied, angereichert um sein SEPA-Mandat, seine Zuweisung zu
 * einer Beitragsgruppe und die nächste offene Forderung, falls vorhanden – alles
 * ist unabhängig vom Mitglied selbst (siehe Migration 000137/000138/000139).
 *
 * Mandat und Beitrag kommen aus dem Mandats- und Zuweisungsmodell (Mandate/
 * Assignment – der Aufnahme-Assistent und der CSV-Import legen dort an); die
 * Normalisierung für die Anzeige steckt in lib/memberRow.js. Die „Nächste
 * Fälligkeit" kommt aus der Forderungsübersicht (GET /claims/overview, eine
 * Abfrage für die ganze Liste). Zuweisungen werden hier nicht inline
 * bearbeitet – sie führen zu den Beitragsgruppen (`manage-assignments`), das
 * Mandat zur Akte.
 *
 * Frueher SettingsMembers.vue im Einstellungen-Modal, jetzt Unterreiter
 * „Mitglieder" von ContributionsTab.vue, siehe NAVIGATION-KONZEPT.md
 * Abschnitt 4. Die Formulare leben in eigenen Dialogen (MemberDialog.vue,
 * MemberImportDialog.vue), die per $refs von der Kopfzeile in
 * ContributionsTab.vue geoeffnet werden.
 *
 * Erreichbar ab Rolle Buchhalter (siehe MemberController) – anders als der
 * Einzug-Unterreiter *nicht* ab Revisor, weil hier unmaskierte Kontaktdaten
 * stehen (Spec §3.9).
 */
export default {
	name: 'MembersList',
	components: { NcButton, NcActions, NcActionButton, NcEmptyContent, NcIconSvgWrapper, MemberDialog, MemberImportDialog, MemberCard },
	props: {
		isMobile: { type: Boolean, default: false },
		defaultFeeAmount: { type: [Number, String], default: '' },
	},

	emits: ['manage-assignments'],

	setup() {
		const mandates = useMandates()
		const assignments = useAssignments()
		const members = useMembers()
		const claimOverview = useClaimOverview()
		const akteRequest = useMemberAkteRequest()
		return {
			...toRefs(mandates.state),
			...toRefs(assignments.state),
			...toRefs(members.state),
			claimOverview: claimOverview.state,
			loadClaimOverview: claimOverview.load,
			loadMandates: mandates.loadMandates,
			loadAssignments: assignments.loadAssignments,
			loadMembers: members.loadMembers,
			akteRequest: akteRequest.request,
			takeMemberAkteRequest: akteRequest.takeMemberAkteRequest,
		}
	},

	data() {
		return {
			saving: false,
			search: '',
			onlyProblems: false,
			memberDialogOpen: false,
			editingMember: null,
			/** Abschnitt, zu dem die Akte beim Öffnen scrollt ('mandate' oder leer). */
			akteSection: '',
			importDialogOpen: false,
			mdiAccountEdit,
			mdiFileSign,
			mdiPencil,
		}
	},

	computed: {
		/** Ein Mitglied ist die Zeile; sein Mandat/Beitrag (falls vorhanden) hängt sich daran. */
		rows() {
			const sources = {
				mandates: this.mandates,
				assignments: this.assignments,
				nextDueDates: nextDueDates(this.claimOverview.claims),
			}
			return this.members
				.map((member) => buildMemberRow(member, sources))
				.sort((a, b) => a.displayName.localeCompare(b.displayName, 'de'))
		},

		filteredRows() {
			const suche = this.search.trim().toLowerCase()
			return this.rows.filter((r) => {
				if (this.onlyProblems && !this.hasProblem(r)) { return false }
				if (!suche) { return true }
				return [r.displayName, r.email, r.member.memberNumber, r.mandate?.iban, r.mandate?.mandateReference]
					.filter(Boolean)
					.some((v) => String(v).toLowerCase().includes(suche))
			})
		},

		/** Beitragsaufkommen aufs Jahr hochgerechnet – nur aktive Beiträge (je Zeile in buildMemberRow() gesummt). */
		jahresSumme() {
			return this.rows.reduce((summe, r) => summe + r.yearlyAmount, 0)
		},
	},

	watch: {
		// Sprung aus dem Aufgaben-Flyout (App.vue::onTaskNavigate): die Anfrage
		// kann vor dem ersten Rendern dieser Liste da sein, daher immediate.
		'akteRequest.memberId': {
			immediate: true,
			handler(memberId) { if (memberId) { this.openRequestedAkte() } },
		},
	},

	mounted() {
		this.loadMembers()
		this.loadMandates()
		this.loadAssignments()
		this.loadClaimOverview()
	},

	methods: {
		errMsg,
		formatMoney,
		/** Von der Kopfzeile in ContributionsTab.vue per $refs aufgerufen. */
		openMemberDialog() { this.editingMember = null; this.akteSection = ''; this.memberDialogOpen = true },
		openImportDialog() { this.importDialogOpen = true },
		openMemberAkte(member, section = '') { this.editingMember = member; this.akteSection = section; this.memberDialogOpen = true },
		/**
		 * Öffnet die Akte, um die das Aufgaben-Flyout gebeten hat. Fehlt das
		 * Mitglied in der geladenen Liste (jemand hat es eben erst angelegt,
		 * oder die Liste lädt noch), wird einmal frisch geladen - findet es sich
		 * auch dann nicht, ist es weg, und das wird gesagt statt still nichts zu tun.
		 */
		async openRequestedAkte() {
			const memberId = this.takeMemberAkteRequest()
			if (!memberId) { return }
			let member = this.members.find((m) => m.id === memberId)
			if (!member) {
				await this.loadMembers()
				member = this.members.find((m) => m.id === memberId)
			}
			if (member) { this.openMemberAkte(member) } else { showError(this.t('Das Mitglied wurde nicht gefunden – vielleicht wurde es inzwischen gelöscht.')) }
		},

		/** Was der Verwalter sehen sollte: fehlende Adresse, Lastschrift ohne Mandat. */
		hasProblem(row) {
			if (row.fee && row.fee.active && row.fee.needsMandate && !row.mandate) { return true }
			return !row.email
		},

		async reload() {
			await Promise.all([
				this.loadMembers(),
				this.loadMandates(),
				this.loadAssignments(),
				this.loadClaimOverview(),
			])
			// Die offene Akte zeigt sonst weiter den Stand von vor dem Neuladen -
			// nach @changed (verknuepft/geloest/Austritt) muss sie den frischen
			// Datensatz bekommen, sonst wirkt z.B. "Verknuepfen" folgenlos.
			if (this.editingMember) {
				this.editingMember = this.members.find((m) => m.id === this.editingMember.id) ?? null
			}
		},

		/**
		 * Stammdaten anlegen/ändern, dazu beim Anlegen optional ein SEPA-Mandat
		 * (papier oder elektronisch, Issue #66/#67) und eine Zuweisung zu einer
		 * Beitragsgruppe (Issue #68) in einem Zug – der dreistufige
		 * Aufnahme-Assistent aus Spec §3.1 (MemberDialog.vue: Stammdaten → Mandat
		 * → Beitrag, Schritt 2/3 überspringbar). Schlägt eine spätere Stufe fehl,
		 * bleibt stehen, was schon entstanden ist – die Meldung sagt ausdrücklich,
		 * was das war, statt nur „fehlgeschlagen".
		 */
		async saveMember(payload) {
			this.saving = true
			let stage = 'member'
			try {
				if (this.editingMember) {
					await api.updateMember(this.editingMember.id, payload.stammdaten)
				} else {
					const { data: member } = await api.createMember(payload.stammdaten)
					if (payload.mandate) {
						stage = 'mandate'
						// Schritt 2 des Assistenten (Spec §3.1): Papier entscheidet per Datum
						// aktiv/Entwurf, elektronisch verschickt den Einmal-Link (lib/mandateCreate.js).
						await createMandateForMember(member.id, payload.mandate)
					}
					if (payload.assignment) {
						stage = 'assignment'
						await api.createAssignment({ memberId: member.id, ...payload.assignment })
					}
				}
				this.memberDialogOpen = false
				await this.reload()
				showSuccess(this.t(this.editingMember ? 'Mitglied gespeichert.' : 'Mitglied aufgenommen.'))
			} catch (e) {
				await this.reload()
				showError(this.errMsg(e, {
					member: this.t('Mitglied konnte nicht gespeichert werden'),
					mandate: this.t('Das Mitglied wurde angelegt, das Mandat nicht'),
					assignment: this.t('Mitglied und Mandat wurden angelegt, der Beitrag nicht'),
				}[stage]))
			} finally { this.saving = false }
		},
	},
}
</script>
