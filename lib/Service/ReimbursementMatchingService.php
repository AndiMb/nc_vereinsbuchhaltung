<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCA\Vereinsbuchhaltung\Db\ReimbursementMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Erkennt, zu welcher freigegebenen Auslage ein Kontoumsatz (Abgang) gehört.
 *
 * Zwei Stufen, stärkste zuerst:
 *  1. **Auslagennummer** im Verwendungszweck (`AUSL-2026-0012`), vollständig und nicht
 *     als Anfang einer längeren Zahl. Sie wird ohne Trennzeichen und Groß-/Kleinschreibung verglichen, denn Banken kürzen und
 *     schreiben um. Stimmt der Betrag nicht, bleibt es ein Hinweis (`exact` false).
 *  2. **IBAN und Betrag**: der Empfänger ist die IBAN des Antrags, der Betrag
 *     stimmt genau. So findet sich auch eine Überweisung ohne Nummer im Text.
 *
 * Gebucht wird nie von selbst: das Aufwandskonto kennt nur, wer die Auslage
 * freigibt. Die Treffer sind Vorschläge für „Zuzuordnen“; alles Übrige ordnet
 * der Buchende dort von Hand einer Auslage zu.
 */
class ReimbursementMatchingService {

	public const REASON_NUMBER = 'number';
	public const REASON_IBAN_AMOUNT = 'iban_amount';

	/** @var array<int, string> */
	private array $memberNames = [];

	public function __construct(
		private ReimbursementMapper $reimbursements,
		private MemberMapper $members,
	) {
	}

	/**
	 * @param list<BankTransaction> $txs
	 * @param list<Reimbursement>|null $candidates die freigegebenen Auslagen, wenn der Aufrufer sie schon hat
	 * @return array<int, list<array{reimbursementId: int, number: ?string, memberName: string, amountCents: int, reason: string, exact: bool}>> Umsatz-ID => Vorschläge; nur Umsätze mit Treffer
	 */
	public function suggestForMany(array $txs, ?array $candidates = null): array {
		$outflows = array_values(array_filter($txs, static fn (BankTransaction $tx): bool => $tx->getAmountCents() < 0));
		if ($outflows === []) {
			return [];
		}
		$candidates ??= $this->reimbursements->findApproved();
		if ($candidates === []) {
			return [];
		}

		$result = [];
		foreach ($outflows as $tx) {
			$haystack = self::normalize((string)$tx->getPurpose());
			$iban = self::normalize((string)$tx->getCounterpartyIban());
			foreach ($candidates as $reimbursement) {
				$exact = abs($tx->getAmountCents()) === $reimbursement->getAmountCents();
				$number = $reimbursement->getNumber();
				if ($number !== null && self::containsNumber($haystack, $number)) {
					$reason = self::REASON_NUMBER;
				} elseif ($exact && $iban !== '' && $iban === self::normalize($reimbursement->getIban())) {
					$reason = self::REASON_IBAN_AMOUNT;
				} else {
					continue;
				}
				$result[(int)$tx->getId()][] = [
					'reimbursementId' => $reimbursement->getId(),
					'number' => $number,
					'memberName' => $this->memberName($reimbursement),
					'amountCents' => $reimbursement->getAmountCents(),
					'reason' => $reason,
					'exact' => $exact,
				];
			}
		}
		return $result;
	}

	/** Die Nummer steht vollständig im Text: `AUSL-2026-0012` steckt nicht in `AUSL-2026-00123`. */
	private static function containsNumber(string $normalizedText, string $number): bool {
		return preg_match('/' . preg_quote(self::normalize($number), '/') . '(?!\d)/', $normalizedText) === 1;
	}

	/** Nur Buchstaben und Ziffern, groß: `AUSL-2026-0012` und `ausl 2026 0012` sind gleich. */
	public static function normalize(string $text): string {
		return strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '', $text));
	}

	private function memberName(Reimbursement $reimbursement): string {
		$id = $reimbursement->getMemberId();
		if (!isset($this->memberNames[$id])) {
			try {
				$this->memberNames[$id] = $this->members->find($id)->displayName();
			} catch (DoesNotExistException) {
				$this->memberNames[$id] = '';
			}
		}
		return $this->memberNames[$id];
	}
}
