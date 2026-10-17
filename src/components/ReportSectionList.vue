<template>
	<div class="vbh-seclist">
		<fieldset
			v-for="(section, i) in sections"
			:key="i"
			class="vbh-sec"
			:class="{ 'vbh-sec--open': open.has(i) }">
			<legend class="vbh-sec-legend">
				<button
					:ref="'toggle' + i"
					type="button"
					class="vbh-sec-toggle"
					:aria-expanded="open.has(i) ? 'true' : 'false'"
					@click="toggle(i)">
					<NcIconSvgWrapper :path="open.has(i) ? mdiChevronDown : mdiChevronRight" :size="18" />
					<span>{{ section.title || t('Abschnitt ohne Überschrift') }}</span>
					<span class="vbh-sec-kind">{{ summaryText(section) }}</span>
				</button>
			</legend>
			<div class="vbh-sec-tools">
				<NcButton
					:ref="'up' + i"
					variant="tertiary"
					size="small"
					:disabled="i === 0"
					:aria-label="tRaw('{title} nach oben', { title: section.title || t('Abschnitt') })"
					@click="move(i, -1)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowUp" :size="18" />
					</template>
				</NcButton>
				<NcButton
					:ref="'down' + i"
					variant="tertiary"
					size="small"
					:disabled="i === sections.length - 1"
					:aria-label="tRaw('{title} nach unten', { title: section.title || t('Abschnitt') })"
					@click="move(i, 1)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowDown" :size="18" />
					</template>
				</NcButton>
				<NcButton variant="tertiary" size="small" @click="insertAfter(i)">
					{{ t('Abschnitt danach') }}
				</NcButton>
				<NcButton
					variant="tertiary"
					size="small"
					:aria-label="tRaw('{title} löschen', { title: section.title || t('Abschnitt') })"
					@click="remove(i)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDelete" :size="18" />
					</template>
				</NcButton>
			</div>

			<div v-if="open.has(i)" class="vbh-sec-body">
				<div class="vbh-form">
					<label class="vbh-grow">{{ t('Überschrift') }}
						<input :value="section.title" maxlength="255" @input="patch(i, { title: $event.target.value })">
					</label>
				</div>

				<div class="vbh-form vbh-sec-filter">
					<fieldset class="vbh-fieldset-reset">
						<legend class="vbh-legend">
							{{ t('Kontoart') }}
						</legend>
						<div class="vbh-checkrow">
							<NcCheckboxRadioSwitch
								v-for="type in TYPES"
								:key="type"
								:modelValue="typeChecked(section, type)"
								@update:modelValue="setType(i, type, $event)">
								{{ typeName(type) }}
							</NcCheckboxRadioSwitch>
						</div>
					</fieldset>
					<div class="vbh-grow">
						<NcSelect
							:modelValue="selectedOptions(sphereOptions, section.filter.spheres)"
							:options="sphereOptions"
							:multiple="true"
							label="label"
							:inputLabel="t('Sphären')"
							:placeholder="t('alle')"
							@update:modelValue="setFilter(i, 'spheres', $event)" />
					</div>
					<div class="vbh-grow">
						<NcSelect
							:modelValue="selectedOptions(groupOptions, section.filter.groups)"
							:options="groupOptions"
							:multiple="true"
							label="label"
							:inputLabel="t('Gruppen')"
							:placeholder="t('alle')"
							@update:modelValue="setFilter(i, 'groups', $event)" />
					</div>
				</div>

				<div class="vbh-form">
					<NcCheckboxRadioSwitch :modelValue="section.hideEmpty" @update:modelValue="patch(i, { hideEmpty: $event })">
						{{ t('Ausblenden, wenn leer') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch :modelValue="section.summary" @update:modelValue="patch(i, { summary: $event })">
						{{ t('Nur Zusammenfassung (zählt nicht zur Gesamtsumme)') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div class="vbh-form">
					<NcCheckboxRadioSwitch :modelValue="section.footer !== null" @update:modelValue="patch(i, { footer: $event ? { label: '' } : null })">
						{{ t('Summenzeile') }}
					</NcCheckboxRadioSwitch>
					<label v-if="section.footer" class="vbh-grow">{{ t('Bezeichnung') }}
						<input :value="section.footer.label" maxlength="255" @input="patch(i, { footer: { label: $event.target.value } })">
					</label>
					<span v-if="section.footer" class="vbh-hint vbh-sec-footerkind">{{ footerHint(typesOf(section)) }}</span>
				</div>

				<fieldset class="vbh-fieldset-reset vbh-sec-content">
					<legend class="vbh-legend">
						{{ t('Inhalt') }}
					</legend>
					<div class="vbh-checkrow">
						<NcCheckboxRadioSwitch
							type="radio"
							:name="'content-' + uid + '-' + i"
							:modelValue="section.levels !== null ? 'levels' : 'sections'"
							value="levels"
							@update:modelValue="setContent(i, 'levels')">
							{{ t('Gliederungsebenen') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							type="radio"
							:name="'content-' + uid + '-' + i"
							:modelValue="section.levels !== null ? 'levels' : 'sections'"
							value="sections"
							:disabled="depth >= MAX_SECTION_DEPTH"
							:title="depth >= MAX_SECTION_DEPTH ? tRaw('Höchstens {n} Ebenen Abschnitte', {}, { n: MAX_SECTION_DEPTH }) : ''"
							@update:modelValue="setContent(i, 'sections')">
							{{ t('Unterabschnitte') }}
						</NcCheckboxRadioSwitch>
					</div>

					<ol v-if="section.levels !== null" class="vbh-levels">
						<li v-for="(level, j) in section.levels" :key="j" class="vbh-level">
							<div class="vbh-form">
								<label>{{ tRaw('Ebene {n}', {}, { n: j + 1 }) }}
									<select :value="level.by" @change="setLevel(i, j, withLevelBy(level, $event.target.value))">
										<option
											v-for="by in LEVELS"
											:key="by"
											:value="by"
											:disabled="by !== level.by && (usedLevels(section).includes(by) || (by === 'account' && j !== section.levels.length - 1))">
											{{ levelName(by) }}
										</option>
									</select>
								</label>
								<template v-if="level.by === 'account'">
									<label>{{ t('Tiefe') }}
										<select :value="level.depth" @change="setLevel(i, j, { ...level, depth: $event.target.value })">
											<option v-for="d in DEPTHS" :key="d" :value="d">{{ depthName(d) }}</option>
										</select>
									</label>
									<NcCheckboxRadioSwitch :modelValue="level.ancestors" @update:modelValue="setLevel(i, j, { ...level, ancestors: $event })">
										{{ t('Oberkonten als Zwischenüberschrift') }}
									</NcCheckboxRadioSwitch>
								</template>
								<NcCheckboxRadioSwitch :modelValue="level.hideEmpty" @update:modelValue="setLevel(i, j, { ...level, hideEmpty: $event })">
									{{ t('Leere ausblenden') }}
								</NcCheckboxRadioSwitch>
								<span class="vbh-level-tools">
									<NcButton
										variant="tertiary"
										size="small"
										:disabled="j === 0"
										:aria-label="tRaw('Ebene {n} nach oben', {}, { n: j + 1 })"
										@click="moveLevel(i, j, -1)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiArrowUp" :size="18" />
										</template>
									</NcButton>
									<NcButton
										variant="tertiary"
										size="small"
										:disabled="j === section.levels.length - 1"
										:aria-label="tRaw('Ebene {n} nach unten', {}, { n: j + 1 })"
										@click="moveLevel(i, j, 1)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiArrowDown" :size="18" />
										</template>
									</NcButton>
									<NcButton
										variant="tertiary"
										size="small"
										:aria-label="tRaw('Ebene {n} löschen', {}, { n: j + 1 })"
										@click="removeLevel(i, j)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiDelete" :size="18" />
										</template>
									</NcButton>
								</span>
							</div>
							<div v-if="level.by !== 'account' && j < section.levels.length - 1" class="vbh-form">
								<NcCheckboxRadioSwitch :modelValue="level.footer !== null" @update:modelValue="setLevel(i, j, { ...level, footer: $event ? { label: '' } : null })">
									{{ t('Summenzeile je Gruppe') }}
								</NcCheckboxRadioSwitch>
								<label v-if="level.footer" class="vbh-grow">{{ t('Bezeichnung') }}
									<input :value="level.footer.label" maxlength="255" @input="setLevel(i, j, { ...level, footer: { label: $event.target.value } })">
								</label>
							</div>
							<p v-else-if="level.by !== 'account'" class="vbh-hint">
								{{ t('Letzte Ebene: jede Gruppe erscheint als eine Zeile mit ihrem Betrag.') }}
							</p>
						</li>
						<li class="vbh-level-add">
							<NcButton
								variant="secondary"
								size="small"
								:disabled="freeLevels(section).length === 0"
								@click="addLevel(i)">
								{{ t('Ebene hinzufügen') }}
							</NcButton>
						</li>
					</ol>

					<ReportSectionList
						v-else
						:sections="section.sections"
						:depth="depth + 1"
						:parentTypes="typesOf(section)"
						:groupOptions="groupOptions"
						@update:sections="patch(i, { sections: $event })" />
				</fieldset>
			</div>
		</fieldset>

		<NcButton variant="secondary" @click="insertAfter(sections.length - 1)">
			{{ depth === 1 ? t('Abschnitt hinzufügen') : t('Unterabschnitt hinzufügen') }}
		</NcButton>
	</div>
</template>

<script>
import { mdiArrowDown, mdiArrowUp, mdiChevronDown, mdiChevronRight, mdiDelete } from '@mdi/js'
import { NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcSelect } from '@nextcloud/vue'
import { useConfirm } from '../composables/useConfirm.js'
import {
	DEPTHS,
	footerKind,
	levelName,
	LEVELS,
	MAX_SECTION_DEPTH,
	moved,
	narrow,
	newLevel,
	newSection,
	sphereName,
	SPHERES,
	typeName,
	TYPES,
	withLevelBy,
} from '../lib/reportDefinition.js'

let nextUid = 0

/**
 * Abschnitte einer Gliederungsbericht-Vorlage bearbeiten – rekursiv, ein
 * Abschnitt kann wieder Abschnitte enthalten.
 *
 * Verschoben wird mit ↑/↓ statt per Ziehen: so geht es mit Tastatur und auf
 * dem Handy ohne Sonderfall. Nach dem Verschieben bleibt der Fokus auf dem
 * Knopf, mit dem verschoben wurde.
 *
 * Die Komponente ändert nichts an ihren Props, sondern meldet jede Änderung
 * als neue Liste (`update:sections`).
 */
export default {
	name: 'ReportSectionList',
	components: { NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcSelect },
	props: {
		sections: { type: Array, required: true },
		/** Verschachtelungstiefe dieser Liste, oberste = 1 */
		depth: { type: Number, default: 1 },
		/** Wirksame Kontoarten der Abschnitte darüber, null = beide */
		parentTypes: { type: Array, default: null },
		/** Auswählbare Berichtsgruppen [{id, label}] */
		groupOptions: { type: Array, default: () => [] },
	},

	emits: ['update:sections'],

	setup() {
		return { askConfirm: useConfirm().askConfirm }
	},

	data() {
		return {
			mdiArrowDown,
			mdiArrowUp,
			mdiChevronDown,
			mdiChevronRight,
			mdiDelete,
			TYPES,
			LEVELS,
			DEPTHS,
			MAX_SECTION_DEPTH,
			uid: ++nextUid,
			// Aufgeklappte Abschnitte (Index); eine einzelne Vorlage hat selten mehr als zehn
			open: new Set(),
			sphereOptions: SPHERES.map((s) => ({ id: s, label: sphereName(s) })),
		}
	},

	methods: {
		typeName,
		levelName,
		withLevelBy,

		emit(list) {
			this.$emit('update:sections', list)
		},

		patch(i, change) {
			const list = this.sections.slice()
			list[i] = { ...list[i], ...change }
			this.emit(list)
		},

		toggle(i) {
			const open = new Set(this.open)
			if (open.has(i)) { open.delete(i) } else { open.add(i) }
			this.open = open
		},

		move(i, delta) {
			const target = i + delta
			this.emit(moved(this.sections, i, delta))
			// Der Klappzustand wandert mit
			const open = new Set()
			for (const k of this.open) {
				open.add(k === i ? target : (k === target ? i : k))
			}
			this.open = open
			this.$nextTick(() => {
				const ref = this.$refs[(delta < 0 ? 'up' : 'down') + target]
				const button = Array.isArray(ref) ? ref[0] : ref
				const el = button?.$el ?? button
				// Am Rand ist der Knopf jetzt gesperrt: dann der andere
				if (el && !el.disabled) { el.focus() } else { this.$refs['toggle' + target]?.[0]?.focus() }
			})
		},

		insertAfter(i) {
			const list = this.sections.slice()
			list.splice(i + 1, 0, newSection())
			this.emit(list)
			this.open = new Set([...[...this.open].map((k) => (k > i ? k + 1 : k)), i + 1])
		},

		async remove(i) {
			const title = this.sections[i].title || this.t('Abschnitt ohne Überschrift')
			if (!await this.askConfirm(this.t('Abschnitt löschen'), this.tRaw('Abschnitt „{title}“ samt Inhalt löschen?', { title }))) { return }
			const list = this.sections.slice()
			list.splice(i, 1)
			this.emit(list)
			this.open = new Set([...this.open].filter((k) => k !== i).map((k) => (k > i ? k - 1 : k)))
		},

		summaryText(section) {
			const parts = []
			const types = section.filter.types
			if (types && types.length === 1) { parts.push(typeName(types[0])) }
			if (section.filter.spheres) { parts.push(section.filter.spheres.map(sphereName).join(', ')) }
			if (section.levels) {
				parts.push(section.levels.map((l) => levelName(l.by)).join(' › '))
			} else {
				parts.push(this.n('{count} Unterabschnitt', '{count} Unterabschnitte', section.sections.length, { count: section.sections.length }))
			}
			return parts.join(' · ')
		},

		// --- Filter ----------------------------------------------------------

		typesOf(section) {
			return narrow(this.parentTypes, section.filter.types)
		},

		typeChecked(section, type) {
			return section.filter.types === null || section.filter.types.includes(type)
		},

		setType(i, type, checked) {
			const current = this.sections[i].filter.types ?? TYPES.slice()
			let next = checked ? [...new Set([...current, type])] : current.filter((x) => x !== type)
			// Beide oder keine angehakt heißt: keine Einschränkung
			if (next.length === 0 || next.length === TYPES.length) { next = null }
			this.patch(i, { filter: { ...this.sections[i].filter, types: next } })
		},

		selectedOptions(options, ids) {
			return ids ? options.filter((o) => ids.includes(o.id)) : []
		},

		setFilter(i, key, selected) {
			const ids = (selected || []).map((o) => o.id)
			this.patch(i, { filter: { ...this.sections[i].filter, [key]: ids.length ? ids : null } })
		},

		footerHint(types) {
			return footerKind(types) === 'result'
				? this.t('Zeigt das Ergebnis: Einnahmen − Ausgaben.')
				: this.t('Zeigt die Summe.')
		},

		// --- Inhalt ----------------------------------------------------------

		async setContent(i, kind) {
			const section = this.sections[i]
			const current = section.levels !== null ? 'levels' : 'sections'
			if (kind === current) { return }
			const losing = kind === 'levels' ? section.sections.length > 0 : section.levels.length > 0
			if (losing && !await this.askConfirm(
				this.t('Inhalt umstellen'),
				kind === 'levels'
					? this.t('Die Unterabschnitte dieses Abschnitts gehen dabei verloren.')
					: this.t('Die Gliederungsebenen dieses Abschnitts gehen dabei verloren.'),
				this.t('Umstellen'),
				'primary',
			)) { return }
			this.patch(i, kind === 'levels'
				? { sections: null, levels: [newLevel('account')] }
				: { levels: null, sections: [newSection()] })
		},

		usedLevels(section) {
			return section.levels.map((l) => l.by)
		},

		freeLevels(section) {
			const used = this.usedLevels(section)
			return LEVELS.filter((by) => !used.includes(by))
		},

		depthName(d) {
			if (d === 'plan') { return this.t('Bis zur Planungsebene') }
			if (d === 'all') { return this.t('Alle Ebenen') }
			return this.tRaw('Bis Ebene {n}', {}, { n: Number(d) + 1 })
		},

		setLevel(i, j, level) {
			const levels = this.sections[i].levels.slice()
			levels[j] = level
			this.patch(i, { levels })
		},

		moveLevel(i, j, delta) {
			this.patch(i, { levels: moved(this.sections[i].levels, j, delta) })
		},

		removeLevel(i, j) {
			const levels = this.sections[i].levels.slice()
			levels.splice(j, 1)
			this.patch(i, { levels })
		},

		// Neue Ebenen kommen vor eine vorhandene Konto-Ebene, damit „Konto"
		// die letzte bleibt.
		addLevel(i) {
			const section = this.sections[i]
			const free = this.freeLevels(section).filter((by) => by !== 'account')
			const levels = section.levels.slice()
			const last = levels[levels.length - 1]
			if (free.length === 0) {
				levels.push(newLevel('account'))
			} else if (last && last.by === 'account') {
				levels.splice(levels.length - 1, 0, newLevel(free[0]))
			} else {
				levels.push(newLevel(free[0]))
			}
			this.patch(i, { levels })
		},
	},
}
</script>

<style scoped>
.vbh-seclist { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }

.vbh-sec { width: 100%; border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 12px); padding: 4px 10px 8px; margin: 0; }

.vbh-sec--open { border-color: var(--color-border-dark); }

.vbh-sec-legend { padding: 0 4px; }

.vbh-sec-toggle { display: inline-flex; align-items: center; gap: 6px; border: none; background: transparent; color: inherit; font-weight: 600; cursor: pointer; padding: 2px 4px; min-height: 0; margin: 0; }

.vbh-sec-toggle:hover, .vbh-sec-toggle:focus-visible { background: var(--color-background-hover); }

.vbh-sec-kind { font-weight: 400; color: var(--color-text-maxcontrast); font-size: 0.9em; }

.vbh-sec-tools { display: flex; flex-wrap: wrap; gap: 2px; }

.vbh-sec-body { display: flex; flex-direction: column; gap: 6px; margin-top: 6px; }

.vbh-sec-filter { align-items: flex-start; }

.vbh-sec-footerkind { align-self: center; }

.vbh-sec-content { display: flex; flex-direction: column; gap: 6px; }

.vbh-levels { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }

.vbh-level { border-inline-start: 3px solid var(--color-border); padding-inline-start: 8px; }

.vbh-level-tools { display: inline-flex; gap: 2px; align-self: flex-end; }
</style>
