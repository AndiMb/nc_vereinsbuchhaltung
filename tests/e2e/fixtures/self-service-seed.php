<?php

declare(strict_types=1);

/**
 * Test-Hilfsskript für die Playwright-E2E-Suite (siehe
 * tests/e2e/23-self-service.spec.mjs), NICHT Teil der Anwendung.
 *
 * Grund für dieses Skript statt eines HTTP-Aufrufs: in diesem Stand des
 * Beiträge/SEPA-Moduls (Issue #74, gestapelt auf dem noch offenen #65) gibt
 * es noch keine Member-CRUD-API – die kommt erst mit der eigentlichen
 * Mitgliederverwaltung (Folgeticket). Um trotzdem "verknüpftes Konto sieht
 * eigene Stammdaten" e2e zu belegen, legt dieses Skript die Kontoverknüpfung
 * direkt über MemberMapper an, per `php` im Docker-Exec des Testcontainers
 * (siehe runExec() in der Spec-Datei). Läuft nur dort, wird nicht
 * ausgeliefert (liegt unter tests/, siehe .github/workflows/release.yml).
 *
 * Aufruf:
 *   php self-service-seed.php link   <nc_user_id> <firstName> <lastName> <email>
 *   php self-service-seed.php unlink <nc_user_id>
 */

require_once '/var/www/html/lib/base.php';

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;

/** @var MemberMapper $mapper */
$mapper = \OC::$server->get(MemberMapper::class);

$action = $argv[1] ?? '';

if ($action === 'unlink') {
	$ncUserId = $argv[2] ?? '';
	$existing = $mapper->findByNcUserId($ncUserId);
	if ($existing !== null) {
		$mapper->delete($existing);
	}
	echo "OK\n";
	exit(0);
}

if ($action === 'link') {
	[, , $ncUserId, $firstName, $lastName, $email] = $argv;

	// Idempotent für Retries (beforeAll läuft bei einem Retry erneut) - ein
	// zweiter Lauf soll dieselbe Verknüpfung neu anlegen, nicht an einem
	// Unique-Index (nc_user_id) scheitern.
	$existing = $mapper->findByNcUserId($ncUserId);
	if ($existing !== null) {
		$mapper->delete($existing);
	}

	$member = new Member();
	$member->setMemberType(Member::TYPE_PERSON);
	$member->setFirstName($firstName);
	$member->setLastName($lastName);
	$member->setEmail($email);
	$member->setPhone('+49 30 1234567');
	$member->setJoinedAt('2020-01-01');
	$member->setNcUserId($ncUserId);
	// Belegt im e2e-Test, dass internal_note dem Self-Service NIE angezeigt
	// wird (Spec §2.2) - der Text darf auf der Seite nirgends auftauchen.
	$member->setInternalNote('Vereinsinterne Notiz, darf im Self-Service nie sichtbar sein.');
	$member->setCreatedAt((new DateTime())->format(DATE_ATOM));

	$created = $mapper->insert($member);
	echo $created->getId() . "\n";
	exit(0);
}

fwrite(STDERR, "Unbekannte Aktion: '$action' (erwartet: link|unlink)\n");
exit(1);
