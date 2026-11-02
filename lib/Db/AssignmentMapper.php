<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Assignment>
 */
class AssignmentMapper extends QBMapper {
	private ReadCache $readCache;

	public function __construct(IDBConnection $db, ?ReadCache $readCache = null) {
		parent::__construct($db, 'vbh_assignments', Assignment::class);
		$this->readCache = $readCache ?? new ReadCache();
	}

	/**
	 * Innerhalb eines {@see ReadCache::during()} nur einmal geladen.
	 *
	 * @return list<Assignment>
	 */
	public function findAll(): array {
		return $this->readCache->entities('assignments.all', function (): array {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->orderBy('valid_from', 'DESC')
				->addOrderBy('id', 'DESC');
			return array_values($this->findEntities($qb));
		});
	}

	public function find(int $id): Assignment {
		$cached = $this->readCache->byId('assignments.all', fn () => $this->findAll(), $id);
		if ($cached instanceof Assignment) {
			return $cached;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return Assignment[] */
	public function findByMember(int $memberId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
			->orderBy('valid_from', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Zuweisungen desselben Mitglieds zur selben Gruppe – Grundlage für die
	 * Überlappungsprüfung (AssignmentService::assertNoOverlap()).
	 *
	 * @return Assignment[]
	 */
	public function findByMemberAndGroup(int $memberId, int $groupId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Zuweisungen eines Mitglieds, die über $asOf hinaus laufen (validTo NULL
	 * oder erst danach) – für den Austritts-Hook
	 * (AssignmentService::onMemberLeft()): auch eine Zuweisung mit bereits
	 * gesetztem, aber späterem validTo muss auf das frühere Austrittsdatum
	 * vorgezogen werden.
	 *
	 * @return Assignment[]
	 */
	public function findOpenAsOf(int $memberId, string $asOf): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('valid_to'),
				$qb->expr()->gt('valid_to', $qb->createNamedParameter($asOf)),
			));
		return $this->findEntities($qb);
	}

	public function count(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName());
		$res = $qb->executeQuery();
		$count = (int)$res->fetchOne();
		$res->closeCursor();
		return $count;
	}

	/**
	 * Mitglieder je Turnus, deren Zuweisung an `$date` noch läuft oder erst
	 * später beginnt – damit der Terminplan nur die Turnusse in den
	 * Vordergrund stellt, die tatsächlich Einzüge auslösen.
	 *
	 * @return array<int,int> Turnusmonate => Anzahl Mitglieder
	 */
	public function countMembersByIntervalFrom(string $date): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('interval_months')
			->selectAlias($qb->createFunction('COUNT(DISTINCT ' . $qb->getColumnName('member_id') . ')'), 'members')
			->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->isNull('valid_to'),
				$qb->expr()->gte('valid_to', $qb->createNamedParameter($date)),
			))
			->groupBy('interval_months');
		$res = $qb->executeQuery();
		$counts = [];
		while ($row = $res->fetch()) {
			$counts[(int)$row['interval_months']] = (int)$row['members'];
		}
		$res->closeCursor();
		return $counts;
	}

	/** Zuweisungen einer Gruppe, für die Untergrenzen-Vorschau/-Anhebung. @return Assignment[] */
	public function findByGroup(int $groupId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Zuweisungen, die an `$date` aktiv sind (validFrom <= $date, validTo NULL
	 * oder erst danach) – Grundlage für die periodische Forderungserzeugung
	 * (Issue #70, {@see \OCA\Vereinsbuchhaltung\Service\ClaimGenerationService})
	 * und die Störfall-Abfrage „Kein Mandat + Lastschrift gewollt"
	 * ({@see \OCA\Vereinsbuchhaltung\Service\ContributionCycleTaskService}).
	 *
	 * @return Assignment[]
	 */
	public function findActiveAsOf(string $date): array {
		return $this->readCache->entities('assignments.activeAsOf.' . $date, function () use ($date): array {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->lte('valid_from', $qb->createNamedParameter($date)))
				->andWhere($qb->expr()->orX(
					$qb->expr()->isNull('valid_to'),
					$qb->expr()->gte('valid_to', $qb->createNamedParameter($date)),
				))
				->orderBy('id', 'ASC');
			return array_values($this->findEntities($qb));
		});
	}
}
