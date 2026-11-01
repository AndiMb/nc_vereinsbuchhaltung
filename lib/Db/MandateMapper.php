<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCA\Vereinsbuchhaltung\Service\FieldCipher;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends EncryptingMapper<Mandate>
 */
class MandateMapper extends EncryptingMapper {
	private ReadCache $readCache;

	public function __construct(IDBConnection $db, FieldCipher $cipher, ?ReadCache $readCache = null) {
		parent::__construct($db, 'vbh_mandates', Mandate::class, $cipher);
		$this->readCache = $readCache ?? new ReadCache();
	}

	protected function encryptedProperties(): array {
		return ['iban'];
	}

	/**
	 * Innerhalb eines {@see ReadCache::during()} nur einmal geladen.
	 *
	 * @return list<Mandate>
	 */
	public function findAll(): array {
		return $this->readCache->entities('mandates.all', function (): array {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->orderBy('status', 'ASC')
				->addOrderBy('id', 'DESC');
			return array_values($this->findEntities($qb));
		});
	}

	public function find(int $id): Mandate {
		$cached = $this->readCache->byId('mandates.all', fn () => $this->findAll(), $id);
		if ($cached instanceof Mandate) {
			return $cached;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException wenn es das Mandat nicht (mehr) gibt
	 */
	public function findOrNull(int $id): ?Mandate {
		try {
			return $this->find($id);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return Mandate[] gesamte Historie eines Mitglieds, neueste zuerst */
	public function findByMember(int $memberId): array {
		if ($this->readCache->isActive()) {
			$byMember = $this->readCache->remember('mandates.byMember', function (): array {
				$index = [];
				foreach ($this->findAll() as $mandate) {
					$index[$mandate->getMemberId()][] = $mandate;
				}
				// Neueste zuerst, wie die Abfrage unten.
				return array_map(static function (array $list): array {
					usort($list, static fn (Mandate $a, Mandate $b) => (int)$b->getId() <=> (int)$a->getId());
					return $list;
				}, $index);
			});
			return array_map(static fn (Mandate $m) => clone $m, $byMember[$memberId] ?? []);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Das eine lebende (status <> erloschen) Mandat eines Mitglieds, falls es
	 * eines gibt. Eine echte `UNIQUE (member_id) WHERE status <> 'erloschen'`
	 * ließe sich nicht portabel über MySQL/MariaDB, PostgreSQL *und* SQLite in
	 * einem einzigen Doctrine-Schema-Aufruf ausdrücken (partielle/gefilterte
	 * Indizes sind je Datenbank verschieden oder fehlen ganz) – die Invariante
	 * „höchstens ein lebendes Mandat je Mitglied" (Spec §2.2) wird deshalb auf
	 * Anwendungsebene in {@see \OCA\Vereinsbuchhaltung\Service\MandateService}
	 * durchgesetzt statt per DB-Constraint.
	 *
	 * @return Mandate[]
	 */
	public function findLiveByMember(int $memberId): array {
		// Im Lese-Puffer aus dem einmal geladenen Bestand statt je Mitglied eine Abfrage.
		if ($this->readCache->isActive()) {
			$byMember = $this->readCache->remember('mandates.liveByMember', function (): array {
				$index = [];
				foreach ($this->findAll() as $mandate) {
					if ($mandate->getStatus() !== Mandate::STATUS_ENDED) {
						$index[$mandate->getMemberId()][] = $mandate;
					}
				}
				return $index;
			});
			return array_map(static fn (Mandate $m) => clone $m, $byMember[$memberId] ?? []);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter(Mandate::STATUS_ENDED)));
		return $this->findEntities($qb);
	}

	public function findByReference(string $mandateReference): ?Mandate {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('mandate_reference', $qb->createNamedParameter($mandateReference)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * Alle Referenzen mit einem gegebenen Präfix – Grundlage der
	 * fortlaufenden Nummerierung `<Präfix>-<lfd. Nr.>` (Spec §2.2). Holt
	 * bewusst nur die Referenzen, nicht die ganzen Datensätze: die
	 * eigentliche Maximum-Berechnung übernimmt der reine, unit-getestete
	 * {@see \OCA\Vereinsbuchhaltung\Service\MandateReferenceGenerator}.
	 *
	 * @return list<string>
	 */
	public function findReferencesWithPrefix(string $prefix): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('mandate_reference')
			->from($this->getTableName())
			->where($qb->expr()->like('mandate_reference', $qb->createNamedParameter($this->db->escapeLikeParameter($prefix) . '-%')));
		$result = $qb->executeQuery();
		$refs = [];
		while (($row = $result->fetch()) !== false) {
			$refs[] = (string)$row['mandate_reference'];
		}
		$result->closeCursor();
		return $refs;
	}

	/**
	 * Kandidaten für den 36-Monats-Verfall-Cron: alle noch nicht beendeten
	 * Mandate mit Unterschriftsdatum – die eigentliche Fristberechnung
	 * (COALESCE mit last_presented_due_date) übernimmt
	 * {@see \OCA\Vereinsbuchhaltung\Service\MandateExpiryCalculator}, rein in
	 * PHP statt als Datumsrechnung im SQL-Dialekt dreier Datenbanken.
	 *
	 * @return Mandate[]
	 */
	public function findCandidatesForExpiry(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter([Mandate::STATUS_ACTIVE, Mandate::STATUS_SUSPENDED], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNotNull('signed_at'));
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
	 * Löst den Verweis aller Mandate auf eine Rücklastschrift
	 * (`returned_debit_id`, gesetzt bei der automatischen Sperre nach einer
	 * Rücklastschrift, Spec §2.2). Beim Zurücksetzen (siehe
	 * {@see \OCA\Vereinsbuchhaltung\Service\ContributionResetService}) sind die
	 * Rücklastschriften weg; die Sperre selbst bleibt – sie hebt nur ein Mensch
	 * mit Notiz auf, und der Grund steht weiter in `suspension_note`.
	 *
	 * Es gibt keine Fremdschlüssel im Schema: ohne dieses Aufräumen zeigte die
	 * Sperre auf eine nicht mehr vorhandene ID – und je nach Zähler der
	 * Datenbank später auf eine fremde Rücklastschrift. Die Aufgabenliste
	 * kommt mit einem fehlenden Verweis zurecht (neutraler Text ohne Grund).
	 *
	 * @return int Anzahl betroffener Mandate
	 */
	public function clearReturnedDebitReferences(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('returned_debit_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->isNotNull('returned_debit_id'));
		return $qb->executeStatement();
	}
}
