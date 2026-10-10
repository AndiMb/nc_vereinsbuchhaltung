#!/usr/bin/env bash
#
# Führt ein PHP-Werkzeug aus tests/dev im Nextcloud-Container der Docker-
# Entwicklungsumgebung aus (als www-data) und bereinigt die Ausgabe.
#
#   tests/dev/in-container.sh seed-beitraege.php [--wipe] [--wipe-bank] [--purge] [--check] [--with-anonymization-booking]
#   tests/dev/in-container.sh make-camt053.php [--batch-id=N]
#
# Das Skript liegt im Repository; der Container sieht dasselbe Verzeichnis
# unter /var/www/html/apps-shared/ (Mount des Ordners nextcloud-apps). Der Pfad
# im Container wird aus dem Ort dieses Skripts abgeleitet – es funktioniert
# also gleich aus dem Haupt-Checkout wie aus einem Worktree darunter.
#
# Umgebung:
#   NC_DOCKER_DIR  Compose-Verzeichnis (Standard: ~/Projekte/nextcloud-docker-dev)
#   NC_SERVICE     Compose-Dienst mit Nextcloud (Standard: stable34)
#   NC_APPS_DIR    Ordner, den der Container als apps-shared sieht (Standard: ~/Projekte/nextcloud-apps)

set -euo pipefail

COMPOSE_DIR="${NC_DOCKER_DIR:-/Users/FlorianLudwig/Projekte/nextcloud-docker-dev}"
SERVICE="${NC_SERVICE:-stable34}"
APPS_DIR="${NC_APPS_DIR:-/Users/FlorianLudwig/Projekte/nextcloud-apps}"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ $# -lt 1 ]]; then
	echo "Aufruf: $0 <skript.php> [Optionen]" >&2
	exit 2
fi
case "$HERE" in
	"$APPS_DIR"/*) ;;
	*)
		echo "Dieses Skript liegt nicht unter ${APPS_DIR} – der Container sieht es dort nicht (NC_APPS_DIR setzen)." >&2
		exit 1
		;;
esac
SCRIPT="$1"
shift

cd "$COMPOSE_DIR"
# Die Zeile „Profiler output …" des Containers gehört nicht zur Ausgabe des Werkzeugs
docker compose exec -T -u www-data "$SERVICE" php "/var/www/html/apps-shared/${HERE#"$APPS_DIR"/}/${SCRIPT}" "$@" | { grep -v '^Profiler output' || true; }
