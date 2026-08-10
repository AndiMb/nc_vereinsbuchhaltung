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
		['name' => 'account#setOpening', 'url' => '/api/accounts/{id}/opening', 'verb' => 'POST'],
		['name' => 'account#bulkSphere', 'url' => '/api/accounts/sphere-bulk', 'verb' => 'POST'],
		['name' => 'journal#byAccount', 'url' => '/api/accounts/{id}/journal', 'verb' => 'GET'],

		// Bank transactions
		['name' => 'transaction#index', 'url' => '/api/transactions', 'verb' => 'GET'],
		['name' => 'transaction#assign', 'url' => '/api/transactions/{id}/assign', 'verb' => 'POST'],
		['name' => 'transaction#unassign', 'url' => '/api/transactions/{id}/assign', 'verb' => 'DELETE'],
		['name' => 'transaction#destroy', 'url' => '/api/transactions/{id}', 'verb' => 'DELETE'],

		// Import
		['name' => 'import#preview', 'url' => '/api/import/preview', 'verb' => 'POST'],
		['name' => 'import#commit', 'url' => '/api/import/commit', 'verb' => 'POST'],
		['name' => 'import#index', 'url' => '/api/imports', 'verb' => 'GET'],
		['name' => 'import#xbucPreview', 'url' => '/api/import/xbuc/preview', 'verb' => 'POST'],
		['name' => 'import#xbucCommit', 'url' => '/api/import/xbuc', 'verb' => 'POST'],
		['name' => 'import#reset', 'url' => '/api/reset', 'verb' => 'POST'],

		// Journal / reports
		['name' => 'journal#index', 'url' => '/api/journal', 'verb' => 'GET'],
		['name' => 'journal#years', 'url' => '/api/journal/years', 'verb' => 'GET'],
		['name' => 'journal#balances', 'url' => '/api/journal/balances', 'verb' => 'GET'],
		['name' => 'journal#create', 'url' => '/api/journal', 'verb' => 'POST'],
		['name' => 'journal#update', 'url' => '/api/journal/{id}', 'verb' => 'PUT'],
		['name' => 'journal#reassign', 'url' => '/api/journal/{id}/reassign', 'verb' => 'POST'],
		['name' => 'journal#destroy', 'url' => '/api/journal/{id}', 'verb' => 'DELETE'],

		// Kollaboration: Änderungsstand für das Polling anderer Browser
		['name' => 'sync#revision', 'url' => '/api/revision', 'verb' => 'GET'],

		// Jahresabschluss (Festschreibung) + Änderungsprotokoll
		['name' => 'year#closed', 'url' => '/api/years/closed', 'verb' => 'GET'],
		['name' => 'year#close',  'url' => '/api/years/{year}/close', 'verb' => 'POST'],
		['name' => 'year#reopen', 'url' => '/api/years/{year}/close', 'verb' => 'DELETE'],
		['name' => 'audit#index', 'url' => '/api/audit', 'verb' => 'GET'],

		// Berichte / Kostenstellen / Sphären
		['name' => 'report#costCenters', 'url' => '/api/report/costcenters', 'verb' => 'GET'],
		['name' => 'report#rename', 'url' => '/api/report/costcenters', 'verb' => 'PUT'],
		['name' => 'report#spheres', 'url' => '/api/report/spheres', 'verb' => 'GET'],

		// Kostenstellen pflegen (frei definierbar, Modus 'manual')
		['name' => 'costCenter#index', 'url' => '/api/costcenters', 'verb' => 'GET'],
		['name' => 'costCenter#create', 'url' => '/api/costcenters', 'verb' => 'POST'],
		['name' => 'costCenter#assign', 'url' => '/api/costcenters/assign', 'verb' => 'POST'],
		['name' => 'costCenter#update', 'url' => '/api/costcenters/{id}', 'verb' => 'PUT'],
		['name' => 'costCenter#destroy', 'url' => '/api/costcenters/{id}', 'verb' => 'DELETE'],
		['name' => 'report#multiyearTrend', 'url' => '/api/report/multiyear-trend', 'verb' => 'GET'],
		['name' => 'report#reserves', 'url' => '/api/report/reserves', 'verb' => 'GET'],

		// Finanzplan / Budget
		['name' => 'budget#index', 'url' => '/api/budget', 'verb' => 'GET'],
		['name' => 'budget#set', 'url' => '/api/budget', 'verb' => 'POST'],
		['name' => 'budget#snapshots', 'url' => '/api/budget/snapshots', 'verb' => 'GET'],
		['name' => 'budget#createSnapshot', 'url' => '/api/budget/snapshots', 'verb' => 'POST'],
		['name' => 'budget#snapshot', 'url' => '/api/budget/snapshots/{id}', 'verb' => 'GET'],
		['name' => 'budget#deleteSnapshot', 'url' => '/api/budget/snapshots/{id}', 'verb' => 'DELETE'],

		// Berechtigungen
		['name' => 'permission#me', 'url' => '/api/permissions/me', 'verb' => 'GET'],
		['name' => 'permission#index', 'url' => '/api/permissions', 'verb' => 'GET'],
		['name' => 'permission#groups', 'url' => '/api/permissions/groups', 'verb' => 'GET'],
		['name' => 'permission#users', 'url' => '/api/permissions/users', 'verb' => 'GET'],
		['name' => 'permission#setRole', 'url' => '/api/permissions', 'verb' => 'POST'],
		['name' => 'permission#destroy', 'url' => '/api/permissions/{id}', 'verb' => 'DELETE'],

		// Rules
		['name' => 'rule#index', 'url' => '/api/rules', 'verb' => 'GET'],
		['name' => 'rule#create', 'url' => '/api/rules', 'verb' => 'POST'],
		['name' => 'rule#update', 'url' => '/api/rules/{id}', 'verb' => 'PUT'],
		['name' => 'rule#destroy', 'url' => '/api/rules/{id}', 'verb' => 'DELETE'],

		// SEPA-Lastschriftmandate (optionales Zusatzmodul)
		['name' => 'sepaMandate#index', 'url' => '/api/sepa/mandates', 'verb' => 'GET'],
		['name' => 'sepaMandate#create', 'url' => '/api/sepa/mandates', 'verb' => 'POST'],
		['name' => 'sepaMandate#update', 'url' => '/api/sepa/mandates/{id}', 'verb' => 'PUT'],
		['name' => 'sepaMandate#revoke', 'url' => '/api/sepa/mandates/{id}/revoke', 'verb' => 'POST'],
		['name' => 'sepaMandate#destroy', 'url' => '/api/sepa/mandates/{id}', 'verb' => 'DELETE'],

		// Mitgliedsbeiträge mit Zahlungsfrequenz (optionales Zusatzmodul)
		['name' => 'membershipFee#index', 'url' => '/api/sepa/fees', 'verb' => 'GET'],
		['name' => 'membershipFee#create', 'url' => '/api/sepa/fees', 'verb' => 'POST'],
		['name' => 'membershipFee#update', 'url' => '/api/sepa/fees/{id}', 'verb' => 'PUT'],
		['name' => 'membershipFee#destroy', 'url' => '/api/sepa/fees/{id}', 'verb' => 'DELETE'],

		// Offene Posten
		['name' => 'openItem#index', 'url' => '/api/open-items', 'verb' => 'GET'],
		['name' => 'openItem#create', 'url' => '/api/open-items', 'verb' => 'POST'],
		['name' => 'openItem#markPaid', 'url' => '/api/open-items/{id}/pay', 'verb' => 'POST'],
		['name' => 'openItem#cancel', 'url' => '/api/open-items/{id}/cancel', 'verb' => 'POST'],
		['name' => 'openItem#reopen', 'url' => '/api/open-items/{id}/reopen', 'verb' => 'POST'],
		['name' => 'openItem#destroy', 'url' => '/api/open-items/{id}', 'verb' => 'DELETE'],

		// Export (CSV-Download)
		['name' => 'export#journal',  'url' => '/api/export/journal',  'verb' => 'GET'],
		['name' => 'export#balances', 'url' => '/api/export/balances', 'verb' => 'GET'],
		['name' => 'export#report',   'url' => '/api/export/report',   'verb' => 'GET'],
		['name' => 'export#budget',   'url' => '/api/export/budget',   'verb' => 'GET'],
		['name' => 'export#multiyear', 'url' => '/api/export/multiyear', 'verb' => 'GET'],
		['name' => 'export#kassenbericht', 'url' => '/api/export/kassenbericht', 'verb' => 'GET'],
		['name' => 'export#kurzbericht', 'url' => '/api/export/kurzbericht', 'verb' => 'GET'],
		['name' => 'export#attachments', 'url' => '/api/export/attachments', 'verb' => 'GET'],

		// Einstellungen
		['name' => 'settings#index',  'url' => '/api/settings', 'verb' => 'GET'],
		['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'POST'],

		// Corporate Design (Vereins-Logo für den Kurzbericht)
		['name' => 'branding#view', 'url' => '/api/settings/logo', 'verb' => 'GET'],
		['name' => 'branding#upload', 'url' => '/api/settings/logo', 'verb' => 'POST'],
		['name' => 'branding#destroy', 'url' => '/api/settings/logo', 'verb' => 'DELETE'],

		// Hilfe (Handbuch als lesbare Seite, druckfertige Kassenprüfer-Kurzanleitung)
		['name' => 'help#handbuch', 'url' => '/api/help/handbuch', 'verb' => 'GET'],
		['name' => 'help#pruefleitfaden', 'url' => '/api/help/pruefleitfaden', 'verb' => 'GET'],

		// Beispieldaten (Onboarding: risikolos ausprobieren)
		['name' => 'demo#seed', 'url' => '/api/demo/seed', 'verb' => 'POST'],

		// Belegablage
		['name' => 'attachment#counts',   'url' => '/api/attachments/counts',              'verb' => 'GET'],
		['name' => 'attachment#index',    'url' => '/api/journal/{journalId}/attachments', 'verb' => 'GET'],
		['name' => 'attachment#create',   'url' => '/api/journal/{journalId}/attachments', 'verb' => 'POST'],
		['name' => 'attachment#view',     'url' => '/api/attachments/{id}/view',          'verb' => 'GET'],
		['name' => 'attachment#download', 'url' => '/api/attachments/{id}/download',       'verb' => 'GET'],
		['name' => 'attachment#destroy',  'url' => '/api/attachments/{id}',                'verb' => 'DELETE'],
	],
];
