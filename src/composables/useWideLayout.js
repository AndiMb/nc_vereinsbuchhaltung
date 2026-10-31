import { onBeforeUnmount, ref } from 'vue'

/**
 * Ist das Fenster breit genug, dass Liste und Belegvorschau nebeneinander
 * (im Buchungsdialog zusätzlich neben dem Formular) stehen können? Sonst
 * ersetzt die Vorschau die Liste (Belegvorschau #231, Spec §3.3).
 *
 * Ohne matchMedia (Testumgebung) gilt das Fenster als breit.
 *
 * @param {number} minWidth Mindestbreite in Pixeln
 * @return {{wide: import('vue').Ref<boolean>}}
 */
export function useWideLayout(minWidth = 1500) {
	const wide = ref(true)
	if (typeof window === 'undefined' || !window.matchMedia) { return { wide } }
	const query = window.matchMedia(`(min-width: ${minWidth}px)`)
	const update = () => { wide.value = query.matches }
	update()
	query.addEventListener('change', update)
	onBeforeUnmount(() => query.removeEventListener('change', update))
	return { wide }
}
