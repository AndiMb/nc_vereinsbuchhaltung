<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

use OCP\IL10N;

/**
 * Die Standardvorlagen des Gliederungsberichts.
 *
 * Bis 0.38.0 standen sie schreibgeschützt neben den eigenen Vorlagen. Seither
 * legt {@see ReportTemplateService} sie beim ersten Öffnen einmalig als ganz
 * normale Vorlagen an – danach lassen sie sich ändern und löschen. Diese
 * Klasse liefert nur noch Name und Definition zum Anlegen, übersetzt in die
 * Sprache des laufenden Requests.
 *
 * Beide setzen voraus, dass die Konten eine Sphäre haben; Projekte sind die
 * Auswertungsgruppen des Zweckbetriebs. Ohne Sphären landet alles unter
 * „Ohne Sphäre“, und der Bericht sagt das.
 */
class BuiltinReportTemplates {

	public const GRANTOR = 'zuwendungsgeber';
	public const AREAS = 'bereiche';

	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * @return list<array{key:string, name:string, definition:array<string, mixed>}>
	 */
	public function all(): array {
		return [
			[
				'key' => self::GRANTOR,
				'name' => $this->l10n->t('Haushaltsplan nach Titelgruppen (Zuwendungsgeber)'),
				'definition' => ReportDefinition::normalize($this->grantor()),
			],
			[
				'key' => self::AREAS,
				'name' => $this->l10n->t('Ergebnis je Bereich und Projekt'),
				'definition' => ReportDefinition::normalize($this->areas()),
			],
		];
	}

	/** @return array{key:string, name:string, definition:array<string, mixed>}|null */
	public function find(string $key): ?array {
		foreach ($this->all() as $template) {
			if ($template['key'] === $key) {
				return $template;
			}
		}
		return null;
	}

	/**
	 * Einnahmen, dann Ausgaben; darin zuerst der ideelle Bereich nach
	 * Kontenhierarchie, dann die Projekte je Titelgruppe mit Summe. Am Ende
	 * eine Zusammenfassung nach Hauptgruppen (oberste Kontoebene).
	 *
	 * @return array<string, mixed>
	 */
	private function grantor(): array {
		$side = fn (string $type, string $title, string $footer): array => [
			'title' => $title,
			'filter' => ['types' => [$type]],
			'sections' => [
				$this->tree($this->l10n->t('Ideeller Bereich'), 'ideell', true, false),
				[
					'title' => $this->l10n->t('Titelgruppen / Projekte'),
					'filter' => ['spheres' => ['zweckbetrieb']],
					'hideEmpty' => true,
					'levels' => [
						['by' => 'group', 'footer' => ['label' => $this->l10n->t('Summe der Titelgruppe')]],
						['by' => 'account', 'depth' => 'plan'],
					],
				],
				$this->tree($this->l10n->t('Vermögensverwaltung'), 'vermoegensverwaltung', true, true),
				$this->tree($this->l10n->t('Wirtschaftlicher Geschäftsbetrieb'), 'wirtschaftlich', true, true),
				$this->tree($this->l10n->t('Ohne Sphäre'), ReportDefinition::SPHERE_NONE, true, true),
			],
			'footer' => ['label' => $footer],
		];

		return [
			'version' => ReportDefinition::VERSION,
			'columns' => [
				['source' => 'plan', 'periodOffset' => -1],
				['source' => 'plan', 'periodOffset' => 0],
			],
			'showPlanNotes' => true,
			'sections' => [
				$side('income', $this->l10n->t('Einnahmen'), $this->l10n->t('Gesamteinnahmen')),
				$side('expense', $this->l10n->t('Ausgaben'), $this->l10n->t('Gesamtausgaben')),
				[
					'title' => $this->l10n->t('Zusammenfassung nach Hauptgruppen'),
					'summary' => true,
					'levels' => [
						['by' => 'type', 'footer' => ['label' => $this->l10n->t('Summe')]],
						['by' => 'account', 'depth' => '0', 'ancestors' => true],
					],
				],
			],
			'grandTotal' => ['label' => $this->l10n->t('Differenz')],
		];
	}

	/**
	 * Ideeller Bereich mit Einnahmen, Ausgaben und Ergebnis; danach je
	 * Projekt Einnahmen, Ausgaben und Differenz – so ist zu sehen, was der
	 * Verein für seine Projekte aus eigenen Mitteln aufbringt.
	 *
	 * @return array<string, mixed>
	 */
	private function areas(): array {
		$byType = fn (string $title, string $sphere, string $result, bool $hide): array => [
			'title' => $title,
			'filter' => ['spheres' => [$sphere]],
			'hideEmpty' => $hide,
			'levels' => [
				['by' => 'type', 'footer' => ['label' => $this->l10n->t('Summe')], 'hideEmpty' => false],
				['by' => 'account', 'depth' => '0'],
			],
			'footer' => ['label' => $result],
		];

		return [
			'version' => ReportDefinition::VERSION,
			'columns' => [
				['source' => 'plan', 'periodOffset' => -1],
				['source' => 'actual', 'periodOffset' => -1],
				['source' => 'plan', 'periodOffset' => 0],
				['source' => 'actual', 'periodOffset' => 0],
			],
			'showPlanNotes' => false,
			'sections' => [
				$byType($this->l10n->t('Ideeller Bereich'), 'ideell', $this->l10n->t('Ergebnis ideeller Bereich'), false),
				[
					'title' => $this->l10n->t('Zweckbetrieb'),
					'filter' => ['spheres' => ['zweckbetrieb']],
					'levels' => [
						['by' => 'group', 'footer' => ['label' => $this->l10n->t('Differenz')]],
						['by' => 'type', 'hideEmpty' => false],
					],
					'footer' => ['label' => $this->l10n->t('Ergebnis Zweckbetrieb')],
				],
				$byType($this->l10n->t('Vermögensverwaltung'), 'vermoegensverwaltung', $this->l10n->t('Ergebnis Vermögensverwaltung'), true),
				$byType($this->l10n->t('Wirtschaftlicher Geschäftsbetrieb'), 'wirtschaftlich', $this->l10n->t('Ergebnis wirtschaftlicher Geschäftsbetrieb'), true),
				$byType($this->l10n->t('Ohne Sphäre'), ReportDefinition::SPHERE_NONE, $this->l10n->t('Ergebnis ohne Sphäre'), true),
			],
			'grandTotal' => ['label' => $this->l10n->t('Saldo')],
		];
	}

	/**
	 * Ein Bereich als Kontenbaum bis zur Planungsebene.
	 *
	 * @return array<string, mixed>
	 */
	private function tree(string $title, string $sphere, bool $ancestors, bool $hideEmpty): array {
		return [
			'title' => $title,
			'filter' => ['spheres' => [$sphere]],
			'hideEmpty' => $hideEmpty,
			'levels' => [
				['by' => 'account', 'depth' => 'plan', 'ancestors' => $ancestors],
			],
		];
	}
}
