<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Http;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;

/**
 * Sicherheitsregeln für die eingebettete Belegvorschau (#231,
 * docs/superpowers/specs/2026-10-31-belegvorschau-design.md §3.7).
 *
 * Die Vorschau zeigt Dateien aus dem Wächter-Ordner in einem iframe neben
 * der Belegliste. Dafür braucht es zwei Lockerungen, beide nur für den
 * eigenen Ursprung:
 *
 * - Die App-Seite darf iframes von dort laden (frame-src 'self'). Die
 *   Nextcloud-Standardregel hat kein frame-src und fällt damit auf
 *   default-src 'none' zurück.
 * - Die Datei-Antwort darf dort eingebettet werden (frame-ancestors 'self').
 *   EmptyContentSecurityPolicy setzt sonst frame-ancestors 'none'.
 *
 * Alles andere an der Datei-Antwort bleibt leer: default-src 'none' sperrt
 * Skripte in PDFs weiterhin. Admins müssen nichts einstellen.
 */
final class ReceiptPreviewPolicy {

	/**
	 * Regel der App-Seite: Standard plus iframes vom eigenen Ursprung. blob:
	 * für PDFs, die die Vorschau selbst geladen und auf Vollständigkeit
	 * geprüft hat (ReceiptPreview.vue) - sie zeigt sie aus diesen Daten, statt
	 * die Datei ein zweites Mal zu laden. blob:-Adressen entstehen nur im
	 * eigenen Ursprung.
	 */
	public static function forPage(): ContentSecurityPolicy {
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedFrameDomain("'self'");
		$policy->addAllowedFrameDomain('blob:');
		return $policy;
	}

	/** Regel einer inline ausgelieferten Datei: nichts erlaubt außer Einbetten in die eigene App. */
	public static function forInlineFile(): EmptyContentSecurityPolicy {
		$policy = new EmptyContentSecurityPolicy();
		$policy->addAllowedFrameAncestorDomain("'self'");
		return $policy;
	}

	/**
	 * Eine Belegdatei als Antwort, die der Browser inline zeigt (Vorschau): ohne
	 * Raten des Dateityps und mit der Regel von {@see forInlineFile()}.
	 */
	public static function inlineResponse(string $content, string $name, string $mime): DataDownloadResponse {
		$response = new DataDownloadResponse($content, $name, $mime);
		$response->addHeader('Content-Disposition', 'inline; filename="' . addslashes($name) . '"');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->setContentSecurityPolicy(self::forInlineFile());
		return $response;
	}
}
