// Länderliste für die Auswahl „Land“ (Mitglieder-Stammdaten, Self-Service).
// Gespeichert wird der zweistellige ISO-3166-1-Code („DE“); die Namen kommen
// aus der Sprache des Nutzers (Intl.DisplayNames), damit die Liste weder von
// Hand gepflegt noch übersetzt werden muss.

/** Alle 249 amtlich zugeteilten ISO-3166-1-Alpha-2-Codes. */
export const COUNTRY_CODES = [
	...'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ'.split(' '),
	...'BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ'.split(' '),
	...'CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ'.split(' '),
	...'DE DJ DK DM DO DZ'.split(' '),
	...'EC EE EG EH ER ES ET'.split(' '),
	...'FI FJ FK FM FO FR'.split(' '),
	...'GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY'.split(' '),
	...'HK HM HN HR HT HU'.split(' '),
	...'ID IE IL IM IN IO IQ IR IS IT'.split(' '),
	...'JE JM JO JP'.split(' '),
	...'KE KG KH KI KM KN KP KR KW KY KZ'.split(' '),
	...'LA LB LC LI LK LR LS LT LU LV LY'.split(' '),
	...'MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ'.split(' '),
	...'NA NC NE NF NG NI NL NO NP NR NU NZ'.split(' '),
	...'OM'.split(' '),
	...'PA PE PF PG PH PK PL PM PN PR PS PT PW PY'.split(' '),
	...'QA'.split(' '),
	...'RE RO RS RU RW'.split(' '),
	...'SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ'.split(' '),
	...'TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ'.split(' '),
	...'UA UG UM US UY UZ'.split(' '),
	...'VA VC VE VG VI VN VU'.split(' '),
	...'WF WS'.split(' '),
	...'YE YT'.split(' '),
	...'ZA ZM ZW'.split(' '),
]

/** Land, wenn sich aus der Umgebung keines ableiten lässt (die App ist für deutsche Vereine gebaut). */
export const FALLBACK_COUNTRY = 'DE'

/**
 * Zweistellige Sprachangabe für Intl: Nextcloud liefert „de_DE“ oder „de“.
 *
 * @param {string} [language] Sprache oder Locale
 * @return {string}
 */
function intlTag(language) {
	return (language || 'en').replace('_', '-')
}

/**
 * Name eines Landes in der Sprache des Nutzers; unbekannte Codes kommen unverändert zurück.
 *
 * @param {string|null|undefined} code ISO-Code
 * @param {string} [language] Sprache des Nutzers
 * @return {string}
 */
export function countryName(code, language) {
	if (!code) { return '' }
	try {
		return new Intl.DisplayNames([intlTag(language)], { type: 'region' }).of(code) || code
	} catch {
		return code
	}
}

/**
 * Alle Länder als Auswahlliste, alphabetisch nach dem Namen in der Sprache des Nutzers.
 *
 * @param {string} [language] Sprache des Nutzers
 * @return {{ id: string, label: string }[]}
 */
export function countryOptions(language) {
	const collator = new Intl.Collator(intlTag(language))
	return COUNTRY_CODES
		.map((id) => ({ id, label: countryName(id, language) }))
		.sort((a, b) => collator.compare(a.label, b.label))
}

/**
 * Region aus einer Locale: „de_DE“ → „DE“, „en-GB“ → „GB“, „de“ → null.
 *
 * @param {string|null|undefined} locale Locale oder Sprachtag
 * @return {string|null}
 */
export function regionFromLocale(locale) {
	const match = /^[A-Za-z]{2,3}[_-]([A-Za-z]{2})(?:[_-].*)?$/.exec(locale || '')
	const region = match ? match[1].toUpperCase() : null
	return region && COUNTRY_CODES.includes(region) ? region : null
}

/**
 * Vorgabe für „Land“: das Land der Person, die die App bedient. Nextcloud kennt
 * kein Land am Konto; die beste Näherung ist die Region der Nextcloud-Locale
 * („de_AT“ → Österreich), danach die des Browsers, zuletzt Deutschland.
 *
 * @param {...(string|null|undefined)} locales Locales in der Reihenfolge ihrer Verlässlichkeit
 * @return {string}
 */
export function defaultCountryCode(...locales) {
	for (const locale of locales) {
		const region = regionFromLocale(locale)
		if (region) { return region }
	}
	return FALLBACK_COUNTRY
}
