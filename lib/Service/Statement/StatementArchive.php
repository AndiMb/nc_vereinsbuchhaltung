<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Statement;

use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCP\IL10N;
use OCP\ITempManager;

/**
 * Entpackt ein ZIP-Archiv mit Kontoauszügen (Issue #359).
 *
 * Manche Institute liefern ihre CAMT-Exporte nur gebündelt: ein ZIP mit je
 * einer XML-Datei pro Tag (CAMT.052) oder Auszug (CAMT.053). Statt die Nutzer
 * selbst entpacken zu lassen, liest die Registry jede Datei im Archiv.
 *
 * Das Archiv kommt von außen. Deshalb gelten feste Grenzen für die Zahl der
 * Dateien und die entpackte Gesamtgröße, und gelesen wird je Datei höchstens
 * bis zur Grenze, nicht so viel, wie der ZIP-Eintrag behauptet: ein präpariertes
 * Archiv („ZIP-Bombe“) kann sonst aus wenigen Kilobyte Gigabytes machen.
 * Verschachtelte Archive werden nicht entpackt.
 */
final class StatementArchive {

	/** Höchstens so viele Dateien je Archiv – ein Jahr Tagesreports passt hinein. */
	public const MAX_FILES = 400;

	/** Entpackt höchstens so viel wie eine einzelne hochgeladene Datei. */
	public const MAX_TOTAL_BYTES = 20 * 1024 * 1024;

	public function __construct(
		private ?IL10N $l10n = null,
		private ?ITempManager $tempManager = null,
	) {
	}

	/** ZIP-Dateien beginnen mit der Signatur eines lokalen Dateikopfs. */
	public static function isZip(string $content): bool {
		return str_starts_with($content, "PK\x03\x04");
	}

	private function msg(string $text): string {
		return $this->l10n !== null ? $this->l10n->t($text) : $text;
	}

	/**
	 * @return list<array{name: string, content: string}> die Dateien im Archiv, nach Namen sortiert
	 * @throws UserFacingException bei beschädigtem Archiv, zu vielen oder zu großen Dateien
	 */
	public function files(string $content): array {
		if (!class_exists(\ZipArchive::class)) {
			throw new UserFacingException($this->msg('Auf diesem Server fehlt die PHP-Erweiterung zip.'));
		}
		$path = $this->tempManager?->getTemporaryFile('.zip') ?: tempnam(sys_get_temp_dir(), 'vbh-zip-');
		if ($path === false || file_put_contents($path, $content) === false) {
			throw new \RuntimeException('ZIP-Archiv: temporäre Datei nicht beschreibbar.');
		}
		$zip = new \ZipArchive();
		try {
			if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
				throw new UserFacingException($this->msg('Das ZIP-Archiv ist beschädigt und lässt sich nicht öffnen.'));
			}
			$files = [];
			$total = 0;
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = (string)$zip->getNameIndex($i);
				if (self::skip($name)) {
					continue;
				}
				if (count($files) >= self::MAX_FILES) {
					throw new UserFacingException($this->msg('Das ZIP-Archiv enthält zu viele Dateien.'));
				}
				$data = $this->read($zip, $i, self::MAX_TOTAL_BYTES - $total);
				$total += strlen($data);
				if (trim($data) === '') {
					continue;
				}
				$files[] = ['name' => $name, 'content' => $data];
			}
			$zip->close();
		} finally {
			@unlink($path);
		}
		usort($files, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
		return $files;
	}

	/** Ordner, macOS-Beigaben und versteckte Dateien gehören nicht zum Auszug. */
	private static function skip(string $name): bool {
		if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/')) {
			return true;
		}
		return str_starts_with(basename($name), '.');
	}

	/** Liest eine Datei, aber höchstens $limit Bytes – sonst Fehler statt Speicherüberlauf. */
	private function read(\ZipArchive $zip, int $index, int $limit): string {
		$stream = $zip->getStream((string)$zip->getNameIndex($index));
		if ($stream === false) {
			throw new UserFacingException($this->msg('Das ZIP-Archiv ist beschädigt und lässt sich nicht öffnen.'));
		}
		try {
			$data = stream_get_contents($stream, $limit + 1);
		} finally {
			fclose($stream);
		}
		if ($data === false) {
			throw new UserFacingException($this->msg('Das ZIP-Archiv ist beschädigt und lässt sich nicht öffnen.'));
		}
		if (strlen($data) > $limit) {
			throw new UserFacingException($this->msg('Das ZIP-Archiv ist entpackt zu groß (höchstens 20 MB).'));
		}
		return $data;
	}
}
