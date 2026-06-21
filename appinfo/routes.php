<?php

declare(strict_types=1);

return [
	'routes' => [
		// Page
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Accounts (Kontenrahmen)
		['name' => 'account#index', 'url' => '/api/accounts', 'verb' => 'GET'],
		['name' => 'account#show', 'url' => '/api/accounts/{id}', 'verb' => 'GET'],
		['name' => 'account#create', 'url' => '/api/accounts', 'verb' => 'POST'],
		['name' => 'account#update', 'url' => '/api/accounts/{id}', 'verb' => 'PUT'],
		['name' => 'account#destroy', 'url' => '/api/accounts/{id}', 'verb' => 'DELETE'],
		['name' => 'account#seedDefaults', 'url' => '/api/accounts/seed', 'verb' => 'POST'],

		// Bank transactions
		['name' => 'transaction#index', 'url' => '/api/transactions', 'verb' => 'GET'],
		['name' => 'transaction#assign', 'url' => '/api/transactions/{id}/assign', 'verb' => 'POST'],
		['name' => 'transaction#unassign', 'url' => '/api/transactions/{id}/assign', 'verb' => 'DELETE'],

		// Import
		['name' => 'import#preview', 'url' => '/api/import/preview', 'verb' => 'POST'],
		['name' => 'import#commit', 'url' => '/api/import/commit', 'verb' => 'POST'],
		['name' => 'import#index', 'url' => '/api/imports', 'verb' => 'GET'],

		// Journal / reports
		['name' => 'journal#index', 'url' => '/api/journal', 'verb' => 'GET'],
		['name' => 'journal#balances', 'url' => '/api/journal/balances', 'verb' => 'GET'],

		// Rules
		['name' => 'rule#index', 'url' => '/api/rules', 'verb' => 'GET'],
		['name' => 'rule#create', 'url' => '/api/rules', 'verb' => 'POST'],
		['name' => 'rule#destroy', 'url' => '/api/rules/{id}', 'verb' => 'DELETE'],
	],
];
