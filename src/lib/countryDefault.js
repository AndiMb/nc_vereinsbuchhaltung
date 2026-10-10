import { getLocale } from '@nextcloud/l10n'
import { defaultCountryCode } from './countries.js'

/**
 * Das Land der Person, die die App bedient, als Vorgabe für neue Einträge: die
 * Region der Nextcloud-Locale („de_AT“ → Österreich), sonst die des Browsers,
 * sonst Deutschland (siehe defaultCountryCode()).
 *
 * @return {string} ISO-Code
 */
export function defaultCountry() {
	return defaultCountryCode(getLocale(), typeof navigator !== 'undefined' ? navigator.language : '')
}
