<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Lese-Puffer für eine eng umgrenzte, rein lesende Auswertung.
 *
 * Die Aufgabenliste (GET /api/tasks) fragt in sieben Fachdiensten dieselben
 * Bestände ab: offene Forderungen, Mandate, Zuweisungen, Mitglieder – teils
 * mehrfach vollständig, teils je Forderung einzeln. Innerhalb von
 * {@see during()} merken sich die Mapper diese Abfragen und beantworten auch
 * Einzelzugriffe (`find($id)`) aus dem einmal geladenen Bestand.
 *
 * Außerhalb von during() ist der Puffer wirkungslos: jeder Aufruf geht wie
 * bisher an die Datenbank. Das ist Absicht – Hintergrundjobs und schreibende
 * Requests teilen sich dieselben Mapper-Instanzen und dürfen nie einen
 * veralteten Stand sehen.
 *
 * Entitäten kommen als Kopien heraus: ein Aufrufer, der eine Entität
 * verändert, verändert damit nicht den Stand des nächsten.
 */
class ReadCache {

	/** @var array<string, mixed>|null null = kein Puffer aktiv */
	private ?array $values = null;

	/**
	 * Führt $fn mit aktivem Puffer aus. Verschachtelt nutzt der innere Aufruf
	 * den Puffer des äußeren.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	public function during(callable $fn): mixed {
		if ($this->values !== null) {
			return $fn();
		}
		$this->values = [];
		try {
			return $fn();
		} finally {
			$this->values = null;
		}
	}

	public function isActive(): bool {
		return $this->values !== null;
	}

	/**
	 * Liste von Entitäten: im Puffer einmal laden, danach Kopien liefern.
	 *
	 * @template E of Entity
	 * @param callable(): list<E> $load
	 * @return list<E>
	 */
	public function entities(string $key, callable $load): array {
		if ($this->values === null) {
			return $load();
		}
		if (!array_key_exists($key, $this->values)) {
			$this->values[$key] = $load();
		}
		return array_map(static fn (Entity $e) => clone $e, $this->values[$key]);
	}

	/**
	 * Abgeleiteter Wert (z. B. ein Index nach Mitglied), im Puffer einmal
	 * berechnet. Enthält er Entitäten, muss der Aufrufer sie vor der Herausgabe
	 * kopieren. Ohne aktiven Puffer wird jedes Mal neu berechnet.
	 *
	 * @template V
	 * @param callable(): V $build
	 * @return V
	 */
	public function remember(string $key, callable $build): mixed {
		if ($this->values === null) {
			return $build();
		}
		if (!array_key_exists($key, $this->values)) {
			$this->values[$key] = $build();
		}
		return $this->values[$key];
	}

	/**
	 * Einzelne Entität nach ID aus einer gepufferten Liste. Liefert null, wenn
	 * der Puffer nicht aktiv ist ODER die ID nicht in der Liste steht – der
	 * Aufrufer fragt dann wie gewohnt die Datenbank.
	 *
	 * @template E of Entity
	 * @param callable(): list<E> $loadAll
	 * @return E|null
	 */
	public function byId(string $key, callable $loadAll, int $id): ?Entity {
		if ($this->values === null) {
			return null;
		}
		$indexKey = $key . '#byId';
		if (!array_key_exists($indexKey, $this->values)) {
			if (!array_key_exists($key, $this->values)) {
				$this->values[$key] = $loadAll();
			}
			$index = [];
			foreach ($this->values[$key] as $entity) {
				$index[(int)$entity->getId()] = $entity;
			}
			$this->values[$indexKey] = $index;
		}
		$hit = $this->values[$indexKey][$id] ?? null;
		return $hit === null ? null : clone $hit;
	}
}
