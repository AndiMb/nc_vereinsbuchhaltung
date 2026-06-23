<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;

/**
 * Importiert eine „zero Buchhaltung"-Datei (.xbuc): Kontenbaum + Buchungen.
 */
class XbucImportService {

	/** Feste Kostenstellen, die immer vorhanden sein sollen. */
	private const BUILTIN_COST_CENTERS = ['01' => 'Ideeller Bereich', '11' => 'Verbandszeitung'];

	public function __construct(
		private XbucParser $parser,
		private AccountMapper $accountMapper,
		private CostCenterMapper $costCenterMapper,
		private JournalService $journalService,
		private ResetService $resetService,
	) {
	}

	/**
	 * Analysiert die Datei ohne zu speichern.
	 *
	 * @return array{accounts:int, bookings:int}
	 */
	public function preview(string $content): array {
		$data = $this->parser->parse($content);
		return [
			'accounts' => count($data['accounts']),
			'bookings' => count($data['bookings']),
		];
	}

	/**
	 * Importiert Konten und Buchungen.
	 *
	 * @return array{accounts:int, bookings:int, reset:bool}
	 */
	public function import(string $userId, string $content, bool $reset = true): array {
		$data = $this->parser->parse($content);

		if ($reset) {
			$this->resetService->resetAll($userId);
		}

		// --- Kostenstellen (feste + aus Klassifizierung) ---
		foreach (self::BUILTIN_COST_CENTERS as $code => $name) {
			$this->costCenterMapper->upsert($userId, $code, $name);
		}
		foreach ($data['costCenters'] as $cc) {
			$this->costCenterMapper->upsert($userId, $cc['code'], $cc['name']);
		}

		// --- Konten (Eltern zuerst – Reihenfolge des Parsers) ---
		$byNumber = [];
		foreach ($data['accounts'] as $a) {
			$number = $a['number'];
			$existing = $this->accountMapper->findByNumber($userId, $number);
			if ($existing !== null) {
				$byNumber[$number] = $existing->getId();
				continue;
			}
			$account = new Account();
			$account->setUserId($userId);
			$account->setNumber($number);
			$account->setName($a['name']);
			$account->setType($a['type']);
			$account->setCategory($a['category']);
			$account->setIsBank((bool)$a['isBank']);
			$account->setActive(true);
			$parentNumber = $a['parentNumber'];
			if ($parentNumber !== null && isset($byNumber[$parentNumber])) {
				$account->setParentId($byNumber[$parentNumber]);
			}
			$account = $this->accountMapper->insert($account);
			$byNumber[$number] = $account->getId();
		}

		// --- Buchungen (nach Quell-ID sortiert) ---
		$entryNo = 0;
		$count = 0;
		foreach ($data['bookings'] as $b) {
			$entryNo++;
			$debitId = $this->ensureAccount($userId, $b['sollNumber'], $b['sollName'], $byNumber);
			$creditId = $this->ensureAccount($userId, $b['habenNumber'], $b['habenName'], $byNumber);
			$this->journalService->createBooking(
				$userId,
				$b['date'],
				$b['text'] !== '' ? $b['text'] : 'Buchung',
				$b['docRef'] !== '' ? $b['docRef'] : null,
				$debitId,
				$creditId,
				$b['amountCents'],
				$entryNo,
			);
			$count++;
		}

		return ['accounts' => count($byNumber), 'bookings' => $count, 'reset' => $reset];
	}

	/**
	 * Stellt sicher, dass ein Konto mit der Nummer existiert (legt es sonst an).
	 *
	 * @param array<string,int> $byNumber
	 */
	private function ensureAccount(string $userId, string $number, string $name, array &$byNumber): int {
		if (isset($byNumber[$number])) {
			return $byNumber[$number];
		}
		$account = new Account();
		$account->setUserId($userId);
		$account->setNumber(mb_substr($number, 0, 20));
		$account->setName(mb_substr($name !== '' ? $name : ('Konto ' . $number), 0, 255));
		$account->setType($this->guessType($number));
		$account->setCategory('Importiert');
		$account->setIsBank(in_array($number, ['001', '002'], true));
		$account->setActive(true);
		$account = $this->accountMapper->insert($account);
		$byNumber[$number] = $account->getId();
		return $account->getId();
	}

	private function guessType(string $number): string {
		$first = $number[0] ?? '';
		if ($number === '000') {
			return 'equity';
		}
		if ($first === '0' || $number === '999') {
			return 'asset';
		}
		if (in_array($first, ['1', '2', '3'], true)) {
			return 'income';
		}
		return 'expense';
	}
}
