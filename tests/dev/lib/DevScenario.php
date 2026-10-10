<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Dev;

/**
 * Das Testszenario des Beiträge/SEPA-Moduls als reine Daten – Mitglieder,
 * Beitragsgruppen, Zuweisungen und die Fälle des camt.053-Kontoauszugs.
 *
 * Verbindlich sind Namen, Nummern und Zuordnungen: gegen sie wird das
 * manuelle Komplett-Test geschrieben. Der {@see BeitraegeSeeder}
 * setzt sie um, der {@see CamtGenerator} baut die Bankdatei dazu. Die Datei
 * kennt weder Nextcloud noch die Datenbank, damit tests/unit/DevScenarioTest.php
 * sie ohne laufende Instanz auf Widersprüche prüfen kann (gültige IBANs,
 * eindeutige Nummern, Beträge innerhalb der Gruppenregeln).
 *
 * Alle Daten sind auf „heute = 2026-10-05" zugeschnitten: ein eingereichter
 * Lauf zum 01.10., der nächste Lauf zum 01.11.
 *
 * @phpstan-type Bank array{bankCode:string, account:string, bic:?string}
 * @phpstan-type MandateSpec array{
 *     kind:'paper'|'electronic'|'electronic_draft'|'revoked'|'departed',
 *     signedAt:?string, holder:?string, lastPresented:?string,
 * }
 * @phpstan-type AssignmentSpec array{
 *     group:string, interval:int, monthlyCents:int, method:'direct_debit'|'ueberweisung',
 *     validFrom:string, validTo:?string, minOverrideCents:?int, overrideReason:?string,
 * }
 * @phpstan-type MemberSpec array{
 *     number:string, type:'person'|'organisation', firstName:?string, lastName:?string,
 *     organizationName:?string, email:?string, phone:?string,
 *     street:?string, postalCode:?string, city:?string, joinedAt:string, leftAt:?string,
 *     ncUserId:?string, internalNote:?string, bank:?Bank,
 *     mandate:?MandateSpec, assignment:?AssignmentSpec,
 * }
 * @phpstan-type GroupSpec array{
 *     name:string, minCents:int, defaultCents:int, intervals:list<int>, defaultInterval:int,
 * }
 */
final class DevScenario {

	/** Fälligkeit des eingereichten Oktoberlaufs. */
	public const RUN_DUE_DATE = '2026-10-01';
	/** Fälligkeit des nächsten Laufs (Vorschau, noch nicht freigegeben). */
	public const NEXT_DUE_DATE = '2026-11-01';
	/** Freigabe und Einreichung des Oktoberlaufs (D−5, wie es der Vorlauf-Puffer vorsieht). */
	public const RUN_RELEASED_AT = '2026-09-26 09:10:00';
	public const RUN_SUBMITTED_AT = '2026-09-26 09:25:00';
	/** Vorabinfo des Oktoberlaufs (D−14). */
	public const RUN_PRENOTIFIED_AT = '2026-09-17T06:00:00+00:00';
	/** Das Szenario ist auf diesen Tag zugeschnitten. */
	public const SCENARIO_TODAY = '2026-10-05';

	/** Vorabinfo-Vorlauf in Tagen: so groß, dass heute die Vorabinfo zum 01.11. fällig ist (27 Tage bis dahin). */
	public const PRENOTIFICATION_LEAD_DAYS = 30;
	/** Vorwarnfenster in Tagen (nicht kleiner als der Vorabinfo-Vorlauf, sonst stünde die Zeitachse Kopf). */
	public const WARNING_LEAD_DAYS = 35;

	public const CREDITOR_ID = 'DE98ZZZ09999999999';

	/** Nextcloud-Nutzer → Oberflächensprache (nur diese drei werden gesetzt). */
	public const USER_LANGUAGES = [
		'jane' => 'de',      // informell (Du)
		'john' => 'de_DE',   // förmlich (Sie)
		'user1' => 'en',
	];

	/** Nextcloud-Nutzer → App-Rolle. */
	public const ROLES = [
		'alice' => 'buchhalter',
		'bob' => 'revisor',
	];

	/**
	 * Die Rückgaben des Oktoberlaufs: Mitgliedsnummer (als Zahl) → Fall. `bookedAfterDays`
	 * zählt ab Fälligkeit, `chargesCents` ist die Bankgebühr laut Kontoauszug.
	 *
	 * @return array<int, array{code:string, text:string, chargesCents:int, bookedAfterDays:int}>
	 */
	public static function returns(): array {
		return [
			1004 => ['code' => 'AM04', 'text' => 'Insufficient funds', 'chargesCents' => 350, 'bookedAfterDays' => 1],
			1005 => ['code' => 'AC04', 'text' => 'Closed account number', 'chargesCents' => 400, 'bookedAfterDays' => 4],
		];
	}

	/** @return list<GroupSpec> */
	public static function groups(): array {
		return [
			// Der Monatsbeitrag ist das Atom (Spec §3.3): 50,00 €/Jahr lassen sich nicht in ganzen Cent je Monat
			// ausdrücken, die Fördergruppe steht deshalb bei 5,00 € je Monat = 60,00 € im Jahresturnus.
			['name' => 'Vollmitglied', 'minCents' => 1200, 'defaultCents' => 1500, 'intervals' => [1, 3, 6, 12], 'defaultInterval' => 1],
			['name' => 'Ermäßigt', 'minCents' => 500, 'defaultCents' => 750, 'intervals' => [1, 3, 12], 'defaultInterval' => 1],
			['name' => 'Jugend', 'minCents' => 300, 'defaultCents' => 500, 'intervals' => [1, 12], 'defaultInterval' => 1],
			['name' => 'Fördermitglied', 'minCents' => 400, 'defaultCents' => 500, 'intervals' => [12], 'defaultInterval' => 12],
		];
	}

	/**
	 * @param string $bankCode Bankleitzahl (echt)
	 * @param string $account Kontonummer (frei gewählt)
	 * @return Bank
	 */
	private static function bank(string $bankCode, string $account, ?string $bic = null): array {
		return ['bankCode' => $bankCode, 'account' => $account, 'bic' => $bic];
	}

	/**
	 * @param Bank|null $bank
	 * @return string|null IBAN mit korrekter Prüfsumme
	 */
	public static function iban(?array $bank): ?string {
		return $bank === null ? null : IbanFactory::german($bank['bankCode'], $bank['account']);
	}

	/**
	 * @param 'paper'|'electronic'|'electronic_draft'|'revoked'|'departed' $kind
	 * @return MandateSpec
	 */
	private static function mandate(string $kind, ?string $signedAt = null, ?string $holder = null, ?string $lastPresented = null): array {
		return ['kind' => $kind, 'signedAt' => $signedAt, 'holder' => $holder, 'lastPresented' => $lastPresented];
	}

	/**
	 * @param 'direct_debit'|'ueberweisung' $method
	 * @return AssignmentSpec
	 */
	private static function assignment(string $group, int $interval, int $monthlyCents, string $method, string $validFrom, ?string $validTo = null, ?int $minOverrideCents = null, ?string $overrideReason = null): array {
		return [
			'group' => $group, 'interval' => $interval, 'monthlyCents' => $monthlyCents, 'method' => $method,
			'validFrom' => $validFrom, 'validTo' => $validTo,
			'minOverrideCents' => $minOverrideCents, 'overrideReason' => $overrideReason,
		];
	}

	/**
	 * @param Bank|null $bank
	 * @param MandateSpec|null $mandate
	 * @param AssignmentSpec|null $assignment
	 * @return MemberSpec
	 */
	private static function person(string $number, string $firstName, string $lastName, ?string $email, string $joinedAt, ?array $bank, ?array $mandate, ?array $assignment, ?string $street = null, ?string $postalCode = null, ?string $city = null, ?string $ncUserId = null, ?string $leftAt = null, ?string $note = null, ?string $phone = null): array {
		return [
			'number' => $number, 'type' => 'person', 'firstName' => $firstName, 'lastName' => $lastName,
			'organizationName' => null, 'email' => $email, 'phone' => $phone,
			'street' => $street, 'postalCode' => $postalCode, 'city' => $city,
			'joinedAt' => $joinedAt, 'leftAt' => $leftAt, 'ncUserId' => $ncUserId, 'internalNote' => $note,
			'bank' => $bank, 'mandate' => $mandate, 'assignment' => $assignment,
		];
	}

	/**
	 * Die 16 Mitglieder, Mitgliedsnummern 1001 aufsteigend.
	 *
	 * @return list<MemberSpec>
	 */
	public static function members(): array {
		$dd = 'direct_debit';
		$transfer = 'ueberweisung';
		$start = self::RUN_DUE_DATE;

		return [
			// 1001 Jana: elektronisch erteiltes Mandat, Beitragsmodul seit September – September bezahlt, Oktober im Lauf, November offen.
			self::person('1001', 'Jana', 'Hoffmann', 'jana.hoffmann@example.org', '2019-04-01',
				self::bank('12030000', '202051', 'BYLADEM1001'),
				self::mandate('electronic', '2026-08-20'),
				self::assignment('Vollmitglied', 1, 1500, $dd, '2026-09-01'),
				'Lindenallee 12', '50667', 'Köln', 'jane', null, null, '0221 5550101'),
			// 1002 Jonas: elektronischer Entwurf, noch nicht bestätigt – im Self-Service „Jetzt bestätigen".
			self::person('1002', 'Jonas', 'Richter', 'jonas.richter@example.org', '2021-01-15',
				self::bank('37040044', '532013000', 'COBADEFFXXX'),
				self::mandate('electronic_draft'),
				self::assignment('Vollmitglied', 3, 1500, $dd, $start),
				'Birkenweg 7', '50668', 'Köln', 'john', null, 'Mandat per Einmal-Link oder im Self-Service bestätigen lassen.'),
			// 1003 Lena: Überweiserin, quartalsweise ermäßigt – Zahlungseingang im Bankabgleich (22,50 €).
			self::person('1003', 'Lena', 'Bergmann', 'lena.bergmann@example.org', '2022-09-01',
				self::bank('50010517', '5407324931', 'INGDDEFFXXX'),
				null,
				self::assignment('Ermäßigt', 3, 750, $transfer, $start),
				null, null, null, 'user1', null, 'Studentin, keine Anschrift hinterlegt (Banner in der Beitragsbestätigung).'),
			// 1004 Markus: Papier-Mandat, im Oktoberlauf, Rückgabe AM04 (Deckung fehlt).
			self::person('1004', 'Markus', 'Fuchs', 'markus.fuchs@example.org', '2020-02-01',
				self::bank('50010517', '137075030', 'INGDDEFFXXX'),
				self::mandate('paper', '2024-02-10'),
				self::assignment('Vollmitglied', 1, 1500, $dd, $start),
				'Am Mühlenteich 3', '50670', 'Köln'),
			// 1005 Sophie: jährlich (Eintritt 01.10., anteilig 3 Monate), Rückgabe AC04 (Konto aufgelöst).
			self::person('1005', 'Sophie', 'Krüger', 'sophie.krueger@example.org', $start,
				self::bank('10010010', '987654321', 'PBNKDEFFXXX'),
				self::mandate('paper', '2026-09-20'),
				self::assignment('Vollmitglied', 12, 1500, $dd, $start),
				'Rosenstraße 21', '50672', 'Köln'),
			// 1006 Tobias: Mandat widerrufen, zahlt per Überweisung.
			self::person('1006', 'Tobias', 'Brandt', 'tobias.brandt@example.org', '2018-06-01',
				self::bank('70070010', '123456700', 'DEUTDEMMXXX'),
				self::mandate('revoked', '2025-05-12'),
				self::assignment('Vollmitglied', 1, 1500, $transfer, $start),
				'Kastanienallee 9', '50674', 'Köln'),
			// 1007 Nadine: Mandat zuletzt vor 34 Monaten vorgelegt – Verfall in 57 Tagen; ein eingereichter Einzug setzt die Frist neu in Gang.
			self::person('1007', 'Nadine', 'Schuster', 'nadine.schuster@example.org', '2019-11-01',
				self::bank('76026000', '34567890', 'HYVEDEMM473'),
				self::mandate('paper', '2023-11-20', null, '2023-12-01'),
				null,
				'Schillerstraße 5', '50676', 'Köln'),
			// 1008 Mara: Jugend, Kontoinhaberin ist die Mutter (Test Kontoinhaberwechsel).
			self::person('1008', 'Mara', 'Lindner', 'mara.lindner@example.org', '2024-09-01',
				self::bank('30020900', '765432100'),
				self::mandate('paper', '2026-02-15', 'Petra Lindner'),
				self::assignment('Jugend', 1, 500, $dd, $start),
				'Gartenstraße 18', '50677', 'Köln', null, null, 'Kontoinhaberin ist die Mutter (Petra Lindner).'),
			// 1009 Musikhaus Schmidt GmbH: Organisation, Fördermitglied, Überweisung – Jahresbeitrag 2026 bezahlt.
			[
				'number' => '1009', 'type' => 'organisation', 'firstName' => null, 'lastName' => null,
				'organizationName' => 'Musikhaus Schmidt GmbH', 'email' => 'info@musikhaus-schmidt.example', 'phone' => '0221 5550909',
				'street' => 'Hohe Straße 44', 'postalCode' => '50667', 'city' => 'Köln',
				'joinedAt' => '2023-01-01', 'leftAt' => null, 'ncUserId' => null, 'internalNote' => 'Ansprechpartner: Herr Schmidt.',
				'bank' => null, 'mandate' => null,
				'assignment' => self::assignment('Fördermitglied', 12, 500, $transfer, '2026-01-01'),
			],
			// 1010 Hans: seit 2013 ausgetreten, ohne E-Mail – Kandidat der Anonymisierung (nur mit --with-anonymization-booking).
			self::person('1010', 'Hans', 'Becker', null, '2008-05-01',
				self::bank('25050180', '12345678', 'SPKHDE2HXXX'),
				self::mandate('departed', '2013-01-10'),
				self::assignment('Vollmitglied', 12, 1200, $dd, '2008-01-01', '2013-12-31'),
				'Fichtenweg 2', '30159', 'Hannover', null, '2013-12-31'),
			// 1011–1016: gemischte Gruppen, Mandate aktiv – der Oktoberlauf hat damit zehn Posten.
			self::person('1011', 'Anna', 'Koch', 'anna.koch@example.org', '2017-03-01',
				self::bank('20070000', '456123789'),
				self::mandate('paper', '2025-03-01'),
				self::assignment('Vollmitglied', 1, 1500, $dd, $start, null, 1000, 'Familienrabatt laut Vorstandsbeschluss'),
				'Eichenstraße 14', '50679', 'Köln'),
			self::person('1012', 'Bernd', 'Neumann', 'bernd.neumann@example.org', '2016-01-01',
				self::bank('60050101', '3210987', 'SOLADEST600'),
				self::mandate('paper', '2025-04-14'),
				self::assignment('Vollmitglied', 1, 1500, $dd, $start),
				'Ahornweg 6', '50733', 'Köln'),
			self::person('1013', 'Clara', 'Vogel', 'clara.vogel@example.org', '2022-04-01',
				self::bank('66090800', '212345678', 'GENODE61BBB'),
				self::mandate('electronic', '2026-05-03'),
				self::assignment('Ermäßigt', 1, 750, $dd, $start),
				'Ulmenstraße 30', '50735', 'Köln'),
			self::person('1014', 'David', 'Wolf', 'david.wolf@example.org', '2024-01-01',
				self::bank('10090000', '445566778', 'BEVODEBB'),
				self::mandate('paper', '2025-09-01'),
				self::assignment('Jugend', 1, 500, $dd, $start),
				'Pappelallee 8', '50737', 'Köln'),
			self::person('1015', 'Eva', 'Schröder', 'eva.schroeder@example.org', '2015-10-01',
				self::bank('50040000', '599887766', 'COBADEFFXXX'),
				self::mandate('paper', '2025-06-30'),
				self::assignment('Vollmitglied', 1, 1500, $dd, $start),
				'Weidenweg 11', '50739', 'Köln'),
			// 1016 Felix: Austritt zum 31.12.2026 (künftig) – die Zuweisung läuft bis dahin weiter.
			self::person('1016', 'Felix', 'Maier', 'felix.maier@example.org', '2021-07-01',
				self::bank('70150000', '100200300', 'SSKMDEMMXXX'),
				self::mandate('paper', '2025-07-15'),
				self::assignment('Ermäßigt', 1, 750, $dd, $start),
				'Buchenstraße 3', '50769', 'Köln', null, '2026-12-31'),
		];
	}

	/**
	 * Gewöhnliche Umsätze neben dem Einzug, mit Buchungstag in Tagen ab Fälligkeit
	 * des Oktoberlaufs. `kind` ist `transfer` (Zahlungseingang einer Überweiserin),
	 * `donation` oder `fee`.
	 *
	 * @return list<array{kind:string, afterDays:int, direction:'CRDT'|'DBIT', amountCents:int, counterparty:?string, bank:?Bank, purpose:string, bookingText:string, memberNumber:?string}>
	 */
	public static function plainBankEntries(): array {
		return [
			// Lena Bergmann zahlt ihr erstes Quartal per Überweisung: der Betrag passt eindeutig auf ihre Forderung.
			['kind' => 'transfer', 'afterDays' => 1, 'direction' => 'CRDT', 'amountCents' => 2250, 'counterparty' => 'Lena Bergmann',
				'bank' => ['bankCode' => '50010517', 'account' => '5407324931', 'bic' => null],
				'purpose' => 'Mitgliedsbeitrag 4. Quartal 2026 Lena Bergmann', 'bookingText' => 'ÜBERWEISUNGSGUTSCHRIFT', 'memberNumber' => '1003'],
			// Eine Spende: kein Betrag einer Forderung, also kein Vorschlag.
			['kind' => 'donation', 'afterDays' => 1, 'direction' => 'CRDT', 'amountCents' => 5000, 'counterparty' => 'Familie Winter',
				'bank' => ['bankCode' => '37040044', 'account' => '987654321', 'bic' => null],
				'purpose' => 'Spende Herbstkonzert 2026', 'bookingText' => 'ÜBERWEISUNGSGUTSCHRIFT', 'memberNumber' => null],
			// Kontoführungsentgelt: unabhängige Belastung ohne Mitgliedsbezug.
			['kind' => 'fee', 'afterDays' => 4, 'direction' => 'DBIT', 'amountCents' => 790, 'counterparty' => null,
				'bank' => null,
				'purpose' => 'Kontoführungsentgelt Oktober 2026', 'bookingText' => 'ENTGELTABSCHLUSS', 'memberNumber' => null],
		];
	}
}
