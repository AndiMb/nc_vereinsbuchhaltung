<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\DebtorMapper;
use OCA\Vereinsbuchhaltung\Db\InvoicePlan;
use OCA\Vereinsbuchhaltung\Db\InvoicePlanMapper;
use OCA\Vereinsbuchhaltung\Service\EInvoice\EInvoiceService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDocumentService;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceMailService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Wiederkehrende Rechnungen (Epic #168, Stufe 3): ein Plan legt im Takt eine
 * Rechnung als Entwurf an. Der Verein kann zusätzlich wählen, dass der Plan die
 * Rechnung gleich festschreibt und per Mail verschickt; das setzt voraus, dass
 * alle Pflichtangaben im Plan stehen, sonst bleibt der Entwurf liegen und der
 * Plan nennt den Grund.
 *
 * Termine ergeben sich aus dem Beginn und dem Takt (1, 3, 6 oder 12 Monate), nie
 * aus dem letzten Lauf: so rutscht der 31. nicht dauerhaft auf den 28. Ein Plan, der
 * nach dem Beginn angelegt wird, holt vergangene Termine nicht nach.
 *
 * In Texten ersetzt der Lauf `{monat}`, `{jahr}`, `{quartal}` und `{zeitraum}` durch die
 * Angaben des Termins: aus „Hallenmiete {monat} {jahr}" wird „Hallenmiete Juni 2027".
 */
class InvoicePlanService {

	/** Obergrenze für nachgeholte Termine je Lauf, falls der Hintergrundjob lange stand. */
	private const MAX_CATCH_UP = 12;

	public function __construct(
		private InvoicePlanMapper $plans,
		private DebtorMapper $debtors,
		private InvoiceService $invoices,
		private InvoiceDocumentService $documents,
		private EInvoiceService $eInvoices,
		private InvoiceMailService $mail,
		private AuditService $audit,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private IL10N $l10n,
	) {
	}

	/** @return InvoicePlan[] */
	public function list(): array {
		return $this->plans->findAll();
	}

	/** @throws DoesNotExistException */
	public function find(int $id): InvoicePlan {
		return $this->plans->find($id);
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws \InvalidArgumentException
	 */
	public function create(array $data, ?string $today = null): InvoicePlan {
		$plan = new InvoicePlan();
		$plan->setCreatedAt((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
		$plan->setCreatedBy($this->userSession->getUser()?->getUID() ?? 'system');
		$this->apply($plan, $data);
		$plan->setNextRun($this->firstRun($plan, $today ?? date('Y-m-d')));
		return $this->plans->insert($plan);
	}

	/**
	 * @param array<string, mixed> $data nur die gesendeten Felder werden geändert
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException
	 */
	public function update(int $id, array $data, ?string $today = null): InvoicePlan {
		$plan = $this->plans->find($id);
		$schedule = $plan->getStartDate() . '|' . $plan->getIntervalMonths() . '|' . $plan->getEndDate();
		$this->apply($plan, $data);
		if ($schedule !== $plan->getStartDate() . '|' . $plan->getIntervalMonths() . '|' . $plan->getEndDate()) {
			$plan->setNextRun($this->firstRun($plan, $today ?? date('Y-m-d')));
		}
		return $this->plans->update($plan);
	}

	/** @throws DoesNotExistException */
	public function delete(int $id): void {
		$this->plans->delete($this->plans->find($id));
	}

	/**
	 * Pausiert den Plan oder setzt ihn fort. Beim Fortsetzen springt der nächste Termin auf
	 * den nächsten, der heute oder später liegt: die Pause holt nichts nach.
	 *
	 * @throws DoesNotExistException
	 */
	public function setActive(int $id, bool $active, ?string $today = null): InvoicePlan {
		$plan = $this->plans->find($id);
		$plan->setActive($active ? 1 : 0);
		if ($active) {
			$plan->setNextRun($this->firstRun($plan, $today ?? date('Y-m-d')));
		}
		return $this->plans->update($plan);
	}

	// ------------------------------------------------------------------
	// Lauf
	// ------------------------------------------------------------------

	/**
	 * Alle fälligen Pläne abarbeiten.
	 *
	 * @return array{created: int, issued: int, sent: int, failed: int}
	 */
	public function runDue(?string $today = null): array {
		$today ??= date('Y-m-d');
		$total = ['created' => 0, 'issued' => 0, 'sent' => 0, 'failed' => 0];
		foreach ($this->plans->findDue($today) as $plan) {
			$result = $this->runPlan($plan, $today);
			foreach ($total as $key => $_) {
				$total[$key] += $result[$key];
			}
		}
		return $total;
	}

	/**
	 * Legt für jeden fälligen Termin des Plans eine Rechnung an. Der Plan rückt dabei
	 * Termin für Termin vor, auch wenn ein Entwurf nicht festgeschrieben werden kann.
	 *
	 * @return array{created: int, issued: int, sent: int, failed: int}
	 */
	public function runPlan(InvoicePlan $plan, ?string $today = null): array {
		$today ??= date('Y-m-d');
		$result = ['created' => 0, 'issued' => 0, 'sent' => 0, 'failed' => 0];
		for ($i = 0; $i < self::MAX_CATCH_UP; $i++) {
			$runDate = $plan->getNextRun();
			if ($runDate === null || $runDate > $today || $plan->getActive() !== 1) {
				break;
			}
			$plan->setLastError(null);
			try {
				$invoiceId = $this->createInvoice($plan, $runDate);
				$result['created']++;
				$plan->setLastInvoiceId($invoiceId);
				$outcome = $this->finish($plan, $invoiceId);
				$result['issued'] += $outcome['issued'] ? 1 : 0;
				$result['sent'] += $outcome['sent'] ? 1 : 0;
				if ($outcome['error'] !== null) {
					$result['failed']++;
					$plan->setLastError(mb_substr($outcome['error'], 0, 500));
				}
			} catch (\Throwable $e) {
				$result['failed']++;
				$plan->setLastError(mb_substr($e->getMessage(), 0, 500));
				$this->logger->warning('Wiederkehrende Rechnung {name} fehlgeschlagen: {message}', ['app' => Application::APP_ID, 'name' => $plan->getName(), 'message' => $e->getMessage(), 'exception' => $e]);
			}
			$plan->setRunCount($plan->getRunCount() + 1);
			$plan->setLastRunAt((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
			$plan->setNextRun($this->nextAfter($plan, $runDate));
			$this->plans->update($plan);
		}
		return $result;
	}

	/**
	 * Legt den Entwurf des Termins an.
	 *
	 * @throws \InvalidArgumentException wenn die Angaben des Plans nicht mehr zu einer Rechnung passen
	 */
	private function createInvoice(InvoicePlan $plan, string $runDate): int {
		$periodEnd = (new \DateTimeImmutable($this->addMonths($runDate, $plan->getIntervalMonths())))->modify('-1 day')->format('Y-m-d');
		$vars = $this->placeholders($runDate, $periodEnd);
		$lines = [];
		foreach ($plan->linesData() as $line) {
			foreach (['name', 'description'] as $key) {
				if (isset($line[$key])) {
					$line[$key] = strtr((string)$line[$key], $vars);
				}
			}
			$lines[] = $line;
		}
		$data = [
			'debtorId' => $plan->getDebtorId(),
			'issueDate' => $runDate,
			'lines' => $lines,
			'noteHead' => $this->expand($plan->getNoteHead(), $vars),
			'noteFoot' => $this->expand($plan->getNoteFoot(), $vars),
			'paymentTerms' => $plan->getPaymentTerms(),
			'buyerReference' => $plan->getBuyerReference(),
			'orderReference' => $plan->getOrderReference(),
		];
		if ($plan->getPeriodMode() === InvoicePlan::PERIOD_INTERVAL) {
			$data['periodStart'] = $runDate;
			$data['periodEnd'] = $periodEnd;
		} else {
			$data['deliveryDate'] = $runDate;
		}
		if ($plan->getDueDays() !== null) {
			$data['dueDate'] = (new \DateTimeImmutable($runDate))->modify('+' . $plan->getDueDays() . ' days')->format('Y-m-d');
		}
		$invoice = $this->invoices->createDraft($data);
		$this->audit->log('Wiederkehrende Rechnung angelegt', 'invoice', (int)$invoice->getId(), ['plan' => $plan->getName(), 'runDate' => $runDate]);
		return (int)$invoice->getId();
	}

	/**
	 * Festschreiben und Versenden, soweit der Plan es vorsieht. Ein Fehler hier lässt den
	 * Entwurf bzw. die festgeschriebene Rechnung stehen.
	 *
	 * @return array{issued: bool, sent: bool, error: string|null}
	 */
	private function finish(InvoicePlan $plan, int $invoiceId): array {
		$out = ['issued' => false, 'sent' => false, 'error' => null];
		if ($plan->getAutoIssue() !== 1) {
			return $out;
		}
		try {
			$this->invoices->issue($invoiceId);
			$out['issued'] = true;
		} catch (\Throwable $e) {
			$problems = method_exists($e, 'getProblems') ? implode(', ', (array)$e->getProblems()) : '';
			$out['error'] = $this->l10n->t('Der Entwurf wurde angelegt, ließ sich aber nicht festschreiben: %s', [$problems !== '' ? $problems : $e->getMessage()]);
			return $out;
		}
		try {
			$this->documents->ensurePdf($invoiceId);
			$this->eInvoices->generateSuggested($invoiceId);
		} catch (\Throwable $e) {
			// Das PDF lässt sich jederzeit nachholen; die Rechnung bleibt festgeschrieben.
			$this->logger->warning('PDF zur wiederkehrenden Rechnung {id} fehlt: {message}', ['app' => Application::APP_ID, 'id' => $invoiceId, 'message' => $e->getMessage()]);
		}
		if ($plan->getAutoSend() === 1) {
			try {
				$email = (string)(($this->debtors->find($plan->getDebtorId())->toSnapshot())['email'] ?? '');
				if ($email === '') {
					$out['error'] = $this->l10n->t('Die Rechnung wurde festgeschrieben, aber der Empfänger hat keine E-Mail-Adresse.');
				} else {
					$this->mail->send($invoiceId, $email);
					$out['sent'] = true;
				}
			} catch (\Throwable $e) {
				$out['error'] = $this->l10n->t('Die Rechnung wurde festgeschrieben, ließ sich aber nicht versenden: %s', [$e->getMessage()]);
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Termine
	// ------------------------------------------------------------------

	/** Der erste Termin der Reihe, der heute oder später liegt; null, wenn die Reihe vorbei ist. */
	private function firstRun(InvoicePlan $plan, string $today): ?string {
		for ($n = 0; $n < 2000; $n++) {
			$date = $this->addMonths($plan->getStartDate(), $n * $plan->getIntervalMonths());
			if ($plan->getEndDate() !== null && $date > $plan->getEndDate()) {
				return null;
			}
			if ($date >= $today) {
				return $date;
			}
		}
		return null;
	}

	private function nextAfter(InvoicePlan $plan, string $runDate): ?string {
		for ($n = 1; $n < 2000; $n++) {
			$date = $this->addMonths($plan->getStartDate(), $n * $plan->getIntervalMonths());
			if ($date > $runDate) {
				return $plan->getEndDate() !== null && $date > $plan->getEndDate() ? null : $date;
			}
		}
		return null;
	}

	/** Plus n Monate ab dem Beginn; der Tag bleibt, soweit der Monat ihn hat (31. Januar plus 1 Monat ist der 28. oder 29. Februar). */
	public function addMonths(string $date, int $months): string {
		$base = new \DateTimeImmutable($date);
		$day = (int)$base->format('j');
		$first = $base->modify('first day of this month')->modify('+' . $months . ' months');
		$last = (int)$first->format('t');
		return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min($day, $last))->format('Y-m-d');
	}

	/** @return array<string, string> */
	private function placeholders(string $start, string $end): array {
		$date = new \DateTimeImmutable($start);
		$month = (string)$date->format('n');
		if (class_exists(\IntlDateFormatter::class)) {
			$formatter = new \IntlDateFormatter($this->l10n->getLocaleCode(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'LLLL');
			$name = $formatter->format($date);
			$month = is_string($name) && $name !== '' ? $name : $month;
		}
		$fmt = static fn (string $iso): string => (new \DateTimeImmutable($iso))->format('d.m.Y');
		return [
			'{monat}' => $month,
			'{jahr}' => $date->format('Y'),
			'{quartal}' => (string)(int)ceil((int)$date->format('n') / 3),
			'{zeitraum}' => $fmt($start) . ' – ' . $fmt($end),
		];
	}

	/** @param array<string, string> $vars */
	private function expand(?string $text, array $vars): ?string {
		return $text === null ? null : strtr($text, $vars);
	}

	// ------------------------------------------------------------------
	// Eingabe
	// ------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $data
	 * @throws \InvalidArgumentException
	 */
	private function apply(InvoicePlan $plan, array $data): void {
		$isNew = $plan->getId() === null;
		if ($isNew || array_key_exists('name', $data)) {
			$name = mb_substr(trim((string)($data['name'] ?? '')), 0, 255);
			if ($name === '') {
				throw new \InvalidArgumentException($this->l10n->t('Bitte einen Namen für den Plan angeben.'));
			}
			$plan->setName($name);
		}
		if ($isNew || array_key_exists('debtorId', $data)) {
			$debtorId = (int)($data['debtorId'] ?? 0);
			try {
				$this->debtors->find($debtorId);
			} catch (DoesNotExistException) {
				throw new \InvalidArgumentException($this->l10n->t('Der Empfänger existiert nicht.'));
			}
			$plan->setDebtorId($debtorId);
		}
		if ($isNew || array_key_exists('intervalMonths', $data)) {
			$interval = (int)($data['intervalMonths'] ?? 1);
			if (!in_array($interval, InvoicePlan::INTERVALS, true)) {
				throw new \InvalidArgumentException($this->l10n->t('Der Takt muss monatlich, vierteljährlich, halbjährlich oder jährlich sein.'));
			}
			$plan->setIntervalMonths($interval);
		}
		if ($isNew || array_key_exists('startDate', $data)) {
			$start = $this->date($data['startDate'] ?? null);
			if ($start === null) {
				throw new \InvalidArgumentException($this->l10n->t('Bitte einen Beginn angeben.'));
			}
			$plan->setStartDate($start);
		}
		if (array_key_exists('endDate', $data)) {
			$plan->setEndDate($this->date($data['endDate']));
		}
		if ($plan->getEndDate() !== null && $plan->getEndDate() < $plan->getStartDate()) {
			throw new \InvalidArgumentException($this->l10n->t('Das Ende liegt vor dem Beginn.'));
		}
		if (array_key_exists('periodMode', $data)) {
			$mode = (string)$data['periodMode'];
			if (!in_array($mode, InvoicePlan::PERIOD_MODES, true)) {
				throw new \InvalidArgumentException($this->l10n->t('Unbekannte Art des Leistungszeitraums.'));
			}
			$plan->setPeriodMode($mode);
		}
		if ($isNew || array_key_exists('lines', $data)) {
			$rows = array_values(array_filter((array)($data['lines'] ?? []), 'is_array'));
			$this->invoices->validateLines($rows);
			$plan->setLines(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		}
		foreach (['noteHead' => ['setNoteHead', 2000], 'noteFoot' => ['setNoteFoot', 2000], 'paymentTerms' => ['setPaymentTerms', 1000], 'buyerReference' => ['setBuyerReference', 255], 'orderReference' => ['setOrderReference', 255]] as $key => [$setter, $max]) {
			if (array_key_exists($key, $data)) {
				$value = mb_substr(trim((string)$data[$key]), 0, $max);
				$plan->$setter($value === '' ? null : $value);
			}
		}
		if (array_key_exists('dueDays', $data)) {
			$days = $data['dueDays'];
			if ($days === null || $days === '') {
				$plan->setDueDays(null);
			} else {
				$days = (int)$days;
				if ($days < 0 || $days > InvoiceSettings::MAX_DUE_DAYS) {
					throw new \InvalidArgumentException($this->l10n->t('Das Zahlungsziel muss zwischen 0 und %d Tagen liegen.', [InvoiceSettings::MAX_DUE_DAYS]));
				}
				$plan->setDueDays($days);
			}
		}
		foreach (['autoIssue' => 'setAutoIssue', 'autoSend' => 'setAutoSend'] as $key => $setter) {
			if (array_key_exists($key, $data)) {
				$plan->$setter(filter_var($data[$key], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
			}
		}
		if ($plan->getAutoSend() === 1 && $plan->getAutoIssue() !== 1) {
			throw new \InvalidArgumentException($this->l10n->t('Versenden setzt voraus, dass der Plan die Rechnung auch festschreibt.'));
		}
	}

	private function date(mixed $value): ?string {
		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
		if ($date === false || $date->format('Y-m-d') !== $text) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültiges Datum.'));
		}
		return $text;
	}
}
