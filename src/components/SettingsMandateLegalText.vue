<template>
	<div class="vbh-legaltext">
		<p class="vbh-hint">
			{{ t('Dieser Text steht auf jedem SEPA-Lastschriftmandat – im druckfertigen Formular und auf der Seite, auf der Mitglieder elektronisch zustimmen. Er besteht aus dem Pflichtblock, den das Lastschriftverfahren vorschreibt, und einem Rahmen, den Sie frei ergänzen können, z. B. um Hinweise zum Beitragseinzug oder zum Datenschutz.') }}
		</p>

		<div v-if="loadFailed" class="vbh-card">
			<p class="vbh-hint vbh-hint--error" role="alert">
				{{ t('Der Mandats-Rechtstext konnte nicht geladen werden.') }}
			</p>
			<NcButton @click="load">
				{{ t('Erneut versuchen') }}
			</NcButton>
		</div>
		<NcLoadingIcon v-else-if="loading" :size="24" />

		<template v-else>
			<p class="vbh-hint vbh-hint--info">
				{{ t('Aktuell gültig: Fassung {number} vom {date} ({author}).', { number: current.number, date: when(current), author: authorLabel(current.createdBy) }) }}
				{{ t('Eine neue Fassung gilt ab sofort für neue Mandatsformulare und Zustimmungen. Bestehende Mandate behalten die Fassung, die ihnen beim Erteilen angezeigt wurde – gespeicherte Fassungen werden nie geändert.') }}
			</p>

			<div class="vbh-card">
				<h4>{{ t('Text bearbeiten') }}</h4>
				<div class="vbh-legaltext-grid">
					<div class="vbh-legaltext-col">
						<div class="vbh-legaltext-label">
							<NcIconSvgWrapper :path="mdiLockOutline" :size="16" />
							<span id="vbh-legaltext-protected-label">{{ t('Pflichtblock (geschützt)') }}</span>
						</div>
						<div
							class="vbh-legaltext-box vbh-legaltext-box--protected"
							role="group"
							aria-labelledby="vbh-legaltext-protected-label"
							data-testid="legaltext-protected">
							<p v-for="(paragraph, i) in protectedParagraphs" :key="i">
								{{ paragraph }}
							</p>
						</div>
						<p class="vbh-hint">
							{{ t('Dieser Teil ist durch das SEPA-Lastschriftverfahren vorgegeben und kann nicht geändert werden. {placeholder} wird beim Anzeigen durch den Vereinsnamen ersetzt – das funktioniert auch in Ihrem Rahmentext.', { placeholder: placeholder }) }}
						</p>

						<label class="vbh-legaltext-label" for="vbh-legaltext-rahmen">{{ t('Rahmentext (optional)') }}</label>
						<textarea
							id="vbh-legaltext-rahmen"
							v-model="draft"
							class="vbh-legaltext-textarea"
							rows="8"
							aria-describedby="vbh-legaltext-rahmen-hint vbh-legaltext-rahmen-count" />
						<p id="vbh-legaltext-rahmen-hint" class="vbh-hint">
							{{ t('Erscheint unter dem Pflichtblock. Leere Zeilen trennen Absätze.') }}
						</p>
						<p id="vbh-legaltext-rahmen-count" class="vbh-legaltext-count" :class="{ 'is-over': length > maxLength }">
							{{ t('{length} von {max} Zeichen', { length, max: maxLength }) }}
						</p>
					</div>

					<div class="vbh-legaltext-col">
						<div class="vbh-legaltext-label">
							<span id="vbh-legaltext-preview-label">{{ t('Vorschau') }}</span>
						</div>
						<div
							class="vbh-legaltext-box"
							role="group"
							aria-labelledby="vbh-legaltext-preview-label"
							data-testid="legaltext-preview">
							<p v-for="(paragraph, i) in previewParagraphs" :key="i">
								{{ paragraph }}
							</p>
						</div>
						<p v-if="trimmedClubName" class="vbh-hint">
							<!-- Der Vereinsname steht ausserhalb von t(): dessen Variablen werden als HTML maskiert ("&" würde zu "&amp;"), Vue maskiert ein zweites Mal -->
							{{ t('So lesen Mitglieder den Text – mit dem Vereinsnamen') }} <strong data-testid="legaltext-club-name">{{ trimmedClubName }}</strong>.
						</p>
						<p v-else class="vbh-hint vbh-hint--warning">
							{{ t('Der Vereinsname ist noch nicht eingetragen (Abschnitt „Verein"). Bis dahin steht im Text „den Verein".') }}
						</p>
					</div>
				</div>

				<p v-if="inputError || error" class="vbh-hint vbh-hint--error" role="alert">
					{{ inputError || error }}
				</p>
				<div class="vbh-form">
					<NcButton variant="primary" :disabled="!canSave" @click="save">
						{{ t('Neue Version speichern') }}
					</NcButton>
					<NcButton
						v-if="dirty"
						variant="tertiary"
						:disabled="saving"
						@click="discard">
						{{ t('Änderungen verwerfen') }}
					</NcButton>
				</div>
			</div>

			<div class="vbh-card">
				<h4>{{ t('Versionsverlauf') }}</h4>
				<p class="vbh-hint">
					{{ t('Jede Änderung legt eine neue Fassung an; frühere Fassungen bleiben unverändert erhalten. Ändert ein App-Update den Pflichtblock, legt die App ebenfalls selbst eine neue Fassung an und übernimmt Ihren Rahmentext.') }}
				</p>
				<ul class="vbh-legaltext-history">
					<li v-for="v in history" :key="v.id" :data-testid="`legaltext-version-${v.number}`">
						<div class="vbh-legaltext-historyhead">
							<strong>{{ t('Fassung {number}', { number: v.number }) }}</strong>
							<span v-if="v.id === current.id" class="vbh-legaltext-badge">{{ t('aktuell') }}</span>
							<span class="vbh-legaltext-meta">{{ when(v) }} · {{ authorLabel(v.createdBy) }}</span>
							<NcButton
								variant="tertiary"
								size="small"
								:aria-expanded="isExpanded(v) ? 'true' : 'false'"
								@click="toggle(v)">
								{{ isExpanded(v) ? t('Ausblenden') : t('Anzeigen') }}
							</NcButton>
						</div>
						<div v-if="isExpanded(v)" class="vbh-legaltext-box">
							<p v-for="(paragraph, i) in paragraphsOf(v)" :key="i">
								{{ paragraph }}
							</p>
						</div>
					</li>
				</ul>
			</div>
		</template>
	</div>
</template>

<script>
import { mdiLockOutline } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcIconSvgWrapper, NcLoadingIcon } from '@nextcloud/vue'
import api from '../api.js'
import { formatDate } from '../lib/format.js'
import { authorLabel, CREDITOR_PLACEHOLDER, legalTextParagraphs, MAX_RAHMEN_LENGTH, normalizeRahmen, rahmenError } from '../lib/mandateLegalText.js'
import { saveErrorMessage } from '../lib/sepaSettings.js'

/**
 * Editor des Mandats-Rechtstexts (Spec §3.11/§2.2 MandateLegalTextVersion,
 * Issue #101): der geschützte Pflichtblock steht nur zur Ansicht da, der
 * Rahmen ist editierbar, die Vorschau ersetzt den Platzhalter durch den
 * Vereinsnamen, „Neue Version speichern" legt eine neue Fassung an, der
 * Versionsverlauf zeigt alle früheren.
 *
 * Pflichtblock und Rahmen kommen vom Server getrennt und ohne den internen
 * Rahmen-Marker (MandateLegalTextController::present()); die Oberfläche
 * rechnet nie mit dem zusammengesetzten Textkörper und kann den Marker
 * deshalb gar nicht erst anzeigen. Gerendert wird ausschließlich als Text
 * (kein v-html).
 *
 * Abweichung vom Muster der übrigen Einstellungskarten: kein Teil des
 * gemeinsamen Einstellungssatzes und kein Composable-Singleton - der Text
 * gehört dieser einen Komponente, der Vereinsname kommt als Prop von der
 * Einstellungsseite (er darf dort noch ungespeichert in Bearbeitung sein).
 */
export default {
	name: 'SettingsMandateLegalText',
	components: { NcButton, NcIconSvgWrapper, NcLoadingIcon },
	props: {
		clubName: { type: String, default: '' },
	},

	data() {
		return {
			mdiLockOutline,
			placeholder: CREDITOR_PLACEHOLDER,
			maxLength: MAX_RAHMEN_LENGTH,
			loading: true,
			loadFailed: false,
			current: null,
			history: [],
			// Rahmen der aktuellen Fassung (Ausgangspunkt für „geändert?") und der Entwurf
			savedRahmen: '',
			draft: '',
			saving: false,
			error: '',
			expanded: {},
		}
	},

	computed: {
		trimmedClubName() { return this.clubName.trim() },
		length() { return [...normalizeRahmen(this.draft)].length },
		inputError() { return rahmenError(this.draft) },
		dirty() { return normalizeRahmen(this.draft) !== normalizeRahmen(this.savedRahmen) },
		canSave() { return this.dirty && !this.inputError && !this.saving },
		// Der Pflichtblock zeigt den Platzhalter wie gespeichert; ersetzt wird erst in der Vorschau
		protectedParagraphs() {
			return this.current.pflichtblock.split(/\n{2,}/).map((p) => p.trim()).filter((p) => p !== '')
		},

		previewParagraphs() {
			return legalTextParagraphs(this.current.pflichtblock, this.draft, this.clubName)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		authorLabel,

		async load() {
			this.loading = true
			this.loadFailed = false
			try {
				const [current, history] = await Promise.all([api.getMandateLegalText(), api.getMandateLegalTextHistory()])
				this.applyCurrent(current.data)
				this.history = history.data
			} catch {
				this.loadFailed = true
			} finally {
				this.loading = false
			}
		},

		applyCurrent(data) {
			this.current = data.version
			this.savedRahmen = data.rahmen
			this.draft = data.rahmen
		},

		when(version) {
			return `${formatDate(version.createdAt)} ${String(version.createdAt).slice(11, 16)}`.trim()
		},

		paragraphsOf(version) {
			return legalTextParagraphs(version.pflichtblock, version.rahmen, this.clubName)
		},

		isExpanded(version) { return !!this.expanded[version.id] },

		toggle(version) {
			this.expanded = { ...this.expanded, [version.id]: !this.expanded[version.id] }
		},

		discard() {
			this.draft = this.savedRahmen
			this.error = ''
		},

		async save() {
			if (!this.canSave) { return }
			this.saving = true
			this.error = ''
			try {
				const { data } = await api.saveMandateLegalText(this.draft)
				this.applyCurrent(data)
				const history = await api.getMandateLegalTextHistory()
				this.history = history.data
				showSuccess(this.t('Neue Fassung {number} gespeichert.', { number: data.version.number }))
			} catch (e) {
				this.error = saveErrorMessage(e)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-legaltext-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr));
	gap: 16px;
}

.vbh-legaltext-col {
	min-width: 0;
}

.vbh-legaltext-label {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 0 0 4px;
	font-size: 0.85em;
	font-weight: 600;
}

.vbh-legaltext-box {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.vbh-legaltext-box p {
	margin: 0 0 8px;
	white-space: pre-line;
	overflow-wrap: anywhere;
}

.vbh-legaltext-box p:last-child {
	margin-bottom: 0;
}

/* Der geschützte Block liest sich wie ein gesperrtes Feld: gestrichelter Rahmen, kein Eingabefeld */
.vbh-legaltext-box--protected {
	border-style: dashed;
	background-color: var(--color-background-dark);
}

.vbh-legaltext .vbh-legaltext-textarea {
	width: 100%;
	min-height: 9em;
	box-sizing: border-box;
	resize: vertical;
}

.vbh-legaltext-count {
	margin: 0 0 8px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.vbh-legaltext-count.is-over {
	color: var(--color-error-text);
	font-weight: 600;
}

.vbh-legaltext-history {
	margin: 0;
	padding: 0;
	list-style: none;
}

.vbh-legaltext-history li {
	padding: 8px 0;
	border-top: 1px solid var(--color-border);
}

.vbh-legaltext-history li:first-child {
	border-top: none;
}

.vbh-legaltext-historyhead {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.vbh-legaltext-meta {
	flex: 1 1 auto;
	color: var(--color-text-maxcontrast);
}

.vbh-legaltext-badge {
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 100px);
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 0.8em;
	font-weight: 600;
}

.vbh-legaltext-historyhead + .vbh-legaltext-box {
	margin-top: 8px;
}
</style>
