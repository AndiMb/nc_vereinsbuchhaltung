<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Service\FolderPathValidator;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Einstellungen des Rechnungsmoduls: Standardwerte, Prüfregeln und die
 * Pflichtangaben des Ausstellers (§ 14 Abs. 4 Nr. 1 und 2 UStG).
 */
class InvoiceSettingsTest extends TestCase {

	/** @var array<string, string> */
	private array $store = [];

	private function resetStore(): void {
		$this->store = [];
	}

	private function settings(): InvoiceSettings {
		$this->resetStore();
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->store[$key] = $value;
		});
		$config->method('deleteAppValue')->willReturnCallback(function (string $app, string $key): void {
			unset($this->store[$key]);
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []) => vsprintf($text, (array)$params));
		return new InvoiceSettings($config, new FolderPathValidator($l10n), $l10n);
	}

	public function testStandardwerte(): void {
		$s = $this->settings();

		$this->assertFalse($s->isActive());
		$this->assertSame(Invoice::TAX_SMALL_BUSINESS, $s->taxMode());
		$this->assertSame(14, $s->defaultDueDays());
		$this->assertSame('Vereinsbuchhaltung/Rechnungen', $s->folder());
		$this->assertNull($s->accountId());
		$this->assertSame('DE', $s->issuer()['country']);
	}

	public function testFehlendeAusstellerangabenWerdenBenannt(): void {
		$s = $this->settings();

		$this->assertSame(
			['club_name', 'club_street', 'club_postal_code', 'club_city', 'club_tax_number'],
			array_keys($s->missingIssuerFields()),
		);

		$this->store['club_name'] = 'TSV Beispiel e. V.';
		$s->update([
			'club_street' => 'Hauptstr. 1',
			'club_postal_code' => '12345',
			'club_city' => 'Beispielstadt',
			'club_tax_number' => '12/345/67890',
		]);
		$this->assertSame([], $s->missingIssuerFields());
	}

	public function testUstIdErsetztSteuernummer(): void {
		$s = $this->settings();
		$this->store['club_name'] = 'Verein';
		$s->update(['club_street' => 'a', 'club_postal_code' => '1', 'club_city' => 'b', 'club_vat_id' => 'de 123456789']);

		$this->assertSame([], $s->missingIssuerFields());
		$this->assertSame('DE123456789', $s->issuer()['vatId']);
	}

	public function testRegelbesteuerungIstInStufeEinsNichtWaehlbar(): void {
		$s = $this->settings();

		$this->assertNotNull($s->update(['invoice_tax_mode' => Invoice::TAX_REGULAR]));
		$this->assertSame(Invoice::TAX_SMALL_BUSINESS, $s->taxMode());
		$this->assertNull($s->update(['invoice_tax_mode' => Invoice::TAX_EXEMPT]));
		$this->assertSame(Invoice::TAX_EXEMPT, $s->taxMode());
	}

	public function testFehlerSchreibtNichts(): void {
		$s = $this->settings();

		$error = $s->update(['club_city' => 'Ort', 'invoice_default_due_days' => 9999]);

		$this->assertNotNull($error);
		$this->assertSame('', $s->issuer()['city'], 'Eine fehlerhafte Anfrage hinterlässt keine halbe Änderung.');
	}

	/** @dataProvider ungueltigeEingaben */
	public function testUngueltigeEingabenWerdenAbgelehnt(array $params): void {
		$this->assertNotNull($this->settings()->update($params));
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function ungueltigeEingaben(): array {
		return [
			'Land dreistellig' => [['club_country' => 'DEU']],
			'E-Mail kaputt' => [['club_email' => 'kein-mail']],
			'USt-IdNr. kaputt' => [['club_vat_id' => '12']],
			'BIC kaputt' => [['invoice_bic' => 'ABC']],
			'Zahlungsziel negativ' => [['invoice_default_due_days' => -1]],
			'Pfad mit ..' => [['invoice_folder' => 'a/../b']],
		];
	}

	public function testBicUndLandWerdenNormalisiert(): void {
		$s = $this->settings();

		$this->assertNull($s->update(['invoice_bic' => 'genodef1 abc', 'club_country' => 'de']));

		$this->assertSame('GENODEF1ABC', $s->bic());
		$this->assertSame('DE', $s->issuer()['country']);
	}

	public function testAllEnthältFehlendeAngabenFürDieOberfläche(): void {
		$all = $this->settings()->all();

		$this->assertFalse($all['invoice_module_active']);
		$this->assertContains('club_tax_number', $all['invoice_missing_issuer_fields']);
	}
}
