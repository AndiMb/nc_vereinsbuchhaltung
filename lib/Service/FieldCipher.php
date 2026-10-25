<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCP\IConfig;

/**
 * Verschlüsselt einzelne Datenbankfelder (Encryption at rest), bisher die
 * IBANs der Mitglieder und Zahler und die Suchwerte der Zuordnungsregeln.
 *
 * Schützt die Werte in einem Datenbank-Dump oder -Backup, das ohne die
 * config.php der Instanz irgendwo landet, und gegen reinen Lesezugriff auf
 * die Datenbank. Der Schlüssel wird aus dem `secret` der config.php
 * abgeleitet: Wer Datenbank *und* config.php hat – also den Server –, liest
 * die Werte weiterhin. Umgekehrt sind sie nach einer Wiederherstellung ohne
 * die zugehörige config.php verloren.
 *
 * Bewusst nicht `ICrypto`: Das leitet den Schlüssel bei jedem Aufruf neu ab
 * (rund 0,4 ms je Wert), beim Laden von 500 Bankumsätzen kämen so 0,2 s dazu.
 * AES-256-GCM über OpenSSL mit einmal abgeleitetem Schlüssel ist ebenso
 * authentifiziert, braucht Mikrosekunden und liefert ein Drittel der Länge.
 *
 * Format: `vbh1:` + Base64(Nonce ‖ Tag ‖ Chiffrat). Kein gespeicherter
 * Klartext beginnt mit dem Präfix, deshalb reicht {@see decrypt()} Klartext
 * unverändert durch – Bestandswerte vor der Migration und Werte, die ältere
 * Migrationen direkt geschrieben haben.
 */
class FieldCipher {

	private const PREFIX = 'vbh1:';
	private const CIPHER = 'aes-256-gcm';
	private const NONCE_LENGTH = 12;
	private const TAG_LENGTH = 16;
	/** Trennt diesen Schlüssel von allem, was sonst aus dem `secret` abgeleitet wird. */
	private const KEY_INFO = 'vereinsbuchhaltung/db/v1';

	private ?string $key = null;

	public function __construct(
		private IConfig $config,
	) {
	}

	public function encrypt(string $plaintext): string {
		$nonce = random_bytes(self::NONCE_LENGTH);
		$tag = '';
		$ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LENGTH);
		if ($ciphertext === false) {
			throw new \RuntimeException('Der Wert konnte nicht verschlüsselt werden.');
		}
		return self::PREFIX . base64_encode($nonce . $tag . $ciphertext);
	}

	/**
	 * @throws \RuntimeException wenn ein Chiffrat sich nicht entschlüsseln
	 *                           lässt (anderes `secret`, veränderter Wert)
	 */
	public function decrypt(string $value): string {
		if (!self::isEncrypted($value)) {
			return $value;
		}
		$raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
		$plaintext = false;
		if ($raw !== false && strlen($raw) >= self::NONCE_LENGTH + self::TAG_LENGTH) {
			$plaintext = openssl_decrypt(
				substr($raw, self::NONCE_LENGTH + self::TAG_LENGTH),
				self::CIPHER,
				$this->key(),
				OPENSSL_RAW_DATA,
				substr($raw, 0, self::NONCE_LENGTH),
				substr($raw, self::NONCE_LENGTH, self::TAG_LENGTH),
			);
		}
		if ($plaintext === false) {
			throw new \RuntimeException('Ein verschlüsselter Wert (IBAN, Regel) konnte nicht entschlüsselt werden. Gehört die config.php (secret) zu dieser Datenbank?');
		}
		return $plaintext;
	}

	public static function isEncrypted(string $value): bool {
		return str_starts_with($value, self::PREFIX);
	}

	private function key(): string {
		if ($this->key === null) {
			$secret = $this->config->getSystemValueString('secret');
			if ($secret === '') {
				// Mit leerem secret wäre der Schlüssel für jede Instanz derselbe.
				throw new \RuntimeException('In der config.php fehlt das secret, ohne das sich nichts verschlüsseln lässt.');
			}
			$this->key = hash_hkdf('sha256', $secret, 32, self::KEY_INFO);
		}
		return $this->key;
	}
}
