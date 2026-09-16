<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use PHPUnit\Framework\TestCase;

class ContributionGroupTest extends TestCase {

	public function testAllowedIntervalsArraySortiertUndDedupliziert(): void {
		$group = new ContributionGroup();
		$group->setAllowedIntervalsArray([12, 1, 1, 3]);
		$this->assertSame('1,3,12', $group->getAllowedIntervals());
		$this->assertSame([1, 3, 12], $group->getAllowedIntervalsArray());
	}

	public function testLeereAllowedIntervalsErgibtLeeresArray(): void {
		$group = new ContributionGroup();
		$group->setAllowedIntervals('');
		$this->assertSame([], $group->getAllowedIntervalsArray());
	}

	public function testJsonSerializeRechnetCentsInEuroUm(): void {
		$group = new ContributionGroup();
		$group->setMinMonthlyAmountCents(500);
		$group->setDefaultMonthlyAmountCents(800);
		$group->setAllowedIntervalsArray([1, 12]);
		$data = $group->jsonSerialize();
		$this->assertEquals(5.0, $data['minMonthlyAmount']);
		$this->assertEquals(8.0, $data['defaultMonthlyAmount']);
		$this->assertSame([1, 12], $data['allowedIntervals']);
	}
}
