import { inNextcloud } from './girocode.mjs'

// Bestätigung einer Kontoverknüpfung durch das Mitglied (#295).
//
// Eine Verknüpfung gilt erst, wenn das Mitglied den Einmal-Link aus seiner Mail
// mit dem verknüpften Konto bestätigt. Die App speichert vom Link nur den Hash;
// lesen lässt er sich also nur aus der Mail. Specs, die eine geltende
// Verknüpfung bloß als Voraussetzung brauchen (Self-Service, Auslagen …),
// sollen dafür keinen Mailabgriff aufbauen. Stattdessen setzt dieser Helfer
// einen bekannten Link und löst ihn über den Dienst der App ein – derselbe
// Weg wie der Klick auf „Konto bestätigen", nur ohne Browser. Den echten Weg
// über Mail-Link und Seite prüft 23-members.spec.mjs.

/** Ein gültiger Einmal-Link (64 Zeichen, alphanumerisch) – fest, damit Fehlerausgaben lesbar bleiben. */
function newToken() {
	let token = 'e2e'
	while (token.length < 64) {
		token += Math.random().toString(36).slice(2)
	}
	return token.slice(0, 64).replace(/[^a-z0-9]/gi, 'x')
}

/**
 * Setzt für das (ausstehend verknüpfte) Mitglied einen Bestätigungslink und
 * liefert den Token. Die Seite dazu: `/index.php/apps/vereinsbuchhaltung/link-account/<token>`.
 */
export async function setMemberLinkToken(memberId) {
	const token = newToken()
	const result = await inNextcloud(String.raw`
$mapper = \OCP\Server::get(\OCA\Vereinsbuchhaltung\Db\MemberMapper::class);
$member = $mapper->find((int)$argv[1]);
$member->setNcLinkTokenHash(hash('sha256', $argv[2]));
$member->setNcLinkTokenExpiresAt((new \DateTime('+1 day'))->format('Y-m-d H:i:s'));
$mapper->update($member);
echo 'VBH-RESULT ' . json_encode(['ncUserId' => $member->getNcUserId()]);
`, [String(memberId), token])
	if (!result.ncUserId) {
		throw new Error(`Mitglied ${memberId} ist mit keinem Nextcloud-Konto verknüpft – nichts zu bestätigen.`)
	}
	return token
}

/** Bestätigt die Verknüpfung des Mitglieds so, wie es das Mitglied mit seinem Konto täte. */
export async function confirmMemberLink(memberId) {
	const token = await setMemberLinkToken(memberId)
	const result = await inNextcloud(String.raw`
$mapper = \OCP\Server::get(\OCA\Vereinsbuchhaltung\Db\MemberMapper::class);
$uid = (string)$mapper->find((int)$argv[1])->getNcUserId();
$service = \OCP\Server::get(\OCA\Vereinsbuchhaltung\Service\MemberLinkConfirmationService::class);
echo 'VBH-RESULT ' . json_encode(['status' => $service->confirm($argv[2], $uid)['status']]);
`, [String(memberId), token])
	if (result.status !== 'confirmed') {
		throw new Error(`Bestätigung der Verknüpfung von Mitglied ${memberId} fehlgeschlagen: ${result.status}`)
	}
}
