<template>
	<div class="vbh-selfservice">
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else-if="member">
			<div class="vbh-card">
				<h4>{{ t('Meine Stammdaten') }}</h4>
				<dl class="vbh-selfservice-fields">
					<dt>{{ t('Name') }}</dt>
					<dd>{{ member.displayName }}</dd>

					<template v-if="member.email">
						<dt>{{ t('E-Mail') }}</dt>
						<dd>{{ member.email }}</dd>
					</template>

					<template v-if="member.phone">
						<dt>{{ t('Telefon') }}</dt>
						<dd>{{ member.phone }}</dd>
					</template>

					<template v-if="addressLine">
						<dt>{{ t('Adresse') }}</dt>
						<dd>{{ addressLine }}</dd>
					</template>

					<template v-if="member.memberNumber">
						<dt>{{ t('Mitgliedsnummer') }}</dt>
						<dd>{{ member.memberNumber }}</dd>
					</template>

					<dt>{{ t('Mitglied seit') }}</dt>
					<dd>{{ formatDate(member.joinedAt) }}</dd>

					<template v-if="member.leftAt">
						<dt>{{ t('Mitglied bis') }}</dt>
						<dd>{{ formatDate(member.leftAt) }}</dd>
					</template>
				</dl>
			</div>
			<p class="vbh-hint">
				{{ t('Mandat und Beitrag lassen sich hier bald selbst verwalten – bis dahin wendet euch für Änderungen an den Vorstand.') }}
			</p>
		</template>
		<p v-else class="vbh-hint vbh-hint--warning">
			{{ t('Deine Stammdaten konnten nicht geladen werden.') }}
		</p>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcLoadingIcon } from '@nextcloud/vue'
import api from '../api.js'
import { errMsg, formatDate } from '../lib/format.js'

/**
 * Bereich „Mein Beitrag" (Spec §3.4): Self-Service-Grundgerüst für
 * Mitglieder mit verknüpftem NC-Konto – ersetzt das frühere „Kein
 * Zugriff"-Panel für diese Konten (App.vue) und steht additiv neben dem
 * Buchhaltungs-Tab, wenn dieselbe Person zusätzlich eine vbh-Rolle hat
 * (Personalunion).
 *
 * Nur lesend: der Aktionskatalog (Mandat erfassen, Beitrag ändern) ist NICHT
 * Teil dieses Tickets (#74) und kommt mit #75/#76. Lädt seine Daten beim
 * eigenen mounted() wie MembersList.vue/SepaBatchPanel.vue, statt von
 * App.vue vorgeladen zu werden – dieselbe Konvention wie ContributionsTab.vue.
 */
export default {
	name: 'SelfServiceTab',
	components: { NcLoadingIcon },

	data() {
		return {
			loading: true,
			member: null,
		}
	},

	computed: {
		addressLine() {
			const m = this.member
			if (!m) { return '' }
			const line1 = m.street || ''
			const line2 = [m.postalCode, m.city].filter(Boolean).join(' ')
			return [line1, line2].filter(Boolean).join(', ')
		},
	},

	async mounted() {
		this.loading = true
		try {
			const { data } = await api.selfMe()
			this.member = data
		} catch (e) {
			showError(errMsg(e, 'Stammdaten konnten nicht geladen werden'))
		} finally {
			this.loading = false
		}
	},

	methods: { formatDate },
}
</script>
