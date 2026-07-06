<?php
declare(strict_types=1);
// Einstiegspunkt: der Vue-Bundle hängt sich an #vereinsbuchhaltung-app.
// #content ist in NC ein Flex-Container: ohne width/flex/min-width wäre dieses
// Div inhaltsbestimmt breit (Tabellen wachsen über den Viewport hinaus).
?>
<div id="vereinsbuchhaltung-app" style="height: 100%; width: 100%; min-width: 0; max-width: 100%; flex: 1 1 0;"></div>
