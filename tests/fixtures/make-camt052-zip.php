<?php

declare(strict_types=1);

// Erzeugt beispiel-camt052.zip (Issue #359): derselbe Kontoauszug wie
// beispiel-camt053.xml, aber als CAMT.052-Report (<Rpt> statt <Stmt>) und
// zusammen mit einem leeren Tagesreport in ein ZIP gepackt – so liefern
// manche Institute ihre Umsätze aus.
//
// Aufruf: php -d extension=zip tests/fixtures/make-camt052-zip.php

$dir = __DIR__;
$camt053 = (string)file_get_contents($dir . '/beispiel-camt053.xml');
$report = strtr($camt053, [
	'camt.053.001.02' => 'camt.052.001.02',
	'BkToCstmrStmt' => 'BkToCstmrAcctRpt',
	'<Stmt>' => '<Rpt>',
	'</Stmt>' => '</Rpt>',
	'Derselbe Kontoauszug wie beispiel-camt.csv, nur im ISO-20022-Format.' => 'Derselbe Kontoauszug wie beispiel-camt.csv, als CAMT.052-Report.',
]);
$empty = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
	. '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.02"><BkToCstmrAcctRpt>'
	. '<GrpHdr><MsgId>TESTMSG-LEER</MsgId><CreDtTm>2026-02-01T08:00:00</CreDtTm></GrpHdr>'
	. '<Rpt><Id>REPORT-OHNE-UMSATZ</Id><Acct><Id><IBAN>DE12500105170648489890</IBAN></Id></Acct></Rpt>'
	. '</BkToCstmrAcctRpt></Document>' . "\n";

$target = $dir . '/beispiel-camt052.zip';
@unlink($target);
$zip = new ZipArchive();
$zip->open($target, ZipArchive::CREATE);
$zip->addFromString('20260131_camt052.xml', $report);
$zip->addFromString('20260201_camt052.xml', $empty);
$zip->close();
echo "geschrieben: $target\n";
