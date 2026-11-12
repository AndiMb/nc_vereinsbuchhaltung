<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Backup;

use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCP\IL10N;

/**
 * Verschlüsselte Hülle der Sicherungsdatei (Issue #111).
 *
 * Aufbau:
 *
 *   VBHBACKUP1\n
 *   {"format":1,"kdf":"pbkdf2-sha256","iterations":600000,"salt":"…","chunk":1048576}\n
 *   Block*   je Block: 4 Byte Länge (big endian) | 12 Byte Nonce | 16 Byte Tag | Chiffrat
 *
 * Jeder Block ist für sich mit AES-256-GCM verschlüsselt; der Schlüssel
 * entsteht per PBKDF2 aus dem Passwort. In die Prüfsumme jedes Blocks gehen
 * seine laufende Nummer und das Kennzeichen „letzter Block“ ein
 * ({@see aad()}). Dadurch fallen vertauschte, fehlende, angehängte und
 * abgeschnittene Blöcke beim Entschlüsseln auf – eine halb heruntergeladene
 * Sicherung wird nie als vollständig gelesen.
 *
 * Das Passwort wird nirgends gespeichert und erscheint in keiner Meldung.
 */
class BackupContainer {

	public const MAGIC = "VBHBACKUP1\n";
	public const FORMAT = 1;
	public const MIN_PASSWORD_LENGTH = 10;

	private const KDF = 'pbkdf2-sha256';
	private const ITERATIONS = 600000;
	private const SALT_LENGTH = 16;
	private const CHUNK = 1048576;
	private const CIPHER = 'aes-256-gcm';
	private const NONCE_LENGTH = 12;
	private const TAG_LENGTH = 16;
	/** Obergrenzen für Werte aus einer fremden Kopfzeile, damit sie keinen Server lahmlegt. */
	private const MAX_ITERATIONS = 10000000;
	private const MAX_CHUNK = 16777216;

	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * Verschlüsselt die Datei `$plainPath` nach `$targetPath`.
	 *
	 * @throws UserFacingException bei zu kurzem Passwort
	 */
	public function encrypt(string $plainPath, string $targetPath, string $password): void {
		if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
			throw new UserFacingException($this->l10n->t('Das Passwort braucht mindestens %s Zeichen.', [self::MIN_PASSWORD_LENGTH]));
		}
		$salt = random_bytes(self::SALT_LENGTH);
		$key = hash_pbkdf2('sha256', $password, $salt, self::ITERATIONS, 32, true);

		$in = $this->open($plainPath, 'rb');
		$out = $this->open($targetPath, 'wb');
		try {
			fwrite($out, self::MAGIC . json_encode([
				'format' => self::FORMAT,
				'kdf' => self::KDF,
				'iterations' => self::ITERATIONS,
				'salt' => base64_encode($salt),
				'chunk' => self::CHUNK,
			], JSON_THROW_ON_ERROR) . "\n");

			$index = 0;
			$current = $this->readExactly($in, self::CHUNK);
			do {
				$next = $this->readExactly($in, self::CHUNK);
				$final = $next === '';
				$nonce = random_bytes(self::NONCE_LENGTH);
				$tag = '';
				$cipher = openssl_encrypt($current, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, self::aad($index, $final), self::TAG_LENGTH);
				if ($cipher === false) {
					throw new \RuntimeException('Sicherung: Block ' . $index . ' ließ sich nicht verschlüsseln.');
				}
				fwrite($out, pack('N', strlen($cipher)) . $nonce . $tag . $cipher);
				$current = $next;
				$index++;
			} while (!$final);
		} catch (\Throwable $e) {
			fclose($in);
			fclose($out);
			@unlink($targetPath);
			throw $e;
		}
		fclose($in);
		fclose($out);
	}

	/**
	 * Entschlüsselt `$sourcePath` nach `$plainPath`. Scheitert es, bleibt
	 * keine (Teil-)Datei zurück.
	 *
	 * @throws UserFacingException keine Sicherungsdatei, falsches Passwort, beschädigt oder unvollständig
	 */
	public function decrypt(string $sourcePath, string $plainPath, string $password): void {
		$in = $this->open($sourcePath, 'rb');
		$out = null;
		try {
			$header = $this->readHeader($in);
			$key = hash_pbkdf2('sha256', $password, $header['salt'], $header['iterations'], 32, true);
			$out = $this->open($plainPath, 'wb');

			$index = 0;
			$finished = false;
			while (true) {
				$lengthRaw = $this->readExactly($in, 4);
				if ($lengthRaw === '') {
					break;
				}
				if ($finished) {
					// Nach dem Schlussblock darf nichts mehr kommen.
					throw $this->broken();
				}
				if (strlen($lengthRaw) < 4) {
					throw $this->incomplete();
				}
				$length = unpack('N', $lengthRaw)[1];
				if ($length > $header['chunk']) {
					throw $this->broken();
				}
				$body = $this->readExactly($in, self::NONCE_LENGTH + self::TAG_LENGTH + $length);
				if (strlen($body) < self::NONCE_LENGTH + self::TAG_LENGTH + $length) {
					throw $this->incomplete();
				}
				$atEnd = $this->atEnd($in);
				$plain = $this->decryptBlock($body, $key, $index, $atEnd);
				if ($plain === null) {
					// Passt der Block mit dem anderen Kennzeichen, ist die Datei echt, aber
					// abgeschnitten (kein Schlussblock) oder verlängert (Daten nach dem Schluss).
					if ($this->decryptBlock($body, $key, $index, !$atEnd) !== null && $atEnd) {
						throw $this->incomplete();
					}
					throw $this->broken();
				}
				fwrite($out, $plain);
				$finished = $atEnd;
				$index++;
			}
			if (!$finished) {
				throw $this->incomplete();
			}
		} catch (\Throwable $e) {
			fclose($in);
			if ($out !== null) {
				fclose($out);
			}
			@unlink($plainPath);
			throw $e;
		}
		fclose($in);
		fclose($out);
	}

	/**
	 * @param resource $in
	 * @return array{salt: string, iterations: int, chunk: int}
	 */
	private function readHeader($in): array {
		$foreign = new UserFacingException($this->l10n->t('Das ist keine Sicherungsdatei der Vereinsbuchhaltung.'));
		if ($this->readExactly($in, strlen(self::MAGIC)) !== self::MAGIC) {
			throw $foreign;
		}
		$line = fgets($in, 4096);
		$header = is_string($line) && str_ends_with($line, "\n") ? json_decode($line, true) : null;
		$salt = is_array($header) && is_string($header['salt'] ?? null) ? base64_decode($header['salt'], true) : false;
		if (
			!is_array($header)
			|| ($header['format'] ?? null) !== self::FORMAT
			|| ($header['kdf'] ?? null) !== self::KDF
			|| !is_int($header['iterations'] ?? null) || $header['iterations'] < 1 || $header['iterations'] > self::MAX_ITERATIONS
			|| !is_int($header['chunk'] ?? null) || $header['chunk'] < 1 || $header['chunk'] > self::MAX_CHUNK
			|| $salt === false || $salt === ''
		) {
			throw $foreign;
		}
		return ['salt' => $salt, 'iterations' => $header['iterations'], 'chunk' => $header['chunk']];
	}

	private function decryptBlock(string $body, string $key, int $index, bool $final): ?string {
		$plain = openssl_decrypt(
			substr($body, self::NONCE_LENGTH + self::TAG_LENGTH),
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			substr($body, 0, self::NONCE_LENGTH),
			substr($body, self::NONCE_LENGTH, self::TAG_LENGTH),
			self::aad($index, $final),
		);
		return $plain === false ? null : $plain;
	}

	/** Zusatzdaten eines Blocks: binden ihn an seine Stelle und an das Dateiende. */
	private static function aad(int $index, bool $final): string {
		return 'vbhbackup1|' . $index . '|' . ($final ? '1' : '0');
	}

	private function broken(): UserFacingException {
		return new UserFacingException($this->l10n->t('Das Passwort passt nicht oder die Datei ist beschädigt.'));
	}

	private function incomplete(): UserFacingException {
		return new UserFacingException($this->l10n->t('Die Sicherungsdatei ist unvollständig.'));
	}

	/** @return resource */
	private function open(string $path, string $mode) {
		$handle = @fopen($path, $mode);
		if ($handle === false) {
			throw new \RuntimeException('Sicherung: Datei lässt sich nicht öffnen (' . $mode . ').');
		}
		return $handle;
	}

	/**
	 * Liest bis zu `$length` Byte; weniger nur am Dateiende (fread darf kürzer liefern).
	 *
	 * @param resource $handle
	 */
	private function readExactly($handle, int $length): string {
		$data = '';
		while (strlen($data) < $length && !feof($handle)) {
			$part = fread($handle, $length - strlen($data));
			if ($part === false || $part === '') {
				break;
			}
			$data .= $part;
		}
		return $data;
	}

	/** @param resource $handle */
	private function atEnd($handle): bool {
		$byte = fread($handle, 1);
		if ($byte === false || $byte === '') {
			return true;
		}
		fseek($handle, -1, SEEK_CUR);
		return false;
	}
}
