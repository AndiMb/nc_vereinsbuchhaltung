<template>
	<div
		ref="root"
		class="vbh-newmenu-wrap"
		@keydown.esc.stop.prevent="close(true)"
		@focusout="onFocusOut">
		<NcButton
			ref="trigger"
			variant="primary"
			class="vbh-newmenu-btn"
			:title="t('Neu anlegen')"
			aria-haspopup="menu"
			:aria-expanded="String(open)"
			@click="toggle"
			@keydown.down.prevent="openAndFocus(0)">
			<template #icon>
				<NcIconSvgWrapper :path="mdiPlus" :size="20" />
			</template>
			{{ t('Neu') }}
		</NcButton>
		<ul
			v-if="open"
			class="vbh-newmenu-list"
			role="menu"
			:aria-label="t('Neu anlegen')"
			@keydown.down.prevent="move(1)"
			@keydown.up.prevent="move(-1)">
			<li v-for="item in items" :key="item.id" role="none">
				<button type="button" role="menuitem" @click="choose(item.id)">
					<NcIconSvgWrapper :path="item.icon" :size="20" />
					{{ item.label }}
				</button>
			</li>
		</ul>
	</div>
</template>

<script>
import { mdiPlus } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

/**
 * Das „+ Neu“ im Kopf der App: ein kleines Menü zum Anlegen von Buchung,
 * Mitglied oder Rechnung. Bewusst kein NcActions: dessen Popover legt einen
 * Fokusfang an, der nach dem Schließen hängen blieb und in danach geöffneten
 * Dialogen die Tab-Taste auf einem Feld festhielt. Dieses Menü nimmt dem Dialog
 * den Fokus nicht ab: Auswahl schließt es sofort, Escape und Klick daneben
 * ebenfalls, und mit den Pfeiltasten lässt es sich ohne Maus bedienen.
 */
export default {
	name: 'NewMenu',
	components: { NcButton, NcIconSvgWrapper },
	props: {
		/** Einträge: `{ id, label, icon }` (icon ist ein Pfad aus @mdi/js). */
		items: { type: Array, required: true },
	},

	emits: ['select'],

	data() {
		return { open: false, mdiPlus }
	},

	mounted() {
		document.addEventListener('pointerdown', this.onOutside, true)
	},

	beforeUnmount() {
		document.removeEventListener('pointerdown', this.onOutside, true)
	},

	methods: {
		toggle() { this.open = !this.open },

		close(refocus = false) {
			const wasOpen = this.open
			this.open = false
			if (refocus && wasOpen) { this.$nextTick(() => this.$refs.trigger?.$el?.focus()) }
		},

		openAndFocus(index) {
			this.open = true
			this.$nextTick(() => this.buttons()[index]?.focus())
		},

		buttons() { return [...(this.$refs.root?.querySelectorAll('[role="menuitem"]') ?? [])] },

		move(delta) {
			const list = this.buttons()
			if (list.length === 0) { return }
			const at = list.indexOf(document.activeElement)
			list[(at + delta + list.length) % list.length].focus()
		},

		choose(id) {
			this.open = false
			this.$emit('select', id)
		},

		onOutside(event) {
			if (this.open && !this.$refs.root?.contains(event.target)) { this.open = false }
		},

		/** Wer mit Tab aus dem Menü hinaustabbt, lässt es zufallen. */
		onFocusOut(event) {
			if (this.open && event.relatedTarget && !this.$refs.root?.contains(event.relatedTarget)) { this.open = false }
		},
	},
}
</script>

<style scoped>
.vbh-newmenu-wrap { position: relative; display: inline-flex; }

.vbh-newmenu-list { position: absolute; inset-inline-end: 0; top: calc(100% + 6px); z-index: 2000; margin: 0; padding: 6px; list-style: none; min-width: 200px; background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 12px); box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3); }

.vbh-newmenu-list button { width: 100%; display: flex; align-items: center; gap: 10px; padding: 8px 12px; border: none; border-radius: var(--border-radius, 8px); background: transparent; color: var(--color-main-text); font-size: 1em; text-align: start; cursor: pointer; min-height: 40px; }

.vbh-newmenu-list button:hover, .vbh-newmenu-list button:focus-visible { background: var(--color-background-hover); }
</style>
