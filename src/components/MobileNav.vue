<template>
	<nav class="vbh-bottomnav" :aria-label="t('Hauptnavigation')">
		<button v-if="canWrite"
			type="button"
			class="vbh-fab"
			:aria-label="t('Neue Buchung anlegen')"
			:title="t('Neue Buchung anlegen')"
			@click="$emit('new-booking')">
			<NcIconSvgWrapper :path="mdiPlus" :size="26" />
		</button>
		<button v-for="tab in tabs"
			:key="tab.id"
			type="button"
			class="vbh-bottomnav-item"
			:class="{ active: activeTab === tab.id }"
			:aria-current="activeTab === tab.id ? 'page' : null"
			@click="$emit('select', tab.id)">
			<span class="vbh-bottomnav-icon">
				<NcIconSvgWrapper :path="tab.icon" :size="22" />
				<span v-if="tab.id === 'bookings' && unassignedCount > 0" class="vbh-badge vbh-badge--alert vbh-bottomnav-badge">{{ unassignedCount }}</span>
				<span v-if="tab.id === 'contributions' && overdueMembershipCount > 0" class="vbh-badge vbh-badge--alert vbh-bottomnav-badge">{{ overdueMembershipCount }}</span>
			</span>
			<span class="vbh-bottomnav-label">{{ tab.label }}</span>
		</button>
	</nav>
</template>

<script>
import { NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiPlus } from '@mdi/js'

/**
 * Untere Navigationsleiste für Mobilgeräte: alle Tabs gleichzeitig sichtbar
 * in Daumenreichweite, plus schwebender "Neue Buchung"-Button (FAB).
 * Wird nur bei isMobile gerendert; am Desktop bleibt die Tab-Leiste im Kopf.
 */
export default {
	name: 'MobileNav',
	components: { NcIconSvgWrapper },
	props: {
		tabs: { type: Array, required: true },
		activeTab: { type: String, required: true },
		unassignedCount: { type: Number, default: 0 },
		overdueMembershipCount: { type: Number, default: 0 },
		canWrite: { type: Boolean, default: false },
	},
	data() {
		return { mdiPlus }
	},
}
</script>
