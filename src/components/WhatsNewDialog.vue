<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-whatsnew"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-whatsnew">
			<h3 id="vbh-modal-title-whatsnew" class="vbh-modal-title">
				{{ t('Was ist neu?') }}
			</h3>

			<div v-if="entries.length" class="vbh-whatsnew-list">
				<div v-for="entry in entries" :key="entry.version" class="vbh-whatsnew-entry">
					<h4>{{ t('Version {v}', { v: entry.version }) }}</h4>
					<ul>
						<li v-for="(item, i) in entry.items" :key="i">
							{{ item }}
						</li>
					</ul>
				</div>
			</div>
			<p v-else class="vbh-hint">
				{{ t('Aktuell nichts Neues zu vermelden.') }}
			</p>

			<div class="vbh-whatsnew-footer">
				<a
					href="https://github.com/AndiMb/nc_vereinsbuchhaltung/blob/main/CHANGELOG.md"
					target="_blank"
					rel="noopener noreferrer"
					class="vbh-help-full">
					{{ t('Vollständige Änderungsliste öffnen ↗') }}
				</a>
				<NcButton variant="primary" @click="$emit('dismiss')">
					{{ t('Verstanden') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'

export default {
	name: 'WhatsNewDialog',
	components: { NcModal, NcButton },
	props: {
		show: { type: Boolean, default: false },
		// Reine Anzeige: gefiltert wird in App.vue (whatsNewEntries), damit
		// Gate und Anzeige nicht zwei Rechnungen ueber dieselben Eingaben sind.
		entries: { type: Array, default: () => [] },
	},

	emits: ['close', 'update:show', 'dismiss'],
}
</script>

<style scoped>
.vbh-whatsnew {
	padding: 8px 4px 4px;
}

.vbh-whatsnew-list {
	display: flex;
	flex-direction: column;
	gap: 16px;
	margin-top: 8px;
}

.vbh-whatsnew-entry h4 {
	margin: 0 0 4px;
}

.vbh-whatsnew-entry ul {
	margin: 0;
	padding-inline-start: 22px;
}

.vbh-whatsnew-entry li {
	margin: 3px 0;
}

.vbh-whatsnew-footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-top: 24px;
}
</style>
