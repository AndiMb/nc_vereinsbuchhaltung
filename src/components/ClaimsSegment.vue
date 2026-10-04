<template>
	<div class="vbh-claims">
		<div class="vbh-claims-head">
			<h4>{{ t('Forderungen') }}</h4>
			<div class="vbh-claims-views" role="group" :aria-label="t('Darstellung der Forderungen')">
				<NcButton
					size="small"
					:variant="view === 'claims' ? 'primary' : 'secondary'"
					:aria-pressed="view === 'claims' ? 'true' : 'false'"
					@click="view = 'claims'">
					{{ t('Je Forderung') }}
				</NcButton>
				<NcButton
					size="small"
					:variant="view === 'members' ? 'primary' : 'secondary'"
					:aria-pressed="view === 'members' ? 'true' : 'false'"
					@click="view = 'members'">
					{{ t('Je Mitglied') }}
				</NcButton>
			</div>
			<span class="vbh-claims-spacer" />
			<NcButton
				size="small"
				variant="tertiary"
				:aria-label="t('Forderungen aktualisieren')"
				:title="t('Forderungen aktualisieren')"
				:disabled="loading"
				@click="load">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="18" />
					<NcIconSvgWrapper v-else :path="mdiRefresh" :size="18" />
				</template>
			</NcButton>
			<NcButton
				v-if="canWrite"
				size="small"
				variant="primary"
				@click="claimDialogOpen = true">
				{{ t('+ Einzelforderung') }}
			</NcButton>
		</div>
		<p class="vbh-hint">
			{{ t('Forderungen an Mitglieder mit Zustand, Störfällen und Mahnstand. Vorgabe der Liste sind die nicht beglichenen (offen, im Einzug, zurückgegeben). Die allgemeinen offenen Posten (Rechnungen ohne Mitglied) bleiben unter Buchungen.') }}
		</p>

		<NcLoadingIcon v-if="!loaded && loading" :size="32" :name="t('Wird geladen…')" />

		<div v-else-if="!loaded" class="vbh-hint vbh-hint--warning">
			{{ error || t('Die Forderungen konnten nicht geladen werden.') }}
			<NcButton size="small" @click="load">
				{{ t('Erneut versuchen') }}
			</NcButton>
		</div>

		<template v-else>
			<!-- Die Serverantwort steht nicht in einer t()-Variable (die würde sie HTML-escapen). -->
			<p v-if="error" class="vbh-hint vbh-hint--warning" role="status">
				{{ t('Die Ansicht konnte nicht aktualisiert werden:') }} {{ error }}
			</p>

			<div class="vbh-claims-filters vbh-form" role="group" :aria-label="t('Filter')">
				<label>{{ t('Zustand') }}
					<!-- aria-label: sonst bestünde der Name des Auswahlfelds aus Label UND gewähltem Eintrag. -->
					<select v-model="filters.state" :aria-label="t('Zustand')">
						<option v-for="option in stateOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
					</select>
				</label>
				<label>{{ t('Störfall') }}
					<select v-model="filters.issue" :aria-label="t('Störfall')">
						<option v-for="option in issueOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
					</select>
				</label>
				<label v-if="filters.memberId === null" class="vbh-grow">{{ t('Mitglied') }}
					<input v-model="filters.member" type="search" :placeholder="t('Name enthält …')">
				</label>
				<div v-else class="vbh-claims-memberchip">
					<span>{{ t('Mitglied:') }} <strong>{{ filteredMemberName }}</strong></span>
					<NcButton size="small" variant="tertiary" @click="filters.memberId = null">
						{{ t('Alle Mitglieder') }}
					</NcButton>
				</div>
				<label class="vbh-claims-date">{{ t('Fällig von') }}
					<input v-model="filters.from" type="date">
				</label>
				<label class="vbh-claims-date">{{ t('Fällig bis') }}
					<input v-model="filters.to" type="date">
				</label>
				<NcButton
					v-if="changed"
					size="small"
					variant="tertiary"
					@click="resetFilters">
					{{ t('Filter zurücksetzen') }}
				</NcButton>
			</div>

			<p class="vbh-hint vbh-claims-summary" role="status">
				{{ summaryText }}
			</p>

			<p v-if="filtered.length === 0" class="vbh-hint">
				{{ claims.length === 0 ? t('Es gibt noch keine Forderung an ein Mitglied.') : t('Keine Forderung passt zu den gewählten Filtern.') }}
			</p>

			<!-- ============ JE FORDERUNG ============ -->
			<template v-else-if="view === 'claims'">
				<div v-if="isMobile" class="vbh-cardlist">
					<div v-for="claim in shown" :key="claim.id" class="vbh-mcard">
						<div class="vbh-mcard-top">
							<span class="vbh-mcard-title">{{ claim.memberDisplayName }}</span>
							<span class="vbh-mcard-amount">{{ formatMoney(claim.amount) }}</span>
						</div>
						<div class="vbh-mcard-bottom">
							<span class="vbh-mcard-accounts">{{ claim.description }} <span class="vbh-typetag">{{ claimTypeLabel(claim.type) }}</span></span>
						</div>
						<div class="vbh-mcard-bottom">
							<span class="vbh-mcard-accounts">{{ t('Fällig am {datum}', { datum: formatDate(claim.dueDate) }) }}</span>
							<span v-if="isOverdue(claim)" class="vbh-typetag">{{ t('überfällig') }}</span>
						</div>
						<div class="vbh-mcard-bottom vbh-claims-tags">
							<DebitStatusTag kind="claim" :value="claim.state" :settlementType="claim.settlementType" />
							<span v-if="claim.deferred" class="vbh-typetag">{{ t('gestundet bis {datum}', { datum: formatDate(claim.deferredUntil) }) }}</span>
							<DebitStatusTag v-if="severityOf(claim)" kind="severity" :value="severityOf(claim)" />
						</div>
						<div v-if="dunningOf(claim).label || nextTextOf(claim)" class="vbh-mcard-bottom">
							<span class="vbh-mcard-accounts">
								<strong v-if="dunningOf(claim).label">{{ dunningOf(claim).label }}</strong>
								<template v-if="dunningOf(claim).detail"> · {{ dunningOf(claim).detail }}</template>
								<template v-if="nextTextOf(claim)"><br>{{ t('Nächste Stufe:') }} {{ nextTextOf(claim) }}</template>
							</span>
						</div>
						<div class="vbh-mcard-actions">
							<NcButton
								variant="tertiary"
								size="small"
								:aria-expanded="expandedId === claim.id ? 'true' : 'false'"
								:aria-controls="`vbh-claim-detail-${claim.id}`"
								@click="toggle(claim.id)">
								{{ expandedId === claim.id ? t('Details ausblenden') : t('Details anzeigen') }}
							</NcButton>
						</div>
						<div v-if="expandedId === claim.id" :id="`vbh-claim-detail-${claim.id}`" class="vbh-mcard-subcards">
							<ClaimDetail
								:claim="claim"
								:today="today"
								:dunningIntervalDays="dunningIntervalDays"
								:canWrite="canWrite"
								@changed="load"
								@openMember="openMember" />
						</div>
					</div>
				</div>

				<div v-else class="vbh-tablecard">
					<table class="vbh-table vbh-claims-table">
						<thead>
							<tr>
								<th>{{ t('Mitglied') }}</th>
								<th>{{ t('Bezeichnung') }}</th>
								<th class="nowrap">
									{{ t('Fällig') }}
								</th>
								<th class="num">
									{{ t('Betrag') }}
								</th>
								<th>{{ t('Zustand') }}</th>
								<th class="vbh-claims-col-dunning">
									{{ t('Mahnstand') }}
								</th>
								<th class="vbh-claims-col-actions" />
							</tr>
						</thead>
						<tbody>
							<template v-for="claim in shown" :key="claim.id">
								<tr :class="{ 'vbh-claims-open': expandedId === claim.id }">
									<td>{{ claim.memberDisplayName }}</td>
									<td>
										{{ claim.description }}
										<span class="vbh-typetag">{{ claimTypeLabel(claim.type) }}</span>
									</td>
									<td class="nowrap">
										{{ formatDate(claim.dueDate) }}
										<div v-if="isOverdue(claim)">
											<span class="vbh-typetag">{{ t('überfällig') }}</span>
										</div>
									</td>
									<td class="num nowrap">
										{{ formatMoney(claim.amount) }}
									</td>
									<td>
										<div class="vbh-claims-tags">
											<DebitStatusTag kind="claim" :value="claim.state" :settlementType="claim.settlementType" />
											<span v-if="claim.deferred" class="vbh-typetag">{{ t('gestundet bis {datum}', { datum: formatDate(claim.deferredUntil) }) }}</span>
											<DebitStatusTag v-if="severityOf(claim)" kind="severity" :value="severityOf(claim)" />
										</div>
									</td>
									<td>
										<strong v-if="dunningOf(claim).label">{{ dunningOf(claim).label }}</strong>
										<span v-else class="vbh-hint">–</span>
										<div v-if="dunningOf(claim).detail" class="vbh-hint">
											{{ dunningOf(claim).detail }}
										</div>
										<div v-if="nextTextOf(claim)" class="vbh-hint">
											{{ t('Nächste Stufe:') }} {{ nextTextOf(claim) }}
										</div>
									</td>
									<td class="nowrap right">
										<NcButton
											variant="tertiary"
											size="small"
											:aria-expanded="expandedId === claim.id ? 'true' : 'false'"
											:aria-controls="`vbh-claim-detail-${claim.id}`"
											:aria-label="detailsLabel(claim)"
											@click="toggle(claim.id)">
											{{ expandedId === claim.id ? t('Ausblenden') : t('Details') }}
										</NcButton>
									</td>
								</tr>
								<tr v-if="expandedId === claim.id">
									<td :id="`vbh-claim-detail-${claim.id}`" colspan="7" class="vbh-claims-detailcell">
										<ClaimDetail
											:claim="claim"
											:today="today"
											:dunningIntervalDays="dunningIntervalDays"
											:canWrite="canWrite"
											@changed="load"
											@openMember="openMember" />
									</td>
								</tr>
							</template>
						</tbody>
					</table>
				</div>

				<NcButton
					v-if="filtered.length > limit"
					variant="tertiary"
					size="small"
					@click="limit += PAGE">
					{{ t('Weitere anzeigen ({n} von {gesamt})', { n: shown.length, gesamt: filtered.length }) }}
				</NcButton>
			</template>

			<!-- ============ JE MITGLIED ============ -->
			<template v-else>
				<p class="vbh-hint">
					{{ t('Die Mahnstufen werden je Mitglied gebündelt versandt. Der Mahnstand zählt nur die noch nicht erledigten Forderungen der Auswahl.') }}
				</p>
				<div v-if="isMobile" class="vbh-cardlist">
					<div v-for="group in memberRows" :key="group.memberId" class="vbh-mcard">
						<div class="vbh-mcard-top">
							<span class="vbh-mcard-title">{{ group.name }}</span>
							<span class="vbh-mcard-amount">{{ formatMoney(group.sumCents / 100) }}</span>
						</div>
						<div class="vbh-mcard-bottom">
							<span class="vbh-mcard-accounts">{{ claimCountText(group.count) }}<template v-if="group.deferredCount"> · {{ n('%n gestundet', '%n gestundet', group.deferredCount) }}</template></span>
							<DebitStatusTag v-if="group.severity" kind="severity" :value="group.severity" />
						</div>
						<div class="vbh-mcard-bottom">
							<span class="vbh-mcard-accounts">
								<strong>{{ memberStageText(group) || t('keine Mahnung') }}</strong>
								<template v-if="group.lastSentAt"> · {{ t('zuletzt am {datum}', { datum: formatDate(group.lastSentAt) }) }}</template>
								<template v-if="memberNextText(group)"><br>{{ t('Nächste Stufe:') }} {{ memberNextText(group) }}</template>
							</span>
						</div>
						<div class="vbh-mcard-actions">
							<NcButton variant="tertiary" size="small" @click="showMemberClaims(group)">
								{{ t('Forderungen anzeigen') }}
							</NcButton>
							<NcButton
								v-if="canWrite"
								variant="tertiary"
								size="small"
								@click="openMember(group.memberId)">
								{{ t('Akte öffnen') }}
							</NcButton>
						</div>
					</div>
				</div>

				<div v-else class="vbh-tablecard">
					<table class="vbh-table vbh-claims-table">
						<thead>
							<tr>
								<th>{{ t('Mitglied') }}</th>
								<th>{{ t('Forderungen') }}</th>
								<th class="num">
									{{ t('Summe') }}
								</th>
								<th class="vbh-claims-col-dunning">
									{{ t('Mahnstand') }}
								</th>
								<th>{{ t('Störfall') }}</th>
								<th class="vbh-claims-col-memberactions" />
							</tr>
						</thead>
						<tbody>
							<tr v-for="group in memberRows" :key="group.memberId">
								<td>{{ group.name }}</td>
								<td>
									{{ claimCountText(group.count) }}
									<div v-if="group.deferredCount" class="vbh-hint">
										{{ n('davon %n gestundet', 'davon %n gestundet', group.deferredCount) }}
									</div>
								</td>
								<td class="num nowrap">
									{{ formatMoney(group.sumCents / 100) }}
								</td>
								<td>
									<strong v-if="memberStageText(group)">{{ memberStageText(group) }}</strong>
									<span v-else class="vbh-hint">–</span>
									<div v-if="group.lastSentAt" class="vbh-hint">
										{{ t('zuletzt am {datum}', { datum: formatDate(group.lastSentAt) }) }}
									</div>
									<div v-if="memberNextText(group)" class="vbh-hint">
										{{ t('Nächste Stufe:') }} {{ memberNextText(group) }}
									</div>
								</td>
								<td>
									<DebitStatusTag v-if="group.severity" kind="severity" :value="group.severity" />
									<span v-else class="vbh-hint">–</span>
								</td>
								<td class="nowrap right">
									<div class="vbh-actions">
										<NcButton size="small" variant="tertiary" @click="showMemberClaims(group)">
											{{ t('Forderungen anzeigen') }}
										</NcButton>
										<NcButton
											v-if="canWrite"
											size="small"
											variant="tertiary"
											@click="openMember(group.memberId)">
											{{ t('Akte öffnen') }}
										</NcButton>
									</div>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</template>
		</template>

		<!-- Die vorhandene Erfassung aus den Beitragsgruppen (Issue #68), nicht ein zweites Formular. -->
		<ManualClaimDialog
			v-if="canWrite"
			:show="claimDialogOpen"
			@close="claimDialogOpen = false"
			@update:show="claimDialogOpen = $event"
			@save="saveClaim" />
	</div>
</template>

<script>
import { mdiRefresh } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcIconSvgWrapper, NcLoadingIcon } from '@nextcloud/vue'
import { toRefs } from 'vue'
import ClaimDetail from './ClaimDetail.vue'
import DebitStatusTag from './DebitStatusTag.vue'
import ManualClaimDialog from './ManualClaimDialog.vue'
import api from '../api.js'
import { useClaimOverview } from '../composables/useClaimOverview.js'
import { useEinzugRequest } from '../composables/useEinzugRequest.js'
import { useMemberAkteRequest } from '../composables/useMemberAkteRequest.js'
import {
	claimCountText,
	claimTypeLabel,
	dunningCompact,
	dunningNextText,
	dunningStageLabel,
	emptyFilters,
	FILTER_ALL,
	filterClaims,
	filtersChanged,
	groupByMember,
	issueFilterOptions,
	stateFilterOptions,
	worstSeverity,
} from '../lib/claims.js'
import { errMsg, formatDate, formatMoney } from '../lib/format.js'

/** So viele Zeilen zeigt die Liste zunächst; der Rest kommt auf Knopfdruck (ein erledigtes Jahr hat Tausende). */
const PAGE = 100

/**
 * Segment „Forderungen“ im Einzug-Unterreiter (Issue #104, Spec §3.6/§6 „Aufgaben
 * & Offene Posten: im Einzug, Segment neben dem Zeitstrahl“): alle Forderungen
 * mit Mitglied, mit abgeleitetem Zustand (offen, im Einzug, eingezogen,
 * zurückgegeben, erledigt, storniert), Störfällen in zwei Schweregraden und dem
 * Mahnstand – je Forderung und je Mitglied (die Mahnstufen werden gebündelt
 * versandt). Filter: Zustand, Störfall, Mitglied, Fälligkeits-Zeitraum.
 *
 * Lesend ab `revisor`; Schreibaktionen (bezahlt, Erlass, Stundung, Storno,
 * Einzelforderung) erst ab `buchhalter` – `canWrite` blendet sie aus, das Backend
 * prüft sie noch einmal. Die generische Offene-Posten-Sicht unter Buchungen
 * zeigt weiter die freien Posten ohne Mitglied.
 *
 * Die Daten kommen aus GET /claims/overview (ClaimOverviewService). Geladen wird
 * beim Sichtbarwerden und bei jeder Rückkehr ins Fenster; nach einer Aktion lädt
 * ClaimDetail über `changed` neu.
 */
export default {
	name: 'ClaimsSegment',
	components: { ClaimDetail, DebitStatusTag, ManualClaimDialog, NcButton, NcIconSvgWrapper, NcLoadingIcon },
	props: {
		canWrite: { type: Boolean, default: false },
		isMobile: { type: Boolean, default: false },
		// Ob das Segment gerade angezeigt wird – geladen wird erst dann (und bei jeder Rückkehr frisch).
		active: { type: Boolean, default: false },
	},

	setup() {
		const overview = useClaimOverview()
		const akte = useMemberAkteRequest()
		const einzugRequest = useEinzugRequest()
		return {
			einzugRequest: einzugRequest.request,
			takeMemberFocus: einzugRequest.takeMemberFocus,
			...toRefs(overview.state),
			load: overview.load,
			requestMemberAkte: akte.requestMemberAkte,
		}
	},

	data() {
		return {
			mdiRefresh,
			PAGE,
			view: 'claims',
			filters: emptyFilters(),
			expandedId: null,
			limit: PAGE,
			claimDialogOpen: false,
		}
	},

	computed: {
		stateOptions: stateFilterOptions,
		issueOptions: issueFilterOptions,

		filtered() { return filterClaims(this.claims, this.filters) },

		shown() { return this.filtered.slice(0, this.limit) },

		changed() { return filtersChanged(this.filters) },

		memberRows() { return groupByMember(this.filtered) },

		// Der Name des Mitglieds, auf das die Liste eingegrenzt ist (aus der Auswahl, nicht aus den Filtern).
		filteredMemberName() {
			return this.claims.find((c) => c.memberId === this.filters.memberId)?.memberDisplayName ?? ''
		},

		summaryText() {
			const sum = this.filtered.reduce((total, c) => total + c.amountCents, 0)
			return this.t('{angezeigt} von {gesamt} Forderungen, zusammen {summe}', {
				angezeigt: this.filtered.length,
				gesamt: this.claims.length,
				summe: formatMoney(sum / 100),
			})
		},
	},

	watch: {
		active: {
			immediate: true,
			handler(value) { if (value) { this.load() } },
		},

		// Ein Sprung aus Buchungen → Offene Posten (Issue #121) grenzt die Liste auf das Mitglied der
		// Forderung ein, und zwar auf alle Zustände: auch eine erledigte oder stornierte Forderung soll
		// dort zu finden sein. Gilt einmal und wird dabei abgeräumt.
		'einzugRequest.memberId': {
			immediate: true,
			handler(memberId) {
				if (memberId === null) { return }
				this.takeMemberFocus()
				this.filters = { ...emptyFilters(), state: FILTER_ALL, memberId }
				this.view = 'claims'
			},
		},

		// Fällt die aufgeklappte Forderung aus der Auswahl (erledigt, storniert, anderer Filter), klappt ihr Detail
		// zu – sonst stünde es aufgeklappt wieder da, sobald sie erneut in der Auswahl erscheint.
		filtered() {
			if (this.expandedId !== null && !this.filtered.some((claim) => claim.id === this.expandedId)) { this.expandedId = null }
		},

		// Ein anderer Filter beginnt wieder mit der Kurzliste und klappt nichts Fremdes auf.
		filters: {
			deep: true,
			handler() { this.limit = PAGE },
		},
	},

	mounted() {
		window.addEventListener('focus', this.onWindowFocus)
	},

	beforeUnmount() {
		window.removeEventListener('focus', this.onWindowFocus)
	},

	methods: {
		formatDate,
		formatMoney,
		claimTypeLabel,
		claimCountText,

		// Wer aus einem anderen Fenster zurückkommt, soll keinen veralteten Stand sehen (Vermerk, Stundung oder Rücklastschrift kann eine andere Person gemacht haben).
		onWindowFocus() {
			if (this.active && !document.hidden) { this.load() }
		},

		resetFilters() {
			this.filters = emptyFilters()
		},

		toggle(id) {
			this.expandedId = this.expandedId === id ? null : id
		},

		severityOf(claim) { return worstSeverity(claim.issues) },

		dunningOf(claim) { return dunningCompact(claim.dunning) },

		nextTextOf(claim) { return dunningNextText(claim.dunning) },

		/** Offen oder zurückgegeben und die Fälligkeit liegt vor dem Stichtag; eine Stundung hält das an. */
		isOverdue(claim) {
			return !claim.deferred
				&& (claim.state === 'offen' || claim.state === 'zurueckgegeben')
				&& !!claim.dueDate && !!this.today && claim.dueDate < this.today
		},

		/** Zugänglicher Name des Detail-Knopfs. Der Name des Mitglieds steht nicht in einer t()-Variable (HTML-Escaping). */
		detailsLabel(claim) {
			return `${this.t('Details zur Forderung')}: ${claim.memberDisplayName}, ${claim.description}`
		},

		/** „Zahlungserinnerung“ o. ä. – die höchste erreichte Stufe, oder leer. */
		memberStageText(group) {
			if (group.escalated) { return dunningStageLabel(3) }
			return group.stage === null ? '' : dunningStageLabel(group.stage)
		},

		memberNextText(group) {
			return group.nextStage === null ? '' : this.t('{stufe} ab {datum}', { stufe: dunningStageLabel(group.nextStage), datum: formatDate(group.nextDueOn) })
		},

		/** Aus der Mitglieds-Sicht in die Liste: nur noch die Forderungen dieses Mitglieds. */
		showMemberClaims(group) {
			this.filters.memberId = group.memberId
			this.filters.member = ''
			this.view = 'claims'
		},

		/** Sprung in die Mitglieder-Akte über denselben Mechanismus wie das Aufgaben-Flyout (MembersList öffnet sie als Dialog). */
		openMember(memberId) {
			this.requestMemberAkte(memberId)
		},

		async saveClaim(form) {
			try {
				await api.createClaim(form)
				this.claimDialogOpen = false
				showSuccess(this.t('Einzelforderung angelegt.'))
				await this.load()
			} catch (e) { showError(errMsg(e, this.t('Einzelforderung konnte nicht angelegt werden'))) }
		},
	},
}
</script>

<style scoped>
.vbh-claims-head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 12px;
}

.vbh-claims-head h4 {
	margin: 0;
}

.vbh-claims-views {
	display: inline-flex;
	gap: 4px;
}

.vbh-claims-spacer {
	flex: 1 1 auto;
}

.vbh-claims-filters {
	margin-top: 4px;
}

/* Ein Auswahlfeld ist so breit wie sein längster Eintrag: auf einem schmalen Bildschirm ragte es samt Label über
   den Rand und schob den ganzen Abschnitt seitlich (gemessen im echten App-Rahmen bei 375 px). Deckel auf die
   Breite des Rahmens, damit es auch mit einer breiteren Schrift nicht passiert. */
.vbh-claims-filters label {
	min-width: 0;
	max-width: 100%;
}

.vbh-claims-filters select,
.vbh-claims-filters input {
	min-width: 0;
	max-width: 100%;
}

.vbh-claims-memberchip {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 8px;
	min-height: var(--default-clickable-area, 44px);
}

/* Handy (derselbe Umbruch wie isMobile in App.vue): jedes Feld eine volle Zeile, nur die beiden Daten teilen sich eine. */
@media (max-width: 640px) {
	.vbh-claims-filters label {
		flex: 1 1 100%;
	}

	.vbh-claims-filters label.vbh-claims-date {
		flex: 1 1 calc(50% - 10px);
	}

	.vbh-claims-filters select,
	.vbh-claims-filters input {
		width: 100%;
	}
}

.vbh-claims-summary {
	margin: 8px 0;
}

.vbh-claims-tags {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 6px;
}

/* Spaltenbreiten: Zahl-/Datums-/Aktionsspalten fest, Textspalten teilen sich den Rest (siehe .vbh-table in
   styles.css). Der Mahnstand nennt „Zahlungsaufforderung“ in einem Wort und braucht Platz, damit nichts mitten
   im Wort umbricht; die globale Breite der Aktionsspalten (160/230 px) ist für einen einzelnen Knopf zu groß. */
.vbh-claims-table thead th.vbh-claims-col-dunning {
	width: 22%;
	min-width: 170px;
}

.vbh-claims-table thead th.vbh-claims-col-actions {
	width: 110px;
}

.vbh-claims-table thead th.vbh-claims-col-memberactions {
	width: 230px;
}

.vbh-claims-detailcell {
	background-color: var(--color-background-hover);
}

.vbh-claims-open td {
	background-color: var(--color-background-hover);
}
</style>
