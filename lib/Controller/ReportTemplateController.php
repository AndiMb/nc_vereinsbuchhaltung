<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\Export\GliederungsberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Report\ReportTemplateService;
use OCA\Vereinsbuchhaltung\Service\Report\StructuredReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Gliederungsbericht: Vorlagen verwalten und eine Vorlage auswerten.
 *
 * Lesen dürfen alle mit Lesezugriff, ändern alle mit Schreibrecht – die
 * {@see \OCA\Vereinsbuchhaltung\Middleware\PermissionMiddleware} leitet das
 * aus dem HTTP-Verb ab. Mitgelieferte Vorlagen sind schreibgeschützt (403).
 */
class ReportTemplateController extends Controller {

	use BookContext;

	public function __construct(
		IRequest $request,
		private ReportTemplateService $templates,
		private StructuredReportService $structured,
		private GliederungsberichtRenderer $renderer,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Alle Vorlagen, dazu die Auswertungsgruppen des Kontenrahmens – der
	 * Editor braucht sie, um sie umzubenennen oder zusammenzufassen.
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		$userId = $this->userId();
		return new DataResponse([
			'templates' => $this->templates->all($userId),
			'groups' => $this->structured->costCenterGroups($userId),
		]);
	}

	/**
	 * @param mixed $definition Definition als JSON-Objekt
	 */
	#[NoAdminRequired]
	public function create(string $name = '', mixed $definition = null): DataResponse {
		[$template, $errors] = $this->templates->create($this->userId(), $name, $definition);
		if ($template === null) {
			return $this->invalid($errors);
		}
		return new DataResponse($template, Http::STATUS_CREATED);
	}

	/**
	 * @param mixed $definition Definition als JSON-Objekt
	 */
	#[NoAdminRequired]
	public function update(string $id, string $name = '', mixed $definition = null): DataResponse {
		[$template, $errors] = $this->templates->update($this->userId(), $id, $name, $definition);
		if ($template !== null) {
			return new DataResponse($template);
		}
		return $errors !== [] ? $this->invalid($errors) : $this->notFound();
	}

	#[NoAdminRequired]
	public function destroy(string $id): DataResponse {
		return $this->templates->delete($this->userId(), $id)
			? new DataResponse(['status' => 'ok'])
			: $this->notFound();
	}

	/** Die Zeilenliste einer Vorlage für eine Periode. */
	#[NoAdminRequired]
	public function evaluate(string $template, ?int $period = null): DataResponse {
		$userId = $this->userId();
		$found = $this->templates->find($userId, $template);
		if ($found === null) {
			return $this->notFound();
		}
		$report = $this->structured->build($userId, $found, $period);
		$p = $report['period'];
		return new DataResponse([
			'template' => $report['template'],
			'period' => ['id' => (int)$p->getId(), 'label' => $p->getLabel()],
			'columns' => $report['columns'],
			'rows' => $report['rows'],
			'warnings' => array_map(fn (array $w): array => $w + ['message' => $this->renderer->warning($w)], $report['warnings']),
			'hasSpheres' => $report['hasSpheres'],
		]);
	}

	/** @param list<string> $errors */
	private function invalid(array $errors): DataResponse {
		return new DataResponse(['message' => $errors[0] ?? '', 'errors' => $errors], Http::STATUS_UNPROCESSABLE_ENTITY);
	}

	private function notFound(): DataResponse {
		return new DataResponse(['message' => $this->l10n->t('Vorlage nicht gefunden')], Http::STATUS_NOT_FOUND);
	}
}
