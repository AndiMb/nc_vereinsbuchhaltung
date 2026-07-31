<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenter;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Frei definierbare Kostenstellen und ihre Zuordnung zu Konten.
 *
 * Die App kannte Kostenstellen zunächst nur als Ableitung aus der Kontonummer
 * (zweite Zahlengruppe) oder als „ein Konto = eine Kostenstelle". Beides passt
 * jeweils nur zu einem bestimmten Kontenrahmen. Hier werden Kostenstellen
 * stattdessen ausdrücklich angelegt und Konten frei zugeordnet – auch Konten
 * mit ganz unterschiedlichen Nummern lassen sich so zu einem Projekt bündeln.
 *
 * Welche der drei Sichtweisen der Bericht benutzt, entscheidet die Einstellung
 * `cost_center_mode`, siehe {@see ReportService::costCenterMode()}.
 */
class CostCenterService {

	/** Länge der Spalte vbh_costcenters.code (siehe Migration 000104). */
	private const CODE_MAX = 8;

	public function __construct(
		private CostCenterMapper $mapper,
		private AccountMapper $accountMapper,
		private TransactionRunner $transaction,
		private AuditService $audit,
	) {
	}

	/**
	 * @return CostCenter[]
	 */
	public function findAll(string $userId): array {
		return $this->mapper->findAll($userId);
	}

	public function create(string $userId, string $code, string $name): CostCenter {
		$code = $this->validateCode($code);
		$name = $this->validateName($name);
		if ($this->mapper->findByCode($userId, $code) !== null) {
			throw new \InvalidArgumentException('Es gibt bereits eine Kostenstelle mit dem Kürzel „' . $code . '".');
		}
		$cc = new CostCenter();
		$cc->setUserId($userId);
		$cc->setCode($code);
		$cc->setName($name);
		$cc = $this->mapper->insert($cc);
		$this->audit->log('Kostenstelle angelegt', 'costcenter', $cc->getId(), [
			'code' => $code,
			'name' => $name,
		]);
		return $cc;
	}

	/**
	 * @throws DoesNotExistException wenn es die Kostenstelle nicht (mehr) gibt
	 */
	public function update(int $id, string $userId, string $code, string $name): CostCenter {
		$code = $this->validateCode($code);
		$name = $this->validateName($name);
		$cc = $this->mapper->find($id, $userId);
		$other = $this->mapper->findByCode($userId, $code);
		if ($other !== null && $other->getId() !== $cc->getId()) {
			throw new \InvalidArgumentException('Es gibt bereits eine Kostenstelle mit dem Kürzel „' . $code . '".');
		}
		$cc->setCode($code);
		$cc->setName($name);
		$cc = $this->mapper->update($cc);
		$this->audit->log('Kostenstelle geändert', 'costcenter', $cc->getId(), [
			'code' => $code,
			'name' => $name,
		]);
		return $cc;
	}

	/**
	 * Löscht eine Kostenstelle und löst zuvor die Zuordnung ihrer Konten.
	 *
	 * Buchungen bleiben unberührt – eine Kostenstelle bündelt nur Konten, sie
	 * trägt selbst keine Beträge.
	 *
	 * @throws DoesNotExistException wenn es die Kostenstelle nicht (mehr) gibt
	 */
	public function delete(int $id, string $userId): void {
		$this->transaction->run(function () use ($id, $userId): void {
			$cc = $this->mapper->find($id, $userId);
			$detached = $this->accountMapper->clearCostCenter($userId, $id);
			$this->mapper->delete($cc);
			$this->audit->log('Kostenstelle gelöscht', 'costcenter', $id, [
				'code' => $cc->getCode(),
				'name' => $cc->getName(),
				'konten' => $detached,
			]);
		});
	}

	/**
	 * Ordnet mehrere Konten auf einmal einer Kostenstelle zu.
	 *
	 * @param int[] $accountIds
	 * @param int|null $costCenterId null = Zuordnung aufheben
	 * @return int Anzahl tatsächlich geänderter Konten (fremde/unbekannte IDs werden übersprungen)
	 * @throws DoesNotExistException wenn es die Kostenstelle nicht (mehr) gibt
	 */
	public function assign(string $userId, array $accountIds, ?int $costCenterId): int {
		$target = null;
		if ($costCenterId !== null && $costCenterId > 0) {
			// Vor der Schleife prüfen: sonst hingen bei einer falschen ID die
			// ersten Konten an einer Kostenstelle, die es nicht gibt.
			$target = $this->mapper->find($costCenterId, $userId);
		}
		$count = 0;
		foreach ($accountIds as $id) {
			try {
				$account = $this->accountMapper->find((int)$id, $userId);
			} catch (DoesNotExistException) {
				continue;
			}
			$account->setCostCenterId($target?->getId());
			$this->accountMapper->update($account);
			$count++;
		}
		$this->audit->log('Kostenstellen zugeordnet', 'costcenter', $target?->getId(), [
			'anzahl' => $count,
			'kostenstelle' => $target !== null ? $target->getCode() . ' ' . $target->getName() : '(keine)',
		]);
		return $count;
	}

	/**
	 * Prüft eine Kostenstellen-ID für die Konto-Bearbeitung.
	 *
	 * @param int|null $costCenterId null/0 = keine Kostenstelle
	 * @throws \InvalidArgumentException wenn die Kostenstelle nicht existiert
	 */
	public function resolveId(string $userId, ?int $costCenterId): ?int {
		if ($costCenterId === null || $costCenterId <= 0) {
			return null;
		}
		try {
			return $this->mapper->find($costCenterId, $userId)->getId();
		} catch (DoesNotExistException) {
			throw new \InvalidArgumentException('Kostenstelle nicht gefunden.');
		}
	}

	private function validateCode(string $code): string {
		$code = trim($code);
		if ($code === '') {
			throw new \InvalidArgumentException('Das Kürzel der Kostenstelle ist Pflicht.');
		}
		if (mb_strlen($code) > self::CODE_MAX) {
			throw new \InvalidArgumentException('Das Kürzel darf höchstens ' . self::CODE_MAX . ' Zeichen lang sein.');
		}
		return $code;
	}

	private function validateName(string $name): string {
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException('Der Name der Kostenstelle ist Pflicht.');
		}
		return mb_substr($name, 0, 255);
	}
}
