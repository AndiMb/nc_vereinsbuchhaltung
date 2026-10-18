<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\ReportTemplate;
use OCA\Vereinsbuchhaltung\Db\ReportTemplateMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Vorlagen des Gliederungsberichts – alle in der Datenbank, alle änderbar.
 *
 * Die beiden Standardvorlagen ({@see BuiltinReportTemplates}) legt der erste
 * Abruf der Liste einmalig an; der App-Wert `report_templates_seeded` hält
 * fest, dass das geschehen ist. Wer sie danach löscht, bekommt sie nicht
 * ungefragt zurück, sondern nur über {@see addStandard()}.
 *
 * Zwei gleichzeitige erste Abrufe dürfen nicht beide anlegen: das Anlegen
 * läuft unter einer exklusiven Nextcloud-Sperre, und der Vermerk wird nach
 * dem Sperren noch einmal geprüft. Ist die Sperre belegt, legt gerade ein
 * anderer Request an – dieser liefert dann einfach, was schon da ist.
 */
class ReportTemplateService {

	public const SEEDED_KEY = 'report_templates_seeded';
	private const LOCK = 'vereinsbuchhaltung/report-templates-seed';

	public function __construct(
		private ReportTemplateMapper $mapper,
		private BuiltinReportTemplates $builtin,
		private TransactionRunner $transaction,
		private ILockingProvider $locking,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	/**
	 * Alle Vorlagen in ihrer Reihenfolge; legt beim allerersten Abruf die
	 * Standardvorlagen an.
	 *
	 * @return list<array{id:string, name:string, definition:array<string, mixed>}>
	 */
	public function all(string $userId): array {
		$this->seedOnce($userId);
		return array_map(fn (ReportTemplate $t): array => $this->describe($t), array_values($this->mapper->findAll($userId)));
	}

	/**
	 * Eine Vorlage – oder null. Nur Datenbank-IDs; Verweise auf die bis 0.38.0
	 * mitgelieferten Vorlagen („builtin:…“) gibt es nicht mehr.
	 *
	 * @return array{id:string, name:string, definition:array<string, mixed>}|null
	 */
	public function find(string $userId, string $id): ?array {
		$t = $this->own($userId, $id);
		return $t === null ? null : $this->describe($t);
	}

	/**
	 * Liefert die neue Vorlage oder die Fehler.
	 *
	 * @return array{0: ?array{id:string, name:string, definition:array<string, mixed>}, 1: list<string>}
	 */
	public function create(string $userId, string $name, mixed $definition): array {
		[$name, $def, $errors] = $this->check($name, $definition);
		if ($errors !== []) {
			return [null, $errors];
		}
		return [$this->describe($this->insert($userId, $name, $def)), []];
	}

	/**
	 * Liefert die geänderte Vorlage oder die Fehler; null ohne Fehler heißt
	 * „nicht gefunden“.
	 *
	 * @return array{0: ?array{id:string, name:string, definition:array<string, mixed>}, 1: list<string>}
	 */
	public function update(string $userId, string $id, string $name, mixed $definition): array {
		$t = $this->own($userId, $id);
		if ($t === null) {
			return [null, []];
		}
		[$name, $def, $errors] = $this->check($name, $definition);
		if ($errors !== []) {
			return [null, $errors];
		}
		$t->setName($name);
		$t->setDefinition(json_encode($def, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		$t->setUpdatedAt($this->time->getDateTime());
		return [$this->describe($this->mapper->update($t)), []];
	}

	/** @return bool false = nicht gefunden */
	public function delete(string $userId, string $id): bool {
		$t = $this->own($userId, $id);
		if ($t === null) {
			return false;
		}
		$this->mapper->delete($t);
		return true;
	}

	/**
	 * Legt die Standardvorlagen (erneut) an, in ihrer aktuellen Fassung.
	 * Vorhandene Vorlagen bleiben unberührt; gleichnamige gibt es danach
	 * doppelt – diese Namen meldet der zweite Rückgabewert.
	 *
	 * @return array{0: list<array{id:string, name:string, definition:array<string, mixed>}>, 1: list<string>}
	 */
	public function addStandard(string $userId): array {
		$existing = array_map(static fn (ReportTemplate $t): string => $t->getName(), $this->mapper->findAll($userId));
		$created = $this->transaction->run(function () use ($userId): array {
			$created = $this->insertStandard($userId);
			$this->appConfig->setValueString(Application::APP_ID, self::SEEDED_KEY, '1');
			return $created;
		});
		$duplicates = array_values(array_intersect(
			array_map(static fn (ReportTemplate $t): string => $t->getName(), $created),
			$existing,
		));
		return [array_map(fn (ReportTemplate $t): array => $this->describe($t), $created), $duplicates];
	}

	/** Für „Alle Daten löschen“: alle Vorlagen weg. */
	public function deleteAllForUser(string $userId): void {
		$this->mapper->deleteAllForUser($userId);
	}

	/**
	 * Für „Alle Daten löschen“: der nächste Abruf legt die Standardvorlagen
	 * wieder an, wie in einem frischen Verein.
	 */
	public function forgetSeeded(): void {
		$this->appConfig->deleteKey(Application::APP_ID, self::SEEDED_KEY);
	}

	/**
	 * Ein anderer Request kann den Vermerk zwischen zwei Aufrufen gesetzt
	 * haben; wer frisch lesen muss, leert vorher den Cache (siehe seedOnce()).
	 *
	 * @phpstan-impure
	 */
	private function seeded(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, self::SEEDED_KEY) === '1';
	}

	private function seedOnce(string $userId): void {
		if ($this->seeded()) {
			return;
		}
		try {
			$this->locking->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return;
		}
		try {
			// Zwischen der ersten Prüfung und der Sperre kann ein anderer
			// Request fertig geworden sein. Nextcloud hält App-Werte je Request
			// im Speicher; ohne frisches Lesen sähe dieser Request das nicht.
			$this->appConfig->clearCache(true);
			if ($this->seeded()) {
				return;
			}
			$this->transaction->run(function () use ($userId): void {
				$this->insertStandard($userId);
				$this->appConfig->setValueString(Application::APP_ID, self::SEEDED_KEY, '1');
			});
		} finally {
			$this->locking->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return list<ReportTemplate> */
	private function insertStandard(string $userId): array {
		$out = [];
		foreach ($this->builtin->all() as $standard) {
			$out[] = $this->insert($userId, $standard['name'], $standard['definition']);
		}
		return $out;
	}

	/** @param array<string, mixed> $def bereits normalisiert und geprüft */
	private function insert(string $userId, string $name, array $def): ReportTemplate {
		$now = $this->time->getDateTime();
		$t = new ReportTemplate();
		$t->setUserId($userId);
		$t->setName($name);
		$t->setDefinition(json_encode($def, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		$t->setPosition($this->mapper->maxPosition($userId) + 1);
		$t->setCreatedAt($now);
		$t->setUpdatedAt($now);
		return $this->mapper->insert($t);
	}

	private function own(string $userId, string $id): ?ReportTemplate {
		return ctype_digit($id) ? $this->mapper->findForUser($userId, (int)$id) : null;
	}

	/**
	 * @return array{0:string, 1:array<string, mixed>, 2:list<string>}
	 */
	private function check(string $name, mixed $definition): array {
		$name = mb_substr(trim($name), 0, 255);
		$def = ReportDefinition::normalize($definition);
		$errors = ReportDefinition::validate($def, $this->l10n);
		if ($name === '') {
			array_unshift($errors, $this->l10n->t('Die Vorlage braucht einen Namen.'));
		}
		return [$name, $def, $errors];
	}

	/** @return array{id:string, name:string, definition:array<string, mixed>} */
	private function describe(ReportTemplate $t): array {
		try {
			$raw = json_decode($t->getDefinition(), true, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$raw = [];
		}
		return [
			'id' => (string)$t->getId(),
			'name' => $t->getName(),
			'definition' => ReportDefinition::normalize($raw),
		];
	}
}
