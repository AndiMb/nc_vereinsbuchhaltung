<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\DonationReceipt;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
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
use OCP\IL10N;

/**
 * Zuwendungsbestätigungen über Mitgliedsbeiträge (Issue #10, Teil A).
 *
 * Je Mitglied und abgeschlossenem Kalenderjahr eine Sammelbestätigung über
 * die in diesem Jahr gezahlten Beiträge. Anders als die informelle
 * Beitragsbestätigung ({@see \OCA\Vereinsbuchhaltung\Service\Export\BeitragsbescheinigungRenderer})
 * zählt nicht die Fälligkeitsperiode, sondern der Tag der Zahlung – das
 * amtliche Muster fragt nach dem „Datum der Zuwendung“, und die Spende ist
 * im Jahr der Zahlung abziehbar (§ 11 Abs. 2 EStG). Maßgeblich ist das Datum
 * der Buchung, die den Beitrag beglichen hat; ohne Buchung (als bezahlt
 * markiert) der Tag der Markierung.
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
	) {
	}

	public function isEnabled(): bool {
		return $this->settings->isEnabled();
	}

	/**
	 * Was die Mitgliederakte zeigt: ausstellbare Jahre mit Betrag und die
	 * ausgestellten Bestätigungen.
	 *
	 * @return array{enabled: bool, problems: list<string>, years: list<array{year: int, amountCents: int, count: int, receiptId: int|null}>, receipts: list<array<string, mixed>>}
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn es das Mitglied nicht gibt
	 */
	public function overview(int $memberId): array {
		$this->members->find($memberId);
		if (!$this->settings->isEnabled()) {
			return ['enabled' => false, 'problems' => [], 'years' => [], 'receipts' => []];
		}
		$receipts = $this->receipts->findByMember($memberId);
		$active = [];
		foreach ($receipts as $receipt) {
			if (!$receipt->isVoided()) {
				$active[$receipt->getReceiptYear()] = (int)$receipt->getId();
			}
		}
		$currentYear = (int)substr($this->clock->today(), 0, 4);
		$years = [];
		foreach ($this->payments($memberId) as $payment) {
			$year = (int)substr($payment['date'], 0, 4);
			if ($year >= $currentYear) {
				continue;
			}
			$years[$year] ??= ['year' => $year, 'amountCents' => 0, 'count' => 0, 'receiptId' => $active[$year] ?? null];
			$years[$year]['amountCents'] += $payment['amountCents'];
			$years[$year]['count']++;
		}
		krsort($years);
		return [
			'enabled' => true,
			'problems' => $this->settings->problems($this->clock->today()),
			'years' => array_values($years),
			'receipts' => array_map(fn (DonationReceipt $r): array => $this->describe($r, true), $receipts),
		];
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
	 * Stellt die Sammelbestätigung eines Mitglieds für ein Kalenderjahr aus.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn es das Mitglied nicht gibt
	 * @throws \InvalidArgumentException wenn sich (noch) keine Bestätigung ausstellen lässt
	 */
	public function issue(int $memberId, int $year, string $actor): DonationReceipt {
		$today = $this->clock->today();
		if (!$this->settings->isEnabled()) {
			throw new \InvalidArgumentException($this->l10n->t('Zuwendungsbestätigungen sind nicht eingeschaltet.'));
		}
		$problems = $this->settings->problems($today);
		if ($problems !== []) {
			throw new \InvalidArgumentException($problems[0]);
		}
		if ($year >= (int)substr($today, 0, 4)) {
			throw new \InvalidArgumentException($this->l10n->t('Eine Zuwendungsbestätigung gibt es erst nach Ablauf des Kalenderjahres.'));
		}

		return $this->transaction->run(function () use ($memberId, $year, $actor, $today): DonationReceipt {
			$member = $this->members->find($memberId);
			if ($member->isRedacted()) {
				throw new \InvalidArgumentException($this->l10n->t('Dieses Mitglied ist anonymisiert.'));
			}
			if (!$member->hasAddress()) {
				throw new \InvalidArgumentException($this->l10n->t('Für die Zuwendungsbestätigung fehlt die Anschrift des Mitglieds (Straße, PLZ und Ort).'));
			}
			$confirmed = [];
			foreach ($this->receipts->findByMember($memberId) as $existing) {
				if ($existing->isVoided()) {
					continue;
				}
				if ($existing->getReceiptYear() === $year) {
					throw new \InvalidArgumentException($this->l10n->t('Für %1$s gibt es bereits die Zuwendungsbestätigung %2$s. Für eine neue zuerst diese stornieren.', [(string)$year, $existing->getNumber()]));
				}
				foreach ($existing->itemList() as $item) {
					$confirmed[(int)$item['openItemId']] = true;
				}
			}
			$items = array_values(array_filter(
				$this->payments($memberId),
				static fn (array $p): bool => (int)substr($p['date'], 0, 4) === $year && !isset($confirmed[$p['openItemId']]),
			));
			if ($items === []) {
				throw new \InvalidArgumentException($this->l10n->t('Im Jahr %s hat das Mitglied keine Beiträge gezahlt.', [(string)$year]));
			}
			$amount = array_sum(array_column($items, 'amountCents'));

			$number = $this->numbers->issue(NumberRange::KIND_DONATION_RECEIPT, new \DateTimeImmutable($today))['number'];
			$receipt = new DonationReceipt();
			$receipt->setMemberId($memberId);
			$receipt->setReceiptYear($year);
			$receipt->setNumber($number);
			$receipt->setAmountCents($amount);
			$receipt->setPeriodFrom($year . '-01-01');
			$receipt->setPeriodTo($year . '-12-31');
			$receipt->setItems((string)json_encode($items, JSON_UNESCAPED_UNICODE));
			$receipt->setIssuedAt((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)); // UTC-Zeitstempel (#263)
			$receipt->setIssuedBy($actor);
			$receipt->setFileName(SafeFileName::of($number . ' ' . $member->displayName(), 100) . '.pdf');

			$content = $this->renderer->render([
				'number' => $number,
				'issueDate' => $today,
				'periodFrom' => $receipt->getPeriodFrom(),
				'periodTo' => $receipt->getPeriodTo(),
				'amountCents' => $amount,
				'donorLines' => self::donorLines($member),
				'donorLabel' => self::donorLabel($member),
				'items' => $items,
			], $this->settings->data());
			$receipt = $this->storage->store($receipt, $content);

			$this->audit->log('Zuwendungsbestätigung ausgestellt', 'donation_receipt', (int)$receipt->getId(), [
				'number' => $number,
				'memberId' => $memberId,
				'year' => $year,
				'amountCents' => $amount,
				'sha256' => $receipt->getSha256(),
			], $actor);
			return $receipt;
		});
	}

	/**
	 * Storniert eine Bestätigung. Datensatz und PDF bleiben als Doppel erhalten.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
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
	 * Löscht die Bestätigungen eines Mitglieds samt PDF (Anonymisierung). Läuft in
	 * der Transaktion des Aufrufers; die Dateien verschwinden erst nach dem Commit.
	 */
	public function deleteForMember(int $memberId): void {
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

	/**
	 * @return array<string, mixed>
	 */
	private function describe(DonationReceipt $receipt, bool $staff): array {
		$data = $receipt->jsonSerialize();
		if (!$staff) {
			unset($data['issuedBy'], $data['voidedBy'], $data['voidReason'], $data['voidedAt']);
			return $data;
		}
		$data['integrity'] = $this->storage->verify($receipt);
		$data['outdated'] = !$receipt->isVoided() && $this->isOutdated($receipt);
		return $data;
	}

	/** Stimmt eine bestätigte Zahlung nicht mehr (Rücklastschrift, Storno, geänderter Betrag)? */
	private function isOutdated(DonationReceipt $receipt): bool {
		$current = [];
		foreach ($this->payments($receipt->getMemberId()) as $payment) {
			$current[$payment['openItemId']] = $payment['amountCents'];
		}
		foreach ($receipt->itemList() as $item) {
			if (($current[(int)$item['openItemId']] ?? null) !== (int)$item['amountCents']) {
				return true;
			}
		}
		return false;
	}

	/** @throws UserFacingException */
	public function assertBelongsTo(DonationReceipt $receipt, int $memberId): void {
		if ($receipt->getMemberId() !== $memberId || $receipt->isVoided()) {
			throw new UserFacingException($this->l10n->t('Zuwendungsbestätigung nicht gefunden'));
		}
	}
}
