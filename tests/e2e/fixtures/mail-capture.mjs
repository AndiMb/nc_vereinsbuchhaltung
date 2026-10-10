import { getContainer, runExec, runOcc } from '@nextcloud/e2e-test-server'

// Mails des Testservers abgreifen (Issue #120, GiroCode-Anhang der Mahnmails).
//
// Der Testserver hat keinen Mailserver. Die Spezifikation 28/43 stellen den NC-
// Mail-Modus auf "null" – dort wird die Mail angenommen und verworfen, man sieht
// nur, DASS versandt wurde. Für den GiroCode muss man sehen, WAS versandt wurde:
// Anhänge, Dateinamen, Bildinhalt.
//
// Gewählt ist ein sendmail-Ersatz IM Container: NC-Modus "sendmail" mit
// `mail_sendmailmode=pipe` startet `sendmail -t …` und schreibt die fertige
// Mail (genau die Bytes, die ein Mailserver bekäme) auf dessen Standardeingabe.
// Ein winziges Shell-Skript legt sie als .eml ab, der Test liest sie per
// `docker exec` zurück und zerlegt sie.
// Warum nicht anders:
//   - SMTP-Senke auf dem Host: der Container müsste den Host erreichen
//     (Linux-CI: Bridge-Gateway, Docker Desktop: host.docker.internal), dazu
//     ein Socket-Server im Testprozess – plattformabhängig und mehr Teile.
//   - Mail-Hook: NC kennt nur ein Ereignis vor dem Versand, einen Listener
//     könnte nur App-Code registrieren – Testcode gehört nicht in die App.
//   - Dienst per PHP-Skript aufrufen: bewiese nur den Dienst, nicht den echten
//     Auslöser (Tageslauf, Mandatswiderruf per Web-Request) samt Transport.
// Ohne Netz, ohne zusätzlichen Dienst, ohne Änderung an ci.yml.

export const SHIM_PATH = '/usr/local/bin/sendmail'
export const MAIL_DIR = '/tmp/vbh-e2e-mails'

// NC sucht `sendmail` über PATH (/usr/local/bin steht vor /usr/sbin) und
// ersatzweise in festen Verzeichnissen. Das Skript muss immer mit 0 enden,
// sonst meldet der Transport einen Fehlversand.
const SHIM_SCRIPT = [
	'#!/bin/sh',
	'# E2E-Ersatz für sendmail (tests/e2e/fixtures/mail-capture.mjs): Mail unverändert ablegen.',
	'set -e',
	`tmp=$(mktemp ${MAIL_DIR}/XXXXXXXX)`,
	'cat > "$tmp"',
	'mv "$tmp" "$tmp.eml"',
	'',
].join('\n')

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/** Legt den sendmail-Ersatz und das Ablageverzeichnis an (als root; die Mail schreibt später www-data). */
export async function installSendmailShim({ container = getContainer() } = {}) {
	const encoded = Buffer.from(SHIM_SCRIPT, 'utf8').toString('base64')
	await runExec(
		['sh', '-c', `mkdir -p ${MAIL_DIR} && chmod 777 ${MAIL_DIR} && rm -f ${MAIL_DIR}/* && echo '${encoded}' | base64 -d > ${SHIM_PATH} && chmod 755 ${SHIM_PATH}`],
		{ container, user: 'root' },
	)
}

export async function removeSendmailShim({ container = getContainer() } = {}) {
	await runExec(['sh', '-c', `rm -f ${SHIM_PATH}; rm -rf ${MAIL_DIR}`], { container, user: 'root' })
}

/**
 * Schaltet die Instanz auf den Abgriff um. `config.php` gehört nicht zum
 * Datenbank-Snapshot: wer das aufruft, ruft in `afterAll` {@link stopMailCapture}
 * auf (wie 28/43 beim Modus "null"), sonst gilt es für alle folgenden Specs.
 */
export async function startMailCapture({ container = getContainer() } = {}) {
	await installSendmailShim({ container })
	await runOcc(['config:system:set', 'mail_smtpmode', '--value', 'sendmail'], { container })
	await runOcc(['config:system:set', 'mail_sendmailmode', '--value', 'pipe'], { container })
	// Der Apache-Prozess liest config.php über opcache (Standard: Zeitstempel
	// alle 2 s prüfen). Ohne diese Pause sähe ein Web-Request direkt nach dem
	// Umschalten noch den alten Mail-Modus und der Versand scheiterte an
	// 127.0.0.1:25. Kommandozeilen-Läufe (occ) lesen die Datei immer frisch.
	await sleep(4000)
}

export async function stopMailCapture({ container = getContainer() } = {}) {
	await runOcc(['config:system:delete', 'mail_smtpmode'], { container, failOnError: false })
	await runOcc(['config:system:delete', 'mail_sendmailmode'], { container, failOnError: false })
	await removeSendmailShim({ container })
}

export async function clearCapturedMails({ container = getContainer() } = {}) {
	await runExec(['sh', '-c', `rm -f ${MAIL_DIR}/*.eml`], { container, user: 'root' })
}

/**
 * Alle bisher abgegriffenen Mails, älteste zuerst, jeweils zerlegt:
 * `{ file, raw, to, subject, text, attachments: [{ filename, contentType, data }] }`.
 */
export async function capturedMails({ container = getContainer() } = {}) {
	const { stdout } = await runExec(['sh', '-c', `ls -1tr ${MAIL_DIR}/*.eml 2>/dev/null || true`], { container, user: 'root' })
	const mails = []
	for (const file of stdout.split('\n').map((l) => l.trim()).filter(Boolean)) {
		const { stdout: raw } = await runExec(['cat', file], { container, user: 'root' })
		const mime = parseMime(raw)
		mails.push({
			file,
			raw,
			to: mime.headers.to || '',
			subject: decodeHeader(mime.headers.subject || ''),
			text: textOf(mime),
			attachments: attachmentsOf(mime),
		})
	}
	return mails
}

/** Wartet, bis mindestens `count` Mails an `recipient` abgegriffen sind, und liefert diese. */
export async function waitForMailsTo(recipient, { count = 1, timeoutMs = 20000, container = getContainer() } = {}) {
	const deadline = Date.now() + timeoutMs
	let matching = []
	do {
		matching = (await capturedMails({ container })).filter((m) => m.to.includes(recipient))
		if (matching.length >= count) {
			return matching
		}
		await sleep(500)
	} while (Date.now() < deadline)
	throw new Error(
		`Keine Mail an ${recipient} abgegriffen (erwartet: ${count}, gefunden: ${matching.length}). `
		+ 'Ist der sendmail-Ersatz aktiv (mail_smtpmode=sendmail, mail_sendmailmode=pipe) und hat der Auslöser versandt?',
	)
}

// --- MIME ------------------------------------------------------------------
// Eben genug für die Ausgabe des Symfony Mailers, den Nextcloud benutzt:
// verschachtelte multipart-Teile, base64- und quoted-printable-Inhalte,
// RFC-2047-Betreff. Kein Ersatz für eine MIME-Bibliothek.

/** Zerlegt eine Mail (oder einen Teil) in `{ headers, contentType, body, children }`. */
export function parseMime(raw) {
	const text = raw.replace(/\r\n/g, '\n')
	const split = text.indexOf('\n\n')
	const headerBlock = split === -1 ? text : text.slice(0, split)
	const body = split === -1 ? '' : text.slice(split + 2)

	const headers = {}
	for (const line of headerBlock.replace(/\n[ \t]+/g, ' ').split('\n')) {
		const match = /^([^:\s]+):\s*(.*)$/.exec(line)
		if (match && !(match[1].toLowerCase() in headers)) {
			headers[match[1].toLowerCase()] = match[2]
		}
	}

	const contentTypeHeader = headers['content-type'] || 'text/plain'
	const part = {
		headers,
		contentType: contentTypeHeader.split(';')[0].trim().toLowerCase(),
		body,
		children: [],
	}
	const boundary = /boundary="?([^";\s]+)"?/i.exec(contentTypeHeader)?.[1]
	if (part.contentType.startsWith('multipart/') && boundary) {
		for (const chunk of body.split(`--${boundary}`).slice(1)) {
			if (chunk.startsWith('--')) {
				break // Schlussmarke
			}
			part.children.push(parseMime(chunk.replace(/^[ \t]*\n/, '').replace(/\n$/, '')))
		}
	}
	return part
}

/** Inhalt eines Blattteils nach Transfer-Encoding dekodiert. */
function decodeBody(part) {
	const encoding = (part.headers['content-transfer-encoding'] || '7bit').toLowerCase()
	if (encoding === 'base64') {
		return Buffer.from(part.body.replace(/\s+/g, ''), 'base64')
	}
	if (encoding === 'quoted-printable') {
		const joined = part.body.replace(/=\n/g, '')
		const bytes = []
		for (let i = 0; i < joined.length; i++) {
			const hex = joined[i] === '=' ? joined.slice(i + 1, i + 3) : ''
			if (/^[0-9A-Fa-f]{2}$/.test(hex)) {
				bytes.push(parseInt(hex, 16))
				i += 2
			} else {
				bytes.push(...Buffer.from(joined[i], 'utf8'))
			}
		}
		return Buffer.from(bytes)
	}
	return Buffer.from(part.body, 'utf8')
}

/** Alle Anhänge (Teile mit Dateiname oder `Content-Disposition: attachment`). */
export function attachmentsOf(part, found = []) {
	for (const child of part.children) {
		attachmentsOf(child, found)
	}
	if (part.children.length === 0) {
		const disposition = part.headers['content-disposition'] || ''
		const named = /filename\*?="?([^";]+)"?/i.exec(disposition) || /name="?([^";]+)"?/i.exec(part.headers['content-type'] || '')
		if (/^attachment/i.test(disposition) || named) {
			found.push({
				filename: named ? named[1] : null,
				contentType: part.contentType,
				data: decodeBody(part),
			})
		}
	}
	return found
}

/** Klartext-Teil der Mail (der erste `text/plain`-Teil ohne Dateinamen). */
function textOf(part) {
	if (part.children.length === 0) {
		const isFile = /filename|attachment/i.test(part.headers['content-disposition'] || '')
		return part.contentType === 'text/plain' && !isFile ? decodeBody(part).toString('utf8') : ''
	}
	return part.children.map(textOf).find((t) => t !== '') ?? ''
}

/** RFC-2047-Kopfzeilen (`=?utf-8?Q?…?=`, `=?utf-8?B?…?=`) lesbar machen. */
export function decodeHeader(value) {
	return value
		.replace(/(\?=)\s+(=\?)/g, '$1$2')
		.replace(/=\?([^?]+)\?([QqBb])\?([^?]*)\?=/g, (_, _charset, encoding, payload) => {
			if (encoding.toUpperCase() === 'B') {
				return Buffer.from(payload, 'base64').toString('utf8')
			}
			const bytes = []
			const text = payload.replace(/_/g, ' ')
			for (let i = 0; i < text.length; i++) {
				if (text[i] === '=' && /^[0-9A-Fa-f]{2}$/.test(text.slice(i + 1, i + 3))) {
					bytes.push(parseInt(text.slice(i + 1, i + 3), 16))
					i += 2
				} else {
					bytes.push(...Buffer.from(text[i], 'utf8'))
				}
			}
			return Buffer.from(bytes).toString('utf8')
		})
}
