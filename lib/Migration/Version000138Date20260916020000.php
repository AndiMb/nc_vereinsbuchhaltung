<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCA\Vereinsbuchhaltung\Service\MemberService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Datenübernahme für die Mitglied-Entity (Spec §2.2/§3.1 „Umbaupfad",
 * docs/beitraege-sepa-modul-spec.md): legt je distinctem Zahler aus
 * `vbh_sepa_mandates`/`vbh_membership_fees` genau ein Mitglied an und trägt
 * `member_id` an allen betroffenen Zeilen nach. Version000137 hat die Spalte
 * bereits angelegt, Version000139 entfernt danach die alten Spalten
 * `member_uid`/`member_label` – erst müssen die Daten stimmen (siehe
 * Version000137 für die Begründung der Dreiteilung).
 *
 * Split-Heuristik je distinctem Zahler (Spec §3.1): `member_uid` → Mitglied
 * mit dem aktuellen NC-Displaynamen; `member_label` mit Leerzeichen →
 * `person` (Split am ersten Leerzeichen); ohne Leerzeichen → `organisation`
 * (siehe {@see MemberService::splitLabel()}, dieselbe reine Funktion nutzt
 * auch der CSV-Import). Nur direktes SQL über {@see IDBConnection}, keine
 * volle MemberService-Instanz: dasselbe Vorgehen wie in den bisherigen
 * datenübernehmenden Migrationen dieser App (siehe Version000133).
 *
 * Erzeugt danach eine Aufgabe „N Mitglieder übernommen — Namen/Mailadressen
 * prüfen" (Spec §3.1/§7) – sofern überhaupt ein Zahler zu übernehmen war.
 */
class Version000138Date20260916020000 extends SimpleMigrationStep {

	/** Beide Tabellen, die member_uid/member_label auf member_id umstellen. */
	private const TABLES = ['vbh_sepa_mandates', 'vbh_membership_fees'];

	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->memberCount() > 0) {
			// Bereits übernommen (z. B. ein vorheriger, teilweise gelaufener
			// Versuch) – ein zweiter Lauf würde wegen des Unique-Index auf
			// nc_user_id nur Fehler werfen und hilft niemandem.
			$output->info('Vereinsbuchhaltung: vbh_members ist nicht leer, Datenübernahme wird übersprungen.');
			return;
		}

		$memberIdForUid = [];
		$memberIdForLabel = [];
		foreach (self::TABLES as $table) {
			foreach ($this->distinctPayers($table) as [$uid, $label]) {
				if ($uid !== null) {
					$memberIdForUid[$uid] ??= $this->insertMemberForNcUser($uid);
				} elseif ($label !== null) {
					$memberIdForLabel[$label] ??= $this->insertMemberForLabel($label);
				}
			}
		}

		$migratedRows = 0;
		foreach (self::TABLES as $table) {
			$migratedRows += $this->assignMemberIds($table, $memberIdForUid, $memberIdForLabel);
		}

		$count = count($memberIdForUid) + count($memberIdForLabel);
		if ($count > 0) {
			$this->insertTask(sprintf(
				'%d Mitglieder übernommen — Namen/Mailadressen prüfen',
				$count,
			));
		}
		$output->info(sprintf(
			'Vereinsbuchhaltung: %d Mitglieder aus member_uid/member_label übernommen, %d Zeilen auf member_id umgestellt.',
			$count,
			$migratedRows,
		));
	}

	/** @return list<array{0: ?string, 1: ?string}> je distinctem (member_uid, member_label)-Paar */
	private function distinctPayers(string $table): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct(['member_uid', 'member_label'])->from($table);
		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$uid = self::nullIfEmpty($row['member_uid'] ?? null);
			$label = self::nullIfEmpty($row['member_label'] ?? null);
			if ($uid === null && $label === null) {
				continue;
			}
			$rows[] = [$uid, $label];
		}
		$result->closeCursor();
		return $rows;
	}

	/** Leere DB-Werte (null oder '') einheitlich auf null normalisieren. */
	private static function nullIfEmpty(mixed $value): ?string {
		return $value !== null && $value !== '' ? (string)$value : null;
	}

	private function insertMemberForNcUser(string $uid): int {
		$user = $this->userManager->get($uid);
		$split = MemberService::splitLabel($user?->getDisplayName() ?? $uid);
		return $this->insertMember($split, $uid, $user?->getEMailAddress());
	}

	private function insertMemberForLabel(string $label): int {
		return $this->insertMember(MemberService::splitLabel($label), null, null);
	}

	/** @param array{type:string, firstName:?string, lastName:?string, organizationName:?string} $split */
	private function insertMember(array $split, ?string $ncUserId, ?string $email): int {
		$qb = $this->db->getQueryBuilder();
		$now = (new \DateTime())->format('Y-m-d H:i:s');
		$qb->insert('vbh_members')->values([
			'member_type' => $qb->createNamedParameter($split['type']),
			'first_name' => $qb->createNamedParameter($split['firstName']),
			'last_name' => $qb->createNamedParameter($split['lastName']),
			'organization_name' => $qb->createNamedParameter($split['organizationName']),
			'email' => $qb->createNamedParameter($email),
			'nc_user_id' => $qb->createNamedParameter($ncUserId),
			'joined_at' => $qb->createNamedParameter(substr($now, 0, 10)),
			'created_at' => $qb->createNamedParameter($now),
		]);
		$qb->executeStatement();
		return (int)$qb->getLastInsertId();
	}

	/** @param array<string,int> $memberIdForUid @param array<string,int> $memberIdForLabel */
	private function assignMemberIds(string $table, array $memberIdForUid, array $memberIdForLabel): int {
		$count = 0;
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'member_uid', 'member_label')
			->from($table)
			->where($qb->expr()->isNull('member_id'));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		foreach ($rows as $row) {
			$uid = self::nullIfEmpty($row['member_uid'] ?? null);
			$label = self::nullIfEmpty($row['member_label'] ?? null);
			$memberId = $uid !== null ? ($memberIdForUid[$uid] ?? null) : ($label !== null ? ($memberIdForLabel[$label] ?? null) : null);
			if ($memberId === null) {
				continue;
			}
			$update = $this->db->getQueryBuilder();
			$update->update($table)
				->set('member_id', $update->createNamedParameter($memberId, IQueryBuilder::PARAM_INT))
				->where($update->expr()->eq('id', $update->createNamedParameter($row['id'], IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
			$count++;
		}
		return $count;
	}

	private function insertTask(string $message): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('vbh_tasks')->values([
			'severity' => $qb->createNamedParameter('hinweis'),
			'message' => $qb->createNamedParameter($message),
			'object_type' => $qb->createNamedParameter('member'),
			'created_at' => $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')),
		]);
		$qb->executeStatement();
	}

	private function memberCount(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from('vbh_members');
		$res = $qb->executeQuery();
		$count = (int)$res->fetchOne();
		$res->closeCursor();
		return $count;
	}
}
