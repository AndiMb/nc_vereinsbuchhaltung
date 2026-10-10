/**
 * Text in die Zwischenablage kopieren. `navigator.clipboard` gibt es nur in einem gesicherten
 * Kontext (https, localhost); auf einer Nextcloud über plain http fehlt es, dort greift der Umweg
 * über ein verstecktes Textfeld. Ergebnis: true, wenn kopiert wurde.
 *
 * @param {string} text
 * @return {Promise<boolean>}
 */
export async function copyText(text) {
	if (navigator.clipboard?.writeText) {
		try {
			await navigator.clipboard.writeText(text)
			return true
		} catch {
			// Berechtigung verweigert: es bleibt der Umweg unten.
		}
	}
	const area = document.createElement('textarea')
	area.value = text
	area.setAttribute('readonly', '')
	area.style.position = 'fixed'
	area.style.opacity = '0'
	document.body.appendChild(area)
	area.select()
	try {
		return document.execCommand('copy')
	} catch {
		return false
	} finally {
		document.body.removeChild(area)
	}
}
