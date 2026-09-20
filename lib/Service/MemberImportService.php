<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentEvent;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\Sepa\MemberCsvParser;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Massenanlage von Mitgliedern: legt aus einer CSV-Liste je Zeile ein
 * Mitglied sowie optional ein SEPA-Mandat (voller Lifecycle, Issue #66/#67)
 * und eine Zuweisung zu einer Beitragsgruppe (Issue #68) an.
 *
 * Vollständig auf das neue Domänenmodell umgestellt (Issue #69, Spec §3.1):
 * vorher richtete sich dieselbe Klasse gegen die flachen Alt-Tabellen
 * `vbh_sepa_mandates`/`vbh_membership_fees` (siehe {@see SepaMandateService},
 * {@see MembershipFeeService}, weiterhin unverändert für den Alt-Bestand
 * nutzbar). Die beiden Systeme laufen bewusst nebeneinander, bis ein
 * gesondertes Migrationsticket den Alt-Bestand ablöst (Spec §3.1 „Umbaupfad").
 *
 * **„CSV-Import legt nur an, gleicht nie ab"** (Spec §3.1) – diese Klasse
 * berührt nie ein bereits bestehendes Mitglied. Dublettenregeln:
 * - hart (member_number/nc_user_id bereits vergeben): die ganze Zeile wird
 *   übersprungen (kein Mitglied, kein Mandat, keine Zuweisung) – ein erneuter
 *   Einlauf derselben Liste darf niemandem ein zweites Mandat verpassen.
 * - weich (Namensgleichheit): nur eine Warnung, die Zeile wird trotzdem
 *   angelegt (Mailadressen sind in Vereinen nicht eindeutig, Namen erst recht
 *   nicht – das allein blockiert nichts).
 * Mailadresse ist ausdrücklich **kein** Dublettenschlüssel.
 *
 * **Betrag = Monatsbeitrag** (bewusste Modellentscheidung für dieses Ticket):
 * anders als beim alten `vbh_membership_fees`-Import (dort war „Betrag" der
 * tatsächlich je Frequenz fällige Betrag) ist die Spalte „Betrag" hier direkt
 * `Assignment::monthlyAmountCents` – „Der Monatsbeitrag ist das Atom" (Spec
 * §3.3), genau wie es AssignmentDialog.vue für die manuelle Erfassung auch
 * verlangt. Eine Division des Altbetrags durch den Turnus hätte krumme Cent-
 * Beträge riskiert; diese Neuinterpretation der Spalte ist für den neuen,
 * vollständigen Import (Issue #69) tragbar, weil es die erste Version dieses
 * Imports mit Beitragsgruppen überhaupt ist.
 *
 * **Mandats-Aktivierung** (Spec §3.1): ein per Import angelegtes Mandat hat
 * durch die Parser-Regel „IBAN verlangt ein Mandatsdatum" immer ein
 * `signed_at` – es wird deshalb immer sofort aktiviert, nie im Entwurf
 * belassen. Die Bestätigungs-Checkbox in der Vorschau ("die unterschriebenen
 * Mandate liegen vor", siehe {@see \OCA\Vereinsbuchhaltung\Controller\MemberImportController})
 * *ist* die vom Aktivierungs-Gate verlangte Admin-Handlung – ohne sie lehnt
 * {@see import()} jede Datei mit mindestens einer Mandatszeile komplett ab.
 * Der Import erzeugt ausschließlich Papier-Mandate: ein Einmal-Link (Issue
 * #67) setzt eine tatsächliche Zustimmung *durch das Mitglied selbst* voraus,
 * die ein Massenimport nicht ersetzen kann.
 *
 * Der Ablauf ist zweistufig: erst {@see preview()} (ändert nichts, zeigt je
 * Zeile, was entstehen würde und was nicht stimmt), dann {@see import()}.
 */
class MemberImportService {

	public function __construct(
		private MemberCsvParser $parser,
		private MemberMapper $memberMapper,
		private MandateService $mandates,
		private AssignmentService $assignments,
		private ContributionGroupMapper $groupMapper,
		private IUserManager $userManager,
		private TransactionRunner $transaction,
		private AuditService $audit,
		private IUserSession $userSession,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	/**
	 * Standard-Beitrag aus den Einstellungen (SettingsSepaBasics.vue), fuer
	 * Zeilen mit Start-Datum, aber ohne eigenen Betrag - siehe
	 * MemberCsvParser::parseRow(). Cents ist null, wenn kein Standardbeitrag
	 * hinterlegt ist.
	 *
	 * @return array{0: ?int, 1: ?string}
	 */
	private function defaultFee(): array {
		$cents = $this->config->getAppValue(Application::APP_ID, 'default_fee_amount_cents', '');
		if ($cents === '') {
			return [null, null];
		}
		return [(int)$cents, $this->config->getAppValue(Application::APP_ID, 'default_fee_frequency', 'yearly')];
	}

	/**
	 * Prüflauf ohne jede Änderung.
	 *
	 * @return array{error: ?string, rows: list<array<string, mixed>>, summary: array<string, int>}
	 */
	public function preview(string $csv): array {
		return $this->run($csv, false, false);
	}

	/**
	 * Legt an, was sich anlegen lässt. Fehlerhafte Zeilen werden übersprungen
	 * und einzeln gemeldet – ein Tippfehler in Zeile 143 darf die 142 Zeilen
	 * davor nicht wertlos machen.
	 *
	 * @param bool $mandatesConfirmed Die Bestätigungs-Checkbox aus der Vorschau
	 *                                ("die unterschriebenen Mandate liegen vor") – Pflicht, sobald
	 *                                mindestens eine Zeile ein Mandat anlegen würde (Spec §3.1).
	 * @return array{error: ?string, rows: list<array<string, mixed>>, summary: array<string, int>}
	 */
	public function import(string $csv, bool $mandatesConfirmed = false): array {
		return $this->run($csv, true, $mandatesConfirmed);
	}

	/**
	 * @return array{error: ?string, rows: list<array<string, mixed>>, summary: array<string, int>}
	 */
	private function run(string $csv, bool $persist, bool $mandatesConfirmed): array {
		[$defaultAmountCents, $defaultFrequency] = $this->defaultFee();
		$parsed = $this->parser->parse($csv, $defaultAmountCents, $defaultFrequency);
		if ($parsed['error'] !== null) {
			return ['error' => $parsed['error'], 'rows' => [], 'summary' => $this->emptySummary()];
		}

		if ($persist && !$mandatesConfirmed && $this->anyRowCreatesMandate($parsed['rows'])) {
			return [
				'error' => $this->l10n->t('Mindestens eine Zeile würde ein SEPA-Mandat anlegen. Bitte bestätigen Sie zuerst, dass die unterschriebenen Mandate vorliegen.'),
				'rows' => [],
				'summary' => $this->emptySummary(),
			];
		}

		$groups = $this->groupMapper->findAll();
		$knownNames = $this->loadExistingDisplayNames();

		$rows = [];
		foreach ($parsed['rows'] as $row) {
			$rows[] = $this->processRow($row, $groups, $knownNames, $persist);
		}

		$summary = $this->summarize($rows);
		if ($persist && $summary['ok'] > 0) {
			$this->audit->log('Mitglieder importiert', 'member', null, [
				'zeilen' => $summary['ok'],
				'mandate' => $summary['mandates'],
				'zuweisungen' => $summary['assignments'],
				'uebersprungen' => $summary['skipped'],
				'fehlerhaft' => $summary['failed'],
			]);
		}
		return ['error' => null, 'rows' => $rows, 'summary' => $summary];
	}

	/**
	 * @param array<string, mixed> $row
	 * @param ContributionGroup[] $groups
	 * @param array<string, bool> $knownNames Kleingeschriebener Anzeigename → schon gesehen (DB oder frühere Zeile dieser Datei)
	 * @return array<string, mixed>
	 */
	private function processRow(array $row, array $groups, array &$knownNames, bool $persist): array {
		if ($row['errors'] !== []) {
			return $this->describe($row, $row['errors']);
		}

		if ($row['memberUid'] !== null && !$this->userManager->userExists((string)$row['memberUid'])) {
			return $this->describe($row, [$this->l10n->t('Es gibt kein Nextcloud-Konto „%s".', [(string)$row['memberUid']])]);
		}

		// Harte Dublette (Spec §3.1): member_number/nc_user_id gibt es schon –
		// die ganze Zeile wird übersprungen, damit ein erneuter Einlauf
		// derselben Liste niemandem ein zweites Mandat/eine zweite Zuweisung
		// verpasst ("legt nur an, gleicht nie ab").
		if ($row['memberUid'] !== null && $this->memberMapper->findByNcUserId((string)$row['memberUid']) !== null) {
			return $this->describe($row, [], null, [], true, $this->l10n->t('Mitglied mit diesem Nextcloud-Konto existiert bereits – Zeile übersprungen.'));
		}
		if ($row['memberNumber'] !== null && $this->memberMapper->findByMemberNumber((string)$row['memberNumber']) !== null) {
			return $this->describe($row, [], null, [], true, $this->l10n->t('Diese Mitgliedsnummer existiert bereits – Zeile übersprungen.'));
		}

		$warnings = [];
		$group = null;
		$assignmentPlanned = false;
		if ($row['amountCents'] !== null) {
			[$group, $groupWarning] = $this->resolveGroup($row['groupName'], $groups);
			$assignmentPlanned = $group !== null;
			if ($groupWarning !== null) {
				$warnings[] = $groupWarning;
			}
		}

		$displayName = $this->plannedDisplayName($row);
		$nameKey = mb_strtolower($displayName);
		if (isset($knownNames[$nameKey])) {
			$warnings[] = $this->l10n->t('Ein Mitglied namens „%s" gibt es schon – trotzdem angelegt (Namensgleichheit ist keine Dublette).', [$displayName]);
		}

		if (!$persist) {
			// Auch im Prüflauf spätere Dubletten *innerhalb derselben Datei*
			// erkennen – zwei gleich benannte Zeilen sollen beide die Warnung
			// zeigen, nicht nur die zweite.
			$knownNames[$nameKey] = true;
			return $this->describe($row, [], null, $warnings, assignmentPlanned: $assignmentPlanned);
		}

		try {
			$created = $this->transaction->run(fn (): array => $this->createRow($row, $group));
			$knownNames[$nameKey] = true;
			return $this->describe($row, [], $created, $warnings, assignmentPlanned: $assignmentPlanned);
		} catch (\Throwable $e) {
			return $this->describe($row, [$e->getMessage()]);
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{memberId:int, mandateId:?int, mandateStatus:?string, assignmentId:?int}
	 */
	private function createRow(array $row, ?ContributionGroup $group): array {
		$member = $this->buildMember($row);

		$mandateId = null;
		$mandateStatus = null;
		if ($row['iban'] !== null) {
			$mandate = $this->mandates->createPaper(
				(int)$member->getId(),
				(string)$row['iban'],
				$row['bic'],
				$row['accountHolder'],
				$row['signedDate'],
				$row['mandateReference'],
			);
			// Die Preview-Bestätigung ("die unterschriebenen Mandate liegen vor")
			// *ist* die vom Aktivierungs-Gate verlangte Admin-Handlung (Spec
			// §3.1) – jede Importzeile mit IBAN hat dank der Parser-Regel immer
			// ein Mandatsdatum, aktiviert also immer sofort.
			$mandate = $this->mandates->activatePaper((int)$mandate->getId());
			$mandateId = $mandate->getId();
			$mandateStatus = $mandate->getStatus();
		}

		$assignmentId = null;
		if ($group !== null && $row['amountCents'] !== null) {
			// Die tatsächlich am Mitglied hinterlegte Mailadresse entscheidet
			// (Spec §3.1 "Zeile ohne Mail landet auf ueberweisung") – bei einem
			// verknüpften NC-Konto kann die aus dessen Kontodaten stammen, auch
			// wenn die CSV-Spalte selbst leer war (siehe buildMember()).
			$paymentMethod = $member->getEmail() !== null ? Assignment::PAYMENT_METHOD_DIRECT_DEBIT : Assignment::PAYMENT_METHOD_TRANSFER;
			$intervalMonths = BillingPeriod::FREQUENCY_MONTHS[$row['frequency']] ?? 12;
			$assignment = $this->assignments->create(
				(int)$member->getId(),
				(int)$group->getId(),
				$intervalMonths,
				(int)$row['amountCents'],
				$paymentMethod,
				(string)$row['startDate'],
				null,
				null,
				null,
				AssignmentEvent::ACTOR_STAFF,
				$this->currentUid(),
			);
			$assignmentId = $assignment->getId();
		}

		return ['memberId' => (int)$member->getId(), 'mandateId' => $mandateId, 'mandateStatus' => $mandateStatus, 'assignmentId' => $assignmentId];
	}

	/**
	 * Legt das Mitglied frisch an – nie ein bestehendes wiederverwenden (siehe
	 * Klassendoc "legt nur an, gleicht nie ab"; die Dublettenprüfung in
	 * {@see processRow()} ist deshalb VOR diesem Aufruf Pflicht). Baut selbst
	 * auf {@see MemberService::splitLabel()} auf (dieselbe Heuristik wie
	 * Migration 000138 und die Alt-Fassung dieser Klasse), ergänzt aber –
	 * anders als MemberService::createFromLabel()/findOrCreateByNcUserId() –
	 * auch Mitgliedsnummer und E-Mail, die dieser Import zusätzlich kennt.
	 *
	 * @param array<string, mixed> $row
	 */
	private function buildMember(array $row): Member {
		$email = $row['email'];
		if ($row['memberUid'] !== null) {
			$user = $this->userManager->get((string)$row['memberUid']);
			$label = $user?->getDisplayName() ?? (string)$row['memberUid'];
			$split = MemberService::splitLabel($label);
			$email ??= $user?->getEMailAddress();
		} else {
			$split = MemberService::splitLabel((string)$row['memberLabel']);
		}

		$member = new Member();
		$member->setMemberType($split['type']);
		$member->setFirstName($split['firstName']);
		$member->setLastName($split['lastName']);
		$member->setOrganizationName($split['organizationName']);
		$member->setEmail($email);
		$member->setMemberNumber($row['memberNumber']);
		if ($row['memberUid'] !== null) {
			$member->setNcUserId((string)$row['memberUid']);
		}
		$member->setJoinedAt((new \DateTime())->format('Y-m-d'));
		$member->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
		return $this->memberMapper->insert($member);
	}

	/**
	 * @param ContributionGroup[] $groups
	 * @return array{0: ?ContributionGroup, 1: ?string} Gruppe (falls auflösbar) + Warnung (falls nicht)
	 */
	private function resolveGroup(?string $groupName, array $groups): array {
		if ($groupName !== null) {
			foreach ($groups as $group) {
				if (mb_strtolower($group->getName()) === mb_strtolower($groupName)) {
					return [$group, null];
				}
			}
			return [null, $this->l10n->t('Unbekannte Beitragsgruppe „%s" – der Beitrag wird nicht angelegt.', [$groupName])];
		}
		// Nachsichtiger Fallback: bei genau einer bestehenden Beitragsgruppe ist
		// die Zuordnung eindeutig, auch ohne eigene Spalte in der Datei (der
		// häufigste Fall: ein Verein mit einem einzigen Beitragssatz).
		if (count($groups) === 1) {
			return [$groups[0], null];
		}
		return [null, $this->l10n->t('Keine Beitragsgruppe angegeben – der Beitrag wird nicht angelegt.')];
	}

	/** @param array<string, mixed> $row */
	private function plannedDisplayName(array $row): string {
		if ($row['memberUid'] !== null) {
			$user = $this->userManager->get((string)$row['memberUid']);
			return $user?->getDisplayName() ?? (string)$row['memberUid'];
		}
		return (string)$row['memberLabel'];
	}

	/** @return array<string, bool> kleingeschriebener Anzeigename → true */
	private function loadExistingDisplayNames(): array {
		$names = [];
		foreach ($this->memberMapper->findAll() as $member) {
			$names[mb_strtolower($member->displayName())] = true;
		}
		return $names;
	}

	/** @param list<array<string, mixed>> $rows */
	private function anyRowCreatesMandate(array $rows): bool {
		foreach ($rows as $row) {
			if ($row['errors'] === [] && $row['iban'] !== null) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string> $errors
	 * @param array{memberId:int, mandateId:?int, mandateStatus:?string, assignmentId:?int}|null $created
	 * @param list<string> $warnings
	 * @return array<string, mixed>
	 */
	private function describe(array $row, array $errors, ?array $created = null, array $warnings = [], bool $skipped = false, ?string $skipReason = null, bool $assignmentPlanned = false): array {
		return [
			'line' => $row['line'],
			'name' => $row['memberUid'] ?? $row['memberLabel'] ?? '',
			'memberNumber' => $row['memberNumber'],
			'iban' => $row['iban'],
			'email' => $row['email'],
			'groupName' => $row['groupName'],
			'amount' => $row['amountCents'] !== null ? $row['amountCents'] / 100 : null,
			'frequency' => $row['frequency'],
			'startDate' => $row['startDate'],
			// "geplant" heißt: nach Auflösung der Beitragsgruppe würde dieser
			// Block tatsächlich entstehen – anders als willCreateAssignment
			// unten (reine Spalten-Absicht) zieht das eine nicht auflösbare
			// Beitragsgruppe (Warnung statt Fehler) bereits ab.
			'willCreateMandate' => $row['iban'] !== null,
			'willCreateAssignment' => $assignmentPlanned,
			'memberId' => $created['memberId'] ?? null,
			'mandateId' => $created['mandateId'] ?? null,
			'mandateStatus' => $created['mandateStatus'] ?? null,
			'assignmentId' => $created['assignmentId'] ?? null,
			'skipped' => $skipped,
			'skipReason' => $skipReason,
			'warnings' => $warnings,
			'errors' => $errors,
		];
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return array<string, int>
	 */
	private function summarize(array $rows): array {
		$summary = $this->emptySummary();
		foreach ($rows as $row) {
			if ($row['skipped']) {
				$summary['skipped']++;
				continue;
			}
			if ($row['errors'] !== []) {
				$summary['failed']++;
				continue;
			}
			$summary['ok']++;
			$summary['mandates'] += $row['willCreateMandate'] ? 1 : 0;
			$summary['assignments'] += $row['willCreateAssignment'] ? 1 : 0;
			$summary['warnings'] += $row['warnings'] !== [] ? 1 : 0;
		}
		return $summary;
	}

	/** @return array<string, int> */
	private function emptySummary(): array {
		return ['ok' => 0, 'skipped' => 0, 'failed' => 0, 'mandates' => 0, 'assignments' => 0, 'warnings' => 0];
	}

	private function currentUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}
}
