// chart.js ist gross und wird nur fuer Dashboard und Berichte gebraucht. Es
// wird deshalb erst beim ersten Zeichnen nachgeladen (eigener Webpack-Chunk)
// und genau einmal registriert; weitere Aufrufe teilen sich dasselbe Promise.

let pending = null

/**
 * Laedt chart.js (einmalig) und gibt die Klasse `Chart` zurueck, in der alle
 * von Dashboard (Balken) und Berichten (Linien) benoetigten Bausteine
 * registriert sind.
 *
 * @return {Promise<typeof import('chart.js').Chart>}
 */
export function loadChart() {
	if (!pending) {
		pending = import(/* webpackChunkName: "vbh-chartjs" */ 'chart.js')
			.then(({
				BarController,
				BarElement,
				CategoryScale,
				Chart,
				Legend,
				LinearScale,
				LineController,
				LineElement,
				PointElement,
				Tooltip,
			}) => {
				Chart.register(
					BarController,
					BarElement,
					LineController,
					LineElement,
					PointElement,
					CategoryScale,
					LinearScale,
					Tooltip,
					Legend,
				)
				return Chart
			})
			.catch((e) => {
				// Ein gescheiterter Abruf (Netz) darf nicht fuer immer haengen bleiben.
				pending = null
				throw e
			})
	}
	return pending
}
