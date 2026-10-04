// Zustandslose Helfer des Segments „Forderungen" im Einzug-Unterreiter (Issue
// #104, Spec §3.6/§6): Filter, Mahnstand in Klartext, Gruppierung je Mitglied
// und die Frage, welche Aktionen eine Forderung gerade zulässt. Reine
// Funktionen ohne Vue-/DOM-Bezug, damit sie sich ohne Komponente testen lassen
// (claims.test.js) – dieselbe Arbeitsteilung wie bei debitRun.js.
//
// Die Zeilen kommen fertig vom Server (GET /claims/overview, ClaimOverviewService):
// Zustand, Mahnstand, Einzug, Rücklastschrift und Störfälle sind dort abgeleitet.
// Hier steckt nur, was die Oberfläche daraus macht. `t()` wird immer erst beim
// Aufruf ausgewertet, nie beim Import: die Übersetzungen sind beim Laden der
// Module noch nicht registriert.
import { formatStamp, relativeDays } from './debitRun.js'
import { formatDate } from './format.js'
import { n, t } from './l10n.js'

/** Es besteht Handlungsbedarf (Spec §7: zwei Schweregrade, kein Quittieren). */
export const SEVERITY_ACTION = 'handlungsbedarf'
export const SEVERITY_HINT = 'hinweis'

/** Stufe nach der Mahnung: die Aufgabe „Vorstand entscheiden lassen" (DunningStatusResolver::NEXT_ESCALATION). */
export const NEXT_ESCALATION = 3

/** Zustände, in denen das Geld noch aussteht – nur für sie zählt der Mahnstand. */
const UNSETTLED_STATES = ['offen', 'im_einzug', 'zurueckgegeben']

export function claimTypeLabel(type) {
	return type === 'gebuehr' ? t('Gebühr') : t('Beitrag')
}

/** Die Mahnstufen wie in der Spec §3.6: 0 Zahlungsaufforderung, 1 Zahlungserinnerung, 2 Mahnung, danach die Eskalation. */
export function dunningStageLabel(stage) {
	return {
		0: t('Zahlungsaufforderung'),
		1: t('Zahlungserinnerung'),
		2: t('Mahnung'),
		[NEXT_ESCALATION]: t('An Vorstand eskaliert'),
	}[stage] ?? ''
}

// --- Filter --------------------------------------------------------------------

export const FILTER_ALL = 'alle'
export const FILTER_UNSETTLED = 'nicht_beglichen'

/** Die Auswahl „Zustand" – der Vorgabewert zeigt, was noch aussteht, damit die Liste nicht mit jedem erledigten Jahr wächst. */
export function stateFilterOptions() {
	return [
		{ value: FILTER_UNSETTLED, label: t('Nicht beglichen (offen, im Einzug, zurückgegeben)') },
		{ value: FILTER_ALL, label: t('Alle Zustände') },
		{ value: 'offen', label: t('offen') },
		{ value: 'im_einzug', label: t('im Einzug') },
		{ value: 'eingezogen', label: t('eingezogen') },
		{ value: 'zurueckgegeben', label: t('zurückgegeben') },
		{ value: 'gestundet', label: t('gestundet') },
		{ value: 'erledigt', label: t('erledigt (bezahlt oder erlassen)') },
		{ value: 'storniert', label: t('storniert') },
	]
}

export function issueFilterOptions() {
	return [
		{ value: FILTER_ALL, label: t('Alle (mit und ohne Störfall)') },
		{ value: 'mit', label: t('Mit Störfall') },
		{ value: SEVERITY_ACTION, label: t('Mit Handlungsbedarf') },
		{ value: SEVERITY_HINT, label: t('Nur mit Hinweis') },
		{ value: 'ohne', label: t('Ohne Störfall') },
	]
}

export function emptyFilters() {
	return { state: FILTER_UNSETTLED, issue: FILTER_ALL, member: '', memberId: null, from: '', to: '' }
}

/** Ob ein Filter vom Vorgabewert abweicht – dann gibt es etwas zurückzusetzen. */
export function filtersChanged(filters) {
	const empty = emptyFilters()
	return Object.keys(empty).some((key) => filters[key] !== empty[key])
}

/** Schwerster Störfall einer Forderung: `handlungsbedarf`, `hinweis` oder null. */
export function worstSeverity(issues) {
	if (!issues?.length) { return null }
	return issues.some((i) => i.severity === SEVERITY_ACTION) ? SEVERITY_ACTION : SEVERITY_HINT
}

function matchesState(claim, filter) {
	if (filter === FILTER_ALL) { return true }
	if (filter === FILTER_UNSETTLED) { return UNSETTLED_STATES.includes(claim.state) }
	if (filter === 'gestundet') { return claim.deferred === true }
	return claim.state === filter
}

function matchesIssue(claim, filter) {
	if (filter === FILTER_ALL) { return true }
	const worst = worstSeverity(claim.issues)
	if (filter === 'mit') { return worst !== null }
	if (filter === 'ohne') { return worst === null }
	return worst === filter
}

/**
 * Filtert die Forderungen nach Zustand, Störfall, Mitglied und Zeitraum (der
 * Zeitraum bezieht sich auf die Fälligkeit, beide Grenzen einschließlich). Die
 * Reihenfolge des Servers (Fälligkeit aufsteigend) bleibt erhalten.
 */
export function filterClaims(claims, filters) {
	const needle = filters.member.trim().toLowerCase()
	return claims.filter((claim) => {
		if (!matchesState(claim, filters.state)) { return false }
		if (!matchesIssue(claim, filters.issue)) { return false }
		if (filters.memberId !== null && claim.memberId !== filters.memberId) { return false }
		if (needle && !String(claim.memberDisplayName).toLowerCase().includes(needle)) { return false }
		if (filters.from && !(claim.dueDate && claim.dueDate >= filters.from)) { return false }
		if (filters.to && !(claim.dueDate && claim.dueDate <= filters.to)) { return false }
		return true
	})
}

// --- Mahnstand -------------------------------------------------------------------

/**
 * Der Mahnstand einer Forderung für die Liste: erreichte Stufe in Klartext
 * (`label`), wann sie versandt wurde (`detail`) – oder `label: ''`, wenn nie
 * etwas versandt wurde. Eine eskalierte Forderung nennt die Eskalation, nicht die
 * Mahnung davor.
 */
export function dunningCompact(dunning) {
	if (dunning.escalated) {
		return { label: dunningStageLabel(NEXT_ESCALATION), detail: '', escalated: true }
	}
	if (dunning.stage === null || dunning.stage === undefined) {
		return { label: '', detail: '', escalated: false }
	}
	const sentAt = dunning.notices.find((notice) => notice.stage === dunning.stage)?.sentAt
	return {
		label: dunningStageLabel(dunning.stage),
		detail: sentAt ? t('versandt am {datum}', { datum: formatDate(sentAt) }) : '',
		escalated: false,
	}
}

/**
 * Die Mahnreihe einer Forderung als Schritte (Zahlungsaufforderung,
 * Erinnerung, Mahnung, Eskalation): je Schritt, ob er erreicht ist, wann er
 * versandt wurde und – beim nächsten – wann er fällig wird. Der Zustand steht als
 * Wort (`versandt`, `eskaliert`, `naechste`, `ausstehend`), die Farbe ist nur die
 * zweite Spur.
 */
export function dunningSteps(dunning) {
	const sentAtOf = (stage) => dunning.notices.find((notice) => notice.stage === stage)?.sentAt ?? null
	const steps = [0, 1, 2].map((stage) => {
		const sentAt = sentAtOf(stage)
		const isNext = dunning.nextStage === stage
		return {
			stage,
			label: dunningStageLabel(stage),
			sentAt,
			dueOn: isNext ? dunning.nextDueOn : null,
			status: sentAt ? 'versandt' : (isNext ? 'naechste' : 'ausstehend'),
		}
	})
	const isNext = dunning.nextStage === NEXT_ESCALATION
	steps.push({
		stage: NEXT_ESCALATION,
		label: dunningStageLabel(NEXT_ESCALATION),
		sentAt: null,
		dueOn: isNext ? dunning.nextDueOn : null,
		status: dunning.escalated ? 'eskaliert' : (isNext ? 'naechste' : 'ausstehend'),
	})
	return steps
}

/** Text zum Status eines Mahnschritts („versandt am …“, „fällig ab …“). */
export function dunningStepText(step, today) {
	if (step.status === 'versandt') { return t('versandt am {zeitpunkt}', { zeitpunkt: formatStamp(step.sentAt) }) }
	if (step.status === 'eskaliert') { return t('Aufgabe für den Vorstand besteht') }
	if (step.status === 'naechste' && step.dueOn) {
		return step.dueOn > today
			? t('fällig ab {datum} ({relativ})', { datum: formatDate(step.dueOn), relativ: relativeDays(step.dueOn, today) })
			: t('fällig seit {datum} – geht mit dem nächsten täglichen Lauf raus', { datum: formatDate(step.dueOn) })
	}
	return t('noch nicht erreicht')
}

/**
 * Kurzer Hinweis zur „nächsten Stufe“ für die Liste und die Mitglieds-Sicht:
 * „Zahlungserinnerung ab 15.10.2026“ – leer, wenn nichts mehr kommt.
 */
export function dunningNextText(dunning) {
	if (dunning.nextStage === null || dunning.nextStage === undefined || !dunning.nextDueOn) { return '' }
	return t('{stufe} ab {datum}', { stufe: dunningStageLabel(dunning.nextStage), datum: formatDate(dunning.nextDueOn) })
}

// --- Aktionen --------------------------------------------------------------------

/** Eine Forderung, bei der das Geld noch aussteht. */
export function isUnsettled(claim) {
	return UNSETTLED_STATES.includes(claim.state)
}

/**
 * Ob sich an einer Forderung noch etwas vermerken lässt: solange sie weder
 * erledigt noch storniert ist. Dazu zählt auch „eingezogen“ – die Bank hat
 * belastet, der Vermerk fehlt noch (das Backend lässt jede nicht erledigte
 * und nicht stornierte Forderung zu).
 */
export function isActionable(claim) {
	return isUnsettled(claim) || claim.state === 'eingezogen'
}

/**
 * Was sich mit einer Forderung gerade tun lässt (die Rollenprüfung ist Sache der
 * Oberfläche, das Backend hat sie ab `buchhalter` noch einmal):
 * - bezahlt/Erlass/Stundung: solange sie nicht erledigt oder storniert ist;
 * - Stundung aufheben statt Stundung, solange eine läuft (höchstens eine aktive);
 * - Storno nur vor der Einreichung: steckt die Forderung in einem Lauf, nennt
 *   `cancelBlock` den Grund (`submitted` oder `released`) statt des Knopfs.
 */
export function claimActions(claim) {
	const open = isActionable(claim)
	const live = claim.debit ?? null
	return {
		settle: open,
		waive: open,
		defer: open && !claim.deferred,
		undefer: open && claim.deferred === true,
		cancel: open && live === null,
		cancelBlock: open && live !== null ? (live.status === 'eingereicht' ? 'submitted' : 'released') : null,
	}
}

/** Warum Storno gerade nicht geht – ein Satz, der den Weg weist (Erlass bzw. Lauf verwerfen). */
export function cancelBlockText(block) {
	if (block === 'submitted') {
		return t('Storno ist nur vor der Einreichung möglich, diese Forderung steckt schon in einem eingereichten Lauf. Ist sie berechtigt und Sie verzichten darauf, vermerken Sie einen Erlass.')
	}
	if (block === 'released') {
		return t('Diese Forderung steckt in einem freigegebenen Lauf, dessen Datei sie bereits enthält. Verwerfen Sie zuerst den Lauf (Ansicht „Zeitstrahl & Läufe“); danach lässt sie sich stornieren.')
	}
	return ''
}

/**
 * Hinweis, wenn eine Erledigung oder Stundung den Einzug nicht aufhält: eine
 * Forderung in einem freigegebenen Lauf bleibt in der Datei, in einem
 * eingereichten Lauf mit künftigem Termin liegt sie schon bei der Bank. Leer,
 * wenn nichts zu warnen ist.
 */
export function debitWarning(claim, today) {
	const live = claim.debit ?? null
	if (!live || claim.state === 'zurueckgegeben') { return '' }
	if (live.status === 'freigegeben') {
		return t('Diese Forderung steckt im freigegebenen Lauf vom {datum}. Die Datei enthält sie weiter – soll sie nicht mehr eingezogen werden, verwerfen Sie zuerst den Lauf.', { datum: formatDate(live.dueDate) })
	}
	if (live.status === 'eingereicht' && live.dueDate > today) {
		return t('Diese Forderung ist bereits bei der Bank eingereicht (Einzug am {datum}). Der Einzug lässt sich dadurch nicht mehr aufhalten.', { datum: formatDate(live.dueDate) })
	}
	return ''
}

// --- Je Mitglied -----------------------------------------------------------------

/**
 * Fasst Forderungen je Mitglied zusammen (Spec §3.6: die Mahnstufen sind „gebündelt je
 * Mitglied“): wie viele Forderungen in welcher Höhe, die höchste erreichte
 * Mahnstufe, der letzte Versand und der nächste fällige Schritt. Der Mahnstand
 * zählt nur die noch nicht erledigten Forderungen – eine bezahlte hat ihn hinter
 * sich. Eskalierte Mitglieder und höhere Stufen stehen oben, sonst nach Namen.
 */
export function groupByMember(claims) {
	const groups = new Map()
	for (const claim of claims) {
		let group = groups.get(claim.memberId)
		if (!group) {
			group = {
				memberId: claim.memberId,
				name: claim.memberDisplayName,
				count: 0,
				sumCents: 0,
				stage: null,
				escalated: false,
				lastSentAt: null,
				nextStage: null,
				nextDueOn: null,
				deferredCount: 0,
				severity: null,
			}
			groups.set(claim.memberId, group)
		}
		group.count++
		group.sumCents += claim.amountCents
		const severity = worstSeverity(claim.issues)
		if (severity === SEVERITY_ACTION || (severity && group.severity === null)) { group.severity = severity }
		if (!isUnsettled(claim)) { continue }
		if (claim.deferred) { group.deferredCount++ }
		const dunning = claim.dunning
		if (dunning.stage !== null && (group.stage === null || dunning.stage > group.stage)) { group.stage = dunning.stage }
		if (dunning.escalated) { group.escalated = true }
		for (const notice of dunning.notices) {
			if (!group.lastSentAt || notice.sentAt > group.lastSentAt) { group.lastSentAt = notice.sentAt }
		}
		if (dunning.nextStage !== null && dunning.nextDueOn && (!group.nextDueOn || dunning.nextDueOn < group.nextDueOn)) {
			group.nextStage = dunning.nextStage
			group.nextDueOn = dunning.nextDueOn
		}
	}
	const rank = (group) => (group.escalated ? NEXT_ESCALATION + 1 : (group.stage ?? -1))
	return [...groups.values()].sort((a, b) => rank(b) - rank(a) || a.name.localeCompare(b.name, 'de'))
}

/** „3 Forderungen“ – die Zahl für die Kopfzeile der Liste. */
export function claimCountText(count) {
	return n('%n Forderung', '%n Forderungen', count)
}
