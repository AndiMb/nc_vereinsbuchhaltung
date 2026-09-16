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
		['name' => 'import#xbucPreview', 'url' => '/api/import/xbuc/preview', 'verb' => 'POST'],
		['name' => 'import#xbucCommit', 'url' => '/api/import/xbuc', 'verb' => 'POST'],
		['name' => 'import#reset', 'url' => '/api/reset', 'verb' => 'POST'],

		// Journal / reports
		['name' => 'journal#index', 'url' => '/api/journal', 'verb' => 'GET'],
		['name' => 'journal#balances', 'url' => '/api/journal/balances', 'verb' => 'GET'],
		['name' => 'journal#create', 'url' => '/api/journal', 'verb' => 'POST'],
		['name' => 'journal#update', 'url' => '/api/journal/{id}', 'verb' => 'PUT'],
		['name' => 'journal#reassign', 'url' => '/api/journal/{id}/reassign', 'verb' => 'POST'],
		['name' => 'journal#destroy', 'url' => '/api/journal/{id}', 'verb' => 'DELETE'],

		// Kollaboration: Änderungsstand für das Polling anderer Browser
		['name' => 'sync#revision', 'url' => '/api/revision', 'verb' => 'GET'],

		// Geschäftsjahre: Liste, Regel, Grenzen, Festschreibung.
		// Die Liste ersetzt die frühere Jahresliste unter /api/journal/years –
		// ein Geschäftsjahr ist seit Issue #8 ein eigener Datensatz und keine
		// aus den Buchungen abgeleitete Zahl mehr.
		['name' => 'period#index', 'url' => '/api/periods', 'verb' => 'GET'],
		['name' => 'period#rule', 'url' => '/api/periods/rule', 'verb' => 'GET'],
		['name' => 'period#saveRule', 'url' => '/api/periods/rule', 'verb' => 'PUT'],
		['name' => 'period#create', 'url' => '/api/periods', 'verb' => 'POST'],
		['name' => 'period#update', 'url' => '/api/periods/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'period#destroy', 'url' => '/api/periods/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'period#close',  'url' => '/api/periods/{id}/close', 'verb' => 'POST'],
		['name' => 'period#reopen', 'url' => '/api/periods/{id}/close', 'verb' => 'DELETE'],

		// Änderungsprotokoll
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

		// Mitglieder-Stammdaten (Spec §2.2, docs/beitraege-sepa-modul-spec.md)
		['name' => 'member#index', 'url' => '/api/members', 'verb' => 'GET'],
		['name' => 'member#show', 'url' => '/api/members/{id}', 'verb' => 'GET'],
		['name' => 'member#create', 'url' => '/api/members', 'verb' => 'POST'],
		['name' => 'member#update', 'url' => '/api/members/{id}', 'verb' => 'PUT'],
		['name' => 'member#destroy', 'url' => '/api/members/{id}', 'verb' => 'DELETE'],
		['name' => 'member#leave', 'url' => '/api/members/{id}/leave', 'verb' => 'POST'],
		['name' => 'member#reactivate', 'url' => '/api/members/{id}/reactivate', 'verb' => 'POST'],
		['name' => 'member#linkSuggestions', 'url' => '/api/members/{id}/link-suggestions', 'verb' => 'GET'],
		['name' => 'member#link', 'url' => '/api/members/{id}/link', 'verb' => 'POST'],
		['name' => 'member#unlink', 'url' => '/api/members/{id}/unlink', 'verb' => 'POST'],

		// Aufgaben/Störfälle (Spec §7, Grundlage siehe lib/Db/Task.php)
		['name' => 'task#index', 'url' => '/api/tasks', 'verb' => 'GET'],

		// SEPA-Lastschriftmandate (optionales Zusatzmodul)
		['name' => 'sepaMandate#index', 'url' => '/api/sepa/mandates', 'verb' => 'GET'],
		['name' => 'sepaMandate#create', 'url' => '/api/sepa/mandates', 'verb' => 'POST'],
		['name' => 'sepaMandate#update', 'url' => '/api/sepa/mandates/{id}', 'verb' => 'PUT'],
		['name' => 'sepaMandate#revoke', 'url' => '/api/sepa/mandates/{id}/revoke', 'verb' => 'POST'],
		['name' => 'sepaMandate#changeBankAccount', 'url' => '/api/sepa/mandates/{id}/change-account', 'verb' => 'POST'],
		['name' => 'sepaMandate#destroy', 'url' => '/api/sepa/mandates/{id}', 'verb' => 'DELETE'],

		// Mitgliedsbeiträge mit Zahlungsfrequenz (optionales Zusatzmodul)
		['name' => 'membershipFee#index', 'url' => '/api/sepa/fees', 'verb' => 'GET'],
		['name' => 'membershipFee#create', 'url' => '/api/sepa/fees', 'verb' => 'POST'],
		['name' => 'membershipFee#update', 'url' => '/api/sepa/fees/{id}', 'verb' => 'PUT'],
		['name' => 'membershipFee#destroy', 'url' => '/api/sepa/fees/{id}', 'verb' => 'DELETE'],
		['name' => 'membershipFee#catchUp', 'url' => '/api/sepa/fees/{id}/catch-up', 'verb' => 'POST'],

		// Massenanlage von Mitgliedern aus einer CSV-Liste
		['name' => 'memberImport#preview', 'url' => '/api/sepa/members/import/preview', 'verb' => 'POST'],
		['name' => 'memberImport#import', 'url' => '/api/sepa/members/import', 'verb' => 'POST'],

		// Mandats-Lifecycle (Issue #66, Papier-Weg) – neues, additives Modell
		// parallel zum alten sepaMandate#-Bestand (siehe MandateService).
		['name' => 'mandate#index', 'url' => '/api/mandates', 'verb' => 'GET'],
		['name' => 'mandate#create', 'url' => '/api/mandates', 'verb' => 'POST'],
		['name' => 'mandate#byMember', 'url' => '/api/mandates/by-member/{memberId}', 'verb' => 'GET'],
		['name' => 'mandate#show', 'url' => '/api/mandates/{id}', 'verb' => 'GET'],
		['name' => 'mandate#activate', 'url' => '/api/mandates/{id}/activate', 'verb' => 'POST'],
		['name' => 'mandate#suspend', 'url' => '/api/mandates/{id}/suspend', 'verb' => 'POST'],
		['name' => 'mandate#resume', 'url' => '/api/mandates/{id}/resume', 'verb' => 'POST'],
		['name' => 'mandate#revoke', 'url' => '/api/mandates/{id}/revoke', 'verb' => 'POST'],
		['name' => 'mandate#correctAccountHolderName', 'url' => '/api/mandates/{id}/correct-name', 'verb' => 'POST'],
		['name' => 'mandate#amendBankDetails', 'url' => '/api/mandates/{id}/amend-bank-details', 'verb' => 'POST'],
		['name' => 'mandate#replace', 'url' => '/api/mandates/{id}/replace', 'verb' => 'POST'],
		['name' => 'mandate#reopenAmendment', 'url' => '/api/mandates/amendments/{amendmentId}/reopen', 'verb' => 'POST'],
		['name' => 'mandate#uploadDocument', 'url' => '/api/mandates/{id}/document', 'verb' => 'POST'],
		['name' => 'mandate#downloadDocument', 'url' => '/api/mandates/{id}/document', 'verb' => 'GET'],

		// SEPA-Sammeleinzüge (pain.008-Export)
		['name' => 'sepaBatch#preview', 'url' => '/api/sepa/export/preview', 'verb' => 'GET'],
		['name' => 'sepaBatch#index', 'url' => '/api/sepa/export/batches', 'verb' => 'GET'],
		['name' => 'sepaBatch#create', 'url' => '/api/sepa/export/batches', 'verb' => 'POST'],
		['name' => 'sepaBatch#destroy', 'url' => '/api/sepa/export/batches/{id}', 'verb' => 'DELETE'],
		['name' => 'sepaBatch#settle', 'url' => '/api/sepa/export/batches/{id}/settle', 'verb' => 'POST'],
		['name' => 'sepaBatch#items', 'url' => '/api/sepa/export/batches/{id}/items', 'verb' => 'GET'],
		['name' => 'sepaBatch#xml', 'url' => '/api/sepa/export/batches/{id}/xml', 'verb' => 'GET'],
		['name' => 'sepaBatch#revertReturn', 'url' => '/api/sepa/export/items/{itemId}/revert-return', 'verb' => 'POST'],

		// Offene Posten
		['name' => 'openItem#index', 'url' => '/api/open-items', 'verb' => 'GET'],
		['name' => 'openItem#create', 'url' => '/api/open-items', 'verb' => 'POST'],
		['name' => 'openItem#markPaid', 'url' => '/api/open-items/{id}/pay', 'verb' => 'POST'],
		['name' => 'openItem#cancel', 'url' => '/api/open-items/{id}/cancel', 'verb' => 'POST'],
		['name' => 'openItem#reopen', 'url' => '/api/open-items/{id}/reopen', 'verb' => 'POST'],
		['name' => 'openItem#destroy', 'url' => '/api/open-items/{id}', 'verb' => 'DELETE'],

		// Beitragsgruppen & Zuweisungen (Issue #68)
		['name' => 'contributionGroup#index', 'url' => '/api/contribution-groups', 'verb' => 'GET'],
		['name' => 'contributionGroup#create', 'url' => '/api/contribution-groups', 'verb' => 'POST'],
		['name' => 'contributionGroup#update', 'url' => '/api/contribution-groups/{id}', 'verb' => 'PUT'],
		['name' => 'contributionGroup#destroy', 'url' => '/api/contribution-groups/{id}', 'verb' => 'DELETE'],
		['name' => 'contributionGroup#minAmountPreview', 'url' => '/api/contribution-groups/{id}/min-amount-preview', 'verb' => 'GET'],
		['name' => 'contributionGroup#applyMinAmountIncrease', 'url' => '/api/contribution-groups/{id}/min-amount-increase', 'verb' => 'POST'],

		['name' => 'assignment#index', 'url' => '/api/assignments', 'verb' => 'GET'],
		['name' => 'assignment#create', 'url' => '/api/assignments', 'verb' => 'POST'],
		['name' => 'assignment#previewNew', 'url' => '/api/assignments/preview', 'verb' => 'POST'],
		['name' => 'assignment#update', 'url' => '/api/assignments/{id}', 'verb' => 'PUT'],
		['name' => 'assignment#setMinAmountOverride', 'url' => '/api/assignments/{id}/min-amount-override', 'verb' => 'POST'],
		['name' => 'assignment#end', 'url' => '/api/assignments/{id}/end', 'verb' => 'POST'],
		['name' => 'assignment#events', 'url' => '/api/assignments/{id}/events', 'verb' => 'GET'],

		// Forderungen inkl. manueller Einzelforderung (Issue #68)
		['name' => 'claim#index', 'url' => '/api/claims', 'verb' => 'GET'],
		['name' => 'claim#create', 'url' => '/api/claims', 'verb' => 'POST'],
		['name' => 'claim#settle', 'url' => '/api/claims/{id}/settle', 'verb' => 'POST'],
		['name' => 'claim#cancel', 'url' => '/api/claims/{id}/cancel', 'verb' => 'POST'],
		['name' => 'claim#defer', 'url' => '/api/claims/{id}/defer', 'verb' => 'POST'],

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
		['name' => 'settings#folders', 'url' => '/api/settings/folders', 'verb' => 'GET'],

		// Corporate Design (Vereins-Logo für den Kurzbericht)
		['name' => 'branding#view', 'url' => '/api/settings/logo', 'verb' => 'GET'],
		['name' => 'branding#upload', 'url' => '/api/settings/logo', 'verb' => 'POST'],
		['name' => 'branding#destroy', 'url' => '/api/settings/logo', 'verb' => 'DELETE'],

		// Hilfe (Handbuch als lesbare Seite, druckfertige Kassenprüfer-Kurzanleitung)
		['name' => 'help#handbuch', 'url' => '/api/help/handbuch', 'verb' => 'GET'],
		['name' => 'help#pruefleitfaden', 'url' => '/api/help/pruefleitfaden', 'verb' => 'GET'],

		// Übersetzungen der Oberfläche. Nextclouds .htaccess liefert keine
		// .json-Dateien aus dem App-Verzeichnis aus, deshalb dieser Umweg –
		// siehe L10nController.
		['name' => 'l10n#bundle', 'url' => '/api/l10n/{lang}', 'verb' => 'GET'],

		// Was ist neu (Splash-Screen nach Updates)
		['name' => 'whatsNew#index', 'url' => '/api/whatsnew', 'verb' => 'GET'],
		['name' => 'whatsNew#markSeen', 'url' => '/api/whatsnew/seen', 'verb' => 'POST'],

		// Beispieldaten (Onboarding: risikolos ausprobieren)
		['name' => 'demo#seed', 'url' => '/api/demo/seed', 'verb' => 'POST'],

		// Belegablage
		['name' => 'attachment#counts',   'url' => '/api/attachments/counts',              'verb' => 'GET'],
		// Wächter-Ordner: Dateien ohne Buchung, Belege ohne Datei, Verknüpfen
		['name' => 'attachment#inbox',        'url' => '/api/attachments/inbox',         'verb' => 'GET'],
		['name' => 'attachment#inboxSummary', 'url' => '/api/attachments/inbox/summary', 'verb' => 'GET'],
		['name' => 'attachment#inboxView',    'url' => '/api/attachments/inbox/{fileId}/view', 'verb' => 'GET'],
		['name' => 'attachment#link',         'url' => '/api/journal/{journalId}/attachments/link', 'verb' => 'POST'],
		['name' => 'attachment#index',    'url' => '/api/journal/{journalId}/attachments', 'verb' => 'GET'],
		['name' => 'attachment#create',   'url' => '/api/journal/{journalId}/attachments', 'verb' => 'POST'],
		['name' => 'attachment#view',     'url' => '/api/attachments/{id}/view',          'verb' => 'GET'],
		['name' => 'attachment#download', 'url' => '/api/attachments/{id}/download',       'verb' => 'GET'],
		['name' => 'attachment#destroy',  'url' => '/api/attachments/{id}',                'verb' => 'DELETE'],

		// Deep-Linking: vue-router (History-Mode, siehe src/router.js) haelt
		// den kompletten Navigationszustand in der URL - Reload oder ein
		// geteilter Link muss also serverseitig dieselbe SPA-Huelle liefern
		// wie die Startseite. Am Ende der Liste, damit die /api/*-Routen
		// oben weiter eindeutig Vorrang haben.
		['name' => 'page#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.*']],
	],
];
