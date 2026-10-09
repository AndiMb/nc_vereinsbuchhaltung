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
use OCP\AppFramework\Utility\ITimeFactory;
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
 * `vbh_sepa_mandates`/`vbh_membership_fees`, die der Cutover (Issue #107,
 * Migration 000157) entfernt hat.
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
 * anders als beim früheren `vbh_membership_fees`-Import (dort war „Betrag" der
 * tatsächlich je Frequenz fällige Betrag) ist die Spalte „Betrag" hier direkt
 * `Assignment::monthlyAmountCents` – „Der Monatsbeitrag ist das Atom" (Spec
 * §3.3), genau wie es AssignmentDialog.vue für die manuelle Erfassung auch
 * verlangt. Eine Division des Altbetrags durch den Turnus hätte krumme Cent-
 * Beträge riskiert.
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
 * **Was der Prüflauf schon meldet:** alles, woran sonst erst {@see import()}
 * scheitern würde, ohne dass sich eine Regel ändert – die Form der IBAN
 * ({@see IbanValidator}, keine Prüfsumme), ein Zuweisungsbeginn in der
 * Vergangenheit ({@see AssignmentService::create()}) und eine Mitgliedsnummer
 * bzw. ein Nextcloud-Konto, das in derselben Datei schon eine frühere Zeile
 * belegt (diese Zeile würde beim Anlegen als Dublette übersprungen). Zeilen mit
 * Beitrag und IBAN, aber ohne E-Mail, bekommen eine Warnung: ihre Zuweisung
 * läuft auf Überweisung statt Lastschrift (siehe {@see createRow()}).
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
		private ITimeFactory $time,
		private IbanValidator $ibanValidator,
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

		$groups = $this->groupMapper->findAll();

		// Bestätigen muss nur, wer wirklich ein Mandat anlegen würde. Das weiß erst
		// ein Probelauf: eine Zeile mit Fehler oder als Dublette (auch mit IBAN)
		// legt keines an und taucht in der Vorschau auch nicht in der Mandatszahl
		// auf – die Checkbox dort wäre sonst nicht zu sehen, der Import aber
		// trotzdem verweigert.
		if ($persist && !$mandatesConfirmed && $this->summarize($this->processRows($parsed['rows'], $groups, false))['mandates'] > 0) {
			return [
				'error' => $this->l10n->t('Mindestens eine Zeile würde ein SEPA-Mandat anlegen. Bitte bestätigen Sie zuerst, dass die unterschriebenen Mandate vorliegen.'),
				'rows' => [],
				'summary' => $this->emptySummary(),
			];
		}

		$rows = $this->processRows($parsed['rows'], $groups, $persist);

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
	 * @param list<array<string, mixed>> $parsedRows
	 * @param ContributionGroup[] $groups
	 * @return list<array<string, mixed>>
	 */
	private function processRows(array $parsedRows, array $groups, bool $persist): array {
		$known = ['names' => $this->loadExistingDisplayNames(), 'numbers' => [], 'accounts' => []];
		$rows = [];
		foreach ($parsedRows as $row) {
			$rows[] = $this->processRow($row, $groups, $known, $persist);
		}
		return $rows;
	}

	/**
	 * `$known` merkt sich, was frühere Zeilen (DB oder dieselbe Datei) schon belegen:
	 * kleingeschriebener Anzeigename → true, Mitgliedsnummer bzw. Nextcloud-Konto →
	 * Zeilennummer der früheren Zeile dieser Datei.
	 *
	 * @param array<string, mixed> $row
	 * @param ContributionGroup[] $groups
	 * @param array{names: array<string, bool>, numbers: array<string, int>, accounts: array<string, int>} $known
	 * @return array<string, mixed>
	 */
	private function processRow(array $row, array $groups, array &$known, bool $persist): array {
		if ($row['errors'] !== []) {
			return $this->describe($row, $row['errors']);
		}

		if ($row['memberUid'] !== null && !$this->userManager->userExists((string)$row['memberUid'])) {
			return $this->describe($row, [$this->l10n->t('Es gibt kein Nextcloud-Konto „%s".', [(string)$row['memberUid']])]);
		}

		// Harte Dublette (Spec §3.1): member_number/nc_user_id gibt es schon –
		// die ganze Zeile wird übersprungen, damit ein erneuter Einlauf
		// derselben Liste niemandem ein zweites Mandat/eine zweite Zuweisung
		// verpasst ("legt nur an, gleicht nie ab"). Dasselbe gilt für eine frühere
		// Zeile derselben Datei – im Prüflauf entsteht sie nicht wirklich, soll
		// aber genauso gemeldet werden wie beim Anlegen.
		if ($row['memberUid'] !== null && isset($known['accounts'][(string)$row['memberUid']])) {
			return $this->describe($row, [], null, [], true, $this->l10n->t('Dieses Nextcloud-Konto steht in der Datei schon in Zeile %d – Zeile übersprungen.', [$known['accounts'][(string)$row['memberUid']]]));
		}
		if ($row['memberNumber'] !== null && isset($known['numbers'][(string)$row['memberNumber']])) {
			return $this->describe($row, [], null, [], true, $this->l10n->t('Diese Mitgliedsnummer steht in der Datei schon in Zeile %d – Zeile übersprungen.', [$known['numbers'][(string)$row['memberNumber']]]));
		}
		if ($row['memberUid'] !== null && $this->memberMapper->findByNcUserId((string)$row['memberUid']) !== null) {
			return $this->describe($row, [], null, [], true, $this->l10n->t('Mitglied mit diesem Nextcloud-Konto existiert bereits – Zeile übersprungen.'));
		}
		if ($row['memberNumber'] !== null && $this->memberMapper->findByMemberNumber((string)$row['memberNumber']) !== null) {
			return $this->describe($row, [], null, [], true, $this->l10n->t('Diese Mitgliedsnummer existiert bereits – Zeile übersprungen.'));
		}

		$warnings = $row['warnings'];
		$group = null;
		$assignmentPlanned = false;
		if ($row['amountCents'] !== null) {
			[$group, $groupWarning] = $this->resolveGroup($row['groupName'], $groups);
			$assignmentPlanned = $group !== null;
			if ($groupWarning !== null) {
				$warnings[] = $groupWarning;
			}
		}

		// Was sonst erst beim Anlegen auffiele (MandateService/AssignmentService
		// werfen dieselben Regeln, die Zeile fiele dann mit Fehlermeldung durch):
		// hier mit denselben Regeln vorgezogen. Die Prüfsumme der IBAN wird, wie
		// dort, nie geprüft.
		$errors = [];
		if ($row['iban'] !== null) {
			try {
				$this->ibanValidator->validate((string)$row['iban']);
			} catch (\InvalidArgumentException $e) {
				$errors[] = $e->getMessage();
			}
		}
		if ($assignmentPlanned && (string)$row['startDate'] < $this->today()) {
			$errors[] = $this->l10n->t('Der Beginn der Zuweisung (%s) liegt in der Vergangenheit – Zuweisungen gelten nie rückwirkend.', [$this->formatDate((string)$row['startDate'])]);
		}
		if ($errors !== []) {
			return $this->describe($row, $errors);
		}

		$plan = $this->plannedMember($row);
		if ($row['memberType'] === Member::TYPE_PERSON && $row['firstName'] === null && MemberService::looksLikeOrganization((string)$row['lastName'])) {
			$warnings[] = $this->l10n->t('Der Name „%s" nennt eine Rechtsform – er wird als Person angelegt. Organisationen gehören in die Spalte „Organisation".', [(string)$row['lastName']]);
		}
		if ($assignmentPlanned && $row['iban'] !== null && $this->effectiveEmail($row) === null) {
			$warnings[] = $this->l10n->t('Ohne E-Mail: Zahlungsart Überweisung statt Lastschrift (die Vorankündigung geht per E-Mail).');
		}

		$displayName = $this->displayNameOf($plan);
		$nameKey = mb_strtolower($displayName);
		if (isset($known['names'][$nameKey])) {
			$warnings[] = $this->l10n->t('Ein Mitglied namens „%s" gibt es schon – trotzdem angelegt (Namensgleichheit ist keine Dublette).', [$displayName]);
		}

		if (!$persist) {
			// Auch im Prüflauf spätere Dubletten *innerhalb derselben Datei*
			// erkennen – zwei gleich benannte Zeilen sollen beide die Warnung
			// zeigen, nicht nur die zweite.
			$this->remember($known, $row, $nameKey);
			return $this->describe($row, [], null, $warnings, assignmentPlanned: $assignmentPlanned);
		}

		try {
			$created = $this->transaction->run(fn (): array => $this->createRow($row, $group));
			$this->remember($known, $row, $nameKey);
			return $this->describe($row, [], $created, $warnings, assignmentPlanned: $assignmentPlanned);
		} catch (\Throwable $e) {
			return $this->describe($row, [$e->getMessage()]);
		}
	}

	/**
	 * Merkt sich, was diese Zeile belegt: Name (weiche Dublette) sowie
	 * Mitgliedsnummer und Nextcloud-Konto (harte Dubletten) für spätere Zeilen.
	 *
	 * @param array{names: array<string, bool>, numbers: array<string, int>, accounts: array<string, int>} $known
	 * @param array<string, mixed> $row
	 */
	private function remember(array &$known, array $row, string $nameKey): void {
		$known['names'][$nameKey] = true;
		if ($row['memberNumber'] !== null) {
			$known['numbers'][(string)$row['memberNumber']] = (int)$row['line'];
		}
		if ($row['memberUid'] !== null) {
			$known['accounts'][(string)$row['memberUid']] = (int)$row['line'];
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
	 * {@see processRow()} ist deshalb VOR diesem Aufruf Pflicht). Der Name kommt
	 * aus {@see plannedMember()} (dieselbe Heuristik wie Migration 000138 und die
	 * Alt-Fassung dieser Klasse, wenn die Datei nur „Name" kennt); anders als
	 * MemberService::createFromLabel()/findOrCreateByNcUserId() ergänzt dieser
	 * Weg auch Mitgliedsnummer, E-Mail, Anschrift, Telefon und das Eintrittsdatum,
	 * die der Import zusätzlich kennt.
	 *
	 * @param array<string, mixed> $row
	 */
	private function buildMember(array $row): Member {
		$plan = $this->plannedMember($row);

		$member = new Member();
		$member->setMemberType($plan['type']);
		$member->setFirstName($plan['firstName']);
		$member->setLastName($plan['lastName']);
		$member->setOrganizationName($plan['organizationName']);
		$member->setEmail($this->effectiveEmail($row));
		$member->setPhone($row['phone']);
		$member->setStreet($row['street']);
		$member->setPostalCode($row['postalCode']);
		$member->setCity($row['city']);
		$member->setMemberNumber($row['memberNumber']);
		if ($row['memberUid'] !== null) {
			$member->setNcUserId((string)$row['memberUid']);
		}
		// Ein Eintritt in der Vergangenheit ist ausdrücklich erlaubt (Bestandsliste);
		// ohne Angabe gilt der Importtag.
		$member->setJoinedAt($row['joinedAt'] ?? $this->today());
		$member->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
		return $this->memberMapper->insert($member);
	}

	/**
	 * Die E-Mail-Adresse, die das Mitglied tatsächlich bekäme: die der Spalte, bei
	 * einem verknüpften Nextcloud-Konto sonst die aus dessen Kontodaten.
	 *
	 * @param array<string, mixed> $row
	 */
	private function effectiveEmail(array $row): ?string {
		$email = $row['email'];
		if ($email === null && $row['memberUid'] !== null) {
			$email = $this->userManager->get((string)$row['memberUid'])?->getEMailAddress();
		}
		return $email !== null && $email !== '' ? (string)$email : null;
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

	/**
	 * Mitgliedstyp und Namensfelder, die für diese Zeile entstehen: die der
	 * Spalten Organisation bzw. Vorname/Nachname, sonst – nur „Name" oder ein
	 * Nextcloud-Konto – das Ergebnis von {@see MemberService::splitLabel()}.
	 *
	 * @param array<string, mixed> $row
	 * @return array{type:string, firstName:?string, lastName:?string, organizationName:?string}
	 */
	private function plannedMember(array $row): array {
		if ($row['memberType'] === Member::TYPE_ORGANIZATION) {
			return ['type' => Member::TYPE_ORGANIZATION, 'firstName' => null, 'lastName' => null, 'organizationName' => $row['organizationName']];
		}
		if ($row['memberType'] === Member::TYPE_PERSON) {
			return ['type' => Member::TYPE_PERSON, 'firstName' => $row['firstName'], 'lastName' => $row['lastName'], 'organizationName' => null];
		}
		if ($row['memberUid'] !== null) {
			$label = $this->userManager->get((string)$row['memberUid'])?->getDisplayName() ?? (string)$row['memberUid'];
		} else {
			$label = (string)$row['memberLabel'];
		}
		return MemberService::splitLabel($label);
	}

	/**
	 * Zusammengesetzter Anzeigename wie {@see Member::displayName()}.
	 *
	 * @param array{type:string, firstName:?string, lastName:?string, organizationName:?string} $plan
	 */
	private function displayNameOf(array $plan): string {
		if ($plan['type'] === Member::TYPE_ORGANIZATION) {
			return (string)$plan['organizationName'];
		}
		return trim(($plan['firstName'] ?? '') . ' ' . ($plan['lastName'] ?? ''));
	}

	/** @return array<string, bool> kleingeschriebener Anzeigename → true */
	private function loadExistingDisplayNames(): array {
		$names = [];
		foreach ($this->memberMapper->findAll() as $member) {
			$names[mb_strtolower($member->displayName())] = true;
		}
		return $names;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string> $errors
	 * @param array{memberId:int, mandateId:?int, mandateStatus:?string, assignmentId:?int}|null $created
	 * @param list<string> $warnings
	 * @return array<string, mixed>
	 */
	private function describe(array $row, array $errors, ?array $created = null, array $warnings = [], bool $skipped = false, ?string $skipReason = null, bool $assignmentPlanned = false): array {
		$structured = $row['memberType'] !== null;
		$named = $structured || $row['memberUid'] !== null || $row['memberLabel'] !== null;
		$plan = $named ? $this->plannedMember($row) : null;
		if (!$structured && $row['memberUid'] !== null && $this->userManager->get((string)$row['memberUid']) === null) {
			// Ein Konto, das es nicht gibt, hat auch keinen Namen, den man einordnen könnte.
			$plan = null;
		}
		return [
			'line' => $row['line'],
			// Bei getrennten Spalten (Vorname/Nachname/Organisation) der zusammengesetzte
			// Anzeigename, sonst – wie bisher – die Eingabe der Zeile (Name oder Konto).
			'name' => $structured && $plan !== null ? $this->displayNameOf($plan) : ($row['memberUid'] ?? $row['memberLabel'] ?? ''),
			// Der erkannte Mitgliedstyp, damit die Vorschau eine Fehlzuordnung der
			// Namens-Heuristik (Person/Organisation) sichtbar macht.
			'memberType' => $plan['type'] ?? null,
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

	private function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');
	}

	/** JJJJ-MM-TT → TT.MM.JJJJ, wie es in der Tabelle der Datei stand. */
	private function formatDate(string $date): string {
		return implode('.', array_reverse(explode('-', $date)));
	}

	private function currentUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}
}
