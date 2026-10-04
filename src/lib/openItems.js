// Zustandslose Helfer der generischen Offene-Posten-Sicht (Buchungen → Offene
// Posten, Issue #121). Dieselbe Tabelle enthält freie Posten (Handwerker-
// rechnung u. Ä.) und die Forderungen des Beitragsmoduls; nur die freien lassen
// sich hier bearbeiten, die Forderungen werden im Einzug-Reiter bearbeitet
// (Server: OpenItemService). Reine Funktionen ohne Vue-/DOM-Bezug, damit sie
// sich ohne Komponente testen lassen (openItems.test.js). `t()` wird erst beim
// Aufruf ausgewertet, nie beim Import.
import { t } from './l10n.js'

/**
 * Ob ein Posten eine Forderung des Beitragsmoduls ist. Der Server liefert
 * `memberId` und `type` ohnehin mit; erkannt wird wie dort
 * (OpenItem::belongsToClaimModule()) an EINEM der beiden Felder, nicht an
 * beiden: das Modul setzt sie immer zusammen, ein halber Satz käme nur von
 * außen und ist trotzdem kein freier Posten.
 *
 * @param {{ memberId?: number|null, type?: string|null }|null|undefined} item Posten aus GET /open-items
 * @return {boolean}
 */
export function isClaimItem(item) {
	return isSet(item?.memberId) || isSet(item?.type)
}

/** Gesetzt heißt: weder null (so liefert der Server leere Felder) noch undefined (Feld fehlt). */
function isSet(value) {
	return value !== null && value !== undefined
}

/**
 * Beschriftung des Status. `waived` (erlassen) gibt es nur an Forderungen; die
 * drei übrigen Werte gelten auch für freie Posten.
 *
 * @param {string} status Wert der Spalte `status`
 * @return {string}
 */
export function openItemStatusLabel(status) {
	return {
		open: t('Offen'),
		paid: t('Bezahlt'),
		cancelled: t('Storniert'),
		waived: t('Erlassen'),
	}[status] || status
}
