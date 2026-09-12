<template>
	<li role="treeitem" :aria-expanded="node.open" :aria-selected="isSelected">
		<div class="vbh-dirtree-row">
			<button
				type="button"
				class="vbh-dirtree-toggle"
				:aria-label="node.open ? t('Zuklappen') : t('Aufklappen')"
				@click="toggle(node)">
				<NcIconSvgWrapper :path="node.open ? mdiChevronDown : mdiChevronRight" :size="20" />
			</button>
			<span v-if="isRoot" class="vbh-dirtree-label">
				<NcIconSvgWrapper :path="mdiHomeOutline" :size="20" />
				<span>{{ node.name }}</span>
			</span>
			<button
				v-else
				type="button"
				class="vbh-dirtree-label"
				:class="{ 'is-selected': isSelected }"
				@click="select(node)">
				<NcIconSvgWrapper :path="node.open ? mdiFolderOpenOutline : mdiFolderOutline" :size="20" />
				<span>{{ node.name }}</span>
			</button>
		</div>
		<template v-if="node.open">
			<p v-if="node.loading" class="vbh-dirtree-note">
				{{ t('Lädt…') }}
			</p>
			<p v-else-if="node.error" class="vbh-dirtree-note vbh-dirtree-note--error">
				{{ node.error }}
			</p>
			<p v-else-if="node.children && node.children.length === 0" class="vbh-dirtree-note">
				{{ t('Keine Unterordner') }}
			</p>
			<ul v-else-if="node.children" role="group">
				<FolderTreeNode
					v-for="child in node.children"
					:key="child.path"
					:node="child"
					:selected="selected"
					:toggle="toggle"
					:select="select" />
			</ul>
		</template>
	</li>
</template>

<script>
import { mdiChevronDown, mdiChevronRight, mdiFolderOpenOutline, mdiFolderOutline, mdiHomeOutline } from '@mdi/js'
import { NcIconSvgWrapper } from '@nextcloud/vue'

/** Ein Knoten des Ordnerbaums (FolderTree.vue), rekursiv für die Unterordner. */
export default {
	name: 'FolderTreeNode',
	components: { NcIconSvgWrapper },
	props: {
		node: { type: Object, required: true },
		selected: { type: String, default: '' },
		toggle: { type: Function, required: true },
		select: { type: Function, required: true },
	},

	data() {
		return { mdiChevronDown, mdiChevronRight, mdiFolderOpenOutline, mdiFolderOutline, mdiHomeOutline }
	},

	computed: {
		isRoot() { return this.node.path === '' },
		isSelected() { return !this.isRoot && this.node.path === this.selected },
	},
}
</script>
