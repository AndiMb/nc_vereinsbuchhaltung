<template>
	<nav class="vbh-bottomnav" :aria-label="t('Hauptnavigation')">
		<template v-if="canWrite">
			<div v-if="menuOpen" class="vbh-fabscrim" @click="menuOpen = false" />
			<ul
				v-if="menuOpen"
				class="vbh-fabmenu"
				role="menu"
				@keydown.esc="menuOpen = false">
				<li v-for="item in items" :key="item.id" role="none">
					<button type="button" role="menuitem" @click="choose(item.id)">
						<NcIconSvgWrapper :path="item.icon" :size="20" />
						{{ item.label }}
					</button>
				</li>
			</ul>
			<button
				type="button"
				class="vbh-fab"
				:aria-label="items.length > 1 ? t('Neu anlegen') : t('Neue Buchung anlegen')"
				:title="items.length > 1 ? t('Neu anlegen') : t('Neue Buchung anlegen')"
				:aria-haspopup="items.length > 1 ? 'menu' : null"
				:aria-expanded="items.length > 1 ? String(menuOpen) : null"
				@click="onFab">
				<NcIconSvgWrapper :path="menuOpen ? mdiClose : mdiPlus" :size="26" />
			</button>
		</template>
		<button
			v-for="tab in tabs"
			:key="tab.id"
			type="button"
			class="vbh-bottomnav-item"
			:class="{ active: activeTab === tab.id }"
			:aria-current="activeTab === tab.id ? 'page' : null"
			@click="$emit('select', tab.id)">
			<span class="vbh-bottomnav-icon">
				<NcIconSvgWrapper :path="tab.icon" :size="22" />
				<span v-if="tab.id === 'bookings' && unassignedCount > 0" class="vbh-badge vbh-badge--alert vbh-bottomnav-badge">{{ unassignedCount }}</span>
			</span>
			<span class="vbh-bottomnav-label">{{ tab.label }}</span>
		</button>
	</nav>
</template>

<script>
import { mdiAccountPlusOutline, mdiClose, mdiFileDocumentOutline, mdiPlus, mdiSwapHorizontal } from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

/**
 * Untere Navigationsleiste für Mobilgeräte: alle Tabs gleichzeitig sichtbar
 * in Daumenreichweite, plus schwebender „Neu“-Knopf (FAB). Gibt es mehr als
 * eine Möglichkeit (Buchung, Rechnung), öffnet er ein
 * kleines Menü; sonst legt er gleich eine Buchung an.
 * Wird nur bei isMobile gerendert; am Desktop bleibt die Tab-Leiste im Kopf.
 */
export default {
	name: 'MobileNav',
	components: { NcIconSvgWrapper },
	props: {
		tabs: { type: Array, required: true },
		activeTab: { type: String, required: true },
		unassignedCount: { type: Number, default: 0 },
		canWrite: { type: Boolean, default: false },
		invoicesActive: { type: Boolean, default: false },
		membershipActive: { type: Boolean, default: false },
	},

	emits: ['new-booking', 'new-invoice', 'new-member', 'select'],

	data() {
		return { mdiClose, mdiPlus, menuOpen: false }
	},

	computed: {
		items() {
			const items = [{ id: 'booking', label: this.t('Buchung'), icon: mdiSwapHorizontal }]
			if (this.membershipActive) { items.push({ id: 'member', label: this.t('Mitglied'), icon: mdiAccountPlusOutline }) }
			if (this.invoicesActive) { items.push({ id: 'invoice', label: this.t('Rechnung'), icon: mdiFileDocumentOutline }) }
			return items
		},
	},

	methods: {
		onFab() {
			if (this.items.length === 1) {
				this.$emit('new-booking')
				return
			}
			this.menuOpen = !this.menuOpen
		},

		choose(id) {
			this.menuOpen = false
			if (id === 'booking') { this.$emit('new-booking') } else if (id === 'member') { this.$emit('new-member') } else { this.$emit('new-invoice', id) }
		},
	},
}
</script>
