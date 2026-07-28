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

		// Bekannte technische Schuld: BookingDialog.vue und AccountsTab.vue
		// bearbeiten das Formular-Objekt des Elternteils direkt, statt Aenderungen
		// per Event zurueckzumelden. In Vue 2 funktioniert das (Objekt-Props sind
		// Referenzen), es koppelt Kind und Elternteil aber fest aneinander und
		// muesste vor einer Vue-3-Migration aufgeloest werden. Bewusst als
		// Warnung sichtbar gehalten statt abgeschaltet - aber kein Grund, die
		// CI rot zu faerben, solange der Umbau noch aussteht.
		'vue/no-mutating-props': 'warn',
	},
}
