import { loadState } from '@nextcloud/initial-state'

// Startwerte, die die Seite gleich mitbringt (Issue #264): PageController legt
// `me`, `periods` und – ab Leserolle – `settings` als Initial State ins HTML.
// Die App spart sich damit beim Start drei Anfragen, die bisher nacheinander
// liefen, bevor irgendetwas zu sehen war.
//
// Jeder Wert gilt nur für das erste Laden: danach (Speichern, Zeitraum
// angelegt, Änderung aus einem anderen Fenster) muss der aktuelle Stand vom
// Server kommen, nicht der von vor zehn Minuten. loadState() selbst merkt sich
// den Wert für immer, deshalb führt dieses Modul Buch.

const APP_ID = 'vereinsbuchhaltung'
const taken = new Set()

/**
 * Der Startwert zu `key` beim ersten Aufruf, danach null.
 *
 * null auch dann, wenn die Seite ihn nicht mitbringt (Einstellungsseite,
 * Konto ohne Leserolle, Fehler beim Zusammenstellen) – der Aufrufer holt ihn
 * dann wie bisher über die API.
 *
 * @param {string} key 'me', 'periods' oder 'settings'
 * @return {object|Array|null} der Wert, wie ihn der Endpunkt liefern würde
 */
export function takeBootState(key) {
	if (taken.has(key)) { return null }
	taken.add(key)
	try {
		return loadState(APP_ID, key, null)
	} catch {
		return null
	}
}
