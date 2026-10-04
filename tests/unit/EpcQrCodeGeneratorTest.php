<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use OCA\Vereinsbuchhaltung\Service\EpcQrCodeGenerator;
use PHPUnit\Framework\TestCase;

/**
 * GiroCode-Payload (EPC069-12, Spec §3.11 T32, Issue #73) – Schwerpunkt: das
 * zeilenweise Format ist formatkritisch (ein Fehler fiele erst beim Scan in
 * der Banking-App auf), deshalb hier Zeile für Zeile geprüft statt nur grob
 * auf "irgendein PNG kam raus". Dazu (Issue #120): das PNG ist ein gültiges,
 * wieder lesbares Bild, und ohne `gd` scheitert die Erzeugung fangbar.
 */
class EpcQrCodeGeneratorTest extends TestCase {

	private function generator(): EpcQrCodeGenerator {
		return new EpcQrCodeGenerator();
	}

	private function lines(string $payload): array {
		return explode("\n", $payload);
	}

	public function testPayloadHatDieElfPflichtfelderInDerRichtigenReihenfolge(): void {
		$lines = $this->lines($this->generator()->buildPayload('Musterverein e.V.', 'DE02120300000000202051', 'BYLADEM1001', 4599, 'Beitrag 2026-Q1'));

		$this->assertSame('BCD', $lines[0]);
		$this->assertSame('002', $lines[1]);
		$this->assertSame('1', $lines[2]);
		$this->assertSame('SCT', $lines[3]);
		$this->assertSame('BYLADEM1001', $lines[4]);
		$this->assertSame('Musterverein e.V.', $lines[5]);
		$this->assertSame('DE02120300000000202051', $lines[6]);
		$this->assertSame('EUR45.99', $lines[7]);
		$this->assertSame('', $lines[8]); // Purpose
		$this->assertSame('', $lines[9]); // strukturierte Referenz
		$this->assertSame('Beitrag 2026-Q1', $lines[10]);
	}

	public function testBicDarfLeerBleiben(): void {
		$lines = $this->lines($this->generator()->buildPayload('Musterverein e.V.', 'DE02120300000000202051', null, 100, 'x'));
		$this->assertSame('', $lines[4]);
	}

	public function testIbanWirdNormalisiertGrossOhneLeerzeichen(): void {
		$lines = $this->lines($this->generator()->buildPayload('V', 'de02 1203 0000 0000 2020 51', null, 100, 'x'));
		$this->assertSame('DE02120300000000202051', $lines[6]);
	}

	public function testBetragWirdMitPunktAlsDezimaltrennerFormatiert(): void {
		$lines = $this->lines($this->generator()->buildPayload('V', 'DE02120300000000202051', null, 100000, 'x'));
		$this->assertSame('EUR1000.00', $lines[7]);
	}

	public function testNameUndVerwendungszweckWerdenGekuerzt(): void {
		$langerName = str_repeat('A', 100);
		$langerZweck = str_repeat('B', 200);
		$lines = $this->lines($this->generator()->buildPayload($langerName, 'DE02120300000000202051', null, 100, $langerZweck));
		$this->assertSame(70, mb_strlen($lines[5]));
		$this->assertSame(140, mb_strlen($lines[10]));
	}

	public function testNichtPositiverBetragWirft(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->generator()->buildPayload('V', 'DE02120300000000202051', null, 0, 'x');
	}

	public function testLeereIbanWirft(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->generator()->buildPayload('V', '  ', null, 100, 'x');
	}

	public function testNutzlastOhneVerwendungszweckEndetOhneTrennzeichen(): void {
		// EPC069-12 2.2: "The last populated element is not followed by any
		// character or element separator".
		$payload = $this->generator()->buildPayload('V', 'DE02120300000000202051', null, 100, '  ');

		$this->assertSame("BCD\n002\n1\nSCT\n\nV\nDE02120300000000202051\nEUR1.00", $payload);
	}

	public function testNutzlastBleibtBeiMehrbyteZeichenUnterDenErlaubten331Bytes(): void {
		// EPC069-12 2.2: "The total payload is limited to 331 bytes. Please note
		// that the number of characters may be less than the numbers of bytes
		// with UTF-8." Name (70) und Verwendungszweck (140) randvoll mit Umlauten.
		$name = str_repeat('ü', 70);
		$zweck = str_repeat('ä', 140);

		$payload = $this->generator()->buildPayload($name, 'DE02120300000000202051', 'BYLADEM1001', 123456, $zweck);

		$this->assertLessThanOrEqual(331, strlen($payload));
		$this->assertTrue(mb_check_encoding($payload, 'UTF-8'), 'Gekürzt wird an einer Zeichengrenze, nie mitten im Umlaut');
		$lines = $this->lines($payload);
		$this->assertSame($name, $lines[5], 'Der Name bleibt unverändert, nur der Verwendungszweck gibt nach');
		$this->assertLessThan(140, mb_strlen($lines[10]));
		$this->assertStringStartsWith($lines[10], $zweck);
	}

	public function testReinesAsciiLaesstDenVerwendungszweckBisZurZeichengrenzeUnangetastet(): void {
		$zweck = str_repeat('B', 140);

		$lines = $this->lines($this->generator()->buildPayload(str_repeat('A', 70), 'DE02120300000000202051', 'BYLADEM1001', 99999999999, $zweck));

		$this->assertSame($zweck, $lines[10]);
		$this->assertSame('EUR999999999.99', $lines[7], 'Obergrenze des Betrags laut EPC069-12: 999999999.99');
	}

	// --- PNG: echtes Bild, gültig und lesbar ----------------------------------------

	/** Ende-zu-Ende: die eigentliche PNG-Erzeugung ueber chillerlan/php-qrcode funktioniert und liefert echte Bilddaten (kein Data-URI, kein SVG). */
	public function testGeneratePngLiefertEchteBinaereBilddaten(): void {
		$png = $this->generator()->generatePng('Musterverein e.V.', 'DE02120300000000202051', 'BYLADEM1001', 4599, 'Beitrag 2026-Q1');

		$this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png, 'Erwartet die PNG-Magic-Bytes, kein base64-Data-URI');
		$this->assertGreaterThan(100, strlen($png));
	}

	public function testGeneratePngLiefertEinGueltigesQuadratischesBildMitAusreichenderGroesse(): void {
		$png = $this->generator()->generatePng('Musterverein e.V.', 'DE02120300000000202051', 'BYLADEM1001', 4599, 'Beitrag 2026-Q1');

		$info = getimagesizefromstring($png);
		$this->assertIsArray($info, 'Die Bytes müssen sich als Bild einlesen lassen');
		$this->assertSame(IMAGETYPE_PNG, $info[2]);
		$this->assertSame($info[0], $info[1], 'Ein QR-Code ist quadratisch');
		// Version 6 (41 Module) bei Skalierung 6 plus Ruhezone ergibt rund 250 px;
		// unter 150 px ließe sich der Code am Bildschirm kaum noch scannen.
		$this->assertGreaterThanOrEqual(150, $info[0]);
		$this->assertGreaterThan(800, strlen($png), 'Ein leeres oder abgeschnittenes PNG wäre deutlich kleiner');
		$this->assertInstanceOf(\GdImage::class, imagecreatefromstring($png), 'GD kann das PNG wieder dekodieren');
	}

	/**
	 * Hin und zurück: was die Banking-App aus dem Bild liest, ist genau die
	 * EPC-Nutzlast – Umlaute, Betrag und Verwendungszweck eingeschlossen. Der
	 * Dekoder gehört zur selben Bibliothek; die Gegenprobe mit unabhängigen
	 * Dekodern (zxing-cpp, OpenCV) lief einmalig von Hand (Issue #120).
	 */
	public function testPngEnthaeltDieEpcNutzlastLesbar(): void {
		$generator = $this->generator();
		$name = 'Förderverein Müller & Söhne e.V.';
		$zweck = 'Beitrag 2026 (2026-01-01 – 2026-12-31): 45,99 €, fällig 2026-10-10';

		$png = $generator->generatePng($name, 'DE02 1203 0000 0000 2020 51', 'BYLADEM1001', 4599, $zweck);

		$gelesen = (string)(new QRCode(new QROptions(['readerUseImagickIfAvailable' => false])))->readFromBlob($png);
		$this->assertSame($generator->buildPayload($name, 'DE02 1203 0000 0000 2020 51', 'BYLADEM1001', 4599, $zweck), $gelesen);
		$lines = $this->lines($gelesen);
		$this->assertSame($name, $lines[5]);
		$this->assertSame('DE02120300000000202051', $lines[6]);
		$this->assertSame('EUR45.99', $lines[7]);
		$this->assertSame($zweck, $lines[10]);
	}

	// --- Fehlerfall: kein gd ---------------------------------------------------------

	/**
	 * Ohne `gd` darf die PNG-Erzeugung nur einen FANGBAREN Fehler werfen (die
	 * Aufrufer – {@see \OCA\Vereinsbuchhaltung\Service\DunningLadderService} –
	 * fangen ihn und schicken die Mail ohne GiroCode), nie das Skript beenden.
	 * Das lässt sich nur in einem Kindprozess ohne die Erweiterung prüfen: PHP
	 * entlädt `gd` nicht zur Laufzeit. `php -n` lädt keine ini-Dateien, bei
	 * Builds mit gd als Shared Extension (Docker-Images, Debian/Ubuntu/setup-php)
	 * fehlt es dann. Ist gd fest einkompiliert (z. B. Homebrew), geht das nicht,
	 * der Test wird dann übersprungen.
	 */
	public function testOhneGdWirftGeneratePngEinenFangbarenFehler(): void {
		$result = $this->runWithoutGd([]) ?? $this->runWithoutGd(['-d', 'extension=mbstring']);
		if ($result === null) {
			$this->markTestSkipped('Ein PHP ohne ini-Dateien kommt in dieser Installation nicht ohne mbstring aus.');
		}
		if ($result['gd']) {
			$this->markTestSkipped('gd ist in diesem PHP fest einkompiliert und lässt sich nicht abschalten.');
		}

		$this->assertNotNull($result['thrown'], 'Ohne gd muss generatePng() einen Fehler werfen, statt etwas Unbrauchbares zu liefern');
		$this->assertStringContainsStringIgnoringCase('gd', $result['thrown']['message'], 'Die Fehlermeldung nennt die Ursache, damit sie im Log verständlich ist');
	}

	/**
	 * Führt generatePng() in einem PHP ohne ini-Dateien aus.
	 *
	 * @param list<string> $extraArgs zusätzliche PHP-Optionen (z. B. -d extension=mbstring)
	 * @return array{gd:bool,thrown:array{class:string,message:string}|null}|null null, wenn mbstring (Pflicht der Bibliothek) im Kindprozess fehlt
	 */
	private function runWithoutGd(array $extraArgs): ?array {
		$code = 'if (!extension_loaded("mbstring")) { echo "VBH-RESULT null"; exit; }'
			. 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. '$thrown = null;'
			. 'try { (new OCA\\Vereinsbuchhaltung\\Service\\EpcQrCodeGenerator())->generatePng("V", "DE02120300000000202051", null, 100, "x"); }'
			. 'catch (\\Throwable $e) { $thrown = ["class" => get_class($e), "message" => $e->getMessage()]; }'
			. 'echo "VBH-RESULT " . json_encode(["gd" => extension_loaded("gd"), "thrown" => $thrown]);';

		$process = proc_open(
			[PHP_BINARY, '-n', ...$extraArgs, '-r', $code],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
		);
		$this->assertIsResource($process, 'Kindprozess konnte nicht gestartet werden');
		// Startwarnungen (z. B. "mbstring bereits geladen") landen auf stderr oder stdout - nur die Ergebniszeile zählt.
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		proc_close($process);

		$this->assertSame(1, preg_match('/VBH-RESULT (.*)$/m', $output, $match), 'Kindprozess lieferte kein Ergebnis: ' . $output);
		return json_decode($match[1], true);
	}
}
