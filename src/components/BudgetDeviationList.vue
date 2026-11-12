<template>
	<section class="vbh-visual-dev">
		<h5 class="vbh-visual-dev__title">
			{{ title }}
		</h5>
		<p v-if="items.length === 0" class="vbh-empty">
			{{ emptyText }}
		</p>
		<ul v-else class="vbh-visual-dev__list">
			<li v-for="item in items" :key="item.accountId" class="vbh-visual-dev__item">
				<component
					:is="linkable ? 'button' : 'span'"
					:type="linkable ? 'button' : null"
					class="vbh-visual-dev__entry"
					:class="{ 'vbh-visual-dev__entry--link': linkable }"
					:aria-label="linkable ? t('Buchungen von {konto} zeigen', { konto: item.number + ' ' + item.label }) : null"
					:aria-describedby="linkable ? detailId(item, 'level') + ' ' + detailId(item, 'amount') : null"
					@click="linkable && $emit('open-account', item.accountId, !!item.parent)">
					<!-- Als Knopf trägt der Eintrag den Namen „Buchungen von … zeigen“;
					     Stufe und Betrag bleiben als Beschreibung hörbar. -->
					<BudgetStatusPill
						:id="detailId(item, 'level')"
						:level="item.level"
						:nature="nature"
						compact />
					<span class="vbh-visual-dev__label">{{ item.number }} {{ item.label }}</span>
					<span :id="detailId(item, 'amount')" class="vbh-visual-dev__amount">{{ money(item.amount) }}</span>
				</component>
			</li>
		</ul>
	</section>
</template>

<script>
import BudgetStatusPill from './BudgetStatusPill.vue'
import { formatMoney, formatSigned } from '../lib/format.js'

/**
 * Liste der größten Abweichungen. Nur mit `linkable` sind die Einträge
 * Schaltflächen, die `open-account` auslösen (Konto-ID und ob es ein Oberkonto
 * ist); sonst bleiben sie reiner Text. Der Betrag eines Oberkontos (`parent`)
 * schließt die Unterkonten ein – das Ziel filtert dann samt Unterkonten.
 * Beträge (Abweichung) in Cent.
 */
export default {
	name: 'BudgetDeviationList',
	components: { BudgetStatusPill },
	props: {
		title: { type: String, default: '' },
		nature: { type: String, default: 'expense' },
		items: { type: Array, default: () => [] },
		emptyText: { type: String, default: '' },
		linkable: { type: Boolean, default: false },
	},

	emits: ['open-account'],

	methods: {
		// Eindeutig je Liste: ein Konto steht nur in der Liste seiner Art.
		detailId(item, part) {
			return `vbh-visual-dev-${this.nature}-${item.accountId}-${part}`
		},

		// Überschreitungen mit Vorzeichen; eine Lücke bei Einnahmen ist ein
		// Fehlbetrag, ein „+“ davor läse sich wie ein Mehrertrag.
		money(cents) {
			return this.nature === 'income' ? formatMoney(cents / 100) : formatSigned(cents / 100)
		},
	},
}
</script>

<style scoped>
.vbh-visual-dev { min-width: 0; }

.vbh-visual-dev__title { margin: 0 0 8px; font-weight: 600; }

.vbh-visual-dev__list { margin: 0; padding: 0; list-style: none; }

/* Mit `linkable` ein nackter <button>: doppelte Klasse gegen Nextclouds
   Grundstil, auch für :hover/:focus/:active (siehe Kopf von src/styles.css). */
.vbh-visual-dev__entry.vbh-visual-dev__entry,
.vbh-visual-dev__entry.vbh-visual-dev__entry:hover,
.vbh-visual-dev__entry.vbh-visual-dev__entry:focus,
.vbh-visual-dev__entry.vbh-visual-dev__entry:active {
	display: flex;
	align-items: center;
	gap: 8px;
	/* Innenabstand für die Hover-Fläche, ausgeglichen: der Text bleibt bündig zum Titel. */
	width: calc(100% + 8px);
	min-height: 0;
	margin: 0 -4px;
	padding: 4px;
	border: 0;
	border-radius: var(--border-radius);
	background: none;
	color: var(--color-main-text);
	font: inherit;
	text-align: start;
}

.vbh-visual-dev__entry.vbh-visual-dev__entry--link { cursor: pointer; }

.vbh-visual-dev__entry.vbh-visual-dev__entry--link:hover { background-color: var(--color-background-hover); }

.vbh-visual-dev__entry.vbh-visual-dev__entry--link:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

/* Die Kontobezeichnung zeigt wie im Aufriss, dass sie in die Buchungen führt. */
.vbh-visual-dev__entry--link .vbh-visual-dev__label {
	text-decoration: underline;
	text-decoration-color: var(--color-text-maxcontrast);
	text-underline-offset: 3px;
}

.vbh-visual-dev__label { flex: 1 1 auto; min-width: 0; overflow-wrap: break-word; hyphens: auto; }

.vbh-visual-dev__amount { flex: 0 0 auto; font-variant-numeric: tabular-nums; font-weight: 600; }
</style>
