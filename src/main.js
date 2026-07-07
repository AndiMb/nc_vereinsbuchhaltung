import Vue from 'vue'
import App from './App.vue'
// Globale .vbh-* Utility-Styles (frueher scoped in App.vue). Global, damit auch
// ausgelagerte Kindkomponenten sie nutzen koennen; alle Selektoren sind
// .vbh-*-praefigiert (bzw. .vbh-table-qualifiziert) und lecken daher nicht in
// die Nextcloud-UI. NcButton-/NcSelect-piercende ::v-deep-Regeln bleiben scoped
// in App.vue.
import './styles.css'

Vue.mixin({ methods: { t, n } })

const View = Vue.extend(App)
new View().$mount('#vereinsbuchhaltung-app')
