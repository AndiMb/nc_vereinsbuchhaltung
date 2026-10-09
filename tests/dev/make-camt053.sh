#!/usr/bin/env bash
#
# Erzeugt die Bankdatei zum Import im Bankabgleich neu:
#   docs/testprotokoll/testdaten/bank-oktober-2026.camt053.xml
# aus dem eingereichten Oktoberlauf in der Datenbank (Seeder vorher ausführen).
#
# Der Container sieht das Repository nur lesend, deshalb schreibt der Aufruf
# hier auf dem Host. Weitere Optionen (z. B. --batch-id=N) gehen an
# make-camt053.php.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${HERE}/../../docs/testprotokoll/testdaten/bank-oktober-2026.camt053.xml"

"${HERE}/in-container.sh" make-camt053.php "$@" >"${OUT}.tmp"
mv "${OUT}.tmp" "${OUT}"
echo "geschrieben: ${OUT}"
