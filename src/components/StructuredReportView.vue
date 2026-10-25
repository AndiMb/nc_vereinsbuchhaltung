<template>
	<div class="vbh-structured">
		<div class="vbh-sectionhead">
			<h4>{{ t('Gliederungsbericht') }}{{ report ? ' ' + report.period.label : '' }}</h4>
			<div class="vbh-structured-actions">
				<a
					v-if="selected"
					:href="printUrl"
					target="_blank"
					rel="noopener"
					class="vbh-export-btn"
					:title="t('Druckfertiger Gliederungsbericht (öffnet in neuem Tab, dort drucken oder als PDF speichern)')"><NcIconSvgWrapper :path="mdiPrinter" :size="16" inline /> {{ t('Drucken / PDF') }}</a>
				<a
					v-if="selected"
					:href="csvUrl"
					download
					class="vbh-export-btn"
					:title="t('Gliederungsbericht als CSV exportieren')"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> {{ t('CSV') }}</a>
			</div>
		</div>
		<p class="vbh-hint">
			{{ t('Derselbe Plan und dieselben Buchungen, gegliedert nach einer Vorlage – etwa für einen Zuwendungsgeber oder für die Mitgliederversammlung. Die Vorlage legt fest, nach welchen Merkmalen gegliedert wird und welche Spalten erscheinen.') }}
		</p>

		<div class="vbh-structured-toolbar">
			<div v-if="templates.length" class="vbh-structured-picker">
				<NcSelect
					:modelValue="selectedOption"
					:options="templateOptions"
					:clearable="false"
					label="label"
					:inputLabel="t('Vorlage')"
					@update:modelValue="selectTemplate($event && $event.id)" />
			</div>
			<template v-if="canWrite">
				<NcButton variant="secondary" @click="openNew">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" :size="18" />
					</template>
					{{ t('Neue Vorlage') }}
				</NcButton>
				<template v-if="selected">
					<NcButton variant="secondary" @click="openCopy">
						{{ t('Kopieren und anpassen') }}
					</NcButton>
					<NcButton variant="secondary" @click="openEdit">
						{{ t('Bearbeiten') }}
					</NcButton>
					<NcButton variant="tertiary" @click="remove">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDelete" :size="18" />
						</template>
						{{ t('Löschen') }}
					</NcButton>
				</template>
				<NcActions :menuName="t('Weitere Aktionen')" :forceMenu="true">
					<NcActionButton :closeAfterClick="true" @click="addStandard">
						<template #icon>
							<NcIconSvgWrapper :path="mdiFileRestore" :size="18" />
						</template>
						{{ t('Standardvorlagen hinzufügen') }}
					</NcActionButton>
				</NcActions>
			</template>
		</div>

		<NcEmptyContent
			v-if="loaded && !templates.length"
			:name="t('Noch keine Vorlage')"
			:description="canWrite
				? t('Legen Sie eine neue Vorlage an oder holen Sie die beiden Standardvorlagen zurück („Haushaltsplan nach Titelgruppen“ und „Ergebnis je Bereich und Projekt“).')
				: t('Für den Gliederungsbericht ist noch keine Vorlage angelegt.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiFileTreeOutline" :size="48" />
			</template>
			<template v-if="canWrite" #action>
				<div class="vbh-structured-empty-actions">
					<NcButton variant="primary" @click="openNew">
						{{ t('Neue Vorlage') }}
					</NcButton>
					<NcButton variant="secondary" @click="addStandard">
						{{ t('Standardvorlagen hinzufügen') }}
					</NcButton>
				</div>
			</template>
		</NcEmptyContent>

		<NcNoteCard v-if="report && selected && !report.hasSpheres" type="info">
			<p>{{ t('Noch kein Einnahmen- oder Ausgabenkonto hat eine Sphäre. Die Standardvorlagen trennen danach den ideellen Bereich von den Projekten – bis dahin steht alles unter „Ohne Sphäre“.') }}</p>
			<NcButton v-if="canWrite" variant="secondary" @click="$emit('open-spheres')">
				{{ t('Sphären zuordnen') }}
			</NcButton>
		</NcNoteCard>
		<NcNoteCard v-for="w in (report && selected ? report.warnings : [])" :key="w.code" type="warning">
			{{ w.message }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading && !report" :size="32" />
		<div v-else-if="report && selected" class="vbh-tablecard">
			<table class="vbh-table vbh-structured-table">
				<thead>
					<tr>
						<th class="nowrap vbh-col-hide-sm vbh-structured-nr">
							{{ t('Nr.') }}
						</th>
						<th>{{ t('Bezeichnung') }}</th>
						<th v-for="(c, i) in report.columns" :key="i" class="num">
							{{ c.label }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, r) in report.rows" :key="r" :class="rowClass(row)">
						<template v-if="row.kind === 'heading' || row.kind === 'note'">
							<td class="vbh-col-hide-sm" />
							<td :colspan="report.columns.length + 1" :style="indent(row)">
								{{ row.label }}
							</td>
						</template>
						<template v-else>
							<td class="nowrap vbh-col-hide-sm">
								{{ row.number || '' }}
							</td>
							<td :style="indent(row)">
								{{ row.label }}
								<span v-if="hasFlag(row, 'inactive')" class="vbh-pill">{{ t('nicht mehr aktiv') }}</span>
								<span v-if="hasFlag(row, 'duplicate')" class="vbh-pill vbh-pill--warn">{{ t('doppelt gezählt') }}</span>
							</td>
							<td
								v-for="(v, i) in row.values"
								:key="i"
								class="num nowrap"
								:class="valueClass(row, i, v)">
								{{ v === null ? '–' : money(v) }}
							</td>
						</template>
					</tr>
				</tbody>
			</table>
		</div>

		<ReportTemplateDialog
			v-if="editor"
			:template="editor.template"
			:isNew="editor.isNew"
			:costCenterGroups="groups"
			@close="editor = null"
			@saved="onSaved" />
	</div>
</template>

<script>
import { mdiDelete, mdiDownload, mdiFileRestore, mdiFileTreeOutline, mdiPlus, mdiPrinter } from '@mdi/js'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { toRefs } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ReportTemplateDialog from './ReportTemplateDialog.vue'
import api from '../api.js'
import { useAuth } from '../composables/useAuth.js'
import { useConfirm } from '../composables/useConfirm.js'
import { usePeriods } from '../composables/usePeriods.js'
import { errMsg, formatMoney, trendClass } from '../lib/format.js'
import { starterDefinition } from '../lib/reportDefinition.js'

const STORAGE_KEY = 'vbh.structuredReport.template'

/**
 * Gliederungsbericht: eine Vorlage für den gewählten Zeitraum, mit Druck- und
 * CSV-Ausgabe. Lädt seine Daten selbst – Vorlagen und Bericht hängen an
 * keinem anderen Bericht, also gibt es nichts mit App.vue zu teilen.
 * `refreshKey` erhöht App.vue, wenn andere Personen etwas geändert haben.
 */
export default {
	name: 'StructuredReportView',
	components: { NcActionButton, NcActions, NcButton, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon, NcNoteCard, NcSelect, ReportTemplateDialog },
	props: {
		refreshKey: { type: Number, default: 0 },
	},

	emits: ['open-spheres'],

	setup() {
		const auth = useAuth()
		const periods = usePeriods()
		return {
			canWrite: auth.canWrite,
			...toRefs(periods.state),
			askConfirm: useConfirm().askConfirm,
		}
	},

	data() {
		return {
			mdiDelete,
			mdiDownload,
			mdiFileRestore,
			mdiFileTreeOutline,
			mdiPlus,
			mdiPrinter,
			templates: [],
			// Erst nach dem ersten Laden zeigt eine leere Liste den Hinweis
			loaded: false,
			groups: [],
			templateId: localStorage.getItem(STORAGE_KEY) || '',
			report: null,
			loading: false,
			// { template, isNew } solange der Editor offen ist
			editor: null,
			// Nur die zuletzt gestellte Anfrage darf das Ergebnis setzen.
			requestNo: 0,
		}
	},

	computed: {
		selected() {
			return this.templates.find((x) => x.id === this.templateId) || null
		},

		templateOptions() {
			return this.templates.map((x) => ({ id: x.id, label: x.name }))
		},

		selectedOption() {
			return this.templateOptions.find((o) => o.id === this.templateId) || null
		},

		printUrl() { return api.gliederungsberichtUrl(this.templateId, this.selectedPeriodId) },
		csvUrl() { return api.gliederungsberichtCsvUrl(this.templateId, this.selectedPeriodId) },
	},

	watch: {
		selectedPeriodId() { this.loadReport() },
		refreshKey() { this.loadTemplates() },
	},

	mounted() {
		this.loadTemplates()
	},

	methods: {
		async loadTemplates() {
			try {
				const { data } = await api.reportTemplates()
				this.templates = data.templates
				this.groups = data.groups
				this.loaded = true
			} catch (e) {
				showError(errMsg(e, this.t('Vorlagen konnten nicht geladen werden')))
				return
			}
			// Eine gemerkte Auswahl, die es nicht mehr gibt – gelöscht oder eine
			// der bis 0.38.0 mitgelieferten Vorlagen („builtin:…“) –, fällt auf
			// die erste Vorlage zurück.
			if (!this.selected) {
				this.templateId = this.templates.length ? this.templates[0].id : ''
			}
			if (!this.templateId) {
				this.report = null
				return
			}
			await this.loadReport()
		},

		async loadReport() {
			if (!this.templateId) { return }
			const no = ++this.requestNo
			this.loading = true
			try {
				const { data } = await api.structuredReport(this.templateId, this.selectedPeriodId)
				if (no === this.requestNo) { this.report = data }
			} catch (e) {
				if (no === this.requestNo) {
					this.report = null
					showError(errMsg(e, this.t('Gliederungsbericht konnte nicht geladen werden')))
				}
			} finally {
				if (no === this.requestNo) { this.loading = false }
			}
		},

		selectTemplate(id) {
			if (!id || id === this.templateId) { return }
			this.templateId = id
			localStorage.setItem(STORAGE_KEY, id)
			this.loadReport()
		},

		openNew() {
			this.editor = {
				isNew: true,
				template: { id: null, name: '', definition: starterDefinition() },
			}
		},

		async addStandard() {
			try {
				const { data } = await api.addStandardReportTemplates()
				showSuccess(this.t('Standardvorlagen hinzugefügt'))
				if (data.duplicateNames.length) {
					showWarning(this.tRaw('Es gibt jetzt mehrere Vorlagen mit dem Namen „{names}“ – zum Unterscheiden eine davon umbenennen.', { names: data.duplicateNames.join('“, „') }))
				}
				if (data.templates.length) {
					this.templateId = data.templates[0].id
					localStorage.setItem(STORAGE_KEY, this.templateId)
				}
				await this.loadTemplates()
			} catch (e) {
				showError(errMsg(e, this.t('Standardvorlagen konnten nicht hinzugefügt werden')))
			}
		},

		openCopy() {
			const source = this.selected
			this.editor = {
				isNew: true,
				template: {
					id: null,
					name: this.tRaw('{name} (Kopie)', { name: source.name }),
					definition: JSON.parse(JSON.stringify(source.definition)),
				},
			}
		},

		openEdit() {
			const source = this.selected
			this.editor = {
				isNew: false,
				template: { id: source.id, name: source.name, definition: JSON.parse(JSON.stringify(source.definition)) },
			}
		},

		async onSaved(template) {
			this.editor = null
			this.templateId = template.id
			localStorage.setItem(STORAGE_KEY, template.id)
			await this.loadTemplates()
		},

		async remove() {
			const source = this.selected
			if (!await this.askConfirm(this.t('Vorlage löschen'), this.tRaw('Vorlage „{name}“ wirklich löschen?', { name: source.name }))) { return }
			try {
				await api.deleteReportTemplate(source.id)
				showSuccess(this.t('Vorlage gelöscht'))
				this.templateId = ''
				localStorage.removeItem(STORAGE_KEY)
				await this.loadTemplates()
			} catch (e) {
				showError(errMsg(e, this.t('Vorlage konnte nicht gelöscht werden')))
			}
		},

		money(cents) {
			return formatMoney(cents / 100)
		},

		indent(row) {
			return { paddingInlineStart: (10 + row.depth * 18) + 'px' }
		},

		hasFlag(row, flag) {
			return (row.flags || []).includes(flag)
		},

		rowClass(row) {
			return {
				'vbh-structured-heading': row.kind === 'heading',
				'vbh-structured-top': row.kind === 'heading' && row.depth === 0,
				'vbh-structured-note': row.kind === 'note',
				'vbh-structured-sum': row.kind === 'footer',
				'vbh-structured-total': row.kind === 'total',
				'vbh-structured-structural': this.hasFlag(row, 'structural'),
			}
		},

		// Gefärbt wird nur die Differenz Ist − Plan: mehr Einnahmen bzw.
		// weniger Ausgaben sind gut, bei einem Ergebnis gilt „mehr" als gut.
		valueClass(row, i, v) {
			if (v === null || this.report.columns[i].source !== 'diff') { return '' }
			return trendClass(row.nature === 'expense' ? 'expense' : 'income', v)
		},
	},
}
</script>

<style scoped>
.vbh-structured-actions { display: flex; flex-wrap: wrap; gap: 8px; }

.vbh-structured-toolbar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 8px; margin: 8px 0 12px; }

.vbh-structured-picker { display: flex; flex-direction: column; gap: 2px; min-width: min(420px, 100%); }

.vbh-structured-table { min-width: 560px; }

.vbh-structured-empty-actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }

.vbh-structured-nr { width: 110px; }

.vbh-structured-table .vbh-structured-heading td { font-weight: 600; background: var(--color-main-background); }

.vbh-structured-table .vbh-structured-top td { font-size: 1.05em; border-bottom: 2px solid var(--color-border-dark); }

.vbh-structured-table .vbh-structured-note td { font-style: italic; color: var(--color-text-maxcontrast); font-size: 0.9em; }

.vbh-structured-table .vbh-structured-sum td { font-weight: 600; border-top: 1px solid var(--color-border-dark); }

.vbh-structured-table .vbh-structured-total td { font-weight: 700; border-top: 2px solid var(--color-border-dark); border-bottom: 2px solid var(--color-border-dark); }

.vbh-structured-table .vbh-structured-structural td { color: var(--color-text-maxcontrast); }

.vbh-pill--warn { background: var(--color-warning); color: var(--color-warning-text); }
</style>
