<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\Service\ImportService;

/**
 * Liest eine hochgeladene Auszugs- oder Importdatei aus der Anfrage – für den
 * Umsatz-Import und die Kontoabstimmung, die dieselben Dateien annehmen.
 *
 * Die benutzende Klasse bringt `$this->request` (IRequest) und `$this->l10n`
 * (IL10N) mit.
 */
trait StatementUpload {

	/**
	 * @return array{content:string, filename:string}
	 * @throws \InvalidArgumentException wenn keine Datei kam oder sie zu groß ist
	 */
	private function readUpload(): array {
		$file = $this->request->getUploadedFile('file');
		$uploaded = $file !== null && isset($file['tmp_name']) && is_uploaded_file($file['tmp_name']);
		// Ohne Datei: Fallback für Tests/JSON-Payload mit Base64/Plaintext
		$raw = $this->request->getParam('content');
		$raw = is_string($raw) ? $raw : '';
		$size = $uploaded ? (int)filesize($file['tmp_name']) : strlen($raw);
		if ($size === 0) {
			throw new \InvalidArgumentException($this->l10n->t('Keine Datei empfangen'));
		}
		if ($size > ImportService::MAX_BYTES) {
			throw new \InvalidArgumentException($this->l10n->t('Die Datei ist zu groß (höchstens %s MB).', [(string)(ImportService::MAX_BYTES / 1024 / 1024)]));
		}
		return $uploaded
			? ['content' => (string)file_get_contents($file['tmp_name']), 'filename' => (string)($file['name'] ?? 'upload.csv')]
			: ['content' => $raw, 'filename' => (string)($this->request->getParam('filename') ?? 'upload.csv')];
	}
}
