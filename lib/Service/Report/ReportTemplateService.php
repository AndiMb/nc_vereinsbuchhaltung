<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

use OCA\Vereinsbuchhaltung\Db\ReportTemplate;
use OCA\Vereinsbuchhaltung\Db\ReportTemplateMapper;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Vorlagen des Gliederungsberichts: mitgelieferte aus dem Code, eigene aus
 * der Datenbank, unter einer gemeinsamen Kennung („builtin:…“ bzw. die ID).
 */
class ReportTemplateService {

	public function __construct(
		private ReportTemplateMapper $mapper,
		private BuiltinReportTemplates $builtin,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	/**
	 * Mitgelieferte zuerst, dann die eigenen in ihrer Reihenfolge.
	 *
	 * @return list<array{id:string, name:string, builtin:bool, definition:array<string, mixed>}>
	 */
	public function all(string $userId): array {
		$out = $this->builtin->all();
		foreach ($this->mapper->findAll($userId) as $t) {
			$out[] = $this->describe($t);
		}
		return $out;
	}

	/** @return array{id:string, name:string, builtin:bool, definition:array<string, mixed>}|null */
	public function find(string $userId, string $id): ?array {
		if (str_starts_with($id, BuiltinReportTemplates::PREFIX)) {
			return $this->builtin->find($id);
		}
		if (!ctype_digit($id)) {
			return null;
		}
		$t = $this->mapper->findForUser($userId, (int)$id);
		return $t === null ? null : $this->describe($t);
	}

	/**
	 * Liefert die neue Vorlage oder die Fehler.
	 *
	 * @return array{0: ?array{id:string, name:string, builtin:bool, definition:array<string, mixed>}, 1: list<string>}
	 */
	public function create(string $userId, string $name, mixed $definition): array {
		[$name, $def, $errors] = $this->check($name, $definition);
		if ($errors !== []) {
			return [null, $errors];
		}
		$now = $this->time->getDateTime();
		$t = new ReportTemplate();
		$t->setUserId($userId);
		$t->setName($name);
		$t->setDefinition(json_encode($def, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		$t->setPosition($this->mapper->maxPosition($userId) + 1);
		$t->setCreatedAt($now);
		$t->setUpdatedAt($now);
		return [$this->describe($this->mapper->insert($t)), []];
	}

	/**
	 * Liefert die geänderte Vorlage oder die Fehler; null ohne Fehler heißt
	 * „nicht gefunden“.
	 *
	 * @return array{0: ?array{id:string, name:string, builtin:bool, definition:array<string, mixed>}, 1: list<string>}
	 * @throws ForbiddenException für mitgelieferte Vorlagen
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

	/**
	 * @return bool false = nicht gefunden
	 * @throws ForbiddenException für mitgelieferte Vorlagen
	 */
	public function delete(string $userId, string $id): bool {
		$t = $this->own($userId, $id);
		if ($t === null) {
			return false;
		}
		$this->mapper->delete($t);
		return true;
	}

	/** @throws ForbiddenException */
	private function own(string $userId, string $id): ?ReportTemplate {
		if (str_starts_with($id, BuiltinReportTemplates::PREFIX)) {
			throw new ForbiddenException($this->l10n->t('Mitgelieferte Vorlagen lassen sich nicht ändern. Bitte eine Kopie anlegen.'));
		}
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

	/** @return array{id:string, name:string, builtin:bool, definition:array<string, mixed>} */
	private function describe(ReportTemplate $t): array {
		try {
			$raw = json_decode($t->getDefinition(), true, 64, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$raw = [];
		}
		return [
			'id' => (string)$t->getId(),
			'name' => $t->getName(),
			'builtin' => false,
			'definition' => ReportDefinition::normalize($raw),
		];
	}
}
