// Anzeigelogik der Mandat-Verwaltung in der Mitglieder-Akte (MandatePanel.vue,
// Issue #100, Spec §2.2/§3.2): Klartexte für Zustände und Enum-Werte, die
// Störfall-Hinweise je Zustand und ein paar Datums-/IBAN-Helfer. Reine
// Funktionen ohne Vue-/DOM-Bezug, damit sich die Fallunterscheidungen ohne
// Komponententest-Umgebung prüfen lassen (mandateView.test.js).
//
// Die Enum-Werte des Backends sind deutsch (`entwurf`/`aktiv`/`ausgesetzt`/
// `erloschen`, `papier`/`elektronisch`, `widerrufen`/`ersetzt`/…, siehe
// Mandate.php); die Oberfläche zeigt nie den Rohwert, sondern Klartext.
import { formatDate } from './format.js'
import { t } from './l10n.js'

const DAY_MS = 24 * 3600 * 1000

function isoToday() {
	return new Date().toISOString().slice(0, 10)
}

/** Zustand des Mandats in Klartext (Spec §2.2 Zustandsmodell). */
export function statusLabel(status) {
	return {
		entwurf: t('Entwurf'),
		aktiv: t('Aktiv'),
		ausgesetzt: t('Ausgesetzt'),
		erloschen: t('Erloschen'),
	}[status] ?? status
}

/** Farbton der Statusmarke (CSS-Modifikator in MandatePanel.vue); ein Entwurf ist bewusst neutral. */
export function statusTone(status) {
	return { aktiv: 'success', ausgesetzt: 'warning', erloschen: 'muted' }[status] ?? 'neutral'
}

export function signatureTypeLabel(type) {
	return {
		papier: t('Papier'),
		elektronisch: t('Elektronisch (Einmal-Link oder Konto)'),
		qes: t('Qualifizierte elektronische Signatur'),
	}[type] ?? type
}

/** Warum ein Mandat erloschen ist (`end_reason`, Spec §2.2). */
export function endReasonLabel(reason) {
	return {
		widerrufen: t('Widerrufen'),
		ersetzt: t('Ersetzt (Kontoinhaberwechsel)'),
		verfallen: t('Verfallen (36 Monate ohne Einzug)'),
		beendet: t('Beendet (Austritt)'),
	}[reason] ?? reason ?? ''
}

export function suspensionOriginLabel(origin) {
	return {
		manuell: t('manuell gesperrt'),
		ruecklastschrift: t('gesperrt wegen Rücklastschrift'),
	}[origin] ?? origin ?? ''
}

/** Wer eine Änderung ausgelöst hat (`actor_type` der Historie, Spec §3.9: der Kanal entscheidet). */
export function actorLabel(actorType) {
	return {
		member: t('Mitglied'),
		staff: t('Verein'),
		system: t('System'),
	}[actorType] ?? actorType
}

/** Amendments tragen englische Enum-Werte (`open`/`transmitted`, siehe MandateAmendment.php). */
export function amendmentStatusLabel(status) {
	return {
		open: t('offen – noch nicht an die Bank gemeldet'),
		transmitted: t('an die Bank gemeldet'),
	}[status] ?? status
}

/**
 * IBAN in Vierergruppen. Die maskierte Fassung (`DE12••••9890`, Revisor-Sicht)
 * läuft durch dieselbe Gruppierung, die Punkte bleiben einfach stehen.
 */
export function formatIban(iban) {
	if (!iban) { return '' }
	return String(iban).replace(/\s+/g, '').replace(/(.{4})/g, '$1 ').trim()
}

/** Zeitstempel der Historie (`2026-09-20 14:05:33`) als „20.09.2026 14:05“. */
export function formatStamp(s) {
	if (!s) { return '' }
	const time = String(s).replace('T', ' ').slice(11, 16)
	return time ? `${formatDate(s)} ${time}` : formatDate(s)
}

/** Ganze Tage von `today` bis `date` (negativ = schon vorbei); null ohne Datum. */
export function daysUntil(date, today = isoToday()) {
	if (!date) { return null }
	return Math.round((Date.parse(String(date).slice(0, 10)) - Date.parse(today)) / DAY_MS)
}

/**
 * Würde ein Mandat mit diesem Unterschriftsdatum nach der Aktivierung
 * sofort verfallen? Die 36 Monate laufen ab Unterschrift (bzw. letztem
 * Einzug), der Verfalls-Cron beendet es dann beim nächsten Lauf – eine
 * Warnung vor dem Aktivieren erspart die Überraschung.
 */
export function signatureTooOld(signedAt, today = isoToday()) {
	if (!signedAt) { return false }
	const limit = new Date(`${String(signedAt).slice(0, 10)}T00:00:00Z`)
	limit.setUTCMonth(limit.getUTCMonth() + 36)
	return limit.toISOString().slice(0, 10) <= today
}

/**
 * Zustand des Einmal-Links eines elektronischen Entwurfs aus
 * `mandate.activationLink` (MandateActivationService::linkStatus()):
 * `none` = noch keiner versendet, `pending` = gültig und unbeantwortet,
 * `expired` = abgelaufen ohne Zustimmung.
 */
export function activationLinkState(link) {
	if (!link) { return 'none' }
	return link.expired ? 'expired' : 'pending'
}

/**
 * Störfall-Hinweise je Zustand (Spec §3.2/§7), jeweils mit dem Klartext-Titel
 * aus dem Backend (`storyText`, dieselbe Formulierung wie in der Aufgabenliste)
 * und einer Erläuterung, was zu tun ist. `tone` ist `warning` (Handlungsbedarf)
 * oder `info` (Hinweis ohne Dringlichkeit, wie „Mandat ohne Nachweis“).
 *
 * @param {object} mandate Mandat der Mandat-API (inkl. storyText, hasDocument,
 *        showMissingDocumentWarning, expiresAt, expiryWarning; für elektronische
 *        Entwürfe optional activationLink aus der Einzelansicht)
 * @param {string} today Stichtag (für Tests)
 * @return {{key: string, tone: string, title: string, text: string}[]}
 */
export function mandateNotices(mandate, today = isoToday()) {
	const notices = []

	if (mandate.status === 'entwurf') {
		notices.push({
			key: 'draft',
			tone: 'warning',
			title: mandate.storyText || t('Unterschrift fehlt'),
			text: draftExplanation(mandate),
		})
	} else if (mandate.status === 'ausgesetzt') {
		notices.push({
			key: 'suspended',
			tone: 'warning',
			title: mandate.storyText || t('Klärung offen'),
			text: mandate.suspensionOrigin === 'ruecklastschrift'
				? t('Das Mandat wurde automatisch nach einer Rücklastschrift ausgesetzt. Klären Sie den Fall mit dem Mitglied und entsperren Sie das Mandat erst danach – eine Einziehung ist solange ausgeschlossen, offene Forderungen bleiben offen.')
				: t('Das Mandat ist ausgesetzt: bis zur Klärung wird nichts darüber eingezogen, offene Forderungen bleiben offen. Entsperren Sie es nach der Klärung.'),
		})
	} else if (mandate.status === 'erloschen') {
		notices.push({
			key: 'ended',
			tone: 'warning',
			title: mandate.storyText || t('neues Mandat einholen'),
			text: t('Dieses Mandat ist erloschen ({grund}). Für Lastschrifteinzüge braucht das Mitglied ein neues Mandat.', { grund: endReasonLabel(mandate.endReason) }),
		})
	}

	if (mandate.expiryWarning && mandate.expiresAt) {
		const days = daysUntil(mandate.expiresAt, today)
		notices.push({
			key: 'expiry',
			tone: 'warning',
			title: days > 0
				? t('Mandat läuft in {tage} Tagen ab', { tage: days })
				: t('Mandat läuft heute ab'),
			text: t('Die 36-Monats-Frist endet am {datum}. Ohne Einzug bis dahin erlischt das Mandat automatisch; jeder eingereichte Einzug setzt die Frist neu in Gang.', { datum: formatDate(mandate.expiresAt) }),
		})
	}

	// Dauer-Hinweis, in den Einstellungen abschaltbar (Server entscheidet: showMissingDocumentWarning).
	if (mandate.showMissingDocumentWarning) {
		notices.push({
			key: 'document',
			tone: 'info',
			title: t('Mandat ohne Nachweis'),
			text: t('Zum Mandat ist kein unterschriebenes Dokument hinterlegt. Laden Sie den Nachweis hoch, damit er im Streitfall zur Hand ist.'),
		})
	}

	return notices
}

function draftExplanation(mandate) {
	if (mandate.signatureType === 'elektronisch') {
		const state = activationLinkState(mandate.activationLink)
		if (state === 'expired') {
			return t('Der Einmal-Link ist abgelaufen, ohne dass das Mitglied zugestimmt hat. Senden Sie ihn erneut.')
		}
		if (state === 'pending') {
			return t('Der Einmal-Link ist unterwegs – das Mandat wird aktiv, sobald das Mitglied zustimmt.')
		}
		return t('Es wurde noch kein Einmal-Link verschickt. Senden Sie ihn, damit das Mitglied zustimmen kann.')
	}
	return mandate.signedAt
		? t('Das Unterschriftsdatum ist eingetragen – aktivieren Sie das Mandat, damit es einzugsfähig wird.')
		: t('Tragen Sie das Unterschriftsdatum ein, sobald das unterschriebene Mandat vorliegt, und aktivieren Sie es damit.')
}
