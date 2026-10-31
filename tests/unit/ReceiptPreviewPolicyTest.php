<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Http\ReceiptPreviewPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Belegvorschau (#231): Die App-Seite darf Dateien vom eigenen Ursprung in
 * einem iframe zeigen, und die Datei-Antworten dürfen nur dort eingebettet
 * werden. Skripte in den Dateien bleiben gesperrt (default-src 'none').
 */
class ReceiptPreviewPolicyTest extends TestCase {

	public function testInlineFileMayBeFramedBySelfOnly(): void {
		$policy = ReceiptPreviewPolicy::forInlineFile()->buildPolicy();

		$this->assertStringContainsString("frame-ancestors 'self'", $policy);
		$this->assertStringContainsString("default-src 'none'", $policy);
		$this->assertStringNotContainsString('script-src', $policy);
	}

	public function testPageAllowsSameOriginFrames(): void {
		$policy = ReceiptPreviewPolicy::forPage()->buildPolicy();

		$this->assertStringContainsString("frame-src 'self'", $policy);
		$this->assertStringContainsString("frame-ancestors 'self'", $policy);
	}
}
