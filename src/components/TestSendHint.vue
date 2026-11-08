<template>
	<p v-if="!testTo" class="vbh-hint">
		{{ t('Für den Testversand fehlt in Ihrem Nextcloud-Konto eine E-Mail-Adresse.') }}
		<a :href="personalSettingsUrl" target="_blank" rel="noopener">{{ t('In den persönlichen Einstellungen eintragen') }}</a>
	</p>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

/**
 * Hinweis im Versand- und Mahndialog, wenn der Testversand (#216) mangels
 * eigener E-Mail-Adresse nicht geht. Die Adresse bestimmt der Server aus dem
 * Nextcloud-Konto; eintragen lässt sie sich nur dort.
 */
export default {
	name: 'TestSendHint',
	props: {
		testTo: { type: String, default: '' },
	},

	computed: {
		personalSettingsUrl() { return generateUrl('/settings/user') },
	},
}
</script>
