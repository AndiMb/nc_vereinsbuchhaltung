// Fokussiert ein Feld sofort beim Oeffnen eines NcModal, statt auf dessen
// eigene Fokus-Ermittlung zu warten: focus-trap (in @nextcloud/vue) aktiviert
// sich erst nach Abschluss der Oeffnen-Animation (onAfterEnter). Findet sie
// bis dahin nichts Fokussiertes im Dialog, faellt sie auf ihren
// fallbackFocus zurueck - die Modal-Maske selbst - und das korrigiert sich
// danach nicht mehr von selbst. Bei einem schnellen Klick ins erste Feld
// bleibt der Fokus so dauerhaft haengen, Klicks und Tippen dort laufen dann
// ins Leere.
export function focusOnOpen(vm, getRef) {
	vm.$nextTick(() => getRef()?.focus())
}
