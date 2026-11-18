<template>
	<nav class="vbh-settings-toc" :aria-label="t('Auf dieser Seite')" data-testid="settings-toc">
		<div v-for="group in groups" :key="group.name" class="vbh-settings-toc__group">
			<h3 class="vbh-settings-toc__name">
				{{ group.name }}
			</h3>
			<ul>
				<li v-for="entry in group.entries" :key="entry.id">
					<a :href="'#settings-section_' + entry.id" @click="jump($event, entry.id)">{{ entry.name }}</a>
					<span v-if="entry.off" class="vbh-badge muted">{{ t('ausgeschaltet') }}</span>
				</li>
			</ul>
		</div>
	</nav>
</template>

<script>
/**
 * Inhaltsverzeichnis oben auf der Einstellungsseite: rund 17 Abschnitte mit
 * über 40 Karten stehen untereinander, ohne Sprungziele fand man den
 * gesuchten nur durch Scrollen. Die Links zeigen auf dieselben Anker
 * `settings-section_<id>`, die auch Checkliste, Prüfliste und Terminplan
 * benutzen – ein Klick setzt also eine URL, die man weitergeben kann.
 */
export default {
	name: 'SettingsToc',
	props: {
		// [{ name, entries: [{ id, name, off }] }] – Reihenfolge wie auf der Seite,
		// `off` = Modul ausgeschaltet (siehe lib/settingsSections.js::tocGroups())
		groups: { type: Array, required: true },
	},

	methods: {
		// Nextcloud scrollt die Einstellungsseite in einem eigenen Container; der
		// Sprung per Hash allein landet dort nicht immer zuverlässig, und ein
		// erneuter Klick auf denselben Anker tut gar nichts. Deshalb selbst
		// scrollen und den Anker nur in die Adresse schreiben.
		jump(event, id) {
			const target = document.getElementById('settings-section_' + id)
			if (!target) { return }
			event.preventDefault()
			history.replaceState(null, '', '#settings-section_' + id)
			target.scrollIntoView({ block: 'start' })
		},
	},
}
</script>

<style scoped>
/* Spalten nach verfügbarer Breite statt fester Umbruchpunkte: die Seite steht
   in Nextclouds Einstellungsrahmen, dessen Breite die App nicht kennt. */
.vbh-settings-toc {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 8px 24px;
}

.vbh-settings-toc__name {
	font-size: 1em;
	font-weight: bold;
	margin: 0 0 4px;
}

.vbh-settings-toc ul {
	margin: 0;
	padding: 0;
	list-style: none;
}

.vbh-settings-toc li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	padding: 2px 0;
}

.vbh-settings-toc a {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
