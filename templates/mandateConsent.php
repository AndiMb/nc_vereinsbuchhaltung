<?php
declare(strict_types=1);
/**
 * Oeffentliche Zustimmungsseite der elektronischen Mandatserteilung (Issue
 * #67) - siehe MandateConsentController. Bewusst ohne JavaScript: die
 * Zustimmung ist ein normales HTML-Formular (POST auf dieselbe Route), damit
 * die Seite auch ohne Client-Skripte funktioniert. Nur Deutsch (Spec: der
 * Mandats-Rechtstext ist nicht Teil des l10n-Wegs) - deshalb hier auch die
 * umgebenden Bedienelemente hart auf Deutsch statt ueber $l->t(). Die Seite ist
 * oeffentlich und kennt keine Kontosprache des Besuchers: sie ist durchgehend
 * Sie-Form (fuer Mitglieder ohne Konto gilt immer Sie, Spec §1.4), auch die
 * Meldungen aus MandateActivationService::MESSAGE_* – die Du-Fassung aus
 * l10n/de.json spielt hier nie hinein (Issue #106).
 *
 * Wird per TemplateResponse::RENDER_AS_PUBLIC in die Public-Hülle von
 * Nextcloud eingebettet - deshalb hier bewusst KEIN eigenes
 * <html>/<head>/<body>-Gerüst, sondern nur der Inhalt (mit eigenem
 * Scroll-Container, siehe .vbh-consent-page).
 *
 * $_['status']: 'pending' | 'consumed' | 'invalid' | 'expired'
 * $_['reconfirm']: true = geänderten Text eines bestehenden Mandats erneut bestätigen
 * (Bankverbindung und Referenz bleiben), false = Mandat erteilen
 */

// Stile aus css/mandate-consent.css (#265: eigene Datei statt <style> hier);
// vor der Ausgabe angemeldet, die Public-Huelle setzt sie in den <head>.
\OCP\Util::addStyle(\OCA\Vereinsbuchhaltung\AppInfo\Application::APP_ID, 'mandate-consent');

$status = $_['status'];
$clubName = (string)($_['clubName'] ?? '');
// Die Texte, die sich zwischen Erteilen und erneutem Bestätigen unterscheiden, an einer Stelle.
$copy = !empty($_['reconfirm'])
	? [
		'consumed' => 'Sie haben den aktuellen Text Ihres SEPA-Lastschriftmandats bereits bestätigt',
		'intro' => 'Der Text Ihres SEPA-Lastschriftmandats wurde aktualisiert. Bitte lesen Sie den folgenden Text und bestätigen Sie am Ende der Seite, dass er für Ihr Mandat gilt. Ihre Mandatsreferenz und Ihre Bankverbindung bleiben unverändert, Sie müssen nichts eingeben.',
		'button' => 'Ich bestätige den aktualisierten Mandatstext',
		'hint' => 'Mit Klick auf „Ich bestätige“ gilt Ihr bestehendes SEPA-Lastschriftmandat mit dem oben stehenden Text weiter. An den Angaben oben ändert sich nichts.',
	]
	: [
		'consumed' => 'Sie haben diesem SEPA-Lastschriftmandat bereits zugestimmt',
		'intro' => 'Bitte lesen Sie den folgenden Mandatstext und bestätigen Sie am Ende der Seite Ihre Zustimmung.',
		'button' => 'Ich stimme zu und erteile das Mandat',
		'hint' => 'Mit Klick auf „Ich stimme zu“ ermächtigen Sie den oben genannten Zahlungsempfänger, fällige Beträge per SEPA-Lastschrift von Ihrem Konto einzuziehen. Das Mandat wird sofort aktiv.',
	];
?>
<div class="vbh-consent-page">
	<div class="vbh-consent-card">
		<h1>SEPA-Lastschriftmandat</h1>
		<?php if ($clubName !== '') { ?>
			<div class="vbh-club"><?php p($clubName); ?></div>
		<?php } ?>

		<?php if ($status === 'invalid' || $status === 'expired') { ?>
			<div class="vbh-status-box vbh-error">
				<strong><?php echo $status === 'expired' ? 'Link abgelaufen' : 'Link ungültig'; ?></strong>
				<p><?php p((string)($_['errorMessage'] ?? '')); ?></p>
				<?php if ($status === 'expired') { ?>
					<p>Bitte wenden Sie sich an den Verein, damit ein neuer Link verschickt wird.</p>
				<?php } ?>
			</div>

		<?php } elseif ($status === 'consumed') { ?>
			<div class="vbh-status-box vbh-success">
				<strong>Bereits bestätigt</strong>
				<p><?php p($copy['consumed']); ?><?php if (!empty($_['consentAt'])) {
					echo ' (am ' . htmlspecialchars(\OCA\Vereinsbuchhaltung\Service\GermanDate::format(\OCA\Vereinsbuchhaltung\Service\ClubClock::localDate((string)$_['consentAt'])), ENT_QUOTES) . ')';
				} ?>. Es ist aktiv, eine erneute Bestätigung ist nicht nötig.</p>
			</div>
			<div class="vbh-legal-text"><?php print_unescaped((string)($_['legalTextHtml'] ?? '')); ?></div>
			<?php print_unescaped((string)($_['dataBlockHtml'] ?? '')); ?>

		<?php } else { ?>
			<p><?php p($copy['intro']); ?></p>
			<div class="vbh-legal-text"><?php print_unescaped((string)($_['legalTextHtml'] ?? '')); ?></div>
			<?php print_unescaped((string)($_['dataBlockHtml'] ?? '')); ?>

			<form method="post" action="<?php p((string)$_['acceptUrl']); ?>" class="vbh-actions">
				<button type="submit" class="vbh-consent-submit"><?php p($copy['button']); ?></button>
				<p class="vbh-hint"><?php p($copy['hint']); ?></p>
			</form>
		<?php } ?>
	</div>
</div>
