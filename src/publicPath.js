// Wird von jedem Einstiegspunkt importiert (main.js, settings.js); gesetzt ist der Pfad vor dem ersten import().
// Webpack setzt den Pfad nachgeladener Chunks sonst fest auf /apps/<name>/js/;
// liegt die App in custom_apps/ oder unter einem Unterverzeichnis (Webroot),
// liefen alle Chunks ins Leere. Nextcloud kennt den tatsaechlichen Ort.
import { generateFilePath } from '@nextcloud/router'

// eslint-disable-next-line no-undef
__webpack_public_path__ = generateFilePath('vereinsbuchhaltung', '', 'js/')
