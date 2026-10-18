<template>
	<NcPopover
		:triggers="['hover', 'focus']"
		:popoverTriggers="['hover']"
		:delay="{ show: 150, hide: 100 }"
		noFocusTrap
		placement="left"
		popupRole="tooltip">
		<template #trigger>
			<button
				type="button"
				class="vbh-commentmark"
				:aria-label="t('Kommentar: {text}', { text })">
				<NcIconSvgWrapper :path="mdiCommentTextOutline" :size="16" />
			</button>
		</template>
		<div class="vbh-comment-popover">
			<strong class="vbh-comment-popover-title">{{ t('Kommentar') }}</strong>
			<p>{{ text }}</p>
		</div>
	</NcPopover>
</template>

<script>
import { mdiCommentTextOutline } from '@mdi/js'
import { NcIconSvgWrapper, NcPopover } from '@nextcloud/vue'

/**
 * Kommentar-Symbol in einer Buchungszeile (Reiter Buchungen, Kontoauszug).
 * Beim Überfahren mit der Maus – oder per Tastatur-Fokus – zeigt ein Popover
 * den Kommentar. Bewusst kein title-Tooltip: der Kommentar ist oft mehrzeilig,
 * ein Tooltip erscheint erst verzögert, bricht nicht sauber um und lässt sich
 * nicht markieren. Das Popover bleibt offen, solange die Maus darüber steht.
 */
export default {
	name: 'CommentMark',
	components: { NcIconSvgWrapper, NcPopover },
	props: {
		text: { type: String, required: true },
	},

	data() {
		return { mdiCommentTextOutline }
	},
}
</script>
