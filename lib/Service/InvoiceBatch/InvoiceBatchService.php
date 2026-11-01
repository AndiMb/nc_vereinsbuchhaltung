<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\InvoiceBatch;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\DebtorMapper;
use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceBatch;
use OCA\Vereinsbuchhaltung\Db\InvoiceBatchMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceItemTemplateMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\DebtorService;
use OCA\Vereinsbuchhaltung\Service\InvoiceService;
use OCA\Vereinsbuchhaltung\Service\OptionalL10n;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Sammelrechnungen aus einer Tabelle (#243,
 * docs/superpowers/specs/2026-11-01-sammelrechnungen-design.md).
 *
 * Zweistufig wie der Mitglieder-Import: {@see preview()} prüft und legt nichts
 * an, {@see import()} legt in einer Transaktion den Stapel, fehlende Empfänger
 * und je fehlerfreier Rechnung einen Entwurf an. Fehlerhafte Rechnungen werden
 * übersprungen; die Vorschau nennt sie mit Zeilennummern.
 *
 * Die Positionen jeder Rechnung prüft {@see InvoiceService::validateLines()},
 * also nach denselben Regeln wie der Editor.
 */
class InvoiceBatchService {

	use OptionalL10n;

	public function __construct(
		private InvoiceBatchMapper $batches,
		private InvoiceMapper $invoices,
		private DebtorMapper $debtors,
		private DebtorService $debtorService,
		private InvoiceService $invoiceService,
		private InvoiceItemTemplateMapper $templates,
		private AccountMapper $accounts,
		private CostCenterMapper $costCenters,
		private TransactionRunner $transaction,
		private AuditService $audit,
		private IUserSession $userSession,
		private ?IL10N $l10n = null,
	) {
	}

	/**
	 * Stapel mit der Zahl ihrer Rechnungen, neueste zuerst.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function list(): array {
		$out = [];
		foreach ($this->batches->findAll() as $batch) {
			$out[] = $batch->jsonSerialize() + ['invoiceCount' => count($this->invoices->findByBatch((int)$batch->getId()))];
		}
		return $out;
	}

	/**
	 * Prüflauf: was entstünde, ohne etwas anzulegen.
	 *
	 * @param array<string, mixed> $options Vorgaben aus dem Dialog (siehe {@see options()})
	 * @return array{groups: list<array<string, mixed>>, errors: list<string>, summary: array<string, int>}
	 * @throws \InvalidArgumentException wenn die Datei oder die Vorgaben nicht taugen
	 */
	public function preview(string $content, array $options): array {
		return $this->analyse($content, $this->options($options));
	}

	/**
	 * Legt Stapel, fehlende Empfänger und die Entwürfe an.
	 *
	 * @param array<string, mixed> $options
	 * @return array{batch: array<string, mixed>, groups: list<array<string, mixed>>, errors: list<string>, summary: array<string, int>}
	 * @throws \InvalidArgumentException wenn nichts anzulegen ist oder Angaben fehlen
	 */
	public function import(string $content, ?string $filename, string $name, array $options): array {
		$name = mb_substr(trim($name), 0, 255);
		if ($name === '') {
			throw new \InvalidArgumentException($this->msg('Bitte einen Namen für das Projekt angeben.'));
		}
		$opts = $this->options($options);
		$result = $this->analyse($content, $opts);
		if ($result['summary']['invoices'] === 0) {
			throw new \InvalidArgumentException($this->msg('Die Tabelle enthält keine fehlerfreie Rechnung.'));
		}

		return $this->transaction->run(function () use ($result, $filename, $name, $opts): array {
			$batch = new InvoiceBatch();
			$batch->setName($name);
			$batch->setSourceFilename($filename !== null && $filename !== '' ? mb_substr(basename($filename), 0, 255) : null);
			$batch->setCreatedBy($this->userSession->getUser()?->getUID() ?? 'system');
			$batch->setCreatedAt((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
			$batch = $this->batches->insert($batch);

			/** @var array<string, int> $created Empfänger-Schlüssel => neu angelegter Debitor */
			$created = [];
			$count = 0;
			foreach ($result['groups'] as $i => $group) {
				if ($group['errors'] !== []) {
					continue;
				}
				$debtorId = $group['debtorId'];
				if ($debtorId !== null) {
					if ($group['fill'] !== []) {
						$this->debtorService->update($debtorId, $group['fill']);
					}
				} elseif (isset($created[$group['recipientKey']])) {
					$debtorId = $created[$group['recipientKey']];
				} else {
					$debtorId = (int)$this->debtorService->create($group['recipient'])->getId();
					$created[$group['recipientKey']] = $debtorId;
				}

				$data = ['debtorId' => $debtorId, 'batchId' => (int)$batch->getId(), 'lines' => $group['items']] + $group['header'];
				foreach (['noteHead', 'noteFoot'] as $key) {
					if ($opts[$key] !== null) {
						$data[$key] = $opts[$key];
					}
				}
				$invoice = $this->invoiceService->createDraft($data);
				$result['groups'][$i]['invoiceId'] = (int)$invoice->getId();
				$result['groups'][$i]['debtorId'] = $debtorId;
				$count++;
			}
			$this->audit->log('Rechnungsstapel angelegt', 'invoice_batch', (int)$batch->getId(), [
				'name' => $name,
				'invoices' => $count,
				'newDebtors' => count($created),
			]);
			return ['batch' => $batch->jsonSerialize() + ['invoiceCount' => $count]] + $result;
		});
	}

	/**
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException bei leerem Namen
	 */
	public function rename(int $id, string $name): InvoiceBatch {
		$batch = $this->batches->find($id);
		$name = mb_substr(trim($name), 0, 255);
		if ($name === '') {
			throw new \InvalidArgumentException($this->msg('Bitte einen Namen für das Projekt angeben.'));
		}
		$batch->setName($name);
		$batch = $this->batches->update($batch);
		$this->audit->log('Rechnungsstapel umbenannt', 'invoice_batch', $id, ['name' => $name]);
		return $batch;
	}

	/**
	 * Löscht den Stapel samt seinen Entwürfen – der Rückweg nach einem
	 * verunglückten Import. Neu angelegte Empfänger bleiben.
	 *
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException sobald eine Rechnung des Stapels festgeschrieben ist
	 */
	public function delete(int $id): void {
		$batch = $this->batches->find($id);
		$invoices = $this->invoices->findByBatch($id);
		foreach ($invoices as $invoice) {
			if ($invoice->getStatus() !== Invoice::STATUS_DRAFT) {
				throw new \InvalidArgumentException($this->msg('Das Projekt enthält schon festgeschriebene Rechnungen und lässt sich nicht mehr löschen.'));
			}
		}
		$this->transaction->run(function () use ($batch, $invoices, $id): void {
			foreach ($invoices as $invoice) {
				$this->invoiceService->deleteDraft((int)$invoice->getId());
			}
			$this->batches->delete($batch);
			$this->audit->log('Rechnungsstapel gelöscht', 'invoice_batch', $id, ['name' => $batch->getName(), 'drafts' => count($invoices)]);
		});
	}

	// ------------------------------------------------------------------

	/**
	 * Vorgaben aus dem Dialog, geprüft.
	 *
	 * @param array<string, mixed> $options
	 * @return array{deliveryDate: string|null, periodStart: string|null, periodEnd: string|null, accountId: int|null, noteHead: string|null, noteFoot: string|null}
	 */
	private function options(array $options): array {
		$date = function (string $key) use ($options): ?string {
			$value = trim((string)($options[$key] ?? ''));
			if ($value === '') {
				return null;
			}
			if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
				throw new \InvalidArgumentException($this->msg('Ungültiges Datum: %s', [$value]));
			}
			return $value;
		};
		$out = [
			'deliveryDate' => $date('deliveryDate'),
			'periodStart' => $date('periodStart'),
			'periodEnd' => $date('periodEnd'),
			'accountId' => isset($options['accountId']) && $options['accountId'] !== '' ? (int)$options['accountId'] : null,
			'noteHead' => array_key_exists('noteHead', $options) && $options['noteHead'] !== null ? (string)$options['noteHead'] : null,
			'noteFoot' => array_key_exists('noteFoot', $options) && $options['noteFoot'] !== null ? (string)$options['noteFoot'] : null,
		];
		if (($out['periodStart'] === null) !== ($out['periodEnd'] === null)) {
			throw new \InvalidArgumentException($this->msg('Für den Leistungszeitraum bitte Beginn und Ende angeben.'));
		}
		if ($out['periodStart'] !== null && $out['periodStart'] > (string)$out['periodEnd']) {
			throw new \InvalidArgumentException($this->msg('Der Leistungszeitraum endet vor seinem Beginn.'));
		}
		return $out;
	}

	/**
	 * @param array{deliveryDate: string|null, periodStart: string|null, periodEnd: string|null, accountId: int|null, noteHead: string|null, noteFoot: string|null} $opts
	 * @return array{groups: list<array<string, mixed>>, errors: list<string>, summary: array<string, int>}
	 */
	private function analyse(string $content, array $opts): array {
		try {
			$rows = SpreadsheetReader::read($content);
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException($this->msg($e->getMessage()));
		}
		$parsed = $this->parser()->parse($rows);
		$matcher = new DebtorMatcher($this->debtors->findActive());

		$groups = [];
		$newRecipients = [];
		$existing = [];
		$summary = ['invoices' => 0, 'failed' => 0, 'newDebtors' => 0, 'existingDebtors' => 0, 'withoutEmail' => 0, 'totalCents' => 0];
		foreach ($parsed['groups'] as $group) {
			$group['items'] = array_map(static function (array $item) use ($opts): array {
				if ($item['accountId'] === null && $opts['accountId'] !== null) {
					$item['accountId'] = $opts['accountId'];
				}
				return $item;
			}, $group['items']);

			if ($group['errors'] === []) {
				try {
					$this->invoiceService->validateLines($group['items']);
				} catch (\InvalidArgumentException $e) {
					$group['errors'][] = $e->getMessage();
				}
			}
			foreach ($group['items'] as $item) {
				if ($item['accountId'] === null) {
					$group['warnings'][] = $this->msg('Mindestens eine Position hat kein Erlöskonto – zum Festschreiben nötig (Spalte „Erlöskonto“, Vorlage oder Vorgabe im Dialog).');
					break;
				}
			}

			$group['header'] = $this->header($group['items'], $opts);
			if (!isset($group['header']['deliveryDate']) && !isset($group['header']['periodStart'])) {
				$group['warnings'][] = $this->msg('Kein Leistungsdatum – zum Festschreiben nötig (Spalte „Leistungsdatum“ oder Vorgabe im Dialog).');
			}

			$match = $matcher->match($group['recipient']);
			$debtor = $match['debtor'];
			$group['debtorId'] = $debtor?->getId();
			$group['debtorName'] = $debtor?->getName();
			$group['debtorStatus'] = $debtor !== null ? 'existing' : 'new';
			$group['fill'] = $match['fill'];
			$group['recipientKey'] = self::recipientKey($group['recipient']);
			if ($match['differences'] !== []) {
				$labels = array_map(fn (string $f): string => $this->fieldLabel($f), $match['differences']);
				$group['warnings'][] = $this->msg('Vorhandener Empfänger wird verwendet; abweichende Angaben in der Tabelle (%s) werden nicht übernommen.', [implode(', ', $labels)]);
			}
			$group['email'] = $debtor !== null ? ($debtor->getEmail() ?? $match['fill']['email'] ?? null) : $group['recipient']['email'];
			$group['invoiceId'] = null;

			if ($group['errors'] === []) {
				$summary['invoices']++;
				$summary['totalCents'] += $group['totalCents'];
				if ($group['email'] === null || $group['email'] === '') {
					$summary['withoutEmail']++;
				}
				if ($debtor !== null) {
					$existing[(int)$debtor->getId()] = true;
				} else {
					$newRecipients[$group['recipientKey']] = true;
				}
			} else {
				$summary['failed']++;
			}
			$groups[] = $group;
		}
		$summary['newDebtors'] = count($newRecipients);
		$summary['existingDebtors'] = count($existing);
		return ['groups' => $groups, 'errors' => $parsed['errors'], 'summary' => $summary];
	}

	/**
	 * Leistungsdatum oder -zeitraum der Rechnung: die Vorgabe aus dem Dialog,
	 * sonst aus den Positionen – ein gemeinsames Datum oder der Zeitraum vom
	 * frühesten bis zum spätesten.
	 *
	 * @param list<array<string, mixed>> $items
	 * @param array{deliveryDate: string|null, periodStart: string|null, periodEnd: string|null} $opts
	 * @return array<string, string>
	 */
	private function header(array $items, array $opts): array {
		if ($opts['deliveryDate'] !== null) {
			return ['deliveryDate' => $opts['deliveryDate']];
		}
		if ($opts['periodStart'] !== null && $opts['periodEnd'] !== null) {
			return ['periodStart' => $opts['periodStart'], 'periodEnd' => $opts['periodEnd']];
		}
		$dates = array_values(array_unique(array_filter(array_map(static fn (array $i): ?string => $i['deliveryDate'] ?? null, $items))));
		if ($dates === []) {
			return [];
		}
		sort($dates);
		return count($dates) === 1 ? ['deliveryDate' => $dates[0]] : ['periodStart' => $dates[0], 'periodEnd' => $dates[count($dates) - 1]];
	}

	private function parser(): BatchSheetParser {
		$templates = [];
		foreach ($this->templates->findAll() as $t) {
			$templates[mb_strtolower(trim($t->getName()))] = [
				'name' => $t->getName(),
				'description' => $t->getDescription(),
				'unitCode' => $t->getUnitCode(),
				'unitPriceCents' => $t->getUnitPriceCents(),
				'taxCategory' => $t->getTaxCategory(),
				'taxRateBp' => $t->getTaxRateBp(),
				'accountId' => $t->getAccountId(),
			];
		}
		$accounts = [];
		foreach ($this->accounts->findAll(Application::BOOK) as $account) {
			$accounts[trim($account->getNumber())] = (int)$account->getId();
		}
		$costCenters = [];
		foreach ($this->costCenters->findAll(Application::BOOK) as $cc) {
			$costCenters[mb_strtolower(trim($cc->getName()))] = (int)$cc->getId();
			// Der Code geht dem Namen vor, falls ein Name zufällig einem anderen Code gleicht.
			$costCenters[mb_strtolower(trim($cc->getCode()))] = (int)$cc->getId();
		}
		return new BatchSheetParser($templates, $accounts, $costCenters, $this->l10n);
	}

	/** @param array<string, string|null> $r */
	private static function recipientKey(array $r): string {
		$norm = static fn (?string $v): string => (string)preg_replace('/\s+/u', ' ', mb_strtolower(trim((string)$v)));
		return implode('|', [$norm($r['name']), $norm($r['postalCode']), $norm($r['city']), $norm($r['customerNo']), $norm($r['email'])]);
	}

	private function fieldLabel(string $field): string {
		return match ($field) {
			'name2' => $this->msg('Name 2'),
			'street' => $this->msg('Straße'),
			'postalCode' => $this->msg('PLZ'),
			'city' => $this->msg('Ort'),
			'email' => $this->msg('E-Mail'),
			default => $field,
		};
	}
}
