/** Trennzeichen für eine Adressliste im Empfängerfeld: das Semikolon verstehen auch Outlook und die gängigen Webmailer. */
export const EMAIL_SEPARATOR = '; '

/**
 * Die Adressen der Mitgliederzeilen für eine Sammelmail: nur Mitglieder, die noch dabei sind (ausgetretene
 * bekommen keine Vereinspost), jede Adresse einmal (Familien teilen sich eine, Groß-/Kleinschreibung zählt nicht).
 *
 * @param {Array<{email: ?string, member: {active: boolean}}>} rows Zeilen aus lib/memberRow.js
 * @return {{addresses: string[], withoutEmail: number}} `withoutEmail` zählt die Mitglieder ohne Adresse, die dabei fehlen
 */
export function collectEmails(rows) {
	const seen = new Set()
	const addresses = []
	let withoutEmail = 0
	for (const row of rows) {
		if (!row.member.active) { continue }
		const email = (row.email ?? '').trim()
		if (email === '') {
			withoutEmail++
			continue
		}
		const key = email.toLowerCase()
		if (seen.has(key)) { continue }
		seen.add(key)
		addresses.push(email)
	}
	return { addresses, withoutEmail }
}
