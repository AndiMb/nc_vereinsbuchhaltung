<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IConfig;

/**
 * Vereins-Logo für druckfertige Berichte (Kurzbericht, siehe ExportController::kurzbericht()).
 * Bewusst nur EIN Logo + EINE Akzentfarbe ("Corporate Design light") – kein
 * Schriftarten-/Layout-Editor, das würde ein eigenes Berichtssystem brauchen.
 */
class BrandingService {

	private const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'];
	private const MAX_SIZE = 2 * 1024 * 1024; // 2 MB – ein Logo, kein Beleg-Upload
	private const FOLDER = 'branding';
	private const FILE = 'logo';

	private $appData;

	public function __construct(
		IAppDataFactory $appDataFactory,
		private IConfig $config,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	private function folder() {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}

	public function hasLogo(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'brand_logo_mime', '') !== '';
	}

	/** @return array{content:string,mimeType:string}|null */
	public function getLogo(): ?array {
		$mime = $this->config->getAppValue(Application::APP_ID, 'brand_logo_mime', '');
		if ($mime === '') {
			return null;
		}
		try {
			$content = $this->folder()->getFile(self::FILE)->getContent();
		} catch (NotFoundException|\Throwable) {
			return null;
		}
		return ['content' => $content, 'mimeType' => $mime];
	}

	public function setLogo(string $content, string $mimeType): void {
		if (!in_array($mimeType, self::ALLOWED_MIMES, true)) {
			throw new \InvalidArgumentException('Nur PNG/JPG/SVG/WebP als Logo erlaubt.');
		}
		if (strlen($content) > self::MAX_SIZE) {
			throw new \InvalidArgumentException('Logo zu groß (max. 2 MB).');
		}
		$folder = $this->folder();
		if ($folder->fileExists(self::FILE)) {
			$folder->getFile(self::FILE)->putContent($content);
		} else {
			$folder->newFile(self::FILE)->putContent($content);
		}
		$this->config->setAppValue(Application::APP_ID, 'brand_logo_mime', $mimeType);
	}

	public function deleteLogo(): void {
		try {
			$this->folder()->getFile(self::FILE)->delete();
		} catch (NotFoundException|\Throwable) {
			// schon weg – ignorieren
		}
		$this->config->deleteAppValue(Application::APP_ID, 'brand_logo_mime');
	}
}
