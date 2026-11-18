<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\DonationReceipt;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Debtor;
use OCA\Vereinsbuchhaltung\Db\DebtorMapper;
use OCA\Vereinsbuchhaltung\Db\Donation;
use OCA\Vereinsbuchhaltung\Db\DonationMapper;
use OCA\Vereinsbuchhaltung\Db\DonationReceipt;
use OCA\Vereinsbuchhaltung\Db\DonationReceiptMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\NumberRange;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\ClubClock;
use OCA\Vereinsbuchhaltung\Service\NumberRangeService;
use OCA\Vereinsbuchhaltung\Service\SafeFileName;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Zuwendungsbestätigungen (Issue #10): Mitgliedsbeiträge (Teil A) und
 * Geldspenden (Teil B).
 *
 * Zuwendender ist ein Mitglied oder ein Debitor (externer Spender). Je
 * Zuwendendem und abgeschlossenem Kalenderjahr gibt es eine Sammelbestätigung
 * über die in diesem Jahr gezahlten Beiträge – nur, wenn Beiträge abziehbar
 * sind ({@see DonationReceiptSettings::feesDeductible()}) – und die
 * zugeordneten Spenden. Einzelne Spenden lassen sich außerdem sofort einzeln
 * bestätigen; was eine gültige Bestätigung schon enthält, nimmt keine weitere
 * auf.
 *
 * Anders als die informelle Beitragsbestätigung
 * ({@see \OCA\Vereinsbuchhaltung\Service\Export\BeitragsbescheinigungRenderer})
 * zählt nicht die Fälligkeitsperiode, sondern der Tag der Zahlung – das
 * amtliche Muster fragt nach dem „Datum der Zuwendung“, und die Zuwendung ist
 * im Jahr der Zahlung abziehbar (§ 11 Abs. 2 EStG). Bei Beiträgen ist das das
 * Datum der Buchung, die ihn beglichen hat (ohne Buchung der Tag der
 * Markierung), bei Spenden das Datum der Spendenbuchung.
 *
 * Eine ausgestellte Bestätigung ist das Doppel nach § 50 Abs. 7 EStDV: Datensatz
 * und PDF bleiben unverändert. Eine fehlerhafte wird storniert (mit Grund) und
 * bei Bedarf neu ausgestellt.
 */
class DonationReceiptService {

	public function __construct(
		private DonationReceiptMapper $receipts,
		private DonationReceiptStorage $storage,
		private DonationReceiptSettings $settings,
		private DonationReceiptPdfRenderer $renderer,
		private MemberMapper $members,
		private OpenItemMapper $openItems,
		private JournalMapper $journals,
		private NumberRangeService $numbers,
		private TransactionRunner $transaction,
		private AuditService $audit,
		private ClubClock $clock,
		private IL10N $l10n,
		private ?DonationLedger $ledger = null,
		private ?DonationMapper $donations = null,
		private ?DebtorMapper $debtors = null,
	) {
	}

	public function isEnabled(): bool {
		return $this->settings->isEnabled();
	}

	/** Ersetzt die amtliche Bestätigung die informelle Beitragsbestätigung? Nur, wenn sie Beiträge enthält. */
	public function replacesInformal(): bool {
		return $this->settings->isEnabled() && $this->settings->feesDeductible();
	}

	/**
	 * Was die Mitgliederakte zeigt: ausstellbare Jahre mit Betrag und die
	 * ausgestellten Bestätigungen.
	 *
	 * @return array{enabled: bool, feesIncluded: bool, problems: list<string>, years: list<array{year: int, amountCents: int, count: int, receiptId: int|null}>, receipts: list<array<string, mixed>>}
	 * @throws DoesNotExistException wenn es das Mitglied nicht gibt
	 */
	public function overview(int $memberId): array {
		$this->members->find($memberId);
		if (!$this->settings->isEnabled()) {
			return ['enabled' => false, 'feesIncluded' => false, 'problems' => [], 'years' => [], 'receipts' => []];
		}
		$receipts = $this->receipts->findByMember($memberId);
		return [
			'enabled' => true,
			'feesIncluded' => $this->settings->feesDeductible(),
			'problems' => $this->settings->problems($this->clock->today()),
			'years' => $this->years(Donation::DONOR_MEMBER, $memberId, $receipts),
			'receipts' => array_map(fn (DonationReceipt $r): array => $this->describe($r, true), $receipts),
		];
	}

	/**
	 * Abgeschlossene Jahre mit Zuwendungen für eine Sammelbestätigung, neueste
	 * zuerst; `receiptId` ist die gültige Sammelbestätigung des Jahres.
	 *
	 * @param list<DonationReceipt>|null $receipts schon geladene Bestätigungen des Zuwendenden
	 * @return list<array{year: int, amountCents: int, count: int, receiptId: int|null}>
	 */
	public function years(string $kind, int $id, ?array $receipts = null): array {
		$receipts ??= $this->receiptsOf($kind, $id);
		$collective = [];
		$single = [];
		foreach ($receipts as $receipt) {
			if ($receipt->isVoided()) {
				continue;
			}
			if (!$receipt->isSingle()) {
				$collective[$receipt->getReceiptYear()] = (int)$receipt->getId();
				continue;
			}
			foreach ($receipt->itemList() as $item) {
				$single[DonationReceipt::itemKey($item)] = true;
			}
		}
		$currentYear = (int)substr($this->clock->today(), 0, 4);
		$years = [];
		foreach ($this->entries($kind, $id) as $entry) {
			$year = (int)substr($entry['date'], 0, 4);
			// Einzeln bestätigte Spenden gehören in keine Sammelbestätigung mehr.
			if ($year >= $currentYear || isset($single[DonationReceipt::itemKey($entry)])) {
				continue;
			}
			$years[$year] ??= ['year' => $year, 'amountCents' => 0, 'count' => 0, 'receiptId' => $collective[$year] ?? null];
			$years[$year]['amountCents'] += $entry['amountCents'];
			$years[$year]['count']++;
		}
		krsort($years);
		return array_values($years);
	}

	/**
	 * Die gültigen Bestätigungen eines Mitglieds, für den Self-Service.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forMember(int $memberId): array {
		$out = [];
		foreach ($this->receipts->findByMember($memberId) as $receipt) {
			if (!$receipt->isVoided()) {
				$out[] = $this->describe($receipt, false);
			}
		}
		return $out;
	}

	/**
	 * Sammelbestätigung eines Mitglieds für ein Kalenderjahr (Teil A).
	 *
	 * @throws DoesNotExistException wenn es das Mitglied nicht gibt
	 * @throws \InvalidArgumentException wenn sich (noch) keine Bestätigung ausstellen lässt
	 */
	public function issue(int $memberId, int $year, string $actor): DonationReceipt {
		return $this->issueCollective(Donation::DONOR_MEMBER, $memberId, $year, $actor);
	}

	/**
	 * Stellt die Sammelbestätigung eines Zuwendenden für ein abgeschlossenes
	 * Kalenderjahr aus: alle Beiträge und Spenden dieses Jahres, die noch in
	 * keiner gültigen Bestätigung stehen.
	 *
	 * @throws DoesNotExistException wenn es den Zuwendenden nicht gibt
	 * @throws \InvalidArgumentException wenn sich (noch) keine Bestätigung ausstellen lässt
	 */
	public function issueCollective(string $kind, int $id, int $year, string $actor): DonationReceipt {
		$today = $this->assertIssuable();
		if ($year >= (int)substr($today, 0, 4)) {
			throw new \InvalidArgumentException($this->l10n->t('Eine Zuwendungsbestätigung gibt es erst nach Ablauf des Kalenderjahres.'));
		}

		return $this->transaction->run(function () use ($kind, $id, $year, $actor, $today): DonationReceipt {
			$donor = $this->donor($kind, $id);
			$confirmed = [];
			foreach ($this->receiptsOf($kind, $id) as $existing) {
				if ($existing->isVoided()) {
					continue;
				}
				if (!$existing->isSingle() && $existing->getReceiptYear() === $year) {
					throw new \InvalidArgumentException($this->l10n->t('Für %1$s gibt es bereits die Zuwendungsbestätigung %2$s. Für eine neue zuerst diese stornieren.', [(string)$year, $existing->getNumber()]));
				}
				foreach ($existing->itemList() as $item) {
					$confirmed[DonationReceipt::itemKey($item)] = true;
				}
			}
			$items = array_values(array_filter(
				$this->entries($kind, $id),
				static fn (array $e): bool => (int)substr($e['date'], 0, 4) === $year && !isset($confirmed[DonationReceipt::itemKey($e)]),
			));
			if ($items === []) {
				throw new \InvalidArgumentException($this->l10n->t('Für %s gibt es keine Zuwendungen, die noch nicht bestätigt sind.', [(string)$year]));
			}
			return $this->create($kind, $id, $donor, DonationReceipt::TYPE_COLLECTIVE, $year, $year . '-01-01', $year . '-12-31', $items, $today, $actor);
		});
	}

	/**
	 * Bestätigung über eine einzelne Geldspende – sofort, nicht erst nach
	 * Ablauf des Jahres.
	 *
	 * @throws DoesNotExistException wenn es den Spender nicht mehr gibt
	 * @throws \InvalidArgumentException wenn sich keine Bestätigung ausstellen lässt
	 */
	public function issueSingle(int $journalId, string $actor): DonationReceipt {
		$today = $this->assertIssuable();
		$donations = $this->donations;
		$ledger = $this->ledger;
		if ($donations === null || $ledger === null) {
			throw new \InvalidArgumentException($this->l10n->t('Dieser Buchung ist kein Spender zugeordnet.'));
		}
		return $this->transaction->run(function () use ($donations, $ledger, $journalId, $actor, $today): DonationReceipt {
			$donation = $donations->findByJournal($journalId);
			if ($donation === null) {
				throw new \InvalidArgumentException($this->l10n->t('Dieser Buchung ist kein Spender zugeordnet.'));
			}
			$entry = $ledger->entryFor($donation);
			if ($entry === null) {
				throw new \InvalidArgumentException($this->l10n->t('Die Buchung enthält keine Spende auf einem Spendenkonto.'));
			}
			if ($entry['date'] > $today) {
				throw new \InvalidArgumentException($this->l10n->t('Eine Spende lässt sich erst ab dem Tag der Gutschrift bestätigen.'));
			}
			[$kind, $id] = $donation->donor();
			$donor = $this->donor($kind, $id);
			$key = DonationReceipt::itemKey($entry);
			foreach ($this->receiptsOf($kind, $id) as $existing) {
				if ($existing->isVoided()) {
					continue;
				}
				foreach ($existing->itemList() as $item) {
					if (DonationReceipt::itemKey($item) === $key) {
						throw new \InvalidArgumentException($this->l10n->t('Diese Spende ist bereits in der Zuwendungsbestätigung %s enthalten.', [$existing->getNumber()]));
					}
				}
			}
			$year = (int)substr($entry['date'], 0, 4);
			return $this->create($kind, $id, $donor, DonationReceipt::TYPE_SINGLE, $year, $entry['date'], $entry['date'], [$entry], $today, $actor);
		});
	}

	/**
	 * Storniert eine Bestätigung. Datensatz und PDF bleiben als Doppel erhalten.
	 *
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException ohne Grund oder wenn schon storniert
	 */
	public function void(int $id, string $reason, string $actor): DonationReceipt {
		$reason = trim($reason);
		if ($reason === '') {
			throw new \InvalidArgumentException($this->l10n->t('Bitte einen Grund für die Stornierung angeben.'));
		}
		if (mb_strlen($reason) > 500) {
			throw new \InvalidArgumentException($this->l10n->t('Der Grund ist zu lang (höchstens 500 Zeichen).'));
		}
		$receipt = $this->receipts->find($id);
		if ($receipt->isVoided()) {
			throw new \InvalidArgumentException($this->l10n->t('Diese Zuwendungsbestätigung ist bereits storniert.'));
		}
		$receipt->setVoidedAt((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
		$receipt->setVoidedBy($actor);
		$receipt->setVoidReason($reason);
		$this->receipts->update($receipt);
		$this->audit->log('Zuwendungsbestätigung storniert', 'donation_receipt', $id, [
			'number' => $receipt->getNumber(),
			'memberId' => $receipt->getMemberId(),
			'debtorId' => $receipt->getDebtorId(),
			'reason' => $reason,
		], $actor);
		return $receipt;
	}

	public function find(int $id): DonationReceipt {
		return $this->receipts->find($id);
	}

	/**
	 * @return array{content: string, fileName: string}
	 */
	public function pdf(DonationReceipt $receipt): array {
		return ['content' => $this->storage->read($receipt), 'fileName' => $receipt->getFileName()];
	}

	/**
	 * Löscht die Bestätigungen eines Mitglieds samt PDF und seine
	 * Spenderzuordnungen (Anonymisierung). Läuft in der Transaktion des
	 * Aufrufers; die Dateien verschwinden erst nach dem Commit.
	 */
	public function deleteForMember(int $memberId): void {
		if ($this->donations !== null) {
			foreach ($this->donations->findByDonor(Donation::DONOR_MEMBER, $memberId) as $donation) {
				$this->donations->delete($donation);
			}
		}
		$receipts = $this->receipts->findByMember($memberId);
		if ($receipts === []) {
			return;
		}
		foreach ($receipts as $receipt) {
			$this->receipts->delete($receipt);
		}
		$storage = $this->storage;
		$this->transaction->afterCommit(static function () use ($storage, $receipts): void {
			$storage->deleteFiles($receipts);
		});
	}

	/**
	 * Alle Zuwendungen eines Zuwendenden mit dem Tag der Zahlung, ältere zuerst:
	 * gezahlte Beiträge (nur Mitglieder, nur wenn abziehbar) und zugeordnete Spenden.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function entries(string $kind, int $id): array {
		$out = [];
		if ($kind === Donation::DONOR_MEMBER && $this->settings->feesDeductible()) {
			foreach ($this->payments($id) as $payment) {
				$out[] = $payment + ['type' => 'beitrag', 'waiver' => false];
			}
		}
		foreach ($this->ledger?->forDonor($kind, $id) ?? [] as $donation) {
			$out[] = $donation;
		}
		usort($out, static fn (array $a, array $b): int => [$a['date'], DonationReceipt::itemKey($a)] <=> [$b['date'], DonationReceipt::itemKey($b)]);
		return $out;
	}

	/**
	 * Gezahlte Beiträge eines Mitglieds mit dem Tag der Zahlung, ältere zuerst.
	 *
	 * @return list<array{openItemId: int, date: string, amountCents: int, description: string}>
	 */
	public function payments(int $memberId): array {
		$items = array_values(array_filter(
			$this->openItems->findByMember($memberId),
			static fn (OpenItem $item): bool => $item->getType() === OpenItem::TYPE_CONTRIBUTION
				&& $item->getStatus() === 'paid'
				&& $item->getCancelledAt() === null
				&& $item->getAmountCents() > 0,
		));
		$journalIds = array_values(array_filter(array_map(static fn (OpenItem $i): ?int => $i->getPaidJournalId(), $items)));
		$journals = $this->journals->findByIds(Application::BOOK, $journalIds);
		$out = [];
		foreach ($items as $item) {
			$journal = $item->getPaidJournalId() !== null ? ($journals[$item->getPaidJournalId()] ?? null) : null;
			$date = $journal?->getDate()
				?? ($item->getSettledAt() !== null ? ClubClock::localDate($item->getSettledAt()) : null)
				?? $item->getDueDate();
			if ($date === null || $date === '') {
				continue;
			}
			$out[] = [
				'openItemId' => (int)$item->getId(),
				'date' => substr($date, 0, 10),
				'amountCents' => $item->getAmountCents(),
				'description' => (string)($item->getDescription() ?? ''),
			];
		}
		usort($out, static fn (array $a, array $b): int => [$a['date'], $a['openItemId']] <=> [$b['date'], $b['openItemId']]);
		return $out;
	}

	/**
	 * Die Bestätigungen eines Zuwendenden, neueste zuerst.
	 *
	 * @return list<DonationReceipt>
	 */
	public function receiptsOf(string $kind, int $id): array {
		return $kind === Donation::DONOR_MEMBER ? $this->receipts->findByMember($id) : $this->receipts->findByDebtor($id);
	}

	/** @return list<string> Name und Anschrift für das Feld „Name und Anschrift des Zuwendenden“ */
	public static function donorLines(Member $member): array {
		return array_values(array_filter([
			$member->displayName(),
			trim((string)$member->getStreet()),
			trim($member->getPostalCode() . ' ' . $member->getCity()),
		], static fn (string $line): bool => $line !== ''));
	}

	/** Name und Mitgliedsnummer, zur Zuordnung der Anlage (BMF Nr. 9). */
	public static function donorLabel(Member $member): string {
		$number = (string)($member->getMemberNumber() ?? '');
		return $member->displayName() . ($number !== '' ? ' (Mitgliedsnummer ' . $number . ')' : '');
	}

	/** @return list<string> Name und Anschrift eines Debitors als Zuwendendem */
	public static function debtorLines(Debtor $debtor): array {
		$country = strtoupper(trim((string)$debtor->getCountry()));
		return array_values(array_filter([
			trim((string)$debtor->getName()),
			trim((string)($debtor->getName2() ?? '')),
			trim((string)($debtor->getStreet() ?? '')),
			trim($debtor->getPostalCode() . ' ' . $debtor->getCity()),
			$country !== '' && $country !== 'DE' ? $country : '',
		], static fn (string $line): bool => $line !== ''));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function describe(DonationReceipt $receipt, bool $staff): array {
		$data = $receipt->jsonSerialize();
		if (!$staff) {
			unset($data['issuedBy'], $data['voidedBy'], $data['voidReason'], $data['voidedAt']);
			return $data;
		}
		$data['integrity'] = $this->storage->verify($receipt);
		$data['outdated'] = !$receipt->isVoided() && $this->isOutdated($receipt);
		return $data;
	}

	/** @throws UserFacingException */
	public function assertBelongsTo(DonationReceipt $receipt, int $memberId): void {
		if ($receipt->getMemberId() !== $memberId || $receipt->isVoided()) {
			throw new UserFacingException($this->l10n->t('Zuwendungsbestätigung nicht gefunden'));
		}
	}

	/**
	 * @return string heutiges Datum (Y-m-d)
	 * @throws \InvalidArgumentException wenn ausgeschaltet oder Angaben fehlen
	 */
	private function assertIssuable(): string {
		$today = $this->clock->today();
		if (!$this->settings->isEnabled()) {
			throw new \InvalidArgumentException($this->l10n->t('Zuwendungsbestätigungen sind nicht eingeschaltet.'));
		}
		$problems = $this->settings->problems($today);
		if ($problems !== []) {
			throw new \InvalidArgumentException($problems[0]);
		}
		return $today;
	}

	/**
	 * Name, Anschrift und Bezeichnung des Zuwendenden – mit den Prüfungen, die
	 * eine Bestätigung braucht.
	 *
	 * @return array{lines: list<string>, label: string, name: string}
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException
	 */
	private function donor(string $kind, int $id): array {
		if ($kind === Donation::DONOR_MEMBER) {
			$member = $this->members->find($id);
			if ($member->isRedacted()) {
				throw new \InvalidArgumentException($this->l10n->t('Dieses Mitglied ist anonymisiert.'));
			}
			if (!$member->hasAddress()) {
				throw new \InvalidArgumentException($this->l10n->t('Für die Zuwendungsbestätigung fehlt die Anschrift des Mitglieds (Straße, PLZ und Ort).'));
			}
			return ['lines' => self::donorLines($member), 'label' => self::donorLabel($member), 'name' => $member->displayName()];
		}
		if ($this->debtors === null) {
			throw new DoesNotExistException('debtor');
		}
		$debtor = $this->debtors->find($id);
		if ($debtor->getRedactedAt() !== null) {
			throw new \InvalidArgumentException($this->l10n->t('Dieser Spender ist anonymisiert.'));
		}
		if (trim((string)($debtor->getStreet() ?? '')) === '' || trim((string)($debtor->getPostalCode() ?? '')) === '' || trim((string)($debtor->getCity() ?? '')) === '') {
			throw new \InvalidArgumentException($this->l10n->t('Für die Zuwendungsbestätigung fehlt die Anschrift des Spenders (Straße, PLZ und Ort).'));
		}
		$name = trim((string)$debtor->getName());
		return ['lines' => self::debtorLines($debtor), 'label' => $name, 'name' => $name];
	}

	/**
	 * Nummer ziehen, PDF erzeugen, Doppel ablegen, protokollieren.
	 *
	 * @param array{lines: list<string>, label: string, name: string} $donor
	 * @param list<array<string, mixed>> $items
	 */
	private function create(string $kind, int $id, array $donor, string $type, int $year, string $from, string $to, array $items, string $today, string $actor): DonationReceipt {
		$amount = (int)array_sum(array_column($items, 'amountCents'));
		$number = $this->numbers->issue(NumberRange::KIND_DONATION_RECEIPT, new \DateTimeImmutable($today))['number'];
		$receipt = new DonationReceipt();
		$receipt->setMemberId($kind === Donation::DONOR_MEMBER ? $id : 0);
		$receipt->setDebtorId($kind === Donation::DONOR_DEBTOR ? $id : 0);
		$receipt->setReceiptType($type);
		$receipt->setReceiptYear($year);
		$receipt->setNumber($number);
		$receipt->setAmountCents($amount);
		$receipt->setPeriodFrom($from);
		$receipt->setPeriodTo($to);
		$receipt->setItems((string)json_encode($items, JSON_UNESCAPED_UNICODE));
		$receipt->setIssuedAt((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)); // UTC-Zeitstempel (#263)
		$receipt->setIssuedBy($actor);
		$receipt->setFileName(SafeFileName::of($number . ' ' . $donor['name'], 100) . '.pdf');

		$content = $this->renderer->render([
			'type' => $type,
			'number' => $number,
			'issueDate' => $today,
			'periodFrom' => $from,
			'periodTo' => $to,
			'amountCents' => $amount,
			'donorLines' => $donor['lines'],
			'donorLabel' => $donor['label'],
			'items' => $items,
		], $this->settings->data());
		$receipt = $this->storage->store($receipt, $content);

		$this->audit->log('Zuwendungsbestätigung ausgestellt', 'donation_receipt', (int)$receipt->getId(), [
			'number' => $number,
			'type' => $type,
			'memberId' => $receipt->getMemberId(),
			'debtorId' => $receipt->getDebtorId(),
			'year' => $year,
			'amountCents' => $amount,
			'sha256' => $receipt->getSha256(),
		], $actor);
		return $receipt;
	}

	/** Stimmt eine bestätigte Zuwendung nicht mehr (Rücklastschrift, Storno, geänderte Buchung)? */
	private function isOutdated(DonationReceipt $receipt): bool {
		[$kind, $id] = $receipt->donor();
		$current = [];
		foreach ($this->entries($kind, $id) as $entry) {
			$current[DonationReceipt::itemKey($entry)] = [(int)$entry['amountCents'], (bool)($entry['waiver'] ?? false)];
		}
		foreach ($receipt->itemList() as $item) {
			$now = $current[DonationReceipt::itemKey($item)] ?? null;
			if ($now === null || $now[0] !== (int)$item['amountCents'] || $now[1] !== (bool)($item['waiver'] ?? false)) {
				return true;
			}
		}
		return false;
	}
}
