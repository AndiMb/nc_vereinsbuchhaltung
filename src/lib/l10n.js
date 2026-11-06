import { translate, translatePlural } from '@nextcloud/l10n'
// Uebersetzungs-Helfer fuer die Vue-Oberflaeche.
//
// Die Quelltexte im Code sind bewusst Deutsch (Herkunftssprache der App), nicht
// Englisch wie sonst im Nextcloud-Oekosystem ueblich, und im Beitragsmodul in
// Sie-Form.
//
// Geladen wird hier nichts: Nextcloud bindet die Uebersetzungen der App als
// Skript l10n/<sprache>.js in die Seite ein, bevor die App startet
// (Util::addTranslations im PageController, Issue #264). Das Skript ruft OC.L10N.register()
// auf und fuellt damit dieselbe Ablage, aus der translate() unten liest. Die
// .js-Dateien entstehen aus l10n/<sprache>.json (npm run l10n:js).
//
// Nextcloud kennt zwei deutsche Sprachen: 'de_DE' („Deutsch (Förmlich - Sie)")
// und 'de' (informell, „Du"). 'de_DE' IST der Quelltext, l10n/de_DE.js ist
// leer. 'de' bringt l10n/de.js: das ist keine Übersetzung, sondern die
// Du-Fassung der Texte an Mitglieder (Mein Beitrag, Mails; Spec §1.4, Issue
// #106). Alle übrigen Texte stehen dort nicht und bleiben, wie sie sind.

const APP_ID = 'vereinsbuchhaltung'

// Kein HTML-Escaping und kein DOMPurify in der Uebersetzung: die Texte landen
// ausschliesslich in Vue-Templates ({{ }} und :attr-Bindings), und Vue
// escapt dort selbst. Mit dem Standard (escape: true) wurde ein "&" im
// Kontonamen doppelt kodiert und stand als "&amp;" sichtbar im Finanzplan
// ("Notiz zu 5930 Anschaffung &amp; Wartung Technik"). v-html wird in der
// App nirgends verwendet - sollte das einmal noetig werden, muss der Wert dort
// gezielt escapt werden, nicht hier.
const TRANSLATE_OPTIONS = { escape: false, sanitize: false }

export function t(text, vars, count) {
	return translate(APP_ID, text, vars, count, TRANSLATE_OPTIONS)
}

export function n(textSingular, textPlural, count, vars) {
	return translatePlural(APP_ID, textSingular, textPlural, count, vars, TRANSLATE_OPTIONS)
}

/**
 * Trennzeichen zwischen Kontext und Text im Schlüssel (gettext: msgctxt, EOT).
 * Ein Steuerzeichen, damit es in keinem echten Text vorkommt.
 */
export const CONTEXT_SEPARATOR = '\u0004'

/**
 * Wie t(), für ein deutsches Wort, das in der App mehr als eine Bedeutung hat.
 *
 * Die Bündel sind flach – ein Schlüssel, eine Übersetzung. „Aktiv" ist aber
 * zugleich die Kontoart (englisch „Asset") und der Zustand eines Mandats oder
 * einer Beitragsgruppe („Active"). Ein Kontext trennt die beiden: im Bündel
 * steht der Eintrag unter „Kontext␄Text", im Quelltext bleibt es das schlichte
 * deutsche Wort. Fehlt der Eintrag (deutsche Quellsprache, nicht übersetzte
 * Sprache), erscheint genau dieses Wort.
 *
 * Nur einsetzen, wo es wirklich zwei Bedeutungen gibt; sonst reicht t().
 *
 * @param {string} context kurze Angabe, welche Bedeutung gemeint ist (z. B. „Zustand")
 * @param {string} text Quelltext
 * @param {object} vars Platzhalter wie bei t()
 * @param {number} count Zahl wie bei t()
 * @return {string}
 */
export function tc(context, text, vars, count) {
	const key = `${context}${CONTEXT_SEPARATOR}${text}`
	const translated = translate(APP_ID, key, vars, count, TRANSLATE_OPTIONS)
	return translated.startsWith(`${context}${CONTEXT_SEPARATOR}`) ? translate(APP_ID, text, vars, count, TRANSLATE_OPTIONS) : translated
}

/**
 * Wie t(), nur dass die Werte aus `userData` erst NACH der Übersetzung
 * eingesetzt werden – für alles, was ein Mensch eingetippt hat (Namen,
 * Freitext, Mailadressen, Kontoinhaber) oder der Server als Fehlertext liefert.
 *
 * Seit t() selbst nicht mehr escaped (TRANSLATE_OPTIONS oben) ist „Echo & Söhne"
 * auch dort unverändert; tRaw() bleibt für Nutzerdaten die sichere Wahl, weil
 * der Wert nie durch die Bibliothek läuft: ein Wert, der wie ein Platzhalter
 * aussieht („{datum}", „%n"), wird nicht ein zweites Mal aufgelöst.
 *
 * Der Satz bleibt dabei ein Satz, den man übersetzen kann: der Platzhalter
 * steht im Schlüssel wie bei t(), nur der Wert kommt später. `vars` sind die
 * unkritischen Werte (Zahlen, Datumsangaben, bereits übersetzte Begriffe) und
 * laufen wie bei t() durch die Bibliothek.
 *
 * Das Ergebnis ist reiner Text: NICHT per v-html oder innerHTML ausgeben.
 *
 * @param {string} text Quelltext mit Platzhaltern wie {name}
 * @param {object} userData Platzhalter -> Nutzerdaten
 * @param {object} vars Platzhalter -> unkritische Werte
 * @return {string} der übersetzte Satz mit den Nutzerdaten, unverändert
 */
export function tRaw(text, userData = {}, vars = {}) {
	// Die Nutzerdaten-Platzhalter bleiben als „{name}" stehen: die Bibliothek
	// setzt sie unverändert wieder ein, und der zweite Durchgang unten findet sie.
	const kept = Object.fromEntries(Object.keys(userData).map((key) => [key, `{${key}}`]))
	const translated = t(text, { ...vars, ...kept })
	// Ein einziger Durchgang: steht in einem Wert selbst „{x}", wird das nicht
	// noch einmal aufgelöst.
	return translated.replace(/{([^{}]*)}/g, (match, key) => (
		Object.hasOwn(userData, key) ? String(userData[key] ?? '') : match
	))
}
