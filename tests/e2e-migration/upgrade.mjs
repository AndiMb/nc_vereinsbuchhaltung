#!/usr/bin/env node

import { getContainer, runOcc } from '@nextcloud/e2e-test-server'

// Schritt 2 des Upgrade-Tests: der Container läuft noch mit dem Datenbestand des
// alten App-Stands; der Code im eingehängten App-Verzeichnis ist inzwischen der des
// Pull Requests (die App-Version ist höher). `occ upgrade` führt die Migrationen aus.
const container = getContainer()
await runOcc(['upgrade'], { container, verbose: true })
await runOcc(['app:list', '--shipped=false'], { container, verbose: true })
