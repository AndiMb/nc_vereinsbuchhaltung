<?php

declare(strict_types=1);

// Formatpruefung nach dem Nextcloud-Coding-Standard. Das Werkzeug liegt als
// eigenes composer-Projekt unter .php-cs-fixer/ (gleiches Muster wie .phpstan/,
// Begruendung siehe dort):
//
//   composer install --working-dir=.php-cs-fixer
//   .php-cs-fixer/vendor/bin/php-cs-fixer fix            # anwenden
//   .php-cs-fixer/vendor/bin/php-cs-fixer fix --dry-run  # nur pruefen (CI)
require_once __DIR__ . '/.php-cs-fixer/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->ignoreVCSIgnored(true)
	->notPath('.php-cs-fixer')
	->notPath('.phpstan')
	->notPath('l10n')
	->notPath('node_modules')
	->notPath('src')
	->notPath('vendor')
	->in(__DIR__);

return $config;
