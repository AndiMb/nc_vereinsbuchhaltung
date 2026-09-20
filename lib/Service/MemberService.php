<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\MembershipFeeMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\SepaMandateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Mitglieder-Stammdaten (Spec §2.2/§3.1, docs/beitraege-sepa-modul-spec.md):
 * Mitglieder unabhängig von einem Nextcloud-Konto, mit optionaler, nur per
 * menschlicher Bestätigung entstehender Kontoverknüpfung.
 */
class MemberService {

	public function __construct(
		private MemberMapper $mapper,
		private SepaMandateMapper $mandateMapper,
		private MembershipFeeMapper $feeMapper,
		private AssignmentMapper $assignmentMapper,
		private OpenItemMapper $openItemMapper,
		private IUserManager $userManager,
		private IL10N $l10n,
	) {
	}

	/** @return Member[] */
	public function findAll(): array {
		return $this->mapper->findAll();
	}

	/** @throws DoesNotExistException wenn es das Mitglied nicht (mehr) gibt */
	public function find(int $id): Member {
		return $this->mapper->find($id);
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws \InvalidArgumentException bei ungültigen Eingaben
	 */
	public function create(array $data): Member {
		$member = new Member();
		$this->applyStammdaten($member, $data);
		$member->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
		return $this->mapper->insert($member);
	}

	/**
	 * Ändert die Stammdaten. Bewusst ohne `leftAt`/`ncUserId`: Austritt und
	 * Kontoverknüpfung sind eigene Vorgänge mit eigener Audit-Spur (siehe
	 * {@see leave()}, {@see reactivate()}, {@see link()}, {@see unlink()}),
	 * keine beiläufige Feldänderung im großen Stammdatenformular.
	 *
	 * Nach einer DSGVO-Anonymisierung (Spec §3.8, Issue #78) gesperrt – sonst
	 * ließe sich ein gerade erst geschwärztes Mitglied über dasselbe Formular
	 * gleich wieder mit einem (ggf. frei erfundenen) Namen befüllen, was den
	 * ausdrücklich irreversiblen Anonymisierungs-Vorgang unterlaufen würde.
	 *
	 * @param array<string, mixed> $data
	 * @throws DoesNotExistException wenn es das Mitglied nicht (mehr) gibt
	 * @throws \InvalidArgumentException bei ungültigen Eingaben, oder wenn das Mitglied bereits anonymisiert ist
	 */
	public function update(int $id, array $data): Member {
		$member = $this->mapper->find($id);
		if ($member->isRedacted()) {
			throw new \InvalidArgumentException($this->l10n->t('Dieses Mitglied ist anonymisiert; Stammdaten können nicht mehr bearbeitet werden.'));
		}
		$this->applyStammdaten($member, $data);
		return $this->mapper->update($member);
	}

	/**
	 * Self-Service-Kontaktdatenpflege (Spec §2.2/§3.4: „Kontaktdaten pflegt
	 * das Mitglied, Vereinsdaten pflegt der Verein" – Hoheitsspalte der
	 * Feldtabelle). Anders als {@see update()} bewusst NUR die Felder mit
	 * Hoheit „Mitglied": Name (je nach bestehendem Typ), E-Mail, Telefon,
	 * Adresse. `memberType` (Diskriminator), `memberNumber`, `joinedAt`,
	 * `leftAt`, `ncUserId` und `internalNote` sind Vereinshoheit und bleiben
	 * unberührt – ein fehlender Schlüssel in $data überschreibt hier (anders
	 * als bei {@see applyStammdaten()}) NICHT mit einem Default, sondern
	 * behält den bisherigen Wert.
	 *
	 * @param array<string, mixed> $data
	 * @return array{member: Member, emailChanged: bool, oldEmail: ?string}
	 * @throws DoesNotExistException wenn es das Mitglied nicht (mehr) gibt
	 * @throws \InvalidArgumentException bei ungültigen Eingaben
	 */
	public function updateOwnContactData(int $id, array $data): array {
		$member = $this->mapper->find($id);
		$oldEmail = $member->getEmail();

		if ($member->getMemberType() === Member::TYPE_PERSON) {
			$lastName = trim((string)($data['lastName'] ?? $member->getLastName()));
			if ($lastName === '') {
				throw new \InvalidArgumentException($this->l10n->t('Der Nachname darf nicht leer sein.'));
			}
			$member->setFirstName($this->nullIfEmpty($data['firstName'] ?? $member->getFirstName()));
			$member->setLastName($lastName);
		} else {
			$organizationName = trim((string)($data['organizationName'] ?? $member->getOrganizationName()));
			if ($organizationName === '') {
				throw new \InvalidArgumentException($this->l10n->t('Der Name darf nicht leer sein.'));
			}
			$member->setOrganizationName($organizationName);
		}

		$member->setEmail($this->normalizeEmail($data['email'] ?? $member->getEmail()));
		$member->setPhone($this->nullIfEmpty($data['phone'] ?? $member->getPhone()));
		$member->setStreet($this->nullIfEmpty($data['street'] ?? $member->getStreet()));
		$member->setPostalCode($this->nullIfEmpty($data['postalCode'] ?? $member->getPostalCode()));
		$member->setCity($this->nullIfEmpty($data['city'] ?? $member->getCity()));
		$member->setCountry($this->nullIfEmpty($data['country'] ?? $member->getCountry()));

		$member = $this->mapper->update($member);
		return [
			'member' => $member,
			'emailChanged' => $member->getEmail() !== $oldEmail,
			'oldEmail' => $oldEmail,
		];
	}

	/**
	 * @throws DoesNotExistException wenn es das Mitglied nicht (mehr) gibt
	 * @throws \InvalidArgumentException wenn das Mitglied nicht gelöscht werden darf
	 */
	public function delete(int $id): void {
		$member = $this->mapper->find($id);
		$reasons = $this->blockingReasons($id);
		if ($reasons !== []) {
			throw new \InvalidArgumentException(implode(' ', $reasons));
		}
		$this->mapper->delete($member);
	}

	/**
	 * Gründe, warum sich ein Mitglied (noch) nicht löschen lässt – die
	 * Oberfläche zeigt sie als erklärende Sperrmeldung statt den
	 * Löschen-Knopf einfach auszugrauen (Spec §3.1).
	 *
	 * Referenzielle Sicherheit, damit member_id in keiner der vier
	 * verweisenden Tabellen verwaist, solange es noch keine Kaskaden-Logik
	 * gibt: aktives/widerrufenes SEPA-Mandat, Alt-Beitrag
	 * (vbh_membership_fees), Zuweisung (vbh_assignments) und Forderung
	 * (vbh_open_items mit gesetztem member_id) blockieren alle die Löschung.
	 * Die beiden letzteren gibt es erst seit Issue #68 (Beitragsgruppen/
	 * Zuweisungen) – zuvor war die Prüfung hier laut Akzeptanzkriterium noch
	 * trivial erlaubt, weil es schlicht keine Forderungen mit member_id gab.
	 *
	 * @return string[] leer = löschbar
	 */
	public function blockingReasons(int $id): array {
		return $this->blockingReasonsForIds([$id])[$id] ?? [];
	}

	/**
	 * Dasselbe wie {@see blockingReasons()}, aber für beliebig viele Mitglieder
	 * in wenigen Abfragen statt bis zu vier je Mitglied – wichtig, weil
	 * {@see \OCA\Vereinsbuchhaltung\Controller\MemberController::index()} das
	 * für die gesamte Mitgliederliste auf einmal braucht (sonst N+1).
	 *
	 * @param int[] $memberIds
	 * @return array<int, string[]> member_id => Sperrgründe (leer = löschbar)
	 */
	public function blockingReasonsForIds(array $memberIds): array {
		$activeMandateIds = [];
		$anyMandateIds = [];
		foreach ($this->mandateMapper->findAll() as $mandate) {
			$anyMandateIds[$mandate->getMemberId()] = true;
			if ($mandate->getStatus() === 'active') {
				$activeMandateIds[$mandate->getMemberId()] = true;
			}
		}
		$anyFeeIds = [];
		foreach ($this->feeMapper->findAll() as $fee) {
			$anyFeeIds[$fee->getMemberId()] = true;
		}
		$anyAssignmentIds = [];
		foreach ($this->assignmentMapper->findAll() as $assignment) {
			$anyAssignmentIds[$assignment->getMemberId()] = true;
		}
		$anyClaimIds = [];
		foreach ($this->openItemMapper->findClaims() as $claim) {
			if ($claim->getMemberId() !== null) {
				$anyClaimIds[$claim->getMemberId()] = true;
			}
		}

		$result = [];
		foreach ($memberIds as $id) {
			$reasons = [];
			if (isset($activeMandateIds[$id])) {
				$reasons[] = $this->l10n->t('Es gibt noch ein aktives SEPA-Mandat für dieses Mitglied.');
			} elseif (isset($anyMandateIds[$id])) {
				$reasons[] = $this->l10n->t('Es gibt noch ein (widerrufenes) SEPA-Mandat für dieses Mitglied.');
			}
			if (isset($anyFeeIds[$id])) {
				$reasons[] = $this->l10n->t('Es gibt noch einen Mitgliedsbeitrag für dieses Mitglied.');
			}
			if (isset($anyAssignmentIds[$id])) {
				$reasons[] = $this->l10n->t('Es gibt noch eine Zuweisung zu einer Beitragsgruppe für dieses Mitglied.');
			}
			if (isset($anyClaimIds[$id])) {
				$reasons[] = $this->l10n->t('Es gibt noch eine Forderung für dieses Mitglied.');
			}
			$result[$id] = $reasons;
		}
		return $result;
	}

	/**
	 * Austritt: setzt left_at, auch auf ein Zukunftsdatum. Kein separates
	 * Archivfeld – left_at in der Vergangenheit *ist* der Status
	 * „ausgetreten" (Spec §2.2/§3.1). Kaskadierende Wirkung auf Zuweisungen/
	 * Mandate ist bewusst nicht Teil dieses Tickets.
	 *
	 * @throws DoesNotExistException wenn es das Mitglied nicht (mehr) gibt
	 */
	public function leave(int $id, string $leftAt): Member {
		$member = $this->mapper->find($id);
		$member->setLeftAt($this->requireDate($leftAt, $this->l10n->t('Ungültiges Austrittsdatum (erwartet JJJJ-MM-TT).')));
		return $this->mapper->update($member);
	}

	/** Nimmt einen erklärten Austritt zurück, solange er noch nicht wirksam sein muss. */
	public function reactivate(int $id): Member {
		$member = $this->mapper->find($id);
		$member->setLeftAt(null);
		return $this->mapper->update($member);
	}

	/**
	 * Vorschläge für eine NC-Kontoverknüpfung: alle Nextcloud-Konten mit
	 * genau der Mailadresse des Mitglieds. Mailadresse ist Vorschlagsschlüssel,
	 * nie Vollzug – bei mehreren Treffern werden alle gezeigt, keiner
	 * vorausgewählt (Spec §3.1). Ohne Mailadresse gibt es keinen Vorschlag.
	 *
	 * @return array<int, array{uid:string, displayName:string, email:?string}>
	 * @throws DoesNotExistException wenn es das Mitglied nicht (mehr) gibt
	 */
	public function findLinkSuggestions(int $id): array {
		$member = $this->mapper->find($id);
		if ($member->getEmail() === null || $member->getEmail() === '') {
			return [];
		}
		$suggestions = [];
		foreach ($this->userManager->getByEmail($member->getEmail()) as $user) {
			$suggestions[] = [
				'uid' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
				'email' => $user->getEMailAddress(),
			];
		}
		return $suggestions;
	}

	/**
	 * Verknüpft ein Mitglied mit einem Nextcloud-Konto – ausschließlich nach
	 * menschlicher Bestätigung (Spec §3.1); diese Methode wählt nichts selbst
	 * aus. 1:1-Identität „NC-Konto ist dieses Mitglied", kein Zugriffsrecht.
	 *
	 * @throws DoesNotExistException wenn es das Mitglied nicht (mehr) gibt
	 * @throws \InvalidArgumentException wenn das Konto nicht existiert oder schon verknüpft ist
	 */
	public function link(int $id, string $ncUserId): Member {
		$member = $this->mapper->find($id);
		if (!$this->userManager->userExists($ncUserId)) {
			throw new \InvalidArgumentException($this->l10n->t('Dieses Nextcloud-Konto gibt es nicht: %s', [$ncUserId]));
		}
		$existing = $this->mapper->findByNcUserId($ncUserId);
		if ($existing !== null && $existing->getId() !== $member->getId()) {
			throw new \InvalidArgumentException($this->l10n->t('Dieses Nextcloud-Konto ist bereits mit einem anderen Mitglied verknüpft: %s', [$existing->displayName()]));
		}
		$member->setNcUserId($ncUserId);
		return $this->mapper->update($member);
	}

	/**
	 * Löst nur die Verknüpfung – das Mitglied und seine Historie bleiben
	 * bestehen (Spec §2.2: „NC-Konto-Löschung leert nur nc_user_id").
	 *
	 * @throws DoesNotExistException wenn es das Mitglied nicht (mehr) gibt
	 */
	public function unlink(int $id): Member {
		$member = $this->mapper->find($id);
		$member->setNcUserId(null);
		return $this->mapper->update($member);
	}

	/**
	 * Findet das mit diesem NC-Konto verknüpfte Mitglied oder legt eines neu
	 * an (Anzeigename des Kontos, am ersten Leerzeichen gesplittet – siehe
	 * {@see splitLabel()}). Idempotent über nc_user_id, deshalb sowohl für
	 * die Migration (Version000138, je distinctem member_uid genau einmal)
	 * als auch den CSV-Import (MemberImportService, wiederholte Einläufe
	 * derselben Zeile) geeignet.
	 */
	public function findOrCreateByNcUserId(string $ncUserId): Member {
		$existing = $this->mapper->findByNcUserId($ncUserId);
		if ($existing !== null) {
			return $existing;
		}
		$user = $this->userManager->get($ncUserId);
		$member = $this->newMemberFromLabel($user?->getDisplayName() ?? $ncUserId);
		$member->setEmail($user?->getEMailAddress());
		$member->setNcUserId($ncUserId);
		return $this->mapper->insert($member);
	}

	/**
	 * Legt für einen freien Zahlernamen immer ein neues Mitglied an – anders
	 * als {@see findOrCreateByNcUserId()} bewusst ohne Abgleich: „CSV-Import
	 * legt nur an, gleicht nie ab" (Spec §3.1) gilt für Freitext-Zahler ohne
	 * Konto, weil Namensgleichheit kein verlässlicher Schlüssel ist. Für die
	 * Migration (Version000138) ruft der Aufrufer diese Methode genau einmal
	 * je distinctem member_label auf (Dedup dort, nicht hier).
	 */
	public function createFromLabel(string $label): Member {
		return $this->mapper->insert($this->newMemberFromLabel($label));
	}

	/**
	 * Gemeinsamer Kern von {@see findOrCreateByNcUserId()} und
	 * {@see createFromLabel()}: ein frisches, noch nicht gespeichertes
	 * Mitglied mit den aus dem Namen gesplitteten Stammdaten und den
	 * Default-Feldern (Beitritt heute). Der Aufrufer ergänzt danach, was ihn
	 * unterscheidet (email/nc_user_id bei einem NC-Konto), und speichert.
	 */
	private function newMemberFromLabel(string $label): Member {
		$split = self::splitLabel($label);
		$member = new Member();
		$member->setMemberType($split['type']);
		$member->setFirstName($split['firstName']);
		$member->setLastName($split['lastName']);
		$member->setOrganizationName($split['organizationName']);
		$member->setJoinedAt((new \DateTime())->format('Y-m-d'));
		$member->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
		return $member;
	}

	/**
	 * Split-Heuristik für einen Freitext-Zahlernamen (Spec §3.1 „Umbaupfad"):
	 * enthält der Name ein Leerzeichen, ist er eine Person (Split am ersten
	 * Leerzeichen in Vor-/Nachname), sonst eine Organisation. Bewusst eine
	 * reine, abhängigkeitsfreie Funktion – sowohl von der Migration als auch
	 * von {@see findOrCreateByNcUserId()}/{@see createFromLabel()} genutzt,
	 * und direkt unit-testbar ohne Mock-Aufwand.
	 *
	 * @return array{type:string, firstName:?string, lastName:?string, organizationName:?string}
	 */
	public static function splitLabel(string $label): array {
		$label = trim($label);
		$space = strpos($label, ' ');
		if ($space === false) {
			return ['type' => Member::TYPE_ORGANIZATION, 'firstName' => null, 'lastName' => null, 'organizationName' => $label];
		}
		return [
			'type' => Member::TYPE_PERSON,
			'firstName' => trim(substr($label, 0, $space)),
			'lastName' => trim(substr($label, $space + 1)),
			'organizationName' => null,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws \InvalidArgumentException bei ungültigen Eingaben
	 */
	private function applyStammdaten(Member $member, array $data): void {
		$type = (string)($data['memberType'] ?? Member::TYPE_PERSON);
		if (!in_array($type, Member::TYPES, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültiger Mitgliedstyp: %s', [$type]));
		}
		$member->setMemberType($type);

		if ($type === Member::TYPE_PERSON) {
			$lastName = trim((string)($data['lastName'] ?? ''));
			if ($lastName === '') {
				throw new \InvalidArgumentException($this->l10n->t('Bei einer Person ist der Nachname Pflicht.'));
			}
			$member->setFirstName($this->nullIfEmpty($data['firstName'] ?? null));
			$member->setLastName($lastName);
			$member->setOrganizationName(null);
		} else {
			$organizationName = trim((string)($data['organizationName'] ?? ''));
			if ($organizationName === '') {
				throw new \InvalidArgumentException($this->l10n->t('Bei einer Organisation ist der Name Pflicht.'));
			}
			$member->setFirstName(null);
			$member->setLastName(null);
			$member->setOrganizationName($organizationName);
		}

		$member->setEmail($this->normalizeEmail($data['email'] ?? null));
		$member->setPhone($this->nullIfEmpty($data['phone'] ?? null));
		$member->setStreet($this->nullIfEmpty($data['street'] ?? null));
		$member->setPostalCode($this->nullIfEmpty($data['postalCode'] ?? null));
		$member->setCity($this->nullIfEmpty($data['city'] ?? null));
		$member->setCountry($this->nullIfEmpty($data['country'] ?? null));
		$member->setMemberNumber($this->normalizeMemberNumber($data['memberNumber'] ?? null, $member));
		$member->setJoinedAt($this->requireDate(
			(string)($data['joinedAt'] ?? (new \DateTime())->format('Y-m-d')),
			$this->l10n->t('Ungültiges Beitrittsdatum (erwartet JJJJ-MM-TT).'),
		));
		$member->setInternalNote($this->nullIfEmpty($data['internalNote'] ?? null));
	}

	private function nullIfEmpty(mixed $value): ?string {
		$value = trim((string)$value);
		return $value === '' ? null : $value;
	}

	private function normalizeEmail(mixed $email): ?string {
		$email = trim((string)$email);
		if ($email === '') {
			return null;
		}
		if (!EmailValidator::isValid($email)) {
			throw new \InvalidArgumentException($this->l10n->t('Die E-Mail-Adresse ist ungültig: %s', [$email]));
		}
		return $email;
	}

	/**
	 * member_number wird nie automatisch vergeben (Spec §2.2) – hier wird nur
	 * geprüft und normalisiert, was der Verein eingegeben hat. Eindeutigkeit
	 * für "gesetzt" übernimmt bereits der Unique-Index auf der nullable
	 * Spalte (siehe Migration 000137); die Prüfung hier liefert nur die
	 * verständliche Fehlermeldung statt eines rohen DB-Fehlers.
	 */
	private function normalizeMemberNumber(mixed $memberNumber, Member $member): ?string {
		$memberNumber = trim((string)$memberNumber);
		if ($memberNumber === '') {
			return null;
		}
		$existing = $this->mapper->findByMemberNumber($memberNumber);
		if ($existing !== null && $existing->getId() !== $member->getId()) {
			throw new \InvalidArgumentException($this->l10n->t('Diese Mitgliedsnummer ist schon vergeben: %s', [$memberNumber]));
		}
		return $memberNumber;
	}

	private function requireDate(string $date, string $errorMessage): string {
		$date = trim($date);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException($errorMessage);
		}
		return $date;
	}
}
