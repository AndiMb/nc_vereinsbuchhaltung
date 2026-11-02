<?php
declare(strict_types=1);
// Einstiegspunkt: der Vue-Bundle hängt sich an #vereinsbuchhaltung-app.
// Breite und Flex-Verhalten setzt src/styles.css (#vereinsbuchhaltung-app),
// nicht ein style-Attribut: die CSP von Nextcloud erlaubt Inline-Stile nur
// eingeschränkt, und so steht das Layout an einer Stelle (#265).
?>
<div id="vereinsbuchhaltung-app"></div>
