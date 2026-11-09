<?php
declare(strict_types=1);
/**
 * Bestätigungsseite der Kontoverknüpfung (#295) - siehe MemberLinkController.
 * Ohne JavaScript: bestätigt wird über ein normales HTML-Formular (POST auf
 * dieselbe Route, mit CSRF-Token). Alle Texte kommen fertig übersetzt aus dem
 * Controller; hier wird nur ausgegeben.
 *
 * $_['status']: 'pending' | 'confirmed' | 'wrong_account' | 'invalid' | 'expired'
 */

// Dieselben Stile wie die Zustimmungsseite des Mandats.
\OCP\Util::addStyle(\OCA\Vereinsbuchhaltung\AppInfo\Application::APP_ID, 'mandate-consent');

$status = $_['status'];
$texts = $_['texts'];
$clubName = (string)($_['clubName'] ?? '');
?>
<div class="vbh-consent-page">
	<div class="vbh-consent-card">
		<h1><?php p((string)$_['title']); ?></h1>
		<?php if ($clubName !== '') { ?>
			<div class="vbh-club"><?php p($clubName); ?></div>
		<?php } ?>

		<?php if ($status === 'pending') { ?>
			<p><?php p($texts['intro']); ?></p>
			<p><strong><?php p($texts['question']); ?></strong></p>
			<form method="post" action="<?php p((string)$_['confirmUrl']); ?>" class="vbh-actions">
				<input type="hidden" name="requesttoken" value="<?php p((string)$_['requesttoken']); ?>">
				<button type="submit" class="vbh-consent-submit"><?php p($texts['button']); ?></button>
				<p class="vbh-hint"><?php p($texts['hint']); ?></p>
			</form>

		<?php } elseif ($status === 'confirmed') { ?>
			<div class="vbh-status-box vbh-success">
				<p><?php p($texts['intro']); ?></p>
			</div>
			<p><a href="<?php p((string)$_['appUrl']); ?>"><?php p((string)$_['appLabel']); ?></a></p>

		<?php } else { ?>
			<div class="vbh-status-box vbh-error">
				<p><?php p($texts['intro']); ?></p>
			</div>
		<?php } ?>
	</div>
</div>
