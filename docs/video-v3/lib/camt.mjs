// camt.053-Auszug mit der Sammelgutschrift eines eigenen Einzugs - eine
// Gutschrift ueber die Summe, eine Detailzeile je Posten. Die CSV-Form kennt
// keine Detailzeilen; nachgebaut nach tests/e2e/fixtures/nextcloud.mjs
// (camtStatement, collectionEntry), ohne dessen Playwright-Abhaengigkeit.

const xmlText = (text) => String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const euros = (cents) => (Math.abs(cents) / 100).toFixed(2);

/**
 * @param {{iban:string, bookingDate:string, batchReference:string, bookingText:string,
 *          items:Array<{endToEndId:string, mandateReference:string, amountCents:number, remittanceInfo?:string, memberDisplayName?:string}>}} opts
 */
export function collectionStatement({ iban, bookingDate, batchReference, bookingText, items }) {
	const total = items.reduce((sum, item) => sum + item.amountCents, 0);
	const details = items.map((item) => '<TxDtls>'
		+ `<Refs><EndToEndId>${xmlText(item.endToEndId)}</EndToEndId><MndtId>${xmlText(item.mandateReference)}</MndtId></Refs>`
		+ `<Amt Ccy="EUR">${euros(item.amountCents)}</Amt><CdtDbtInd>CRDT</CdtDbtInd>`
		+ `<RmtInf><Ustrd>${xmlText(`${item.remittanceInfo ?? ''} ${item.memberDisplayName ?? ''}`.trim())}</Ustrd></RmtInf>`
		+ '</TxDtls>').join('');
	const id = `${bookingDate.replace(/-/g, '')}-${batchReference}`;
	return `<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
	<BkToCstmrStmt>
		<GrpHdr><MsgId>VBH-${xmlText(id)}</MsgId><CreDtTm>${bookingDate}T12:00:00</CreDtTm></GrpHdr>
		<Stmt>
			<Id>AUSZUG-${xmlText(id)}</Id>
			<Acct><Id><IBAN>${iban}</IBAN></Id><Ccy>EUR</Ccy></Acct>
			<Ntry>
				<Amt Ccy="EUR">${euros(total)}</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts>
				<BookgDt><Dt>${bookingDate}</Dt></BookgDt><ValDt><Dt>${bookingDate}</Dt></ValDt>
				<NtryDtls><Btch><PmtInfId>${xmlText(batchReference)}</PmtInfId></Btch>${details}</NtryDtls>
				<AddtlNtryInf>${xmlText(bookingText)}</AddtlNtryInf>
			</Ntry>
		</Stmt>
	</BkToCstmrStmt>
</Document>`;
}
