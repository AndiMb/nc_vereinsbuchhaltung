<template>
	<p v-if="!events.length" class="vbh-hint">
		{{ t('Noch keine Einträge.') }}
	</p>
	<ul v-else class="vbh-mandate-events">
		<li v-for="e in events" :key="e.id">
			<span class="vbh-mandate-events__when">{{ formatStamp(e.createdAt) }}</span>
			<span class="vbh-mandate-events__who">{{ who(e) }}</span>
			<span class="vbh-mandate-events__what">{{ e.message }}<template v-if="e.onBehalfNote"> ({{ e.onBehalfNote }})</template></span>
		</li>
	</ul>
</template>

<script>
import { actorLabel, formatStamp } from '../lib/mandateView.js'

/**
 * Ereignishistorie eines Mandats (Spec §2.2 `MandateEvent`, append-only):
 * wer, wann, was – neueste zuerst, in der Reihenfolge, in der das Backend sie
 * liefert. `actor_type` benennt den Kanal (Mitglied/Verein/System, Spec §3.9),
 * `actor_uid` die NC-Kennung, soweit es eine Sitzung gab (der login-lose
 * Einmal-Link und der Cron haben keine).
 */
export default {
	name: 'MandateEventList',
	props: {
		events: { type: Array, default: () => [] },
	},

	methods: {
		formatStamp,
		who(event) {
			const kanal = actorLabel(event.actorType)
			return event.actorUid ? `${kanal} (${event.actorUid})` : kanal
		},
	},
}
</script>

<style scoped>
.vbh-mandate-events {
	list-style: none;
	margin: 6px 0 0;
	padding: 0;
	display: grid;
	gap: 6px;
}

.vbh-mandate-events li {
	display: grid;
	grid-template-columns: max-content max-content 1fr;
	column-gap: 12px;
	align-items: baseline;
	padding-top: 6px;
	border-top: 1px solid var(--color-border);
}

.vbh-mandate-events li:first-child {
	border-top: none;
	padding-top: 0;
}

.vbh-mandate-events__when {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.vbh-mandate-events__who {
	font-weight: 600;
	white-space: nowrap;
}

.vbh-mandate-events__what {
	overflow-wrap: anywhere;
}

/* Schmale Displays: Zeitpunkt und Akteur teilen sich eine Zeile, die Meldung steht darunter über die volle Breite. */
@media (max-width: 640px) {
	.vbh-mandate-events li {
		grid-template-columns: max-content 1fr;
		row-gap: 2px;
	}

	.vbh-mandate-events__what {
		grid-column: 1 / -1;
	}
}
</style>
