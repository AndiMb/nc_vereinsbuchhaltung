<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Sepa\DkReturnReasonCodes;
use PHPUnit\Framework\TestCase;

/** Spec §5: "?34 -> ISO (nur DK-Kernwerte 901-918, nie raten)". */
class DkReturnReasonCodesTest extends TestCase {

	public function testUebersetztBekannteKernwerte(): void {
		$this->assertSame('AC01', DkReturnReasonCodes::translate('901'));
		$this->assertSame('AC04', DkReturnReasonCodes::translate('902'));
		$this->assertSame('AC06', DkReturnReasonCodes::translate('903'));
		$this->assertSame('MD01', DkReturnReasonCodes::translate('909'));
		$this->assertSame('MD06', DkReturnReasonCodes::translate('912'));
		$this->assertSame('MD07', DkReturnReasonCodes::translate('913'));
	}

	/** Mehrdeutige/nicht eindeutig belegte Codes werden NICHT geraten. */
	public function testUnbekannteOderMehrdeutigeCodesLiefernNull(): void {
		$this->assertNull(DkReturnReasonCodes::translate('911'));
		$this->assertNull(DkReturnReasonCodes::translate('914'));
		$this->assertNull(DkReturnReasonCodes::translate('916'));
	}

	/** Werte außerhalb 901-918 sind keine DK-Kernwerte mehr. */
	public function testWerteAusserhalbDesKernbereichsLiefernNull(): void {
		$this->assertNull(DkReturnReasonCodes::translate('920'));
		$this->assertNull(DkReturnReasonCodes::translate('000'));
	}
}
