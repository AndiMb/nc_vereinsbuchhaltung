import { generateUrl, getRootUrl } from '@nextcloud/router'
import { createRouter, createWebHistory } from 'vue-router'

// generateUrl() haengt "/index.php/" nur an, wenn die Seite gerade darueber
// geladen wurde - sonst muss die History-API ohne das Praefix rechnen.
const baseUrl = generateUrl('/apps/vereinsbuchhaltung/')
const withIndexPhp = window.location.pathname.startsWith(getRootUrl() + '/index.php')
const base = withIndexPhp ? baseUrl : baseUrl.replace('/index.php/', '/')

// App.vue rendert weiterhin alles selbst (v-show/data()); kein <router-view>
// mountet diese Komponente je - Routen brauchen aber trotzdem eine.
const RouteHost = { render: () => null }

export default createRouter({
	history: createWebHistory(base),
	routes: [
		{ path: '/', name: 'dashboard', component: RouteHost },

		{ path: '/bookings', name: 'bookings', component: RouteHost },
		{ path: '/bookings/unassigned', name: 'bookings-unassigned', component: RouteHost },
		{ path: '/bookings/open-items', name: 'bookings-open-items', component: RouteHost },
		{ path: '/bookings/rules', name: 'bookings-rules', component: RouteHost },

		{ path: '/accounts', name: 'accounts', component: RouteHost },
		{ path: '/accounts/new', name: 'accounts-new', component: RouteHost },
		{ path: '/accounts/:accountId(\\d+)', name: 'accounts-detail', component: RouteHost },
		{ path: '/accounts/:accountId(\\d+)/edit', name: 'accounts-edit', component: RouteHost },

		{ path: '/reports', name: 'reports', component: RouteHost },
		{ path: '/reports/costcenters', name: 'reports-costcenters', component: RouteHost },
		{ path: '/reports/spheres', name: 'reports-spheres', component: RouteHost },
		{ path: '/reports/reserves', name: 'reports-reserves', component: RouteHost },
		{ path: '/reports/budget', name: 'reports-budget', component: RouteHost },
		{ path: '/reports/audit', name: 'reports-audit', component: RouteHost },

		{ path: '/contributions', name: 'contributions', component: RouteHost },
		{ path: '/contributions/batch', name: 'contributions-batch', component: RouteHost },

		{ path: '/:pathMatch(.*)*', name: 'not-found', component: RouteHost },
	],
})
