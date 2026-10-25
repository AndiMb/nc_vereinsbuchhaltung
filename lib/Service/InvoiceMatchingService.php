<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\Invoice\CreditorReference;
use OCP\IL10N;

/**
 * Erkennt, zu welcher offenen Rechnung ein Zahlungseingang gehört (Epic #168).
 *
 * Vier Stufen, stärkste zuerst:
 *  0. **RF-Referenz** (ISO 11649) im Verwendungszweck: sie enthält die Nummer samt
 *     Prüfziffern und ist daher eindeutig. Die App druckt sie auf die Rechnung und
 *     in den GiroCode; sie wird auch erkannt, wenn die Einstellung später abgeschaltet wird.
 *  1. **Rechnungsnummer** im Verwendungszweck. Die Nummer wird ohne
 *     Trennzeichen und Groß-/Kleinschreibung verglichen, denn Banken und
 *     Zahler schreiben `RE-2026-0042`, `RE 2026 0042` oder `RE20260042`.
 *  2. **Betrag und Name**: der Betrag entspricht genau dem offenen Rest einer
 *     Rechnung, und der Name des Zahlers deckt sich mit dem Empfänger.
 *  3. **Nur der Betrag**: genau eine offene Rechnung hat genau diesen Rest.
 *
 * Nur die Stufen 0 und 1 mit passendem Betrag ist so sicher, dass der Import sie
 * unbeaufsichtigt buchen darf ({@see autoMatch()}). Alles andere bleibt ein
 * Vorschlag, den ein Mensch in „Zuzuordnen" bestätigt.
 */
class InvoiceMatchingService {

	public const REASON_REFERENCE = 'reference';
	public const REASON_NUMBER = 'number';
	public const REASON_AMOUNT_NAME = 'amount_name';
	public const REASON_AMOUNT = 'amount';

	/** Rechtsformen und Füllwörter, die beim Namensvergleich nichts unterscheiden. */
	private const NAME_STOPWORDS = ['gmbh', 'mbh', 'ag', 'kg', 'ug', 'ev', 'e', 'v', 'co', 'und', 'the', 'herr', 'frau', 'dr', 'verein'];

	public function __construct(
		private OpenItemMapper $openItems,
		private InvoiceMapper $invoices,
		private ?IL10N $l10n = null,
	) {
	}

	/**
	 * Vorschläge für mehrere Umsätze auf einmal. Die offenen Rechnungen werden
	 * nur einmal geladen.
	 *
	 * @param list<BankTransaction> $txs
	 * @return array<int, list<array{invoiceId: int, number: string, debtorName: string, remainingCents: int, reason: string, text: string, exact: bool, overpay: bool}>> Umsatz-ID => Vorschläge, stärkster zuerst; nur Umsätze mit Treffer
	 */
	public function suggestForMany(array $txs): array {
		$candidates = $this->candidates();
		if ($candidates === []) {
			return [];
		}
		$result = [];
		foreach ($txs as $tx) {
			if ($tx->getAmountCents() <= 0) {
				continue; // nur Geldeingänge
			}
			$suggestions = $this->matchOne($tx, $candidates);
			if ($suggestions !== []) {
				$result[(int)$tx->getId()] = $suggestions;
			}
		}
		return $result;
	}

	/**
	 * Der eine Treffer, den der Import ohne Rückfrage buchen darf: die
	 * Rechnungsnummer steht im Verwendungszweck, genau eine Rechnung passt, und
	 * der Betrag ist genau der offene Rest.
	 *
	 * @return array{invoiceId: int, number: string}|null
	 */
	public function autoMatch(BankTransaction $tx): ?array {
		if ($tx->getAmountCents() <= 0) {
			return null;
		}
		$byNumber = array_filter(
			$this->matchOne($tx, $this->candidates()),
			static fn (array $s): bool => $s['reason'] === self::REASON_NUMBER || $s['reason'] === self::REASON_REFERENCE,
		);
		if (count($byNumber) !== 1) {
			return null;
		}
		$match = reset($byNumber);
		return $match['exact'] ? ['invoiceId' => $match['invoiceId'], 'number' => $match['number']] : null;
	}

	/**
	 * Offene Rechnungen mit Nummer, Rest und Empfängername.
	 *
	 * @return list<array{invoiceId: int, number: string, normalized: string, reference: string, debtorName: string, remainingCents: int}>
	 */
	private function candidates(): array {
		$items = $this->openItems->findOpenInvoiceItems();
		$items = array_values(array_filter($items, static fn (OpenItem $i): bool => $i->remainingCents() > 0 && $i->getInvoiceId() !== null));
		if ($items === []) {
			return [];
		}
		$invoices = $this->invoices->findByIds(array_map(static fn (OpenItem $i): int => (int)$i->getInvoiceId(), $items));
		$out = [];
		foreach ($items as $item) {
			$invoice = $invoices[(int)$item->getInvoiceId()] ?? null;
			if ($invoice === null || $invoice->getNumber() === null || $invoice->isCredit()
				|| !in_array($invoice->getStatus(), [Invoice::STATUS_ISSUED, Invoice::STATUS_SENT], true)) {
				continue;
			}
			$out[] = [
				'invoiceId' => (int)$invoice->getId(),
				'number' => $invoice->getNumber(),
				'normalized' => self::normalize($invoice->getNumber()),
				'reference' => CreditorReference::fromInvoiceNumber($invoice->getNumber()) ?? '',
				'debtorName' => (string)($invoice->debtorSnapshotData()['name'] ?? $item->getDebtor()),
				'remainingCents' => $item->remainingCents(),
			];
		}
		return $out;
	}

	/**
	 * @param list<array{invoiceId: int, number: string, normalized: string, reference: string, debtorName: string, remainingCents: int}> $candidates
	 * @return list<array{invoiceId: int, number: string, debtorName: string, remainingCents: int, reason: string, text: string, exact: bool, overpay: bool}>
	 */
	private function matchOne(BankTransaction $tx, array $candidates): array {
		$amount = $tx->getAmountCents();
		$purpose = self::normalize(($tx->getPurpose() ?? '') . ' ' . ($tx->getBookingText() ?? ''));
		$counterparty = (string)$tx->getCounterparty();
		$found = [];

		foreach ($candidates as $c) {
			if ($c['reference'] !== '' && str_contains($purpose, $c['reference'])) {
				$found[] = $this->suggestion($c, self::REASON_REFERENCE, $amount);
			}
		}
		if ($found !== []) {
			return $found;
		}

		foreach ($candidates as $c) {
			if ($c['normalized'] !== '' && self::containsNumber($purpose, $c['normalized'])) {
				$found[] = $this->suggestion($c, self::REASON_NUMBER, $amount);
			}
		}
		if ($found !== []) {
			return $found;
		}

		$byAmount = array_values(array_filter($candidates, static fn (array $c): bool => $c['remainingCents'] === $amount));
		foreach ($byAmount as $c) {
			if (self::namesMatch($counterparty, $c['debtorName'])) {
				$found[] = $this->suggestion($c, self::REASON_AMOUNT_NAME, $amount);
			}
		}
		if ($found !== []) {
			return $found;
		}
		if (count($byAmount) === 1) {
			return [$this->suggestion($byAmount[0], self::REASON_AMOUNT, $amount)];
		}
		return [];
	}

	/**
	 * @param array{invoiceId: int, number: string, normalized: string, reference: string, debtorName: string, remainingCents: int} $c
	 * @return array{invoiceId: int, number: string, debtorName: string, remainingCents: int, reason: string, text: string, exact: bool, overpay: bool}
	 */
	private function suggestion(array $c, string $reason, int $amount): array {
		$fmt = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.');
		$text = match ($reason) {
			self::REASON_REFERENCE => $this->msg('Die Zahlungsreferenz der Rechnung %1$s steht im Verwendungszweck.', [$c['number']]),
			self::REASON_NUMBER => $this->msg('Die Rechnungsnummer %1$s steht im Verwendungszweck.', [$c['number']]),
			self::REASON_AMOUNT_NAME => $this->msg('Der Betrag %1$s € passt zur Rechnung %2$s, und der Name stimmt überein.', [$fmt($amount), $c['number']]),
			default => $this->msg('Der Betrag %1$s € passt zur offenen Rechnung %2$s.', [$fmt($amount), $c['number']]),
		};
		return [
			'invoiceId' => $c['invoiceId'],
			'number' => $c['number'],
			'debtorName' => $c['debtorName'],
			'remainingCents' => $c['remainingCents'],
			'reason' => $reason,
			'text' => $text,
			'exact' => $amount === $c['remainingCents'],
			'overpay' => $amount > $c['remainingCents'],
		];
	}

	/** Buchstaben und Ziffern in Großschrift, alles andere fällt weg. */
	public static function normalize(string $text): string {
		return strtoupper((string)preg_replace('/[^\p{L}\p{N}]+/u', '', $text));
	}

	/** Die Nummer steht im Text und wird nicht von weiteren Ziffern fortgesetzt (Nummer 42 ist nicht Nummer 420). */
	private static function containsNumber(string $normalizedText, string $normalizedNumber): bool {
		$offset = 0;
		while (($pos = strpos($normalizedText, $normalizedNumber, $offset)) !== false) {
			$next = $normalizedText[$pos + strlen($normalizedNumber)] ?? '';
			if ($next === '' || !ctype_digit($next)) {
				return true;
			}
			$offset = $pos + 1;
		}
		return false;
	}

	/**
	 * Decken sich Zahlername und Empfänger? Alle bedeutsamen Wörter des
	 * kürzeren Namens kommen im längeren vor; Rechtsformen zählen nicht.
	 */
	public static function namesMatch(string $a, string $b): bool {
		$ta = self::nameTokens($a);
		$tb = self::nameTokens($b);
		if ($ta === [] || $tb === []) {
			return false;
		}
		[$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];
		return array_diff($short, $long) === [];
	}

	/** @return list<string> */
	private static function nameTokens(string $name): array {
		$name = strtr(mb_strtolower($name), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
		$words = preg_split('/[^a-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$words = array_filter($words, static fn (string $w): bool => !in_array($w, self::NAME_STOPWORDS, true) && strlen($w) >= 2);
		return array_values(array_unique($words));
	}

	/** @param list<int|string> $params */
	private function msg(string $text, array $params = []): string {
		return $this->l10n !== null ? $this->l10n->t($text, $params) : vsprintf($text, $params);
	}
}
