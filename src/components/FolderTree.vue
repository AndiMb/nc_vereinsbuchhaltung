<template>
	<ul
		v-if="root"
		class="vbh-dirtree"
		role="tree"
		:aria-label="t('Ordner im Home von {user}', { user })">
		<FolderTreeNode
			:node="root"
			:selected="modelValue"
			:toggle="toggle"
			:select="select" />
	</ul>
</template>

<script>
import FolderTreeNode from './FolderTreeNode.vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

/**
 * Ordnerbaum eines Nutzer-Homes für die Einstellungen: statt einen Pfad zu
 * tippen, klickt der Verwalter den Ordner an. Geladen wird je Ebene beim
 * Aufklappen – ein Home kann tausende Ordner haben, gebraucht wird ein Ast.
 *
 * Die Knoten gehören dieser Komponente; FolderTreeNode zeichnet nur und ruft
 * toggle/select auf (vue/no-mutating-props).
 */
export default {
	name: 'FolderTree',
	components: { FolderTreeNode },
	props: {
		user: { type: String, required: true },
		modelValue: { type: String, default: '' },
	},

	emits: ['update:modelValue'],

	data() {
		return { root: null }
	},

	watch: {
		user: { handler() { this.reveal() }, immediate: true },
	},

	methods: {
		node(name, path) {
			return { name, path, children: null, open: false, loading: false, error: '' }
		},

		async load(node) {
			node.loading = true
			node.error = ''
			try {
				const { data } = await api.listFolders(this.user, node.path)
				node.children = data.folders.map((f) => this.node(f.name, f.path))
			} catch (e) {
				node.error = errMsg(e, this.t('Ordner konnte nicht gelesen werden'))
			} finally {
				node.loading = false
			}
		},

		async toggle(node) {
			node.open = !node.open
			if (node.open && node.children === null && !node.loading) {
				await this.load(node)
			}
		},

		// Die Auswahl klappt den Ordner mit auf: man sieht gleich, was darin liegt.
		select(node) {
			this.$emit('update:modelValue', node.path)
			if (!node.open) { this.toggle(node) }
		},

		// Den Ast zum eingestellten Pfad aufklappen, damit die Auswahl sichtbar
		// ist. Ein Nutzerwechsel zwischendurch bricht den alten Lauf ab.
		async reveal() {
			this.root = this.node(this.user, '')
			const root = this.root
			await this.toggle(root)
			let node = root
			for (const part of this.modelValue.split('/').filter(Boolean)) {
				const child = (node.children || []).find((c) => c.name === part)
				if (this.root !== root || !child) { return }
				await this.toggle(child)
				node = child
			}
		},
	},
}
</script>
