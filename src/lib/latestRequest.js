/**
 * Laufende Nummer gegen überholte Antworten (Muster von journalSeq in
 * useJournal.js). Jeder Ladevorgang holt sich zu Beginn `start()` und schreibt
 * seinen Zustand nur, solange das zurückgegebene `isCurrent()` true liefert –
 * also kein späterer Ladevorgang begonnen hat. So überschreibt die verspätete
 * Antwort einer älteren Abfrage (etwa des vorigen Zeitraums) nie die neuere.
 *
 * Anders als createSharedLoader() teilt das keine Abfragen; es passt für
 * Lader mit Argumenten (Zeitraum, Filter), bei denen jeder Aufruf selbst
 * fragen muss.
 *
 * @return {() => (() => boolean)} start
 */
export function createLatestRequest() {
	let seq = 0
	return function start() {
		const mine = ++seq
		return () => mine === seq
	}
}
