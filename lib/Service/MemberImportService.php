<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\MembershipFeeMapper;
use OCA\Vereinsbuchhaltung\Db\SepaMandateMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\Sepa\MemberCsvParser;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Massenanlage von Mitgliedern: legt aus einer CSV-Liste je Zeile ein
 * SEPA-Mandat und einen Mitgliedsbeitrag an.
 *
 * Ohne diesen Weg musste jedes Mitglied über zwei getrennte Formulare
 * angelegt werden – erst das Mandat, dann der Beitrag, mit erneuter Auswahl
 * desselben Zahlers. Bei einem Chor mit 200 Stimmen ist das der Unterschied
 * zwischen „einmal einlesen" und „einen Nachmittag lang tippen".
 *
 * Der Ablauf ist zweistufig: erst {@see preview()} (ändert nichts, zeigt je
 * Zeile, was entstehen würde und was nicht stimmt), dann {@see import()}.
 * Wer 200 Zeilen einliest, soll vorher sehen, was passiert.
 */
class MemberImportService {

	public function __construct(
		private MemberCsvParser $parser,
		private SepaMandateService $mandates,
		private MembershipFeeService $fees,
		private SepaMandateMapper $mandateMapper,
		private MembershipFeeMapper $feeMapper,
		private IUserManager $userManager,
		private TransactionRunner $transaction,
		private AuditService $audit,
		private IL10N $l10n,
	) {
	}

	/**
	 * Prüflauf ohne jede Änderung.
	 *
	 * @return array{error: ?string, rows: list<array<string, mixed>>, summary: array{ok:int, failed:int, mandates:int, fees:int}}
	 */
	public function preview(string $csv): array {
		$parsed = $this->parser->parse($csv);
		if ($parsed['error'] !== null) {
			return ['error' => $parsed['error'], 'rows' => [], 'summary' => ['ok' => 0, 'failed' => 0, 'mandates' => 0, 'fees' => 0]];
		}

		$rows = [];
		$gesehene = [];
		foreach ($parsed['rows'] as $row) {
			$fehler = array_merge($row['errors'], $this->checkRow($row, $gesehene));
			$rows[] = $this->describe($row, $fehler);
		}
		return ['error' => null, 'rows' => $rows, 'summary' => $this->summarize($rows)];
	}

	/**
	 * Legt an, was sich anlegen lässt. Fehlerhafte Zeilen werden übersprungen
	 * und einzeln gemeldet – ein Tippfehler in Zeile 143 darf die 142 Zeilen
	 * davor nicht wertlos machen.
	 *
	 * Jede Zeile läuft in ihrer eigenen Transaktion: entweder Mandat *und*
	 * Beitrag oder keins von beidem. Ein Beitrag, dessen Mandat fehlt, wäre
	 * stiller Datenmüll – er würde nie eingezogen und niemand wüsste warum.
	 *
	 * @return array{error: ?string, rows: list<array<string, mixed>>, summary: array{ok:int, failed:int, mandates:int, fees:int}}
	 */
	public function import(string $csv): array {
		$parsed = $this->parser->parse($csv);
		if ($parsed['error'] !== null) {
			return ['error' => $parsed['error'], 'rows' => [], 'summary' => ['ok' => 0, 'failed' => 0, 'mandates' => 0, 'fees' => 0]];
		}

		$rows = [];
		$gesehene = [];
		foreach ($parsed['rows'] as $row) {
			$fehler = array_merge($row['errors'], $this->checkRow($row, $gesehene));
			if ($fehler !== []) {
				$rows[] = $this->describe($row, $fehler);
				continue;
			}
			try {
				$rows[] = $this->describe($row, [], $this->createRow($row));
			} catch (\Throwable $e) {
				$rows[] = $this->describe($row, [$e->getMessage()]);
			}
		}

		$summary = $this->summarize($rows);
		if ($summary['ok'] > 0) {
			$this->audit->log('Mitglieder importiert', 'sepa_mandate', null, [
				'zeilen' => $summary['ok'],
				'mandate' => $summary['mandates'],
				'beitraege' => $summary['fees'],
				'fehlerhaft' => $summary['failed'],
			]);
		}
		return ['error' => null, 'rows' => $rows, 'summary' => $summary];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{mandateId:?int, feeId:?int}
	 */
	private function createRow(array $row): array {
		return $this->transaction->run(function () use ($row): array {
			$mandateId = null;
			if ($row['iban'] !== null) {
				$mandateId = (int)$this->mandates->create(
					$row['memberUid'],
					$row['memberLabel'],
					(string)$row['iban'],
					$row['bic'],
					'RCUR',
					(string)$row['signedDate'],
					$row['email'],
				)->getId();
			}

			$feeId = null;
			if ($row['amountCents'] !== null) {
				$feeId = (int)$this->fees->create(
					$row['memberUid'],
					$row['memberLabel'],
					(int)$row['amountCents'],
					(string)$row['frequency'],
					(string)$row['startDate'],
					null,
					$mandateId,
				)->getId();
			}

			return ['mandateId' => $mandateId, 'feeId' => $feeId];
		});
	}

	/**
	 * Prüfungen, die der Parser nicht anstellen kann, weil sie den Datenbestand
	 * brauchen.
	 *
	 * @param array<string, mixed> $row
	 * @param array<string, int> $gesehene Zahler → Zeilennummer, innerhalb dieser Datei
	 * @return list<string>
	 */
	private function checkRow(array $row, array &$gesehene): array {
		$fehler = [];

		if ($row['memberUid'] !== null && !$this->userManager->userExists((string)$row['memberUid'])) {
			$fehler[] = $this->l10n->t('Es gibt kein Nextcloud-Konto „%s".', [(string)$row['memberUid']]);
		}

		// Zweimal derselbe Zahler in einer Datei ist fast immer ein Versehen
		// (kopierte Zeile). Zweimal anlegen ergäbe zwei Mandate und zwei
		// Beiträge – also den doppelten Einzug.
		$schluessel = mb_strtolower((string)($row['memberUid'] ?? $row['memberLabel'] ?? ''));
		if ($schluessel !== '') {
			if (isset($gesehene[$schluessel])) {
				$fehler[] = $this->l10n->t('Dieser Zahler steht schon in Zeile %s dieser Datei.', [(string)$gesehene[$schluessel]]);
			} else {
				$gesehene[$schluessel] = (int)$row['line'];
			}
		}

		if ($row['iban'] !== null && $this->mandateMapper->findActiveByIban((string)$row['iban']) !== null) {
			$fehler[] = $this->l10n->t('Für diese IBAN gibt es bereits ein aktives Mandat.');
		}

		// Dieselbe Liste ein zweites Mal einzulesen ist der Normalfall, nicht
		// die Ausnahme („ist das jetzt durchgelaufen?"). Ohne diese Prüfung
		// bekäme jeder Zahler ohne IBAN dabei einen zweiten Beitrag – und der
		// Verein forderte künftig doppelt, ohne dass es irgendwo auffiele.
		if ($row['amountCents'] !== null
			&& $this->feeMapper->findActiveByMember($row['memberUid'], $row['memberLabel']) !== []) {
			$fehler[] = $this->l10n->t('Für diesen Zahler gibt es bereits einen aktiven Beitrag.');
		}

		return $fehler;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string> $errors
	 * @param array{mandateId:?int, feeId:?int}|null $created
	 * @return array<string, mixed>
	 */
	private function describe(array $row, array $errors, ?array $created = null): array {
		return [
			'line' => $row['line'],
			'name' => $row['memberUid'] ?? $row['memberLabel'] ?? '',
			'iban' => $row['iban'],
			'email' => $row['email'],
			'amount' => $row['amountCents'] !== null ? $row['amountCents'] / 100 : null,
			'frequency' => $row['frequency'],
			'startDate' => $row['startDate'],
			// Was entstehen würde bzw. entstanden ist – im Prüflauf steht hier
			// die Absicht, nach dem Import das Ergebnis.
			'willCreateMandate' => $row['iban'] !== null,
			'willCreateFee' => $row['amountCents'] !== null,
			'mandateId' => $created['mandateId'] ?? null,
			'feeId' => $created['feeId'] ?? null,
			'errors' => $errors,
		];
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return array{ok:int, failed:int, mandates:int, fees:int}
	 */
	private function summarize(array $rows): array {
		$summary = ['ok' => 0, 'failed' => 0, 'mandates' => 0, 'fees' => 0];
		foreach ($rows as $row) {
			if ($row['errors'] !== []) {
				$summary['failed']++;
				continue;
			}
			$summary['ok']++;
			$summary['mandates'] += $row['willCreateMandate'] ? 1 : 0;
			$summary['fees'] += $row['willCreateFee'] ? 1 : 0;
		}
		return $summary;
	}
}
