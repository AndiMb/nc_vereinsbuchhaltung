<template>
	<NcPopover
		class="vbh-commentmark-popover"
		:triggers="['hover', 'focus']"
		:popoverTriggers="['hover']"
		:delay="{ show: 150, hide: 100 }"
		noFocusTrap
		placement="left"
		popupRole="tooltip">
		<!-- NcButton wie Beleg und Bearbeiten daneben: gleiche Groesse und
		     Ausrichtung in der Aktionsspalte, und NcPopover haengt seine
		     aria-Attribute (haspopup, expanded) selbst an. Ein eigener <button>
		     liefe in Nextclouds Grundregeln fuer nackte Buttons. -->
		<template #trigger>
			<NcButton
				variant="tertiary"
				class="vbh-commentmark"
				:aria-label="t('Kommentar: {text}', { text })">
				<template #icon>
					<NcIconSvgWrapper :path="mdiCommentTextOutline" :size="16" />
				</template>
			</NcButton>
		</template>
		<div class="vbh-comment-popover">
			<strong class="vbh-comment-popover-title">{{ t('Kommentar') }}</strong>
			<p>{{ text }}</p>
		</div>
	</NcPopover>
</template>

<script>
import { mdiCommentTextOutline } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcPopover from '@nextcloud/vue/components/NcPopover'

/**
 * Kommentar-Symbol in einer Buchungszeile (Reiter Buchungen, Kontoauszug).
 * Beim Überfahren mit der Maus – oder per Tastatur-Fokus – zeigt ein Popover
 * den Kommentar. Bewusst kein title-Tooltip: der Kommentar ist oft mehrzeilig,
 * ein Tooltip erscheint erst verzögert, bricht nicht sauber um und lässt sich
 * nicht markieren. Das Popover bleibt offen, solange die Maus darüber steht.
 */
export default {
	name: 'CommentMark',
	components: { NcButton, NcIconSvgWrapper, NcPopover },
	props: {
		text: { type: String, required: true },
	},

	data() {
		return { mdiCommentTextOutline }
	},
}
</script>
