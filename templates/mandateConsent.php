<?php
declare(strict_types=1);
/**
 * Oeffentliche Zustimmungsseite der elektronischen Mandatserteilung (Issue
 * #67) - siehe MandateConsentController. Bewusst ohne JavaScript: die
 * Zustimmung ist ein normales HTML-Formular (POST auf dieselbe Route), damit
 * die Seite auch ohne Client-Skripte funktioniert. Nur Deutsch (Spec: der
 * Mandats-Rechtstext ist nicht Teil des l10n-Wegs) - deshalb hier auch die
 * umgebenden Bedienelemente hart auf Deutsch statt ueber $l->t().
 *
 * Wird per TemplateResponse::RENDER_AS_PUBLIC in die Public-Hülle von
 * Nextcloud eingebettet - deshalb hier bewusst KEIN eigenes
 * <html>/<head>/<body>-Gerüst, sondern nur der Inhalt (mit eigenem
 * Scroll-Container, siehe .vbh-consent-page).
 *
 * $_['status']: 'pending' | 'consumed' | 'invalid' | 'expired'
 */

$status = $_['status'];
$clubName = (string)($_['clubName'] ?? '');
?>
<style>
	.vbh-consent-page {
		/* Die Public-Hülle von Nextcloud (#content) ist fest positioniert und
		   schneidet Überlauf ab - ohne eigenen Scroll-Container ist bei einem
		   niedrigen Fenster (Handy, Querformat) der Zustimmungsknopf am Ende
		   der Seite nicht erreichbar. */
		flex: 1 1 auto;
		min-width: 0;
		overflow-y: auto;
		box-sizing: border-box;
		font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
		color: #222;
		background: #f5f5f5;
		padding: 24px 16px;
	}
	.vbh-consent-page *, .vbh-consent-page *::before, .vbh-consent-page *::after { box-sizing: border-box; }
	.vbh-consent-card {
		max-width: 640px;
		margin: 0 auto;
		background: #fff;
		border-radius: 8px;
		padding: 24px;
		box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
	}
	.vbh-consent-card h1 { font-size: 1.3rem; margin: 0 0 4px; }
	.vbh-club { color: #555; font-size: 0.9rem; margin-bottom: 20px; }
	.vbh-legal-text p { line-height: 1.5; }
	dl.vbh-mandate-data { display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; margin: 20px 0; font-size: 0.95rem; }
	dl.vbh-mandate-data dt { font-weight: 600; color: #444; }
	dl.vbh-mandate-data dd { margin: 0; word-break: break-word; }
	.vbh-actions { margin-top: 24px; }
	button.vbh-consent-submit {
		background: #2d7d46;
		color: #fff;
		border: none;
		border-radius: 4px;
		padding: 12px 20px;
		font-size: 1rem;
		cursor: pointer;
	}
	button.vbh-consent-submit:hover { background: #256238; }
	.vbh-hint { color: #555; font-size: 0.85rem; margin-top: 12px; }
	.vbh-status-box {
		border-radius: 6px;
		padding: 16px;
		margin-bottom: 16px;
	}
	.vbh-status-box.vbh-success { background: #eaf6ec; border: 1px solid #bfe3c8; }
	.vbh-status-box.vbh-error { background: #fdeaea; border: 1px solid #f3c2c2; }
	@media (prefers-color-scheme: dark) {
		.vbh-consent-page { background: #1b1b1b; color: #ddd; }
		.vbh-consent-card { background: #262626; box-shadow: none; }
		.vbh-club, .vbh-hint { color: #aaa; }
		dl.vbh-mandate-data dt { color: #ccc; }
		.vbh-status-box.vbh-success { background: #1f3324; border-color: #2d5136; }
		.vbh-status-box.vbh-error { background: #3a2323; border-color: #6b3a3a; }
	}
</style>
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
				<p>Sie haben diesem SEPA-Lastschriftmandat bereits zugestimmt<?php if (!empty($_['consentAt'])) {
					echo ' (am ' . htmlspecialchars(substr((string)$_['consentAt'], 0, 10), ENT_QUOTES) . ')';
				} ?>. Es ist aktiv, eine erneute Bestätigung ist nicht nötig.</p>
			</div>
			<div class="vbh-legal-text"><?php print_unescaped((string)($_['legalTextHtml'] ?? '')); ?></div>
			<?php print_unescaped((string)($_['dataBlockHtml'] ?? '')); ?>

		<?php } else { ?>
			<p>Bitte lesen Sie den folgenden Mandatstext und bestätigen Sie am Ende der Seite Ihre Zustimmung.</p>
			<div class="vbh-legal-text"><?php print_unescaped((string)($_['legalTextHtml'] ?? '')); ?></div>
			<?php print_unescaped((string)($_['dataBlockHtml'] ?? '')); ?>

			<form method="post" action="<?php p((string)$_['acceptUrl']); ?>" class="vbh-actions">
				<button type="submit" class="vbh-consent-submit">Ich stimme zu und erteile das Mandat</button>
				<p class="vbh-hint">Mit Klick auf „Ich stimme zu“ ermächtigen Sie den oben genannten Zahlungsempfänger, fällige Beträge per SEPA-Lastschrift von Ihrem Konto einzuziehen. Das Mandat wird sofort aktiv.</p>
			</form>
		<?php } ?>
	</div>
</div>
