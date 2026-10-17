<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCP\IL10N;

/**
 * Form und Regeln der Definition einer Gliederungsbericht-Vorlage.
 *
 * `normalize()` bringt beliebiges JSON in die kanonische Form: fehlende
 * Felder bekommen ihren Standardwert, unbekannte fallen weg, Typen werden
 * angeglichen. Was danach inhaltlich nicht stimmt (unbekannte Merkmale,
 * „Konto" nicht als letzte Ebene, doppelte Gruppen …), meldet `validate()`.
 * Gespeichert wird nur, was beide Schritte übersteht.
 *
 * Dieselben Regeln stehen für die Oberfläche in `src/lib/reportDefinition.js`;
 * maßgeblich ist diese Klasse.
 *
 * Aufbau siehe docs/gliederungsbericht-design.md, Abschnitt 3.2.
 */
final class ReportDefinition {

	public const VERSION = 1;

	public const TYPES = ['income', 'expense'];
	/** Sphären wie {@see Account::SPHERES}, dazu `none` für „ohne Sphäre". */
	public const SPHERE_NONE = 'none';
	public const LEVELS = ['type', 'sphere', 'group', 'account'];
	public const SOURCES = ['plan', 'actual', 'diff'];

	public const MAX_SECTION_DEPTH = 4;
	public const MAX_COLUMNS = 8;
	public const MIN_PERIOD_OFFSET = -3;
	public const MAX_GROUPS = 100;
	public const MAX_GROUP_ACCOUNTS = 500;
	public const MAX_TEXT = 255;

	/** Präfix der Berichtsgruppen, die eine Vorlage selbst festlegt. */
	public const GROUP_TEMPLATE = 't:';
	/** Präfix der Berichtsgruppen, die einer Kostenstelle entsprechen. */
	public const GROUP_COST_CENTER = 'cc:';

	/**
	 * @return array<string, mixed> kanonische Definition
	 */
	public static function normalize(mixed $raw): array {
		$raw = is_array($raw) ? $raw : [];
		$columns = [];
		foreach (self::listOf($raw['columns'] ?? null) as $c) {
			$c = is_array($c) ? $c : [];
			$columns[] = [
				'source' => self::str($c['source'] ?? 'actual'),
				'periodOffset' => (int)($c['periodOffset'] ?? 0),
			];
		}
		$groups = [];
		foreach (self::listOf($raw['groups'] ?? null) as $g) {
			$g = is_array($g) ? $g : [];
			$groups[] = [
				'key' => self::str($g['key'] ?? ''),
				'label' => self::text($g['label'] ?? ''),
				'costCenters' => array_values(array_map(self::str(...), self::listOf($g['costCenters'] ?? null))),
				'accounts' => array_values(array_map(static fn ($id): int => (int)$id, self::listOf($g['accounts'] ?? null))),
			];
		}
		$sections = [];
		foreach (self::listOf($raw['sections'] ?? null) as $s) {
			$sections[] = self::normalizeSection($s);
		}
		return [
			'version' => (int)($raw['version'] ?? self::VERSION),
			'columns' => $columns,
			'showPlanNotes' => (bool)($raw['showPlanNotes'] ?? false),
			'groups' => $groups,
			'sections' => $sections,
			'grandTotal' => self::footer($raw['grandTotal'] ?? null),
		];
	}

	/**
	 * Inhaltliche Fehler einer normalisierten Definition; leer = gültig.
	 *
	 * @param array<string, mixed> $def aus {@see normalize()}
	 * @return list<string>
	 */
	public static function validate(array $def, IL10N $l10n): array {
		$errors = [];
		if ($def['version'] !== self::VERSION) {
			$errors[] = $l10n->t('Unbekannte Version der Vorlage.');
		}

		$columns = $def['columns'];
		if ($columns === []) {
			$errors[] = $l10n->t('Die Vorlage braucht mindestens eine Spalte.');
		}
		if (count($columns) > self::MAX_COLUMNS) {
			$errors[] = $l10n->t('Höchstens %d Spalten sind möglich.', [self::MAX_COLUMNS]);
		}
		foreach ($columns as $c) {
			if (!in_array($c['source'], self::SOURCES, true)) {
				$errors[] = $l10n->t('Unbekannte Spaltenart „%s“.', [$c['source']]);
			}
			if ($c['periodOffset'] > 0 || $c['periodOffset'] < self::MIN_PERIOD_OFFSET) {
				$errors[] = $l10n->t('Eine Spalte kann höchstens %d Perioden zurückliegen.', [-self::MIN_PERIOD_OFFSET]);
			}
		}

		$groups = $def['groups'];
		if (count($groups) > self::MAX_GROUPS) {
			$errors[] = $l10n->t('Höchstens %d Berichtsgruppen sind möglich.', [self::MAX_GROUPS]);
		}
		$keys = [];
		$costCenters = [];
		$accounts = [];
		foreach ($groups as $g) {
			if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $g['key']) !== 1) {
				$errors[] = $l10n->t('Ungültiger Schlüssel einer Berichtsgruppe.');
			} elseif (isset($keys[$g['key']])) {
				$errors[] = $l10n->t('Die Berichtsgruppe „%s“ ist doppelt angelegt.', [$g['label'] !== '' ? $g['label'] : $g['key']]);
			}
			$keys[$g['key']] = true;
			if ($g['label'] === '') {
				$errors[] = $l10n->t('Jede Berichtsgruppe braucht einen Namen.');
			}
			if (count($g['accounts']) > self::MAX_GROUP_ACCOUNTS) {
				$errors[] = $l10n->t('Die Berichtsgruppe „%s“ enthält zu viele Konten.', [$g['label']]);
			}
			foreach ($g['costCenters'] as $code) {
				if (isset($costCenters[$code])) {
					$errors[] = $l10n->t('Die Auswertungsgruppe „%s“ ist mehreren Berichtsgruppen zugeordnet.', [$code]);
				}
				$costCenters[$code] = true;
			}
			foreach ($g['accounts'] as $id) {
				if (isset($accounts[$id])) {
					$errors[] = $l10n->t('Ein Konto ist mehreren Berichtsgruppen zugeordnet.');
				}
				$accounts[$id] = true;
			}
		}

		if ($def['sections'] === []) {
			$errors[] = $l10n->t('Die Vorlage braucht mindestens einen Abschnitt.');
		}
		foreach ($def['sections'] as $section) {
			self::validateSection($section, 1, [], $l10n, $errors);
		}

		return array_values(array_unique($errors));
	}

	/**
	 * Lässt der wirksame Filter Einnahmen **und** Ausgaben zu, ist eine
	 * Fußzeile ein Ergebnis (Einnahmen − Ausgaben), sonst eine Summe.
	 *
	 * @param list<string>|null $types wirksame Kontoarten, null = beide
	 */
	public static function isResult(?array $types): bool {
		return $types === null || count(array_intersect(self::TYPES, $types)) > 1;
	}

	/**
	 * @param array<string, mixed> $section
	 * @param list<string> $chain Merkmale der Ebenen darüber
	 * @param list<string> $errors
	 */
	private static function validateSection(array $section, int $depth, array $chain, IL10N $l10n, array &$errors): void {
		if ($depth > self::MAX_SECTION_DEPTH) {
			$errors[] = $l10n->t('Abschnitte lassen sich höchstens %d Ebenen tief verschachteln.', [self::MAX_SECTION_DEPTH]);
			return;
		}
		$filter = $section['filter'];
		foreach ($filter['types'] ?? [] as $t) {
			if (!in_array($t, self::TYPES, true)) {
				$errors[] = $l10n->t('Unbekannte Kontoart „%s“.', [$t]);
			}
		}
		foreach ($filter['spheres'] ?? [] as $s) {
			if ($s !== self::SPHERE_NONE && !in_array($s, Account::SPHERES, true)) {
				$errors[] = $l10n->t('Unbekannte Sphäre „%s“.', [$s]);
			}
		}
		foreach ($filter['groups'] ?? [] as $g) {
			if (!str_starts_with($g, self::GROUP_TEMPLATE) && !str_starts_with($g, self::GROUP_COST_CENTER)) {
				$errors[] = $l10n->t('Unbekannte Berichtsgruppe „%s“ im Filter.', [$g]);
			}
		}

		$hasSections = $section['sections'] !== null;
		$hasLevels = $section['levels'] !== null;
		if ($hasSections === $hasLevels) {
			$errors[] = $l10n->t('Ein Abschnitt enthält entweder Unterabschnitte oder Gliederungsebenen.');
			return;
		}
		if ($hasSections) {
			if ($section['sections'] === []) {
				$errors[] = $l10n->t('Ein Abschnitt mit Unterabschnitten braucht mindestens einen davon.');
			}
			foreach ($section['sections'] as $child) {
				self::validateSection($child, $depth + 1, $chain, $l10n, $errors);
			}
			return;
		}

		if ($section['levels'] === []) {
			$errors[] = $l10n->t('Ein Abschnitt braucht mindestens eine Gliederungsebene.');
		}
		$last = count($section['levels']) - 1;
		$seen = [];
		foreach ($section['levels'] as $i => $level) {
			$by = $level['by'];
			if (!in_array($by, self::LEVELS, true)) {
				$errors[] = $l10n->t('Unbekannte Gliederungsebene „%s“.', [$by]);
				continue;
			}
			if (isset($seen[$by])) {
				$errors[] = $l10n->t('Jedes Merkmal darf in einem Abschnitt nur eine Gliederungsebene bilden.');
			}
			$seen[$by] = true;
			if ($by === 'account' && $i !== $last) {
				$errors[] = $l10n->t('„Konto“ kann nur die letzte Gliederungsebene sein.');
			}
			if ($by === 'account' && preg_match('/^(plan|all|[0-9])$/', $level['depth']) !== 1) {
				$errors[] = $l10n->t('Ungültige Kontotiefe „%s“.', [$level['depth']]);
			}
		}
	}

	/** @return array<string, mixed> */
	private static function normalizeSection(mixed $raw): array {
		$raw = is_array($raw) ? $raw : [];
		$filter = is_array($raw['filter'] ?? null) ? $raw['filter'] : [];

		$sections = null;
		$levels = null;
		if (array_key_exists('sections', $raw) && $raw['sections'] !== null) {
			$sections = array_map(self::normalizeSection(...), self::listOf($raw['sections']));
		}
		if (array_key_exists('levels', $raw) && $raw['levels'] !== null) {
			$levels = [];
			foreach (self::listOf($raw['levels']) as $l) {
				$l = is_array($l) ? $l : [];
				$by = self::str($l['by'] ?? '');
				$level = [
					'by' => $by,
					'footer' => self::footer($l['footer'] ?? null),
					'hideEmpty' => (bool)($l['hideEmpty'] ?? true),
				];
				if ($by === 'account') {
					$level['depth'] = self::str($l['depth'] ?? 'plan');
					$level['ancestors'] = (bool)($l['ancestors'] ?? false);
				}
				$levels[] = $level;
			}
		}

		return [
			'title' => self::text($raw['title'] ?? ''),
			'filter' => [
				'types' => self::optionalList($filter['types'] ?? null),
				'spheres' => self::optionalList($filter['spheres'] ?? null),
				'groups' => self::optionalList($filter['groups'] ?? null),
			],
			'summary' => (bool)($raw['summary'] ?? false),
			'hideEmpty' => (bool)($raw['hideEmpty'] ?? false),
			'sections' => $sections,
			'levels' => $levels,
			'footer' => self::footer($raw['footer'] ?? null),
		];
	}

	/** @return array{label:string}|null */
	private static function footer(mixed $raw): ?array {
		if (!is_array($raw)) {
			return null;
		}
		return ['label' => self::text($raw['label'] ?? '')];
	}

	/**
	 * Leere Liste und null heißen gleichermaßen „keine Einschränkung".
	 *
	 * @return list<string>|null
	 */
	private static function optionalList(mixed $raw): ?array {
		$list = array_values(array_unique(array_map(self::str(...), self::listOf($raw))));
		return $list === [] ? null : $list;
	}

	/** @return list<mixed> */
	private static function listOf(mixed $raw): array {
		return is_array($raw) ? array_values($raw) : [];
	}

	private static function str(mixed $v): string {
		return is_scalar($v) ? trim((string)$v) : '';
	}

	private static function text(mixed $v): string {
		return mb_substr(self::str($v), 0, self::MAX_TEXT);
	}
}
