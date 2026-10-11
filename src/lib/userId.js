// Kontoname-Vorschlag für ein neues Nextcloud-Konto (rein, ohne Netzwerk – siehe nextcloudAccount.js).

const UMLAUTS = { ä: 'ae', ö: 'oe', ü: 'ue', ß: 'ss', Ä: 'ae', Ö: 'oe', Ü: 'ue' }

/** „Anna Müller“ → „anna.mueller“: ein Vorschlag für den Kontonamen, nie ein Zwang. */
export function suggestUserId(...parts) {
	return parts
		.map((part) => String(part ?? '')
			.replace(/[äöüßÄÖÜ]/g, (c) => UMLAUTS[c])
			.normalize('NFD')
			.replace(/[\u0300-\u036f]/g, '')
			.toLowerCase()
			.replace(/[^a-z0-9]+/g, '-')
			.replace(/^-+|-+$/g, ''))
		.filter((part) => part !== '')
		.join('.')
}
