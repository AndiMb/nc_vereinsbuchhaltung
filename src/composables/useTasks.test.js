import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// useTasks haelt die Aufgabenliste des Flyouts aktuell (Issue #99). Wichtig
// sind die Randfaelle, nicht das reine Laden: ein Fehler darf eine gute Liste
// nicht wegwerfen, eine ueberholte Antwort darf eine neuere nicht ueberschreiben,
// und nach einer eigenen Schreibaktion muss die Liste von selbst nachziehen
// (eine behobene Stoerung soll sofort aus dem Badge verschwinden).

const listTasks = vi.fn()
vi.mock('../api.js', () => ({ default: { listTasks: () => listTasks() } }))

let responseInterceptor = null
const eject = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: {
		interceptors: {
			response: {
				use: (fn) => { responseInterceptor = fn; return 42 },
				eject: (id) => { eject(id); responseInterceptor = null },
			},
		},
	},
}))

const { useTasks } = await import('./useTasks.js')
const { state, actionTotal, hintTotal, loadTasks, startAutoRefresh } = useTasks()

const task = (id, severity = 'handlungsbedarf') => ({ id, severity, message: 'x', objectType: null, objectId: null, memberId: null })

beforeEach(() => {
	state.tasks = []
	state.loaded = false
	state.loading = false
	state.error = false
	listTasks.mockReset()
	eject.mockReset()
})

describe('loadTasks', () => {
	it('laedt, sortiert Handlungsbedarf nach vorn und zaehlt getrennt', async () => {
		listTasks.mockResolvedValue({ data: [task(1, 'hinweis'), task(2), task(3, 'hinweis')] })
		await loadTasks()
		expect(state.tasks.map((t) => t.id)).toEqual([2, 1, 3])
		expect(actionTotal.value).toBe(1)
		expect(hintTotal.value).toBe(2)
		expect(state.loaded).toBe(true)
		expect(state.error).toBe(false)
		expect(state.loading).toBe(false)
	})

	it('meldet einen Ladefehler, ohne eine zuvor geladene Liste zu verlieren', async () => {
		listTasks.mockResolvedValueOnce({ data: [task(1)] })
		await loadTasks()
		listTasks.mockRejectedValueOnce(new Error('offline'))
		await loadTasks()
		expect(state.error).toBe(true)
		expect(state.loaded).toBe(true)
		expect(state.tasks.map((t) => t.id)).toEqual([1])
		expect(state.loading).toBe(false)
	})

	it('ein Fehler vor der ersten erfolgreichen Antwort laesst loaded auf false', async () => {
		listTasks.mockRejectedValue(new Error('offline'))
		await loadTasks()
		expect(state.error).toBe(true)
		expect(state.loaded).toBe(false)
	})

	it('eine erfolgreiche Antwort nach einem Fehler loescht den Fehler wieder', async () => {
		listTasks.mockRejectedValueOnce(new Error('offline'))
		await loadTasks()
		listTasks.mockResolvedValueOnce({ data: [] })
		await loadTasks()
		expect(state.error).toBe(false)
		expect(state.tasks).toEqual([])
	})

	it('eine ueberholte Antwort ueberschreibt die neuere nicht', async () => {
		let resolveAlt
		listTasks.mockReturnValueOnce(new Promise((resolve) => { resolveAlt = resolve }))
		const alt = loadTasks()
		listTasks.mockResolvedValueOnce({ data: [task('neu')] })
		await loadTasks()
		resolveAlt({ data: [task('alt')] })
		await alt
		expect(state.tasks.map((t) => t.id)).toEqual(['neu'])
		expect(state.loading).toBe(false)
	})

	it('eine unerwartete Antwortform (kein Array) ergibt eine leere Liste statt eines Absturzes', async () => {
		listTasks.mockResolvedValue({ data: { message: 'huch' } })
		await loadTasks()
		expect(state.tasks).toEqual([])
		expect(state.loaded).toBe(true)
	})
})

describe('startAutoRefresh', () => {
	let stop
	const listeners = {}

	beforeEach(() => {
		vi.useFakeTimers()
		vi.stubGlobal('document', { hidden: false })
		vi.stubGlobal('window', {
			addEventListener: (name, fn) => { listeners[name] = fn },
			removeEventListener: (name) => { delete listeners[name] },
		})
		listTasks.mockResolvedValue({ data: [] })
	})

	afterEach(() => {
		if (stop) { stop(); stop = null }
		vi.useRealTimers()
		vi.unstubAllGlobals()
	})

	const write = (method = 'post', url = '/index.php/apps/vereinsbuchhaltung/api/mandates') => responseInterceptor({ config: { method, url } })

	it('laedt sofort und danach alle fuenf Minuten, solange der Tab sichtbar ist', async () => {
		stop = startAutoRefresh()
		expect(listTasks).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(300000)
		expect(listTasks).toHaveBeenCalledTimes(2)
		document.hidden = true
		await vi.advanceTimersByTimeAsync(300000)
		expect(listTasks).toHaveBeenCalledTimes(2)
	})

	it('laedt beim Fokussieren des Fensters neu', () => {
		stop = startAutoRefresh()
		listeners.focus()
		expect(listTasks).toHaveBeenCalledTimes(2)
	})

	it('laedt nach einer eigenen Schreibaktion nach, mehrere in Folge nur einmal', async () => {
		stop = startAutoRefresh()
		listTasks.mockClear()
		write()
		write('put', '/index.php/apps/vereinsbuchhaltung/api/assignments/4')
		write('delete', '/index.php/apps/vereinsbuchhaltung/api/members/7')
		expect(listTasks).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(1500)
		expect(listTasks).toHaveBeenCalledTimes(1)
	})

	it('Lesezugriffe, fremde Adressen und Schreibzugriffe ohne Bezug zu Aufgaben loesen nichts aus', async () => {
		stop = startAutoRefresh()
		listTasks.mockClear()
		write('get')
		write('post', '/index.php/apps/andere-app/api/x')
		write('post', '/index.php/apps/vereinsbuchhaltung/api/journal')
		write('post', '/index.php/apps/vereinsbuchhaltung/api/transactions/3/assign')
		const response = { config: { method: 'get', url: '/index.php/apps/vereinsbuchhaltung/api/tasks' } }
		expect(responseInterceptor(response)).toBe(response)
		await vi.advanceTimersByTimeAsync(5000)
		expect(listTasks).not.toHaveBeenCalled()
	})

	it('ist idempotent und raeumt beim Beenden alles ab', async () => {
		stop = startAutoRefresh()
		expect(startAutoRefresh()).toBe(stop)
		expect(listTasks).toHaveBeenCalledTimes(1)
		write()
		stop()
		stop = null
		expect(eject).toHaveBeenCalledWith(42)
		expect(listeners.focus).toBeUndefined()
		listTasks.mockClear()
		await vi.advanceTimersByTimeAsync(600000)
		expect(listTasks).not.toHaveBeenCalled()
	})
})
