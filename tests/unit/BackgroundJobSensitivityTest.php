<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\BackgroundJob\ContributionDueCycleJob;
use OCA\Vereinsbuchhaltung\BackgroundJob\DunningLadderJob;
use OCA\Vereinsbuchhaltung\BackgroundJob\InvoiceDunningJob;
use OCA\Vereinsbuchhaltung\BackgroundJob\MandateDepartureJob;
use OCA\Vereinsbuchhaltung\BackgroundJob\MandateExpiryJob;
use OCA\Vereinsbuchhaltung\BackgroundJob\MemberDepartureJob;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die täglichen Jobs dürfen sich verschieben (#265): Nextcloud führt
 * zeitunkritische Jobs bevorzugt außerhalb der Hauptlast aus, wenn der
 * Administrator dafür ein Wartungsfenster eingestellt hat. Ob eine Mahnung
 * oder Fälligkeit um 3 Uhr oder um 9 Uhr entsteht, ändert fachlich nichts.
 */
class BackgroundJobSensitivityTest extends TestCase {

	/** @return array<string, array{class-string<TimedJob>}> */
	public static function dailyJobs(): array {
		return [
			'Beitragsfälligkeiten' => [ContributionDueCycleJob::class],
			'Mahnstufen Beiträge' => [DunningLadderJob::class],
			'Mahnstufen Rechnungen' => [InvoiceDunningJob::class],
			'Mandate bei Austritt' => [MandateDepartureJob::class],
			'Mandatsablauf' => [MandateExpiryJob::class],
			'Austritte' => [MemberDepartureJob::class],
		];
	}

	/** @param class-string<TimedJob> $class */
	#[DataProvider('dailyJobs')]
	public function testTaeglicherJobIstZeitunkritisch(string $class): void {
		$job = $this->build($class);

		$this->assertSame(86400, $job->getInterval(), 'Vorbedingung: täglicher Job');
		$this->assertFalse($job->isTimeSensitive());
	}

	/**
	 * Baut den Job mit gemockter ITimeFactory und gemockten Diensten.
	 *
	 * @param class-string<TimedJob> $class
	 */
	private function build(string $class): TimedJob {
		$args = [];
		foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
			$type = $param->getType();
			$this->assertInstanceOf(\ReflectionNamedType::class, $type, 'Konstruktorparameter ohne Klassentyp: $' . $param->getName());
			/** @var class-string $name ITimeFactory, Mapper, Dienste, Logger */
			$name = $type->getName();
			$args[] = $this->createMock($name);
		}
		return new $class(...$args);
	}
}
