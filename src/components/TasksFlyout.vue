<template>
	<NcPopover
		:shown="open"
		popupRole="dialog"
		placement="bottom-end"
		:setReturnFocus="returnFocus"
		@update:shown="onShown">
		<template #trigger>
			<span class="vbh-tasks-anchor">
				<NcButton
					variant="tertiary"
					class="vbh-tasks-trigger"
					:aria-label="triggerLabel"
					:title="triggerLabel">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClipboardCheckOutline" :size="20" />
					</template>
				</NcButton>
				<!-- Nur Handlungsbedarf zaehlt: Hinweise stehen im Flyout, machen
				     aber nicht auf sich aufmerksam. Die Zahl steckt schon im
				     Namen des Knopfs, deshalb fuer Screenreader versteckt. -->
				<span
					v-if="actionTotal > 0"
					class="vbh-badge vbh-badge--alert vbh-tasks-count"
					aria-hidden="true">{{ badgeText }}</span>
			</span>
		</template>

		<div class="vbh-tasks-panel" role="dialog" :aria-label="t('Aufgaben')">
			<header class="vbh-tasks-head">
				<div>
					<h3 class="vbh-tasks-title">
						{{ t('Aufgaben') }}
					</h3>
					<p v-if="summary" class="vbh-tasks-summary">
						{{ summary }}
					</p>
				</div>
				<!-- Bewusst nie disabled: ein deaktivierter Knopf ist nicht fokussierbar.
				     Waehrend des Ladens waere dann womoeglich gar nichts im Flyout
				     fokussierbar (der Fokusfang des Popovers bricht ab, Escape
				     laeuft ins Leere), und wer den Knopf eben angeklickt hat,
				     verlore den Fokus an den body. aria-busy sagt dasselbe
				     ohne den Fokus zu nehmen. -->
				<NcButton
					variant="tertiary"
					:aria-label="t('Aufgaben aktualisieren')"
					:title="t('Aufgaben aktualisieren')"
					:aria-busy="loading ? 'true' : null"
					@click="loadTasks">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<NcIconSvgWrapper v-else :path="mdiRefresh" :size="20" />
					</template>
				</NcButton>
			</header>

			<div class="vbh-tasks-scroll">
				<div v-if="!loaded && !error" class="vbh-tasks-state" role="status">
					<NcLoadingIcon :size="32" :name="t('Wird geladen…')" />
				</div>

				<NcEmptyContent
					v-else-if="!loaded"
					:name="t('Aufgaben konnten nicht geladen werden')"
					:description="t('Bitte prüfen Sie die Verbindung und versuchen Sie es noch einmal.')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiAlertCircleOutline" class="vbh-tasks-erroricon" />
					</template>
					<template #action>
						<NcButton variant="primary" :aria-busy="loading ? 'true' : null" @click="loadTasks">
							{{ t('Erneut versuchen') }}
						</NcButton>
					</template>
				</NcEmptyContent>

				<template v-else>
					<!-- Die Liste ist da, nur die Aktualisierung klappte nicht: den
					     letzten Stand stehen lassen und das sagen, statt ihn durch
					     eine Fehlerseite zu ersetzen. -->
					<p v-if="error" class="vbh-tasks-banner" role="alert">
						{{ t('Die Liste konnte nicht aktualisiert werden. Angezeigt wird der zuletzt geladene Stand.') }}
					</p>

					<NcEmptyContent
						v-if="!tasks.length"
						:name="t('Nichts zu tun')"
						:description="t('Es gibt gerade keine Aufgaben und Hinweise.')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCheckCircleOutline" class="vbh-tasks-okicon" />
						</template>
					</NcEmptyContent>

					<section v-for="group in groups" :key="group.key" class="vbh-tasks-group">
						<h4 class="vbh-tasks-grouptitle">
							{{ group.title }}
							<span class="vbh-badge" :class="{ 'vbh-badge--alert': group.key === 'action' }">{{ group.items.length }}</span>
						</h4>
						<ul class="vbh-tasks-list">
							<li
								v-for="task in group.items"
								:key="task.id"
								class="vbh-tasks-item"
								:class="'vbh-tasks-item--' + group.key">
								<NcIconSvgWrapper :path="group.icon" :size="20" class="vbh-tasks-icon" />
								<div class="vbh-tasks-body">
									<span class="vbh-typetag">{{ kindLabel(task) }}</span>
									<p :id="messageId(task)" class="vbh-tasks-message">
										{{ task.message }}
									</p>
								</div>
								<NcButton
									v-if="targetOf(task)"
									variant="secondary"
									size="small"
									class="vbh-tasks-goto"
									:aria-describedby="messageId(task)"
									@click="go(task)">
									{{ targetLabelOf(task) }}
								</NcButton>
							</li>
						</ul>
					</section>
				</template>
			</div>
		</div>
	</NcPopover>
</template>

<script>
import { mdiAlertCircle, mdiAlertCircleOutline, mdiCheckCircleOutline, mdiClipboardCheckOutline, mdiInformation, mdiRefresh } from '@mdi/js'
import { NcButton, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon, NcPopover } from '@nextcloud/vue'
import { toRefs } from 'vue'
import { useTasks } from '../composables/useTasks.js'
import { SEVERITY_ACTION, targetLabel, taskKindLabel, taskTarget } from '../lib/tasks.js'

/**
 * Aufgaben-Flyout in der Kopfzeile (Spec §6/§7, Issue #99): ein Knopf mit
 * Badge, von jedem Reiter aus erreichbar, und darunter die abgeleitete
 * Aufgabenliste des Servers - zwei Schweregrade (Handlungsbedarf/Hinweis),
 * kein Quittieren: eine Aufgabe verschwindet von selbst, sobald ihre Ursache
 * behoben ist (useTasks.js hält die Liste dafür aktuell).
 *
 * Das Badge zählt nur Handlungsbedarf. Den Text liefert der Server fertig
 * (deutsch, aggregierte Aufgaben wie „N Überweiser-Forderungen überfällig"
 * kommen bereits als eine Zeile); hier kommen nur die Marke des Bereichs und
 * der Sprung dazu. Der Sprung geht über `navigate` an App.vue, die den
 * Reiter wechselt - diese Komponente weiß nichts von der Navigation.
 *
 * Sichtbar ab Buchhalter, solange das Beitragsmodul genutzt wird (App.vue):
 * alle Aufgaben stammen heute daraus, und die Meldungen nennen Mitglieder.
 */
export default {
	name: 'TasksFlyout',
	components: { NcButton, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon, NcPopover },

	emits: ['navigate'],

	setup() {
		const source = useTasks()
		return {
			...toRefs(source.state),
			actionTotal: source.actionTotal,
			hintTotal: source.hintTotal,
			loadTasks: source.loadTasks,
			startAutoRefresh: source.startAutoRefresh,
		}
	},

	data() {
		return {
			open: false,
			// Wahr, solange das Flyout wegen eines Sprungs schliesst: der Fokus
			// soll dann nicht zum Knopf zurueck, sondern bleibt bei dem, was der
			// Sprung oeffnet (die Akte als Dialog).
			leaving: false,
			stopRefresh: null,
			mdiAlertCircleOutline,
			mdiCheckCircleOutline,
			mdiClipboardCheckOutline,
			mdiRefresh,
		}
	},

	computed: {
		triggerLabel() {
			return this.actionTotal > 0
				? this.n('Aufgaben – %n mit Handlungsbedarf', 'Aufgaben – %n mit Handlungsbedarf', this.actionTotal)
				: this.t('Aufgaben')
		},

		badgeText() {
			return this.actionTotal > 99 ? '99+' : String(this.actionTotal)
		},

		/** Eine Zeile unter der Ueberschrift: wie viel von welcher Sorte da ist. */
		summary() {
			if (!this.loaded || !this.tasks.length) { return '' }
			const parts = []
			if (this.actionTotal > 0) {
				parts.push(this.n('%n mit Handlungsbedarf', '%n mit Handlungsbedarf', this.actionTotal))
			}
			if (this.hintTotal > 0) {
				parts.push(this.n('%n Hinweis', '%n Hinweise', this.hintTotal))
			}
			return parts.join(' · ')
		},

		groups() {
			const action = this.tasks.filter((task) => task.severity === SEVERITY_ACTION)
			const hints = this.tasks.filter((task) => task.severity !== SEVERITY_ACTION)
			return [
				{ key: 'action', title: this.t('Handlungsbedarf'), icon: mdiAlertCircle, items: action },
				{ key: 'hint', title: this.t('Hinweise'), icon: mdiInformation, items: hints },
			].filter((group) => group.items.length > 0)
		},
	},

	watch: {
		// Escape auf Dokumentebene, solange das Flyout offen ist. Das Popover
		// schliesst auf Escape nur, wenn der Fokus *im* Popover liegt (floating-vue
		// hoert auf keyup am Popover-Element). Das ist im ersten Augenblick nach
		// dem Oeffnen nicht so - der Fokus sitzt noch am Ausloeser, bis der
		// Fokusfang des Popovers nachzieht - und wird auch spaeter nicht
		// garantiert, sobald der fokussierte Knopf aus dem DOM verschwindet.
		// Synchron, damit der Handler schon steht, wenn der Dialog sichtbar wird.
		// Liegt der Fokus im Popover, haelt NcPopover das keydown selbst an
		// (stopPropagation): dann schliesst dessen eigener Weg, dieser hier
		// bleibt stumm - beide enden im selben Zustand.
		open: {
			handler(open) {
				if (open) {
					document.addEventListener('keydown', this.onKeydown)
				} else {
					document.removeEventListener('keydown', this.onKeydown)
				}
			},

			flush: 'sync',
		},
	},

	mounted() {
		this.stopRefresh = this.startAutoRefresh()
	},

	beforeUnmount() {
		document.removeEventListener('keydown', this.onKeydown)
		if (this.stopRefresh) { this.stopRefresh() }
	},

	methods: {
		kindLabel: taskKindLabel,

		targetOf: taskTarget,

		targetLabelOf(task) {
			return targetLabel(taskTarget(task))
		},

		messageId(task) {
			return `vbh-task-message-${task.id}`
		},

		/** Beim Oeffnen frisch laden: der Stand kann seit dem letzten Poll veraltet sein. */
		onShown(shown) {
			this.open = shown
			if (shown) {
				this.leaving = false
				this.loadTasks()
			}
		},

		go(task) {
			const target = taskTarget(task)
			if (!target) { return }
			this.leaving = true
			this.open = false
			this.$emit('navigate', target)
		},

		onKeydown(event) {
			if (event.key === 'Escape') { this.open = false }
		},

		/** setReturnFocus des Popovers: Fokus zurueck zum Knopf, ausser nach einem Sprung. */
		returnFocus(previouslyFocused) {
			return this.leaving ? false : previouslyFocused
		},
	},
}
</script>

<style scoped>
.vbh-tasks-anchor {
	position: relative;
	display: inline-flex;
}

.vbh-tasks-count {
	position: absolute;
	inset-block-start: -3px;
	inset-inline-end: -3px;
	min-width: 18px;
	padding: 0 5px;
	line-height: 18px;
	text-align: center;
	font-size: 0.75em;
	pointer-events: none;
}

.vbh-tasks-panel {
	display: flex;
	flex-direction: column;
	width: min(440px, calc(100vw - 24px));
	max-height: min(70vh, 560px);
	color: var(--color-main-text);
}

.vbh-tasks-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	flex: 0 0 auto;
	padding: 8px 8px 8px 16px;
	border-bottom: 1px solid var(--color-border);
}

.vbh-tasks-title {
	margin: 0;
	font-size: 1.1em;
}

.vbh-tasks-summary {
	margin: 2px 0 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.vbh-tasks-scroll {
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
	overscroll-behavior: contain;
	padding: 4px 12px 12px;
}

.vbh-tasks-state {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.vbh-tasks-okicon {
	color: var(--color-element-success);
}

.vbh-tasks-erroricon {
	color: var(--color-element-error);
}

.vbh-tasks-banner {
	margin: 8px 0 0;
	padding: 8px 12px;
	border: 1px solid var(--color-element-warning);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-warning);
	color: var(--color-warning-text);
	font-size: 0.9em;
}

.vbh-tasks-grouptitle {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 14px 4px 6px;
	font-size: 0.9em;
	font-weight: 700;
}

.vbh-tasks-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.vbh-tasks-item {
	display: grid;
	grid-template-columns: auto minmax(0, 1fr) auto;
	align-items: start;
	gap: 4px 10px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-inline-start-width: 4px;
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-main-background);
}

/* Schweregrad nie allein ueber die Farbe: Symbol, Gruppenueberschrift und
   Zaehler tragen ihn ebenfalls. Linie und Symbol nehmen die Element-Farben
   (auf normalem Grund lesbar), nie die Flaechenfarben. */
.vbh-tasks-item--action {
	border-inline-start-color: var(--color-element-error);
}

.vbh-tasks-item--action .vbh-tasks-icon {
	color: var(--color-element-error);
}

.vbh-tasks-item--hint {
	border-inline-start-color: var(--color-element-info, var(--color-primary-element));
}

.vbh-tasks-item--hint .vbh-tasks-icon {
	color: var(--color-element-info, var(--color-primary-element));
}

.vbh-tasks-icon {
	margin-top: 1px;
}

.vbh-tasks-message {
	margin: 4px 0 0;
	overflow-wrap: anywhere;
}

/* Schmale Displays: der Sprungknopf rutscht unter den Text, sonst bleibt dem
   Text neben Symbol und Knopf kaum ein Drittel der Breite. */
@media (max-width: 640px) {
	.vbh-tasks-item {
		grid-template-columns: auto minmax(0, 1fr);
	}

	.vbh-tasks-goto {
		grid-column: 2;
		justify-self: start;
	}
}
</style>
