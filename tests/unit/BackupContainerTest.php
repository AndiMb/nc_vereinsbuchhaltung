<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupContainer;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Verschlüsselte Hülle der Sicherungsdatei (Issue #111): blockweise
 * AES-256-GCM, Schlüssel aus dem Passwort. Vertauschte, fehlende und
 * veränderte Blöcke müssen auffallen.
 */
class BackupContainerTest extends TestCase {
	private const PASSWORD = 'Sehr-Geheim-2026';
	private const WRONG = 'Die Sicherungsdatei ist unvollständig.';
	private const BROKEN = 'Das Passwort passt nicht oder die Datei ist beschädigt.';
	private const FOREIGN = 'Das ist keine Sicherungsdatei der Vereinsbuchhaltung.';

	/** @var list<string> */
	private array $files = [];
	private BackupContainer $container;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $params === [] ? $text : vsprintf($text, $params));
		$this->container = new BackupContainer($l10n);
	}

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
	}

	private function temp(?string $content = null): string {
		$path = tempnam(sys_get_temp_dir(), 'vbhtest');
		$this->files[] = $path;
		if ($content === null) {
			unlink($path);
		} else {
			file_put_contents($path, $content);
		}
		return $path;
	}

	/** 2,5 MiB, also drei Blöcke; kein Block gleicht dem anderen. */
	private function largePlain(): string {
		$out = '';
		for ($i = 0; strlen($out) < 2621440; $i++) {
			$out .= 'Zeile ' . $i . ' der Vereinsbuchhaltung, IBAN DE12500105170648489890' . "\n";
		}
		return substr($out, 0, 2621440);
	}

	private function encrypted(string $plain): string {
		$target = $this->temp();
		$this->container->encrypt($this->temp($plain), $target, self::PASSWORD);
		return $target;
	}

	/** @return array{0: string, 1: list<string>} Kopf (Kennung + Kopfzeile) und die rohen Blöcke */
	private function split(string $path): array {
		$raw = file_get_contents($path);
		$headEnd = strpos($raw, "\n", strlen(BackupContainer::MAGIC)) + 1;
		$blocks = [];
		$pos = $headEnd;
		while ($pos < strlen($raw)) {
			$len = unpack('N', substr($raw, $pos, 4))[1];
			$blocks[] = substr($raw, $pos, 4 + 12 + 16 + $len);
			$pos += 4 + 12 + 16 + $len;
		}
		return [substr($raw, 0, $headEnd), $blocks];
	}

	private function assertDecryptFails(string $source, string $message): void {
		$target = $this->temp();
		try {
			$this->container->decrypt($source, $target, self::PASSWORD);
			$this->fail('Entschlüsseln hätte scheitern müssen.');
		} catch (UserFacingException $e) {
			$this->assertSame($message, $e->getMessage());
		}
		$this->assertFileDoesNotExist($target, 'Nach einem Fehler darf kein Teil-Klartext liegen bleiben.');
	}

	public function testHinUndZurueck(): void {
		$plain = $this->largePlain();
		$encrypted = $this->encrypted($plain);

		$raw = file_get_contents($encrypted);
		$this->assertStringStartsWith("VBHBACKUP1\n", $raw);
		$header = json_decode(explode("\n", $raw, 3)[1], true);
		$this->assertSame(1, $header['format']);
		$this->assertSame('pbkdf2-sha256', $header['kdf']);
		$this->assertSame(600000, $header['iterations']);
		$this->assertSame(1048576, $header['chunk']);
		$this->assertSame(16, strlen(base64_decode($header['salt'], true)));
		$this->assertCount(3, $this->split($encrypted)[1]);

		$back = $this->temp();
		$this->container->decrypt($encrypted, $back, self::PASSWORD);
		$this->assertSame(hash('sha256', $plain), hash_file('sha256', $back));
	}

	public function testLeererInhalt(): void {
		$encrypted = $this->encrypted('');
		$this->assertCount(1, $this->split($encrypted)[1]);

		$back = $this->temp();
		$this->container->decrypt($encrypted, $back, self::PASSWORD);
		$this->assertSame('', file_get_contents($back));
	}

	public function testInhaltGenauEinBlock(): void {
		$plain = str_repeat('x', 1048576);
		$encrypted = $this->encrypted($plain);
		$back = $this->temp();
		$this->container->decrypt($encrypted, $back, self::PASSWORD);
		$this->assertSame(hash('sha256', $plain), hash_file('sha256', $back));
	}

	public function testKlartextStehtNichtInDerDatei(): void {
		$this->assertStringNotContainsString('DE12500105170648489890', file_get_contents($this->encrypted($this->largePlain())));
	}

	public function testZweiExporteUnterscheidenSich(): void {
		$this->assertNotSame(file_get_contents($this->encrypted('gleich')), file_get_contents($this->encrypted('gleich')));
	}

	public function testFalschesPasswort(): void {
		$encrypted = $this->encrypted('Inhalt');
		$target = $this->temp();
		try {
			$this->container->decrypt($encrypted, $target, 'Ganz-Anderes-Passwort');
			$this->fail('Falsches Passwort hätte scheitern müssen.');
		} catch (UserFacingException $e) {
			$this->assertSame(self::BROKEN, $e->getMessage());
		}
		$this->assertFileDoesNotExist($target);
	}

	public function testVeraenderterBlock(): void {
		$encrypted = $this->encrypted($this->largePlain());
		$raw = file_get_contents($encrypted);
		$pos = strlen($raw) - 100;
		$raw[$pos] = chr(ord($raw[$pos]) ^ 1);
		$this->assertDecryptFails($this->temp($raw), self::BROKEN);
	}

	public function testVertauschteBloecke(): void {
		[$head, $blocks] = $this->split($this->encrypted($this->largePlain()));
		$this->assertDecryptFails($this->temp($head . $blocks[1] . $blocks[0] . $blocks[2]), self::BROKEN);
	}

	public function testAbgeschnitteneDatei(): void {
		[$head, $blocks] = $this->split($this->encrypted($this->largePlain()));
		// Der Schlussblock fehlt ganz …
		$this->assertDecryptFails($this->temp($head . $blocks[0] . $blocks[1]), self::WRONG);
		// … die Datei endet mitten im Block …
		$this->assertDecryptFails($this->temp($head . $blocks[0] . substr($blocks[1], 0, 5000)), self::WRONG);
		// … oder gleich nach dem Kopf.
		$this->assertDecryptFails($this->temp($head), self::WRONG);
	}

	public function testAngehaengteDatenFallenAuf(): void {
		[$head, $blocks] = $this->split($this->encrypted($this->largePlain()));
		$this->assertDecryptFails($this->temp($head . implode('', $blocks) . $blocks[2]), self::BROKEN);
	}

	public function testFremdeDatei(): void {
		$this->assertDecryptFails($this->temp("PK\x03\x04 irgendein ZIP"), self::FOREIGN);
		$this->assertDecryptFails($this->temp(''), self::FOREIGN);
		$this->assertDecryptFails($this->temp("VBHBACKUP1\n{\"format\":2}\n"), self::FOREIGN);
		$this->assertDecryptFails($this->temp("VBHBACKUP1\nkein json\n"), self::FOREIGN);
	}

	public function testKurzesPasswort(): void {
		$target = $this->temp();
		try {
			$this->container->encrypt($this->temp('Inhalt'), $target, '123456789');
			$this->fail('Ein Passwort mit 9 Zeichen ist zu kurz.');
		} catch (UserFacingException $e) {
			$this->assertSame('Das Passwort braucht mindestens 10 Zeichen.', $e->getMessage());
		}
		$this->assertFileDoesNotExist($target);

		// Zehn Zeichen genügen, gezählt in Zeichen, nicht Bytes.
		$this->container->encrypt($this->temp('Inhalt'), $target, 'äöüäöüäöüä');
		$this->assertFileExists($target);
	}
}
