module.exports = {
	extends: ['@nextcloud'],
	rules: {
		'vue/no-unused-vars': 'warn',

		// Dokumentiert wird in diesem Projekt in Prosa oberhalb der Funktion
		// (auf Deutsch, mit Begruendung) statt in JSDoc-Tags. Die Regeln wuerden
		// nur leere @param-Gerueste erzwingen, die nichts erklaeren.
		'jsdoc/require-jsdoc': 'off',
		'jsdoc/require-param': 'off',
		'jsdoc/require-param-type': 'off',
		'jsdoc/require-param-description': 'off',
	},
}
