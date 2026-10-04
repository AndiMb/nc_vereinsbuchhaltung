import api from '../api.js'
import { useDebitRuns } from './useDebitRuns.js'

/**
 * Die Schreibaktionen des Einzug-Unterreiters (Issue #103, Spec §3.5):
 * Freigeben & Datei erzeugen, als eingereicht markieren, verwerfen,
 * Termin nach hinten verschieben. Lesezustand und Nachladen kommen aus
 * useDebitRuns() (#102); jede Aktion lädt danach den ganzen Unterreiter neu
 * und stellt die Auswahl so, dass das Ergebnis sichtbar ist:
 *
 * - Freigabe: der neue Lauf ist aufgeklappt (dort stehen Download und
 *   Einreichung als nächste Schritte). Die Geisterkarte des Termins
 *   verschwindet, wenn nichts mehr freizugeben ist – deshalb darf keine
 *   Bestätigung an der Karte hängen bleiben, die sie überlebt.
 * - Verwerfen: gewählt ist der Termin, an dem die Forderungen jetzt wieder
 *   frei sind, und dessen Geisterkarte zeigt sie („Neu freigeben“); der
 *   verworfene Lauf bleibt als Historie aufgeklappt. Das ist NICHT immer der
 *   Termin des Laufs: ein nach hinten verschobener Lauf trägt ein anderes Datum
 *   als die Forderungen, die er bündelte (sie behalten ihren Fälligkeitstag).
 * - Verschieben: der neue Termin ist gewählt, der Lauf bleibt aufgeklappt.
 *
 * Die Aktionen werfen den Fehler des Servers weiter; Fehlermeldung und
 * Erfolgsmeldung gehören der aufrufenden Komponente (sie weiß, ob ein Dialog
 * offen ist, in dem der Fehler stehen soll). Bei einem Fehler wird trotzdem
 * neu geladen: meist hat eine andere Person den Lauf zwischenzeitlich
 * weitergeschaltet (400 „nur ein freigegebener Lauf …“), und die Oberfläche
 * soll dann den wirklichen Stand zeigen.
 *
 * Kein eigener Zustand: das Modul-Singleton aus useDebitRuns() ist die
 * einzige Quelle, hier steht nur die Reihenfolge der Schritte.
 */
export function useDebitRunActions() {
	const { state, reload, loadPreview } = useDebitRuns()

	/**
	 * Führt die Aktion aus und lädt danach neu. `focus(result)` liefert, was
	 * vor dem Nachladen zu wählen ist (`selectDueDate`, `expandRunId`). Ein
	 * Termin, den das angezeigte Beitragsjahr nicht kennt, verwirft
	 * `ensureSelection()` beim Laden von selbst – im fremden Jahr bleibt die
	 * Auswahl dann, wie sie war. `onDone(result)` läuft nach dem erfolgreichen
	 * Schreiben und VOR dem Nachladen: wessen Oberfläche das Nachladen
	 * wegräumt (die Geisterkarte nach der Freigabe, die Aktionen eines nun
	 * verworfenen Laufs), schließt dort ihren Dialog und meldet den Erfolg.
	 */
	async function settle(action, focus = () => ({}), onDone = null) {
		let result
		try {
			result = await action()
		} catch (e) {
			await reload()
			throw e
		}
		onDone?.(result)
		const { selectDueDate = null, expandRunId = null } = focus(result)
		if (selectDueDate !== null) { state.selectedDate = selectDueDate }
		if (expandRunId !== null) { state.expandedRunId = expandRunId }
		await reload()
		return result
	}

	/** Schritt 1: Snapshot und pain.008 in einem Zug. Liefert den neuen Lauf (mit `itemCount`, `sumCents`). */
	function releaseRun(dueDate, onDone = null) {
		return settle(
			async () => (await api.releaseDebitBatch(dueDate)).data,
			(batch) => ({ selectDueDate: batch.dueDate, expandRunId: batch.id }),
			onDone,
		)
	}

	/** Schritt 2: „Datei ist bei der Bank eingereicht“ – terminal, kein Storno. */
	function submitRun(run, onDone = null) {
		return settle(async () => (await api.submitDebitBatch(run.id)).data, () => ({}), onDone)
	}

	/** Anzahl freizugebender Forderungen je Termin, wie der Zeitstrahl sie gerade zeigt. */
	function previewCounts() {
		return Object.fromEntries((state.timeline?.dates || []).map((d) => [d.dueDate, d.preview.count]))
	}

	/**
	 * Verwerfen mit Pflicht-Begründung: die Forderungen werden wieder frei, die Historie bleibt.
	 * Danach ist der Termin gewählt, an dem sie wieder auftauchen – erkannt daran, wo die
	 * Vorschau-Anzahl gewachsen ist; ohne Treffer der Termin des Laufs.
	 */
	async function discardRun(run, reason, onDone = null) {
		const before = previewCounts()
		const result = await settle(
			async () => (await api.discardDebitBatch(run.id, reason)).data,
			() => ({ selectDueDate: run.dueDate }),
			onDone,
		)
		const freed = (state.timeline?.dates || []).find((d) => d.preview.count > (before[d.dueDate] ?? 0))
		if (freed && freed.dueDate !== state.selectedDate) {
			state.selectedDate = freed.dueDate
			await loadPreview(freed.dueDate, { force: true })
		}
		return result
	}

	/** Termin nach hinten verschieben (der Server lehnt frühere Daten ab; die Oberfläche bietet sie gar nicht erst an). */
	function rescheduleRun(run, dueDate, onDone = null) {
		return settle(
			async () => (await api.rescheduleDebitBatch(run.id, dueDate)).data,
			(batch) => ({ selectDueDate: batch.dueDate }),
			onDone,
		)
	}

	return { releaseRun, submitRun, discardRun, rescheduleRun }
}
