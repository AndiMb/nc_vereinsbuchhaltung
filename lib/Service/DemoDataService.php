<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCP\IConfig;

/**
 * Legt einen kleinen Beispielverein (Chor) an, damit Neu-User die App vor
 * der echten Ersteinrichtung gefahrlos ausprobieren können. Nutzt bewusst
 * die bestehenden Services (Standard-Kontenrahmen, normale Buchungssätze,
 * Eröffnungssaldo-Mechanik) statt eigener Buchungslogik.
 */
class DemoDataService {

	public function __construct(
		private AccountMapper $accountMapper,
		private AccountService $accountService,
		private OpeningBalanceService $openingService,
		private JournalService $journalService,
		private BankTransactionMapper $txMapper,
		private IConfig $config,
		private TransactionRunner $transaction,
	) {
	}

	public function isEmpty(string $userId): bool {
		return $this->accountMapper->countForUser($userId) === 0;
	}

	/**
	 * @return array{accounts:int, bookings:int, openTransactions:int}
	 */
	public function seed(string $userId): array {
		// Ganz oder gar nicht: ein halb angelegter Beispielverein wäre schlimmer
		// als gar keiner – seed() verweigert danach den Dienst („bereits Konten
		// vorhanden"), und Zurücksetzen wäre der einzige Ausweg.
		return $this->transaction->run(fn (): array => $this->doSeed($userId));
	}

	/**
	 * @return array{accounts:int, bookings:int, openTransactions:int}
	 */
	private function doSeed(string $userId): array {
		if (!$this->isEmpty($userId)) {
			throw new \RuntimeException('Es sind bereits Konten vorhanden – Beispieldaten lassen sich nur in einem leeren Verein anlegen.');
		}

		$accounts = $this->accountService->seedDefaults($userId);
		$byNumber = [];
		foreach ($accounts as $account) {
			$byNumber[$account->getNumber()] = $account;
		}
		$bank = $byNumber['1200'];
		$kasse = $byNumber['1000'];

		// Anfangsbestand vor drei Monaten, damit die laufenden Buchungen danach liegen.
		$openingDate = (new \DateTime('first day of -3 months'))->format('Y-m-d');
		$this->accountService->setOpeningFields($bank->getId(), $userId, 250000, $openingDate);
		$this->openingService->sync($this->accountMapper->find($bank->getId(), $userId));

		$rel = fn (string $offset): string => (new \DateTime($offset))->format('Y-m-d');

		// [Beschreibung, Soll-Konto, Haben-Konto, Betrag in Cent, Datum]
		$bookings = [
			['[Beispiel] Mitgliedsbeiträge Sammeleinzug Q1', $bank, $byNumber['4000'], 85000, $rel('-11 weeks')],
			['[Beispiel] Raummiete Probenraum', $byNumber['5000'], $bank, 12000, $rel('-10 weeks')],
			['[Beispiel] Spende Sommerkonzert', $bank, $byNumber['4100'], 15000, $rel('-9 weeks')],
			['[Beispiel] Notenmaterial', $byNumber['5300'], $bank, 4590, $rel('-9 weeks')],
			['[Beispiel] Versicherung Vereinshaftpflicht', $byNumber['5100'], $bank, 8900, $rel('-8 weeks')],
			['[Beispiel] Getränkeverkauf bei der Probe', $kasse, $byNumber['4900'], 3250, $rel('-7 weeks')],
			['[Beispiel] Bankgebühren', $byNumber['5400'], $bank, 490, $rel('-6 weeks')],
			['[Beispiel] Zuschuss Stadt', $bank, $byNumber['4200'], 50000, $rel('-6 weeks')],
			['[Beispiel] Eintritt Sommerkonzert', $bank, $byNumber['4300'], 31000, $rel('-5 weeks')],
			['[Beispiel] Plakate und Programmdruck', $byNumber['5200'], $bank, 6500, $rel('-5 weeks')],
			['[Beispiel] Raummiete Probenraum', $byNumber['5000'], $bank, 12000, $rel('-4 weeks')],
			['[Beispiel] Mitgliedsbeitrag Nachzahlung', $bank, $byNumber['4000'], 2500, $rel('-3 weeks')],
			['[Beispiel] Blumen für die Dirigentin', $kasse, $byNumber['5900'], 1850, $rel('-2 weeks')],
			['[Beispiel] Raummiete Probenraum', $byNumber['5000'], $bank, 12000, $rel('-1 week')],
		];
		foreach ($bookings as [$desc, $debit, $credit, $cents, $date]) {
			$this->journalService->createBooking($userId, $date, $desc, null, $debit->getId(), $credit->getId(), $cents, null, false);
		}

		// Offene Bankbuchungen zum Ausprobieren des Zuordnens (Tab "Zuzuordnen").
		$open = [
			['[Beispiel] TELEKOM DEUTSCHLAND GMBH', -3500, $rel('-4 days')],
			['[Beispiel] Anna Beispiel Mitgliedsbeitrag', 2500, $rel('-2 days')],
			['[Beispiel] Getränkemarkt Meier', -1875, $rel('-1 day')],
		];
		foreach ($open as $i => [$purpose, $cents, $date]) {
			$tx = new BankTransaction();
			$tx->setUserId($userId);
			$tx->setBookingDate($date);
			$tx->setAmountCents($cents);
			$tx->setCurrency('EUR');
			$tx->setBookingText('Beispielbuchung');
			$tx->setPurpose($purpose);
			$tx->setCounterparty($purpose);
			$tx->setStatus('unassigned');
			$tx->setHash(hash('sha256', 'demo|' . $userId . '|' . $i . '|' . $date . '|' . $cents));
			$this->txMapper->insert($tx);
		}

		$this->config->setAppValue(Application::APP_ID, 'demo_seeded', '1');

		return [
			'accounts' => count($accounts),
			'bookings' => count($bookings),
			'openTransactions' => count($open),
		];
	}

	public function isActive(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'demo_seeded', '') === '1';
	}

	public function clearFlag(): void {
		$this->config->deleteAppValue(Application::APP_ID, 'demo_seeded');
	}
}
