<template>
	<div>
		<div class="vbh-memberfilter">
			<label class="vbh-memberfilter-search">{{ t('Suchen') }}
				<input v-model="search" type="search" :placeholder="t('Name, IBAN, Mitgliedsnummer oder E-Mail')">
			</label>
			<NcCheckboxRadioSwitch v-model="onlyProblems">
				{{ t('nur Auffälligkeiten') }}
			</NcCheckboxRadioSwitch>
		</div>

		<p v-if="rows.length" class="vbh-hint vbh-membersummary">
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
				@manageAssignments="$emit('manage-assignments', row.member.id)"
				@openMember="(section) => openMemberAkte(row.member, section)" />
		</div>
		<div v-else-if="filteredRows.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Mitglied') }}</th>
						<th class="vbh-mlist-col-bank">
							{{ t('Bankverbindung') }}
						</th>
						<th class="num">
							{{ t('Betrag') }}
						</th>
						<th class="vbh-mlist-col-freq">
							{{ t('Frequenz') }}
						</th>
						<th class="vbh-mlist-col-due">
							{{ t('Nächste Fälligkeit') }}
						</th>
						<th class="vbh-mlist-col-state">
							{{ t('Zuweisung') }}
						</th>
						<th class="vbh-col-rowactions" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in filteredRows" :key="row.key">
						<td>
							<div class="vbh-namecell">
								<!-- Der Name öffnet die Akte: der eine Weg dorthin. Das Zeilenmenü führt nur zum Mandat. -->
								<button
									type="button"
									class="vbh-linkbtn"
									:aria-label="`${t('Akte öffnen')}: ${row.displayName}`"
									@click="openMemberAkte(row.member)">
									{{ row.displayName }}
								</button>
								<span v-if="row.member.memberNumber" class="vbh-hint">#{{ row.member.memberNumber }}</span>
								<span v-if="!row.member.active" class="vbh-pill vbh-pill--muted">{{ t('ausgetreten') }}</span>
								<span v-if="!row.email" class="vbh-pill vbh-pill--quiet" :title="t('keine E-Mail – keine Vorankündigung möglich')">
									<NcIconSvgWrapper :path="mdiEmailOffOutline" :size="14" inline />
									{{ t('keine E-Mail') }}
								</span>
							</div>
						</td>
						<td>
							<template v-if="row.mandate">
								<!-- Die IBAN steht ungekürzt: eine mit … abgeschnittene Nummer ist keine. Die Marke bricht darunter um. -->
								<span class="vbh-iban">{{ row.mandate.iban }}</span>
								<span v-if="row.mandate.statusTag" class="vbh-pill vbh-pill--warning">{{ row.mandate.statusTag }}</span>
							</template>
							<span v-else-if="row.fee && row.fee.free" class="vbh-hint">–</span>
							<span v-else-if="row.fee && !row.fee.needsMandate" class="vbh-hint">{{ t('Überweisung') }}</span>
							<span v-else class="vbh-hint">{{ t('kein Mandat') }}</span>
						</td>
						<td class="num nowrap">
							{{ row.fee ? (row.fee.free ? t('beitragsfrei') : formatMoney(row.fee.amount)) : '–' }}
						</td>
						<td>
							{{ row.fee ? row.fee.frequencyLabel : '–' }}
							<span v-if="row.moreFees > 0" class="vbh-hint">
								{{ n('+ %n weitere Zuweisung', '+ %n weitere Zuweisungen', row.moreFees) }}
							</span>
						</td>
						<td class="nowrap">
							{{ row.nextDueDate ? formatDate(row.nextDueDate) : '–' }}
						</td>
						<td>
							<span v-if="row.fee" class="vbh-status" :class="`vbh-status--${row.fee.statusTone}`">{{ row.fee.statusLabel }}</span>
							<span v-else>–</span>
						</td>
						<td class="nowrap right">
							<!-- Alles Weitere zu einem Mitglied steht im Menü (⋯), mit Namen: Mitglied, Mandat, Beitrag.
								Zuweisungen werden nicht inline bearbeitet, ihre Stelle sind die Beitragsgruppen. -->
							<MemberRowMenu
								:row="row"
								@openMember="(section) => openMemberAkte(row.member, section)"
								@manageAssignments="$emit('manage-assignments', row.member.id)" />
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
import { mdiEmailOffOutline } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcCheckboxRadioSwitch, NcEmptyContent, NcIconSvgWrapper } from '@nextcloud/vue'
import { toRefs } from 'vue'
import MemberCard from './MemberCard.vue'
import MemberDialog from './MemberDialog.vue'
import MemberImportDialog from './MemberImportDialog.vue'
import MemberRowMenu from './MemberRowMenu.vue'
import api from '../api.js'
import { useAssignments } from '../composables/useAssignments.js'
import { useClaimOverview } from '../composables/useClaimOverview.js'
import { useMandates } from '../composables/useMandates.js'
import { useMemberAkteRequest } from '../composables/useMemberAkteRequest.js'
import { useMembers } from '../composables/useMembers.js'
import { errMsg, formatDate, formatMoney } from '../lib/format.js'
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
 * bearbeitet – sie führen zu den Beitragsgruppen (`manage-assignments`). Der
 * Name öffnet die Akte (der eine Weg dorthin), das Zeilenmenü springt in der
 * Akte zum Mandat.
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
	components: { NcCheckboxRadioSwitch, NcEmptyContent, NcIconSvgWrapper, MemberDialog, MemberImportDialog, MemberCard, MemberRowMenu },
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
			mdiEmailOffOutline,
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
		formatDate,
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

		/** Was der Verwalter sehen sollte: fehlende Adresse, Lastschrift ohne einzugsfähiges Mandat (auch ein Entwurf oder ausgesetztes Mandat zieht nicht ein). */
		hasProblem(row) {
			if (row.fee && row.fee.active && row.fee.needsMandate && (!row.mandate || row.mandate.statusTag)) { return true }
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
				showSuccess(this.editingMember ? this.t('Mitglied gespeichert.') : this.t('Mitglied aufgenommen.'))
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

<style scoped>
/*
 * Suche und Auffälligkeiten in einer Zeile; die Checkbox sitzt auf der Höhe des
 * Suchfelds. Bewusst nicht in einem .vbh-form: dessen `label`-Regel würde auch
 * das Etikett der NcCheckboxRadioSwitch stapeln und verkleinern.
 */
.vbh-memberfilter {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline, 4px) * 2) calc(var(--default-grid-baseline, 4px) * 4);
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
}

.vbh-memberfilter-search {
	display: flex;
	flex: 1 1 220px;
	flex-direction: column;
	gap: 3px;
	min-width: 0;
	font-size: 0.85em;
}

.vbh-memberfilter-search input {
	width: 100%;
}

/*
 * Spaltenbreiten (bei table-layout: fixed zählt nur die Kopfzeile): die IBAN-Spalte
 * ist so breit, dass die Nummer ungekürzt passt; der Name bekommt den Rest.
 */
.vbh-table thead th.vbh-mlist-col-bank {
	width: 230px;
}

.vbh-table thead th.vbh-mlist-col-freq {
	width: 120px;
}

.vbh-table thead th.vbh-mlist-col-due {
	width: 130px;
}

.vbh-table thead th.vbh-mlist-col-state {
	width: 150px;
}

.vbh-iban {
	margin-inline-end: calc(var(--default-grid-baseline, 4px) * 1);
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

/* Die Summenzeile ist eine Fußnote zur Tabelle, kein Absatz. */
.vbh-membersummary {
	margin: calc(var(--default-grid-baseline, 4px) * 2) 0 calc(var(--default-grid-baseline, 4px) * 1);
	font-size: 0.9em;
}
</style>
