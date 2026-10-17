<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\ReportTemplate;
use OCA\Vereinsbuchhaltung\Db\ReportTemplateMapper;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Service\Report\BuiltinReportTemplates;
use OCA\Vereinsbuchhaltung\Service\Report\ReportTemplateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Vorlagen des Gliederungsberichts: mitgelieferte sind schreibgeschützt,
 * eigene werden nur gültig gespeichert.
 */
class ReportTemplateServiceTest extends TestCase {

	private ReportTemplateMapper&MockObject $mapper;

	private function service(): ReportTemplateService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$this->mapper = $this->createMock(ReportTemplateMapper::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-17 12:00:00'));
		return new ReportTemplateService($this->mapper, new BuiltinReportTemplates($l10n), $time, $l10n);
	}

	private function stored(int $id, string $name, string $json): ReportTemplate {
		$t = new ReportTemplate();
		$t->setId($id);
		$t->setUserId('u');
		$t->setName($name);
		$t->setDefinition($json);
		$t->setPosition(1);
		return $t;
	}

	/** @return array<string, mixed> */
	private function valid(): array {
		return ['columns' => [['source' => 'actual']], 'sections' => [['levels' => [['by' => 'type']]]]];
	}

	public function testMitgelieferteVorAllenEigenen(): void {
		$service = $this->service();
		$this->mapper->method('findAll')->willReturn([$this->stored(5, 'Meine', '{"columns":[{"source":"plan"}]}')]);

		$all = $service->all('u');

		$this->assertSame([BuiltinReportTemplates::GRANTOR, BuiltinReportTemplates::AREAS, '5'], array_column($all, 'id'));
		$this->assertSame([true, true, false], array_column($all, 'builtin'));
		$this->assertSame([['source' => 'plan', 'periodOffset' => 0]], $all[2]['definition']['columns'], 'gespeicherte Definition kommt normalisiert zurück');
	}

	public function testFindetMitgelieferteUndEigene(): void {
		$service = $this->service();
		$this->mapper->method('findForUser')->willReturnCallback(fn (string $u, int $id): ?ReportTemplate => $id === 5 ? $this->stored(5, 'Meine', '{}') : null);

		$this->assertSame(BuiltinReportTemplates::AREAS, $service->find('u', BuiltinReportTemplates::AREAS)['id'] ?? null);
		$this->assertNull($service->find('u', 'builtin:gibtsnicht'));
		$this->assertSame('Meine', $service->find('u', '5')['name'] ?? null);
		$this->assertNull($service->find('u', '6'));
		$this->assertNull($service->find('u', 'abc'));
	}

	public function testDefektesJsonErgibtLeereDefinition(): void {
		$service = $this->service();
		$this->mapper->method('findForUser')->willReturn($this->stored(5, 'Kaputt', '{nicht json'));

		$this->assertSame([], $service->find('u', '5')['definition']['sections'] ?? null);
	}

	public function testAnlegenSpeichertNormalisiertUndHaengtHintenAn(): void {
		$service = $this->service();
		$this->mapper->method('maxPosition')->willReturn(3);
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(function (ReportTemplate $t): ReportTemplate {
			$this->assertSame(4, $t->getPosition());
			$this->assertSame('Verband', $t->getName());
			$this->assertStringContainsString('"periodOffset":0', $t->getDefinition());
			$t->setId(9);
			return $t;
		});

		[$template, $errors] = $service->create('u', '  Verband ', $this->valid());

		$this->assertSame([], $errors);
		$this->assertSame('9', $template['id'] ?? null);
	}

	public function testUngueltigeVorlageWirdNichtGespeichert(): void {
		$service = $this->service();
		$this->mapper->expects($this->never())->method('insert');

		[$template, $errors] = $service->create('u', '', ['sections' => []]);

		$this->assertNull($template);
		$this->assertSame('Die Vorlage braucht einen Namen.', $errors[0]);
		$this->assertContains('Die Vorlage braucht mindestens einen Abschnitt.', $errors);
	}

	public function testMitgelieferteLassenSichNichtAendern(): void {
		$service = $this->service();
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);
		$service->update('u', BuiltinReportTemplates::GRANTOR, 'X', $this->valid());
	}

	public function testMitgelieferteLassenSichNichtLoeschen(): void {
		$service = $this->service();

		$this->expectException(ForbiddenException::class);
		$service->delete('u', BuiltinReportTemplates::AREAS);
	}

	public function testUnbekannteVorlageAendernOderLoeschen(): void {
		$service = $this->service();
		$this->mapper->method('findForUser')->willReturn(null);

		$this->assertSame([null, []], $service->update('u', '42', 'X', $this->valid()));
		$this->assertFalse($service->delete('u', '42'));
	}

	public function testAendern(): void {
		$service = $this->service();
		$this->mapper->method('findForUser')->willReturn($this->stored(5, 'Alt', '{}'));
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		[$template, $errors] = $service->update('u', '5', 'Neu', $this->valid());

		$this->assertSame([], $errors);
		$this->assertSame('Neu', $template['name'] ?? null);
	}
}
