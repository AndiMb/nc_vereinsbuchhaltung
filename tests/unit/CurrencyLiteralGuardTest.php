<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Vereinswährung (#202): Beträge tragen das Zeichen aus ClubCurrency. Ein
 * festes „€“ oder 'EUR' im Servercode wäre bei CHF falsch. Ausgenommen sind
 * Stellen, die nur bei Euro erreichbar sind (SEPA), gesetzliche Euro-Grenzen,
 * Migrationen und die Standardwerte der Entities.
 */
class CurrencyLiteralGuardTest extends TestCase {

	/** Ganze Dateien, relativ zu lib/. */
	private const ALLOWED_FILES = [
		'Service/ClubCurrency.php',
		'Service/ClubCurrencySwitchGuard.php',
		'Service/EpcQrCodeGenerator.php',
		'Service/Sepa/PainXmlBuilder.php',
		'Service/Sepa/SepaText.php',
		'Service/Sepa/SepaMatchingService.php',
		'Service/Sepa/BankReconciliationService.php',
		'Service/DebitBatchTaskService.php',
		'Service/MandateTaskService.php',
		'Service/ContributionPreNotificationService.php',
		'Db/BankTransaction.php',
		'Db/Invoice.php',
	];

	/** Einzelne Zeilen mit gesetzlichen Euro-Grenzen: Datei => Textstück. */
	private const ALLOWED_LINES = [
		'Service/Export/KassenberichtRenderer.php' => 'Freigrenze',
	];

	public function testKeinFestesEuroImServercode(): void {
		$lib = dirname(__DIR__, 2) . '/lib';
		$hits = [];
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($lib) + 1));
			if (str_starts_with($rel, 'Migration/') || in_array($rel, self::ALLOWED_FILES, true)) {
				continue;
			}
			foreach (file($file->getPathname()) as $no => $line) {
				$code = ltrim($line);
				if (str_starts_with($code, '//') || str_starts_with($code, '*') || str_starts_with($code, '/*')) {
					continue;
				}
				if (!str_contains($line, '€') && !preg_match('/[\'"]EUR[\'"]/', $line)) {
					continue;
				}
				if (isset(self::ALLOWED_LINES[$rel]) && str_contains($line, self::ALLOWED_LINES[$rel])) {
					continue;
				}
				$hits[] = $rel . ':' . ($no + 1) . ': ' . trim($line);
			}
		}
		$this->assertSame([], $hits, "Festes Euro im Servercode – ClubCurrency verwenden:\n" . implode("\n", $hits));
	}
}
