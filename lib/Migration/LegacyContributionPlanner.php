<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

/**
 * Plant die Übernahme des bisherigen flachen Beitrags- und SEPA-Moduls
 * (`vbh_sepa_mandates`, `vbh_membership_fees`) in das Mitglieder-Modell
 * (`vbh_mandates`, `vbh_assignments`). Reine Funktionen ohne Datenbank: die
 * Migration {@see Version000158Date20261010000000} liest die Alt-Zeilen, lässt
 * sie hier in Einfügezeilen übersetzen und schreibt sie.
 *
 * **Mandate.** Ein altes Mandat war entweder `active` oder `revoked`, und
 * wiederkehrend (`RCUR`) oder einmalig (`OOFF`). Je Mitglied wird das jüngste
 * aktive Dauermandat `aktiv`; weitere aktive Mandate desselben Mitglieds sind
 * `erloschen (ersetzt)`, widerrufene `erloschen (widerrufen)`, Einmalmandate
 * `erloschen (beendet)`. Das neue Modell kennt je Mitglied nur ein lebendes
 * Mandat. Die alte Mandatsreferenz bleibt erhalten – sie steht auf den
 * Unterschriften der Mitglieder und in den bereits eingezogenen Lastschriften.
 *
 * **Beiträge.** Ein alter Beitrag war ein Betrag je Zahlung mit einer
 * Häufigkeit. Das neue Modell rechnet in Monatsbeträgen mal Turnus; nur wenn
 * sich der Betrag ohne Rest auf Monate verteilen lässt, wird er übernommen.
 * Alles andere (100,00 € jährlich = 8,333… € im Monat) würde stillschweigend
 * eine andere Summe fordern und bleibt deshalb ausdrücklich unübernommen:
 * der bisherige Betrag steht dann in der Notiz der Akte, und eine Aufgabe
 * nennt die Zahl. Die Zuweisung beginnt nie in der Vergangenheit (der
 * Tageslauf holte sonst Perioden nach, die das alte Modul längst abgerechnet
 * hat), sondern im Monat der nächsten Fälligkeit, frühestens im laufenden Monat.
 */
final class LegacyContributionPlanner {

	/** Häufigkeiten des alten Moduls ({@see \OCA\Vereinsbuchhaltung\Service\BillingPeriod::FREQUENCY_MONTHS}). */
	public const FREQUENCY_MONTHS = [
		'monthly' => 1,
		'quarterly' => 3,
		'semiannual' => 6,
		'yearly' => 12,
	];

	/** Turnusse, die die übernommenen Beitragsgruppen erlauben (Teilmenge der gültigen {1,2,3,4,6,12}). */
	public const ALLOWED_INTERVALS = '1,3,6,12';

	/**
	 * @param list<array<string,mixed>> $mandates Zeilen aus vbh_sepa_mandates
	 * @param array<int,string> $holderByMember Mitglieds-ID => Anzeigename (nur bekannte Mitglieder)
	 * @param array<string,bool> $takenReferences schon vergebene Mandatsreferenzen im neuen Modell
	 * @return array{
	 *   inserts: list<array{legacyId:int, row:array<string,?string|int>, message:string}>,
	 *   skipped: list<array{id:int, reason:string}>,
	 *   activeMembers: array<int,true>
	 * }
	 */
	public static function planMandates(array $mandates, array $holderByMember, array $takenReferences): array {
		$byMember = [];
		$skipped = [];
		foreach ($mandates as $mandate) {
			$id = (int)$mandate['id'];
			$memberId = (int)($mandate['member_id'] ?? 0);
			if ($memberId === 0 || !isset($holderByMember[$memberId])) {
				$skipped[] = ['id' => $id, 'reason' => 'Mitglied unbekannt'];
				continue;
			}
			$byMember[$memberId][] = $mandate;
		}

		$inserts = [];
		$activeMembers = [];
		foreach ($byMember as $memberId => $rows) {
			usort($rows, static fn (array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);

			// Das jüngste aktive Dauermandat ist das lebende; ältere aktive sind ersetzt.
			$liveId = null;
			foreach ($rows as $row) {
				if (self::isLiveCandidate($row)) {
					$liveId = (int)$row['id'];
				}
			}

			foreach ($rows as $row) {
				$id = (int)$row['id'];
				$reference = trim((string)($row['mandate_reference'] ?? ''));
				if ($reference === '') {
					$reference = 'ALT-' . $id;
				}
				if (isset($takenReferences[$reference])) {
					$skipped[] = ['id' => $id, 'reason' => 'Referenz schon vorhanden: ' . $reference];
					continue;
				}
				$takenReferences[$reference] = true;

				$created = self::timestamp((string)($row['created_at'] ?? ''));
				$iban = strtoupper(str_replace(' ', '', (string)($row['iban'] ?? '')));
				$bic = trim((string)($row['bic'] ?? ''));
				$signed = trim((string)($row['signed_date'] ?? ''));
				$lastUsed = trim((string)($row['last_used_date'] ?? ''));

				$new = [
					'member_id' => $memberId,
					'mandate_reference' => $reference,
					'iban' => $iban !== '' ? $iban : null,
					'bic' => $bic !== '' ? $bic : null,
					'account_holder' => $holderByMember[$memberId] !== '' ? $holderByMember[$memberId] : '(unbekannt)',
					'signature_type' => 'papier',
					'signed_at' => $signed !== '' ? $signed : null,
					'last_presented_due_date' => $lastUsed !== '' ? $lastUsed : null,
					'created_at' => $created,
					'activated_at' => $created,
				];

				if ($id === $liveId) {
					$new += ['status' => 'aktiv', 'ended_at' => null, 'end_reason' => null];
					$activeMembers[$memberId] = true;
					$what = 'wirksam';
				} elseif (($row['status'] ?? '') === 'revoked') {
					$new += ['status' => 'erloschen', 'ended_at' => $created, 'end_reason' => 'widerrufen'];
					$what = 'widerrufen';
				} elseif (($row['mandate_type'] ?? 'RCUR') === 'OOFF') {
					$new += ['status' => 'erloschen', 'ended_at' => $created, 'end_reason' => 'beendet'];
					$what = 'ein Einmalmandat, beendet';
				} else {
					$new += ['status' => 'erloschen', 'ended_at' => $created, 'end_reason' => 'ersetzt'];
					$what = 'durch ein jüngeres Mandat ersetzt';
				}

				$inserts[] = [
					'legacyId' => $id,
					'row' => $new,
					'message' => sprintf(
						'Aus dem bisherigen Beitragsmodul übernommen (Referenz %s, unterschrieben am %s): %s.',
						$reference,
						$signed !== '' ? self::germanDate($signed) : 'unbekannt',
						$what,
					),
				];
			}
		}

		return ['inserts' => $inserts, 'skipped' => $skipped, 'activeMembers' => $activeMembers];
	}

	/**
	 * @param list<array<string,mixed>> $fees Zeilen aus vbh_membership_fees
	 * @param array<int,string> $knownMembers Mitglieds-ID => Anzeigename
	 * @param array<int,true> $membersWithAssignment Mitglieder, die schon eine Zuweisung haben (keine zweite anlegen)
	 * @param array<int,true> $membersWithLiveMandate Mitglieder mit einem wirksamen Mandat (Zahlart Lastschrift)
	 * @param string $today JJJJ-MM-TT
	 * @param array<int,true> $transferredFeeIds Alt-Beiträge, die ein früherer Lauf schon übernommen hat (still übergangen)
	 * @return array{
	 *   assignments: list<array{legacyId:int, memberId:int, monthlyCents:int, intervalMonths:int, validFrom:string, paymentMethod:string, details:array<string,mixed>}>,
	 *   notTransferred: list<array{legacyId:int, memberId:int, note:string}>,
	 *   inactive: int
	 * }
	 */
	public static function planFees(array $fees, array $knownMembers, array $membersWithAssignment, array $membersWithLiveMandate, string $today, array $transferredFeeIds = []): array {
		$assignments = [];
		$notTransferred = [];
		$inactive = 0;
		$taken = $membersWithAssignment;

		usort($fees, static fn (array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
		foreach ($fees as $fee) {
			$id = (int)$fee['id'];
			if (isset($transferredFeeIds[$id])) {
				continue;
			}
			if (!self::truthy($fee['active'] ?? true)) {
				$inactive++;
				continue;
			}
			$memberId = (int)($fee['member_id'] ?? 0);
			if ($memberId === 0 || !isset($knownMembers[$memberId])) {
				continue;
			}
			$amount = (int)($fee['amount_cents'] ?? 0);
			$frequency = (string)($fee['frequency'] ?? 'monthly');
			$months = self::FREQUENCY_MONTHS[$frequency] ?? null;
			$nextDue = trim((string)($fee['next_due_date'] ?? ''));
			if ($nextDue === '') {
				$nextDue = trim((string)($fee['start_date'] ?? ''));
			}

			if ($months === null || $amount <= 0 || $amount % $months !== 0) {
				$notTransferred[] = [
					'legacyId' => $id,
					'memberId' => $memberId,
					'note' => sprintf(
						'Bisheriger Beitrag (nicht übernommen): %s %s%s – der Betrag lässt sich nicht in gleiche Monatsbeträge teilen.',
						self::euro($amount),
						self::frequencyWord($frequency),
						$nextDue !== '' ? ', nächste Fälligkeit ' . self::germanDate($nextDue) : '',
					),
				];
				continue;
			}
			if (isset($taken[$memberId])) {
				$notTransferred[] = [
					'legacyId' => $id,
					'memberId' => $memberId,
					'note' => sprintf(
						'Bisheriger Beitrag (nicht übernommen, es gibt schon eine Zuweisung): %s %s.',
						self::euro($amount),
						self::frequencyWord($frequency),
					),
				];
				continue;
			}

			$base = $nextDue !== '' && $nextDue > $today ? $nextDue : $today;
			$taken[$memberId] = true;
			$assignments[] = [
				'legacyId' => $id,
				'memberId' => $memberId,
				'monthlyCents' => intdiv($amount, $months),
				'intervalMonths' => $months,
				'validFrom' => substr($base, 0, 7) . '-01',
				'paymentMethod' => isset($membersWithLiveMandate[$memberId]) || !empty($fee['mandate_id']) ? 'direct_debit' : 'ueberweisung',
				'details' => [
					'source' => 'beitragsmodul-alt',
					'legacyFeeId' => $id,
					'legacyAmountCents' => $amount,
					'legacyFrequency' => $frequency,
				],
			];
		}

		return ['assignments' => $assignments, 'notTransferred' => $notTransferred, 'inactive' => $inactive];
	}

	/** Name der Beitragsgruppe zu einem Monatsbetrag („Beitrag 15,00 € im Monat (übernommen)“). */
	public static function groupName(int $monthlyCents): string {
		return sprintf('Beitrag %s im Monat (übernommen)', self::euro($monthlyCents));
	}

	public static function euro(int $cents): string {
		return number_format($cents / 100, 2, ',', '.') . ' €';
	}

	private static function frequencyWord(string $frequency): string {
		return match ($frequency) {
			'monthly' => 'monatlich',
			'quarterly' => 'vierteljährlich',
			'semiannual' => 'halbjährlich',
			'yearly' => 'jährlich',
			default => 'mit unbekannter Häufigkeit „' . $frequency . '“',
		};
	}

	private static function isLiveCandidate(array $row): bool {
		return ($row['status'] ?? '') === 'active' && ($row['mandate_type'] ?? 'RCUR') !== 'OOFF';
	}

	private static function truthy(mixed $value): bool {
		return in_array($value, [true, 1, '1', 't', 'true', 'TRUE'], true);
	}

	/** Alte Zeitstempel („JJJJ-MM-TT HH:MM:SS“ oder ISO) in das Format der neuen Tabellen. */
	private static function timestamp(string $value): string {
		if ($value === '') {
			return (new \DateTime())->format('Y-m-d H:i:s');
		}
		return $value;
	}

	private static function germanDate(string $iso): string {
		return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) === 1 ? $m[3] . '.' . $m[2] . '.' . $m[1] : $iso;
	}
}
