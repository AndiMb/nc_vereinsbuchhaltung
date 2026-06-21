const webpackConfig = require('@nextcloud/webpack-vue-config')
const path = require('path')

// Entry-Name = Dateiname des erzeugten Bundles. Muss zu dem in
// PageController::index() per Util::addScript geladenen Skript passen:
//   js/vereinsbuchhaltung-main.js  ->  addScript('vereinsbuchhaltung', 'vereinsbuchhaltung-main')
webpackConfig.entry = {
	'vereinsbuchhaltung-main': path.join(__dirname, 'src', 'main.js'),
}

// Ausgabedateiname explizit festlegen (kein Content-Hash im Dateinamen),
// damit addScript die Datei zuverlässig findet.
webpackConfig.output = {
	...webpackConfig.output,
	filename: '[name].js',
}

module.exports = webpackConfig
