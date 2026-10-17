<template>
	<NcModal
		:show="true"
		labelId="vbh-modal-title-rtemplate"
		size="large"
		:closeOnClickOutside="false"
		@close="$emit('close')">
		<div class="vbh-modal-inner vbh-rtpl">
			<h2 id="vbh-modal-title-rtemplate" class="vbh-modal-title">
				{{ isNew ? t('Neue Vorlage') : t('Vorlage bearbeiten') }}
			</h2>

			<div class="vbh-rtpl-tabs" role="tablist" :aria-label="t('Bereiche der Vorlage')">
				<button
					v-for="tab in tabs"
					:id="'vbh-rtpl-tab-' + tab.id"
					:key="tab.id"
					type="button"
					role="tab"
					:aria-selected="activeTab === tab.id ? 'true' : 'false'"
					:aria-controls="'vbh-rtpl-panel-' + tab.id"
					:class="{ active: activeTab === tab.id }"
					@click="activeTab = tab.id">
					{{ tab.label }}
				</button>
			</div>

			<NcNoteCard v-if="shownErrors.length" type="error">
				<ul class="vbh-rtpl-errors">
					<li v-for="e in shownErrors" :key="e">
						{{ e }}
					</li>
				</ul>
			</NcNoteCard>

			<!-- Allgemein -->
			<section
				v-show="activeTab === 'general'"
				id="vbh-rtpl-panel-general"
				role="tabpanel"
				aria-labelledby="vbh-rtpl-tab-general">
				<div class="vbh-form">
					<label class="vbh-grow">{{ t('Name') }}
						<input ref="nameInput" v-model="name" maxlength="255">
					</label>
				</div>
				<div class="vbh-form">
					<NcCheckboxRadioSwitch v-model="def.showPlanNotes">
						{{ t('Plannotizen als Erläuterung unter dem Konto') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div class="vbh-form">
					<NcCheckboxRadioSwitch :modelValue="def.grandTotal !== null" @update:modelValue="def.grandTotal = $event ? { label: t('Saldo') } : null">
						{{ t('Gesamtzeile am Ende (Einnahmen − Ausgaben)') }}
					</NcCheckboxRadioSwitch>
					<label v-if="def.grandTotal" class="vbh-grow">{{ t('Bezeichnung') }}
						<input v-model="def.grandTotal.label" maxlength="255">
					</label>
				</div>
			</section>

			<!-- Struktur -->
			<section
				v-show="activeTab === 'structure'"
				id="vbh-rtpl-panel-structure"
				role="tabpanel"
				aria-labelledby="vbh-rtpl-tab-structure"
				class="vbh-rtpl-structure">
				<div class="vbh-rtpl-structure-edit">
					<p class="vbh-hint">
						{{ t('Abschnitte schränken die Konten nach Kontoart, Sphäre oder Gruppe ein und können weitere Abschnitte enthalten. Gliederungsebenen teilen die Konten eines Abschnitts weiter auf; „Konto“ zeigt sie einzeln als Baum.') }}
					</p>
					<ReportSectionList
						:sections="def.sections"
						:groupOptions="filterGroupOptions"
						@update:sections="def.sections = $event" />
				</div>
				<aside class="vbh-rtpl-outline" :aria-label="t('Gliederungsvorschau')">
					<h3>{{ t('Vorschau') }}</h3>
					<ul>
						<li
							v-for="(line, k) in outlineLines"
							:key="k"
							:class="'vbh-rtpl-outline--' + line.kind"
							:style="{ paddingInlineStart: (line.depth * 14) + 'px' }">
							{{ line.text }}
							<span v-if="line.kind === 'sum'" class="vbh-rtpl-outline-hint">Σ</span>
							<span v-else-if="line.kind === 'result'" class="vbh-rtpl-outline-hint">±</span>
						</li>
					</ul>
				</aside>
			</section>

			<!-- Spalten -->
			<section
				v-show="activeTab === 'columns'"
				id="vbh-rtpl-panel-columns"
				role="tabpanel"
				aria-labelledby="vbh-rtpl-tab-columns">
				<ol class="vbh-rtpl-list">
					<li v-for="(col, i) in def.columns" :key="i" class="vbh-form">
						<label>{{ tRaw('Spalte {n}', {}, { n: i + 1 }) }}
							<select v-model="col.source">
								<option v-for="s in SOURCES" :key="s" :value="s">{{ sourceName(s) }}</option>
							</select>
						</label>
						<label>{{ t('Periode') }}
							<select v-model.number="col.periodOffset">
								<option v-for="o in OFFSETS" :key="o" :value="o">{{ offsetName(o) }}</option>
							</select>
						</label>
						<span class="vbh-rtpl-tools">
							<NcButton
								variant="tertiary"
								size="small"
								:disabled="i === 0"
								:aria-label="tRaw('Spalte {n} nach links', {}, { n: i + 1 })"
								@click="def.columns = moved(def.columns, i, -1)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiArrowUp" :size="18" />
								</template>
							</NcButton>
							<NcButton
								variant="tertiary"
								size="small"
								:disabled="i === def.columns.length - 1"
								:aria-label="tRaw('Spalte {n} nach rechts', {}, { n: i + 1 })"
								@click="def.columns = moved(def.columns, i, 1)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiArrowDown" :size="18" />
								</template>
							</NcButton>
							<NcButton
								variant="tertiary"
								size="small"
								:aria-label="tRaw('Spalte {n} löschen', {}, { n: i + 1 })"
								@click="def.columns.splice(i, 1)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiDelete" :size="18" />
								</template>
							</NcButton>
						</span>
					</li>
				</ol>
				<NcButton variant="secondary" :disabled="def.columns.length >= MAX_COLUMNS" @click="def.columns.push({ source: 'actual', periodOffset: 0 })">
					{{ t('Spalte hinzufügen') }}
				</NcButton>
			</section>

			<!-- Gruppen -->
			<section
				v-show="activeTab === 'groups'"
				id="vbh-rtpl-panel-groups"
				role="tabpanel"
				aria-labelledby="vbh-rtpl-tab-groups">
				<p class="vbh-hint">
					{{ t('Ohne Eintrag ist jede Auswertungsgruppe eine eigene Gruppe. Hier lassen sich Gruppen umbenennen, mehrere zusammenfassen oder einzelne Konten (samt Unterkonten) einer anderen Gruppe zuordnen. Ein ausdrücklich zugeordnetes Konto geht vor seiner Auswertungsgruppe.') }}
				</p>
				<ol class="vbh-rtpl-list">
					<li v-for="(g, i) in def.groups" :key="g.key" class="vbh-rtpl-group">
						<div class="vbh-form">
							<label class="vbh-grow">{{ t('Name') }}
								<input v-model="g.label" maxlength="255">
							</label>
							<span class="vbh-rtpl-tools">
								<NcButton
									variant="tertiary"
									size="small"
									:disabled="i === 0"
									:aria-label="tRaw('{name} nach oben', { name: g.label || t('Gruppe') })"
									@click="def.groups = moved(def.groups, i, -1)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiArrowUp" :size="18" />
									</template>
								</NcButton>
								<NcButton
									variant="tertiary"
									size="small"
									:disabled="i === def.groups.length - 1"
									:aria-label="tRaw('{name} nach unten', { name: g.label || t('Gruppe') })"
									@click="def.groups = moved(def.groups, i, 1)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiArrowDown" :size="18" />
									</template>
								</NcButton>
								<NcButton
									variant="tertiary"
									size="small"
									:aria-label="tRaw('{name} löschen', { name: g.label || t('Gruppe') })"
									@click="def.groups.splice(i, 1)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiDelete" :size="18" />
									</template>
								</NcButton>
							</span>
						</div>
						<div class="vbh-form">
							<div class="vbh-grow">
								<NcSelect
									:modelValue="costCenterOptions.filter((o) => g.costCenters.includes(o.id))"
									:options="costCenterOptions"
									:multiple="true"
									label="label"
									:inputLabel="t('Auswertungsgruppen')"
									:placeholder="t('keine')"
									@update:modelValue="g.costCenters = ($event || []).map((o) => o.id)" />
							</div>
							<div class="vbh-grow">
								<NcSelect
									:modelValue="accountOptions.filter((o) => g.accounts.includes(o.id))"
									:options="accountOptions"
									:multiple="true"
									label="label"
									:inputLabel="t('Einzelne Konten')"
									:placeholder="t('keine')"
									@update:modelValue="g.accounts = ($event || []).map((o) => o.id)" />
							</div>
						</div>
					</li>
				</ol>
				<NcButton variant="secondary" @click="def.groups.push(newGroup(def.groups))">
					{{ t('Gruppe hinzufügen') }}
				</NcButton>
			</section>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{ isNew ? t('Anlegen') : t('Speichern') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { mdiArrowDown, mdiArrowUp, mdiDelete } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcModal, NcNoteCard, NcSelect } from '@nextcloud/vue'
import ReportSectionList from './ReportSectionList.vue'
import api from '../api.js'
import { useAccounts } from '../composables/useAccounts.js'
import { errMsg } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'
import {
	GROUP_TEMPLATE,
	MAX_COLUMNS,
	MIN_PERIOD_OFFSET,
	moved,
	newGroup,
	offsetName,
	outline,
	sourceName,
	SOURCES,
	validate,
} from '../lib/reportDefinition.js'

/**
 * Eine Vorlage des Gliederungsberichts anlegen oder ändern.
 *
 * Bearbeitet wird eine tiefe Kopie; erst „Speichern" schickt sie an den
 * Server. Fehler zeigt der Dialog sofort (gleiche Regeln wie der Server, siehe
 * reportDefinition.js), lässt aber trotzdem speichern – die Antwort des
 * Servers ist maßgeblich und erscheint dann an derselben Stelle.
 *
 * Wird nur per v-if eingehängt, solange er offen ist: ein verborgenes NcModal
 * legte seine Fokusfalle schon beim Laden an.
 */
export default {
	name: 'ReportTemplateDialog',
	components: { NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcModal, NcNoteCard, NcSelect, ReportSectionList },
	props: {
		/** { id, name, definition } – id null bei einer neuen Vorlage */
		template: { type: Object, required: true },
		isNew: { type: Boolean, default: false },
		/** Auswertungsgruppen des Kontenrahmens [{ key, code, label }] */
		costCenterGroups: { type: Array, default: () => [] },
	},

	emits: ['close', 'saved'],

	setup() {
		const accounts = useAccounts()
		return { accountsSorted: accounts.accountsSorted }
	},

	data() {
		return {
			mdiArrowDown,
			mdiArrowUp,
			mdiDelete,
			SOURCES,
			MAX_COLUMNS,
			OFFSETS: Array.from({ length: -MIN_PERIOD_OFFSET + 1 }, (_, i) => -i),
			name: this.template.name,
			def: JSON.parse(JSON.stringify(this.template.definition)),
			activeTab: 'general',
			serverErrors: [],
			saving: false,
		}
	},

	computed: {
		tabs() {
			return [
				{ id: 'general', label: this.t('Allgemein') },
				{ id: 'structure', label: this.t('Struktur') },
				{ id: 'columns', label: this.t('Spalten') },
				{ id: 'groups', label: this.t('Gruppen') },
			]
		},

		clientErrors() {
			const errors = validate(this.def)
			if (!this.name.trim()) { errors.unshift(this.t('Die Vorlage braucht einen Namen.')) }
			return errors
		},

		shownErrors() {
			return this.serverErrors.length ? this.serverErrors : this.clientErrors
		},

		outlineLines() {
			return outline(this.def)
		},

		costCenterOptions() {
			return this.costCenterGroups
				.filter((g) => g.code !== null)
				.map((g) => ({ id: g.code, label: g.label }))
		},

		accountOptions() {
			return this.accountsSorted
				.filter((a) => a.type === 'income' || a.type === 'expense')
				.map((a) => ({ id: a.id, label: `${a.number} ${a.name}` }))
		},

		// Filter nach Gruppen: die eigenen der Vorlage und alle Auswertungsgruppen,
		// die die Vorlage nicht schon in eine eigene Gruppe umgehängt hat.
		filterGroupOptions() {
			const taken = new Set(this.def.groups.flatMap((g) => g.costCenters))
			return [
				...this.def.groups.map((g) => ({ id: GROUP_TEMPLATE + g.key, label: g.label || g.key })),
				...this.costCenterGroups
					.filter((g) => g.code === null || !taken.has(g.code))
					.map((g) => ({ id: g.key, label: g.label })),
			]
		},
	},

	watch: {
		def: {
			deep: true,
			handler() { this.serverErrors = [] },
		},

		name() { this.serverErrors = [] },
	},

	mounted() {
		focusOnOpen(this, () => this.$refs.nameInput)
	},

	methods: {
		moved,
		newGroup,
		offsetName,
		sourceName,

		async save() {
			this.saving = true
			try {
				const { data } = this.isNew
					? await api.createReportTemplate(this.name, this.def)
					: await api.updateReportTemplate(this.template.id, this.name, this.def)
				showSuccess(this.t('Vorlage gespeichert'))
				this.$emit('saved', data)
			} catch (e) {
				const errors = e?.response?.data?.errors
				this.serverErrors = Array.isArray(errors) && errors.length ? errors : [errMsg(e, this.t('Vorlage konnte nicht gespeichert werden'))]
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-rtpl { min-height: 60vh; }

.vbh-rtpl-tabs { display: flex; flex-wrap: wrap; gap: 4px; border-bottom: 1px solid var(--color-border); margin-bottom: 12px; }

.vbh-rtpl-tabs button { border: none; border-bottom: 3px solid transparent; border-radius: 0; background: transparent; color: inherit; padding: 6px 12px; margin: 0; min-height: 0; cursor: pointer; }

.vbh-rtpl-tabs button.active { border-bottom-color: var(--color-primary-element); font-weight: 600; }

.vbh-rtpl-tabs button:focus-visible { outline: 2px solid var(--color-primary-element); }

.vbh-rtpl-errors { margin: 0; padding-inline-start: 18px; list-style: disc; }

.vbh-rtpl-structure { display: grid; grid-template-columns: minmax(0, 1fr) 260px; gap: 16px; align-items: start; }

.vbh-rtpl-outline { position: sticky; top: 0; border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 12px); padding: 8px 12px; font-size: 0.9em; }

.vbh-rtpl-outline h3 { font-size: 1em; margin: 0 0 6px; }

.vbh-rtpl-outline ul { list-style: none; margin: 0; padding: 0; }

.vbh-rtpl-outline--section { font-weight: 600; }

.vbh-rtpl-outline--level { color: var(--color-text-maxcontrast); }

.vbh-rtpl-outline--sum, .vbh-rtpl-outline--result { font-style: italic; }

.vbh-rtpl-outline-hint { color: var(--color-text-maxcontrast); margin-inline-start: 4px; }

.vbh-rtpl-list { list-style: none; margin: 0 0 8px; padding: 0; display: flex; flex-direction: column; gap: 8px; }

.vbh-rtpl-group { border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 12px); padding: 6px 10px; }

.vbh-rtpl-tools { display: inline-flex; gap: 2px; align-self: flex-end; }

@media (max-width: 1024px) {
	.vbh-rtpl-structure { grid-template-columns: minmax(0, 1fr); }

	.vbh-rtpl-outline { position: static; }
}
</style>
