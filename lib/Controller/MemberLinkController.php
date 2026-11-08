<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Service\MemberLinkConfirmationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Bestätigungsseite der Kontoverknüpfung (#295), erreichbar über den Link in
 * der Mail an das Mitglied.
 *
 * Anders als die Mandats-Zustimmung ({@see MandateConsentController}) ist die
 * Seite NICHT öffentlich: bestätigen kann nur, wer mit genau dem verknüpften
 * Nextcloud-Konto angemeldet ist. Wer nicht angemeldet ist, landet über
 * Nextcloud auf der Anmeldung und danach wieder hier. Der Link allein (die
 * Mail an das Mitglied) und die Anmeldung allein (das Konto) reichen jeweils
 * nicht – erst beides zusammen zeigt, dass Konto und Mitglied dieselbe Person
 * sind.
 *
 * GET zeigt nur an, bestätigt wird per Formular-POST mit CSRF-Token. Die
 * {@see \OCA\Vereinsbuchhaltung\Middleware\PermissionMiddleware} überspringt den
 * Controller: das Konto hat hier noch keine Rolle und noch keinen Self-Service.
 */
class MemberLinkController extends Controller {

	public function __construct(
		IRequest $request,
		private MemberLinkConfirmationService $confirmation,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IAppConfig $config,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[BruteForceProtection(action: 'vbh_member_link')]
	public function show(string $token): TemplateResponse {
		$view = $this->confirmation->view($token, $this->uid());
		return $this->render($view['status'], $view['member'], $token);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 3600)]
	#[BruteForceProtection(action: 'vbh_member_link')]
	public function confirm(string $token): TemplateResponse {
		$result = $this->confirmation->confirm($token, $this->uid());
		return $this->render($result['status'], $result['member'], $token);
	}

	private function uid(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}

	private function render(string $status, ?Member $member, string $token): TemplateResponse {
		$clubName = $this->config->getValueString(Application::APP_ID, 'club_name', '');
		$club = $clubName !== '' ? $clubName : $this->l10n->t('Ihr Verein');
		$name = $member?->displayName() ?? '';
		$texts = match ($status) {
			MemberLinkConfirmationService::STATUS_PENDING => [
				'intro' => $this->l10n->t('%1$s möchte Ihre Mitgliedschaft mit diesem Nextcloud-Konto („%2$s") verknüpfen.', [$club, $this->uid()]),
				'question' => $this->l10n->t('Sind Sie das Mitglied %s?', [$name]),
				'button' => $this->l10n->t('Ja, das bin ich – Konto bestätigen'),
				'hint' => $this->l10n->t('Sind Sie es nicht, bestätigen Sie bitte nicht und geben Sie dem Verein Bescheid.'),
			],
			MemberLinkConfirmationService::STATUS_CONFIRMED => [
				'intro' => $this->l10n->t('Danke! Ihr Nextcloud-Konto ist jetzt mit Ihrer Mitgliedschaft verknüpft.'),
			],
			MemberLinkConfirmationService::STATUS_WRONG_ACCOUNT => [
				'intro' => $this->l10n->t('Dieser Link gehört zu einem anderen Nextcloud-Konto. Melden Sie sich mit dem Konto an, das in der Mail genannt ist, oder wenden Sie sich an den Verein.'),
			],
			MemberLinkConfirmationService::STATUS_EXPIRED => [
				'intro' => $this->l10n->t('Dieser Link ist abgelaufen. Bitte wenden Sie sich an den Verein, damit ein neuer verschickt wird.'),
			],
			default => [
				'intro' => $this->l10n->t('Dieser Link ist ungültig oder wurde bereits verwendet.'),
			],
		};

		$failed = in_array($status, [MemberLinkConfirmationService::STATUS_INVALID, MemberLinkConfirmationService::STATUS_EXPIRED, MemberLinkConfirmationService::STATUS_WRONG_ACCOUNT], true);
		$response = new TemplateResponse(
			Application::APP_ID,
			'memberLink',
			[
				'status' => $status,
				'title' => $this->l10n->t('Nextcloud-Konto bestätigen'),
				'clubName' => $clubName,
				'texts' => $texts,
				'confirmUrl' => $status === MemberLinkConfirmationService::STATUS_PENDING
					? $this->urlGenerator->linkToRoute('vereinsbuchhaltung.memberLink.confirm', ['token' => $token])
					: '',
				'appUrl' => $this->urlGenerator->linkToRoute('vereinsbuchhaltung.page.index'),
				'appLabel' => $this->l10n->t('Zur Vereinsbuchhaltung'),
			],
			TemplateResponse::RENDER_AS_USER,
			$failed ? ($status === MemberLinkConfirmationService::STATUS_EXPIRED ? Http::STATUS_GONE : Http::STATUS_NOT_FOUND) : Http::STATUS_OK,
		);
		if ($failed) {
			$response->throttle(['action' => 'vbh_member_link']);
		}
		return $response;
	}
}
