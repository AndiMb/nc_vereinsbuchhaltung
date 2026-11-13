<template>
	<div class="vbh-backup">
		<p class="vbh-hint">
			{{ t('Eine Sicherung enthält den gesamten Bestand: Konten, Buchungen, Bankumsätze, Mitglieder, Mandate, Beiträge, Rechnungen, Auslagen, Rollen, Änderungsprotokoll und Einstellungen – auf Wunsch samt Belegen. Sie lässt sich in eine leere Vereinsbuchhaltung derselben Version einspielen, auch auf einer anderen Nextcloud.') }}
		</p>
		<p class="vbh-hint">
			{{ t('Solange eine Sicherung erstellt oder eingespielt wird, sollte niemand in der Buchhaltung arbeiten – sonst hält die Sicherung womöglich einen Zwischenstand fest.') }}
		</p>
		<p v-if="status && !status.zipAvailable" class="vbh-hint vbh-hint--error">
			{{ t('Auf diesem Server fehlt die PHP-Erweiterung zip. Ohne sie lassen sich Sicherungen weder erstellen noch einspielen.') }}
		</p>

		<!-- Erstellen -->
		<h4>{{ t('Sicherung erstellen') }}</h4>
		<div class="vbh-card">
			<p class="vbh-hint">
				{{ t('Die Datei wird mit einem Passwort verschlüsselt, das Sie hier vergeben. Es wird nirgends gespeichert: Ohne das Passwort lässt sich die Sicherung nicht mehr öffnen.') }}
			</p>
			<form class="vbh-form" @submit.prevent="createBackup">
				<label>{{ t('Passwort der Sicherung') }}
					<input
						v-model="exportPassword"
						type="password"
						autocomplete="new-password"
						data-test="backup-export-password">
				</label>
				<label>{{ t('Passwort wiederholen') }}
					<input
						v-model="exportRepeat"
						type="password"
						autocomplete="new-password"
						data-test="backup-export-repeat">
				</label>
			</form>
			<p v-if="exportPassword !== '' && exportProblem === 'short'" class="vbh-warn-inline vbh-warn-inline--block">
				{{ t('Das Passwort braucht mindestens {n} Zeichen.', { n: minPasswordLength }) }}
			</p>
			<p v-else-if="exportRepeat !== '' && exportProblem === 'mismatch'" class="vbh-warn-inline vbh-warn-inline--block">
				{{ t('Die beiden Eingaben stimmen nicht überein.') }}
			</p>
			<NcCheckboxRadioSwitch v-model="withFiles">
				{{ t('Belege und Dokumente mitsichern (Buchungsbelege, Rechnungen, Auslagenbelege, Mandatsnachweise)') }}
			</NcCheckboxRadioSwitch>
			<p v-if="!withFiles" class="vbh-hint">
				{{ t('Ohne Belege ist die Datei klein, im Ziel fehlen dann aber alle Dateien zu Buchungen, Rechnungen und Mandaten.') }}
			</p>
			<div class="vbh-backup-actions">
				<NcButton
					variant="primary"
					:disabled="busy || exportProblem !== ''"
					data-test="backup-export"
					@click="createBackup">
					<template #icon>
						<NcLoadingIcon v-if="exporting" :name="t('Sicherung wird erstellt …')" :size="20" />
						<NcIconSvgWrapper v-else :path="mdiDownload" :size="20" />
					</template>
					{{ t('Sicherung erstellen') }}
				</NcButton>
				<span v-if="exporting" class="vbh-hint">{{ t('Sicherung wird erstellt … Das kann bei vielen Belegen einige Minuten dauern.') }}</span>
			</div>
			<div v-if="exportResult" class="vbh-hint vbh-hint--info" data-test="backup-export-result">
				<p>
					{{ tRaw('Sicherung erstellt: {file} ({size}) – {rows} Datensätze, {files} Dateien. Der Download startet von selbst.', { file: exportResult.fileName, size: formatFileSize(exportResult.size), rows: exportResult.rows, files: exportResult.files }) }}
				</p>
				<template v-if="exportMissing.length">
					<p>{{ t('Diese Dateien waren in der Ablage nicht mehr zu finden und fehlen in der Sicherung:') }}</p>
					<ul class="vbh-backup-list">
						<li v-for="m in exportMissing" :key="m.kind">
							{{ kindLabel(m.kind, m.count) }}<span v-if="m.labels.length"> – {{ m.labels.join(', ') }}<template v-if="m.more > 0"> {{ t('und {n} weitere', { n: m.more }) }}</template></span>
						</li>
					</ul>
				</template>
			</div>
		</div>

		<!-- Einspielen -->
		<h4>{{ t('Sicherung einspielen') }}</h4>
		<div class="vbh-card">
			<p class="vbh-hint">
				{{ t('Einspielen lässt sich eine Sicherung nur in einen leeren Bestand und nur mit derselben Version der Vereinsbuchhaltung, mit der sie erstellt wurde.') }}
			</p>
			<p v-if="status && !status.empty && !importResult" class="vbh-hint vbh-hint--warning" data-test="backup-not-empty">
				{{ t('Diese Buchhaltung enthält bereits Daten. Leeren Sie den Bestand unten vollständig, bevor Sie eine Sicherung einspielen.') }}
			</p>
			<div class="vbh-uploadrow">
				<NcButton variant="secondary" :disabled="busy" @click="$refs.backupInput.click()">
					<template #icon>
						<NcIconSvgWrapper :path="mdiUpload" :size="20" />
					</template>
					{{ t('Datei hochladen') }}
				</NcButton>
				<input
					ref="backupInput"
					type="file"
					accept=".vbhbackup"
					hidden
					data-test="backup-file"
					@change="onFileSelected">
				<NcButton variant="secondary" :disabled="busy" @click="pickFromNextcloud">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFolderOpen" :size="20" />
					</template>
					{{ t('Aus Nextcloud-Dateien wählen') }}
				</NcButton>
				<span class="vbh-filename">{{ sourceLabel }}</span>
			</div>
			<p class="vbh-hint">
				{{ t('Große Sicherungen überschreiten leicht die Upload-Grenze des Servers. Legen Sie die Datei dann in Ihre Nextcloud-Dateien und wählen Sie sie von dort.') }}
			</p>
			<form class="vbh-form" @submit.prevent="inspectBackup">
				<label>{{ t('Passwort der Sicherung') }}
					<input
						v-model="importPassword"
						type="password"
						autocomplete="off"
						data-test="backup-import-password"
						@input="inspection = null">
				</label>
				<NcButton
					variant="secondary"
					:disabled="busy || !hasSource || importPassword === ''"
					data-test="backup-inspect"
					@click="inspectBackup">
					<template #icon>
						<NcLoadingIcon v-if="inspecting" :name="t('Sicherung wird geprüft …')" :size="20" />
					</template>
					{{ t('Sicherung prüfen') }}
				</NcButton>
			</form>

			<div v-if="inspection" class="vbh-preview" data-test="backup-inspection">
				<!-- Kein <dl>: die Einstellungsseite von Nextcloud gestaltet dt/dd selbst. -->
				<div class="vbh-backup-facts">
					<span class="vbh-backup-fact">{{ t('Verein') }}</span>
					<span>{{ inspection.clubName || t('(kein Name eingetragen)') }}</span>
					<span class="vbh-backup-fact">{{ t('Stand') }}</span>
					<span>{{ formatDateTime(inspection.createdAt) }}</span>
					<span class="vbh-backup-fact">{{ t('Version') }}</span>
					<span>{{ inspection.appVersion }}</span>
					<span class="vbh-backup-fact">{{ t('Inhalt') }}</span>
					<span>{{ t('{rows} Datensätze, darunter {accounts} Konten, {bookings} Buchungen, {members} Mitglieder', { rows: inspection.rows, accounts: inspection.counts.vbh_accounts || 0, bookings: inspection.counts.vbh_journal || 0, members: inspection.counts.vbh_members || 0 }) }}</span>
					<span class="vbh-backup-fact">{{ t('Belege und Dokumente') }}</span>
					<span v-if="inspection.withFiles">{{ t('{n} Dateien', { n: inspection.files }) }}<span v-if="inspection.missingFiles > 0"> – {{ t('beim Erstellen fehlten bereits: {n}', { n: inspection.missingFiles }) }}</span></span>
					<span v-else>{{ t('nicht enthalten – die Sicherung wurde ohne Belege erstellt') }}</span>
				</div>
				<p v-if="blocker === 'version'" class="vbh-hint vbh-hint--error" data-test="backup-blocker">
					{{ t('Diese Sicherung stammt aus Version {backup}, installiert ist {installed}. Bringen Sie beide Instanzen auf denselben Stand und erstellen Sie die Sicherung dann neu.', { backup: inspection.appVersion, installed: inspection.installedVersion }) }}
				</p>
				<p v-else-if="blocker === 'notEmpty'" class="vbh-hint vbh-hint--error" data-test="backup-blocker">
					{{ t('Die Buchhaltung ist nicht leer. Eine Sicherung lässt sich nur in einen leeren Bestand einspielen.') }}
				</p>
				<template v-else>
					<p class="vbh-hint">
						{{ t('Beim Einspielen werden Rollen und Änderungsprotokoll dieser Buchhaltung durch die der Sicherung ersetzt. Sie selbst bleiben Verwalter.') }}
					</p>
					<NcButton
						variant="primary"
						:disabled="busy"
						data-test="backup-import"
						@click="importBackup">
						<template #icon>
							<NcLoadingIcon v-if="importing" :name="t('Sicherung wird eingespielt …')" :size="20" />
						</template>
						{{ t('Jetzt einspielen') }}
					</NcButton>
					<span v-if="importing" class="vbh-hint">{{ t('Sicherung wird eingespielt … Bitte lassen Sie dieses Fenster geöffnet.') }}</span>
				</template>
			</div>

			<div v-if="importResult" class="vbh-hint vbh-hint--info" data-test="backup-result">
				<p>
					<strong>{{ t('Die Sicherung ist eingespielt.') }}</strong>
					{{ t('{rows} Datensätze und {files} Dateien übernommen.', { rows: importResult.rows, files: importResult.files }) }}
				</p>
				<template v-if="importMissing.length">
					<p>{{ t('Diese Dateien fehlen – die Datensätze sind da, die Dateien dazu nicht:') }}</p>
					<ul class="vbh-backup-list">
						<li v-for="m in importMissing" :key="m.kind">
							{{ kindLabel(m.kind, m.count) }}<span v-if="m.labels.length"> – {{ m.labels.join(', ') }}<template v-if="m.more > 0"> {{ t('und {n} weitere', { n: m.more }) }}</template></span>
						</li>
					</ul>
				</template>
				<template v-if="importResult.unknownUsers.length || importResult.unknownGroups.length">
					<p>{{ t('Diese Namen aus Rollen und Mitglied-Verknüpfungen gibt es auf dieser Nextcloud nicht. Legen Sie sie an oder vergeben Sie die Rollen unter „Berechtigungen“ neu:') }}</p>
					<ul class="vbh-backup-list">
						<li v-if="importResult.unknownUsers.length">
							{{ tRaw('Benutzer: {names}', { names: importResult.unknownUsers.join(', ') }) }}
						</li>
						<li v-if="importResult.unknownGroups.length">
							{{ tRaw('Gruppen: {names}', { names: importResult.unknownGroups.join(', ') }) }}
						</li>
					</ul>
				</template>
				<p>
					{{ t('Belege und Dokumente liegen jetzt in der app-internen Ablage. Belegablage, Wächter-Ordner und die Ordner für Mandatsnachweise, Rechnungen und SEPA-Dateien gehören zur jeweiligen Nextcloud und sind hier neu einzustellen.') }}
				</p>
				<NcButton variant="primary" data-test="backup-reload" @click="reload">
					{{ t('Seite neu laden') }}
				</NcButton>
			</div>
		</div>

		<!-- Leeren -->
		<div class="vbh-card vbh-card--danger">
			<h4>{{ t('Bestand vollständig leeren') }}</h4>
			<p class="vbh-hint">
				{{ t('Löscht unwiderruflich den gesamten Bestand – auch Mitglieder, Mandate, Beitragsgruppen und die Einstellungen des Vereins – samt der von der App abgelegten Belege und Dokumente. Gedacht als Vorbereitung für das Einspielen einer Sicherung. Bestehen bleiben die Rollen, das Änderungsprotokoll, die Einstellungen zur Ablage sowie alles, was in Ihrem Wächter-Ordner und den Ordnern für Mandatsnachweise und SEPA-Dateien liegt.') }}
			</p>
			<NcButton
				variant="error"
				:disabled="busy || !status || status.empty"
				data-test="backup-wipe"
				@click="openWipe">
				{{ t('Bestand vollständig leeren') }}
			</NcButton>
			<span v-if="status && status.empty" class="vbh-hint">{{ t('Der Bestand ist leer.') }}</span>
		</div>

		<NcModal
			v-if="wipeOpen"
			labelId="vbh-modal-title-backupwipe"
			size="normal"
			@close="wipeOpen = false">
			<div class="vbh-modal-inner" data-test="backup-wipe-dialog">
				<h2 id="vbh-modal-title-backupwipe" class="vbh-modal-title">
					{{ t('Bestand vollständig leeren') }}
				</h2>
				<p>
					{{ t('Damit gehen unwiderruflich verloren:') }}
				</p>
				<ul class="vbh-backup-list">
					<li>{{ t('{n} Konten', { n: status.counts.vbh_accounts || 0 }) }}</li>
					<li>{{ t('{n} Buchungen', { n: status.counts.vbh_journal || 0 }) }}</li>
					<li>{{ t('{n} Mitglieder', { n: status.counts.vbh_members || 0 }) }}</li>
					<li>{{ t('{n} Mandate', { n: status.counts.vbh_mandates || 0 }) }}</li>
					<li>{{ t('{n} Rechnungen', { n: status.counts.vbh_invoices || 0 }) }}</li>
					<li>{{ t('alle weiteren Daten, Belege und die Einstellungen des Vereins') }}</li>
				</ul>
				<p class="vbh-hint vbh-hint--warning">
					{{ t('Haben Sie eine aktuelle Sicherung erstellt und geprüft, dass sie sich öffnen lässt? Rechnungen und Buchungsbelege sind acht bis zehn Jahre aufzubewahren.') }}
				</p>
				<form class="vbh-form" @submit.prevent="wipe">
					<label class="vbh-grow">{{ tRaw('Zur Bestätigung bitte eintippen: {word}', { word: confirmWord }) }}
						<input
							v-model="wipeConfirmation"
							type="text"
							autocomplete="off"
							data-test="backup-wipe-confirmation">
					</label>
				</form>
				<div class="vbh-modal-actions">
					<NcButton variant="tertiary" @click="wipeOpen = false">
						{{ t('Abbrechen') }}
					</NcButton>
					<NcButton
						variant="error"
						:disabled="busy || wipeConfirmation.trim() !== confirmWord"
						data-test="backup-wipe-confirm"
						@click="wipe">
						{{ t('Unwiderruflich leeren') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { mdiDownload, mdiFolderOpen, mdiUpload } from '@mdi/js'
import { getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import api from '../api.js'
import { importBlocker, missingByKind, outcomeUnknown, passwordProblem, wipeWord } from '../lib/backup.js'
import { errMsg, formatDateTime, formatFileSize } from '../lib/format.js'
import { withPasswordConfirmation } from '../lib/passwordGate.js'

/**
 * „Sicherung und Umzug“ (Issue #111): den gesamten Bestand in eine
 * passwortverschlüsselte Datei sichern, eine solche Datei in einen leeren
 * Bestand einspielen, und den Bestand dafür vollständig leeren.
 *
 * Das Passwort der Sicherung bleibt im Formular und geht nur mit der
 * jeweiligen Anfrage an den Server; nach dem Erstellen wird es geleert.
 */
export default {
	name: 'SettingsBackup',
	components: { NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcLoadingIcon, NcModal },
	props: {
		// gemeinsames Ladeflag der Einstellungsseite, wie bei SettingsXbucImport
		busy: { type: Boolean, required: true },
	},

	emits: ['changed', 'update:busy'],

	data() {
		return {
			mdiDownload,
			mdiFolderOpen,
			mdiUpload,
			status: null,
			// Erstellen
			exportPassword: '',
			exportRepeat: '',
			withFiles: true,
			exporting: false,
			exportResult: null,
			// Einspielen: entweder eine hochgeladene Datei oder ein Pfad in den Nextcloud-Dateien
			file: null,
			path: '',
			importPassword: '',
			inspecting: false,
			inspection: null,
			importing: false,
			importResult: null,
			// Leeren
			wipeOpen: false,
			wipeConfirmation: '',
		}
	},

	computed: {
		minPasswordLength() {
			return (this.status && this.status.minPasswordLength) || 10
		},

		exportProblem() {
			return passwordProblem(this.exportPassword, this.exportRepeat, this.minPasswordLength)
		},

		exportMissing() {
			return missingByKind(this.exportResult && this.exportResult.missingFiles)
		},

		importMissing() {
			return missingByKind(this.importResult && this.importResult.missingFiles)
		},

		hasSource() {
			return this.file !== null || this.path !== ''
		},

		sourceLabel() {
			if (this.file) { return this.file.name }
			return this.path !== '' ? this.path : this.t('keine Datei gewählt')
		},

		blocker() {
			return importBlocker(this.inspection)
		},

		confirmWord() {
			return wipeWord(this.status && this.status.clubName)
		},
	},

	mounted() {
		this.loadStatus()
	},

	methods: {
		formatDateTime,
		formatFileSize,

		kindLabel(kind, count) {
			return {
				attachments: this.t('Buchungsbelege: {n}', { n: count }),
				reimbursements: this.t('Belege zu Auslagenanträgen: {n}', { n: count }),
				invoices: this.t('Rechnungsdokumente: {n}', { n: count }),
				mandates: this.t('Mandatsnachweise: {n}', { n: count }),
			}[kind] || String(count)
		},

		async loadStatus() {
			try {
				this.status = (await api.backupStatus()).data
			} catch (e) {
				showError(errMsg(e, this.t('Der Stand der Sicherung konnte nicht geladen werden.')))
			}
		},

		async createBackup() {
			if (this.busy || this.exportProblem !== '') { return }
			if (!await withPasswordConfirmation()) { return }
			this.$emit('update:busy', true)
			this.exporting = true
			this.exportResult = null
			try {
				const result = (await api.backupExport(this.exportPassword, this.withFiles)).data
				this.exportResult = result
				this.exportPassword = ''
				this.exportRepeat = ''
				// Der Browser lädt die Datei selbst – sie liegt nie im Speicher dieser Seite.
				window.location.assign(api.backupDownloadUrl(result.token))
			} catch (e) {
				showError(errMsg(e, this.t('Die Sicherung ist fehlgeschlagen.')))
			} finally {
				this.exporting = false
				this.$emit('update:busy', false)
			}
		},

		onFileSelected(e) {
			this.file = e.target.files[0] || null
			this.path = ''
			this.inspection = null
			this.importResult = null
		},

		async pickFromNextcloud() {
			let picked
			try {
				picked = await getFilePickerBuilder(this.t('Sicherungsdatei wählen'))
					.setMultiSelect(false)
					.allowDirectories(false)
					.setFilter((node) => node.type === 'folder' || String(node.basename || '').toLowerCase().endsWith('.vbhbackup'))
					.addButton({ label: this.t('Auswählen'), variant: 'primary', callback: () => {} })
					.build()
					.pick()
			} catch {
				// Dialog geschlossen, ohne etwas zu wählen.
				return
			}
			if (typeof picked !== 'string' || picked === '') { return }
			this.path = picked
			this.file = null
			if (this.$refs.backupInput) { this.$refs.backupInput.value = '' }
			this.inspection = null
			this.importResult = null
		},

		/** Datei bzw. Pfad und Passwort als Formular – für Prüfen und Einspielen gleich. */
		sourceForm() {
			const form = new FormData()
			if (this.file) {
				form.append('file', this.file)
			} else {
				form.append('path', this.path)
			}
			form.append('password', this.importPassword)
			return form
		},

		async inspectBackup() {
			if (this.busy || !this.hasSource || this.importPassword === '') { return }
			if (!await withPasswordConfirmation()) { return }
			this.$emit('update:busy', true)
			this.inspecting = true
			this.inspection = null
			this.importResult = null
			try {
				this.inspection = (await api.backupInspect(this.sourceForm())).data
			} catch (e) {
				showError(errMsg(e, this.t('Die Sicherung konnte nicht gelesen werden.')))
			} finally {
				this.inspecting = false
				this.$emit('update:busy', false)
			}
		},

		async importBackup() {
			if (this.busy || this.blocker !== '') { return }
			if (!await withPasswordConfirmation()) { return }
			this.$emit('update:busy', true)
			this.importing = true
			try {
				this.importResult = (await api.backupImport(this.sourceForm())).data
				this.inspection = null
				this.importPassword = ''
				showSuccess(this.t('Die Sicherung ist eingespielt.'))
				this.$emit('changed')
				await this.loadStatus()
			} catch (e) {
				if (outcomeUnknown(e)) {
					// Keine Antwort der App: Der Server arbeitet womöglich noch – „nicht verändert" wäre geraten.
					showError(this.t('Die Verbindung ist abgebrochen, bevor der Server geantwortet hat. Das Einspielen läuft womöglich noch: Laden Sie die Seite in einigen Minuten neu und prüfen Sie den Bestand, bevor Sie es erneut versuchen.'), { timeout: -1 })
				} else {
					showError(errMsg(e, this.t('Das Einspielen ist fehlgeschlagen. Der Bestand wurde nicht verändert.')))
				}
				await this.loadStatus()
			} finally {
				this.importing = false
				this.$emit('update:busy', false)
			}
		},

		openWipe() {
			this.wipeConfirmation = ''
			this.wipeOpen = true
		},

		async wipe() {
			if (this.busy || this.wipeConfirmation.trim() !== this.confirmWord) { return }
			if (!await withPasswordConfirmation()) { return }
			this.$emit('update:busy', true)
			try {
				await api.backupWipe(this.wipeConfirmation.trim())
				this.wipeOpen = false
				this.inspection = null
				showSuccess(this.t('Der Bestand ist geleert.'))
				this.$emit('changed')
				await this.loadStatus()
			} catch (e) {
				showError(errMsg(e, this.t('Das Leeren ist fehlgeschlagen.')))
			} finally {
				this.$emit('update:busy', false)
			}
		},

		reload() {
			window.location.reload()
		},
	},
}
</script>

<style scoped>
.vbh-backup-actions {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-top: 10px;
}

.vbh-backup-list {
	margin: 4px 0 8px;
	padding-inline-start: 22px;
	list-style: disc;
}

.vbh-backup-facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin: 8px 0 12px;
}

.vbh-backup-fact {
	font-weight: 600;
}
</style>
