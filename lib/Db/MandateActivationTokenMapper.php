<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<MandateActivationToken>
 */
class MandateActivationTokenMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'vbh_mandate_activation_tokens', MandateActivationToken::class);
	}

	public function find(int $id): MandateActivationToken {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Auflösung des öffentlichen Teils eines Tokens - der Validator wird NIE
	 * für die Datenbankabfrage benutzt (nur der Selector ist indiziert),
	 * siehe {@see \OCA\Vereinsbuchhaltung\Service\MandateActivationService::resolve()}
	 * für den anschließenden Hash-Vergleich.
	 */
	public function findBySelector(string $selector): ?MandateActivationToken {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('selector', $qb->createNamedParameter($selector)))
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * Alle noch nicht verwendeten Links eines Mandats, älteste zuerst - für
	 * das Invalidieren beim Neuversand ({@see MandateActivationService::issueLink()})
	 * und die "Link ist X Tage alt"-Aufgabe (Issue #67).
	 *
	 * @return MandateActivationToken[]
	 */
	public function findOutstandingByMandate(int $mandateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('mandate_id', $qb->createNamedParameter($mandateId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('consumed_at'))
			->orderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Der jeweils älteste ausstehende Link je Mandat mit elektronischem
	 * Entwurf - Grundlage der Aufgabe "Mandat-Entwurf elektronisch, Link ≥14
	 * Tage alt" (Issue #67). Nur `entwurf`+`elektronisch` interessiert: ist
	 * das Mandat längst aktiv (Zustimmung erfolgt) oder beendet, ist der Link
	 * gegenstandslos.
	 *
	 * @return array<int, MandateActivationToken> mandate_id => ältester Token
	 */
	public function findOldestOutstandingByElectronicDraftMandates(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.*')
			->from($this->getTableName(), 't')
			->innerJoin('t', 'vbh_mandates', 'm', $qb->expr()->eq('t.mandate_id', 'm.id'))
			->where($qb->expr()->isNull('t.consumed_at'))
			->andWhere($qb->expr()->eq('m.status', $qb->createNamedParameter(Mandate::STATUS_DRAFT)))
			->andWhere($qb->expr()->eq('m.signature_type', $qb->createNamedParameter(Mandate::SIGNATURE_ELECTRONIC)))
			->orderBy('t.mandate_id', 'ASC')
			->addOrderBy('t.created_at', 'ASC');
		$byMandate = [];
		foreach ($this->findEntities($qb) as $token) {
			// Pro Mandat zaehlt nur der AELTESTE ausstehende Link (die Sortierung
			// oben stellt das sicher) - ein zwischenzeitlicher Neuversand ersetzt
			// die vorherigen Zeilen ohnehin (siehe issueLink()), hier bleibt das
			// nur als zweite Absicherung.
			$mandateId = $token->getMandateId();
			if (!isset($byMandate[$mandateId])) {
				$byMandate[$mandateId] = $token;
			}
		}
		return $byMandate;
	}

	public function findOrNull(int $id): ?MandateActivationToken {
		try {
			return $this->find($id);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** Räumt nicht mehr benötigte, unverbrauchte Links auf (Neuversand invalidiert die alten). */
	public function deleteOutstandingByMandate(int $mandateId): void {
		foreach ($this->findOutstandingByMandate($mandateId) as $token) {
			$this->delete($token);
		}
	}
}
