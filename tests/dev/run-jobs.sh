#!/usr/bin/env bash
#
# Führt die Hintergrundjobs der Vereinsbuchhaltung in der Docker-Entwicklungs-
# umgebung von Hand aus – ohne auf den Cron zu warten.
#
#   tests/dev/run-jobs.sh                  alle fünf Jobs der Reihe nach
#   tests/dev/run-jobs.sh tageslauf        nur der Einzugszyklus (Forderungen, Vorabinfo-Mails)
#   tests/dev/run-jobs.sh mahnwesen        nur die Mahnstufen (Zahlungsaufforderung/-erinnerung/Mahnung)
#   tests/dev/run-jobs.sh verfall          nur der 36-Monats-Verfall der Mandate
#   tests/dev/run-jobs.sh austritt         Austritt: Zuweisungen beenden, dann Mandate beenden
#   tests/dev/run-jobs.sh --list           die Jobs und ihre IDs anzeigen, nichts ausführen
#
# Mehrere Namen lassen sich kombinieren (`run-jobs.sh tageslauf mahnwesen`).
# Die Job-IDs stehen in `occ background-job:list` und ändern sich nur, wenn die
# App neu aktiviert wird; das Skript sucht sie bei jedem Aufruf neu.
#
# Umgebung:
#   NC_DOCKER_DIR  Compose-Verzeichnis (Standard: ~/Projekte/nextcloud-docker-dev)
#   NC_SERVICE     Compose-Dienst mit Nextcloud (Standard: stable34)
#   MAILHOG_URL    Basis-URL der Mailhog-API (Standard: http://127.0.0.1)
#   MAILHOG_HOST   Host-Header dazu (Standard: mail.local)
#
# Wichtig: --force-execute ignoriert, ob der Job heute schon lief – und setzt
# „zuletzt gelaufen" auf jetzt. Der Cron der Umgebung (alle fünf Minuten) führt
# einen Tagesjob frühestens 24 Stunden nach diesem Zeitpunkt wieder von selbst aus.

set -euo pipefail

COMPOSE_DIR="${NC_DOCKER_DIR:-/Users/FlorianLudwig/Projekte/nextcloud-docker-dev}"
SERVICE="${NC_SERVICE:-stable34}"
MAILHOG_URL="${MAILHOG_URL:-http://127.0.0.1}"
MAILHOG_HOST="${MAILHOG_HOST:-mail.local}"
JOB_NAMESPACE='OCA\Vereinsbuchhaltung\BackgroundJob'

# Kurzname -> Klasse, in der Reihenfolge, in der der Tageslauf sie sinnvoll abarbeitet
SHORT_NAMES=(tageslauf mahnwesen verfall austritt-zuweisungen austritt-mandate)
job_class() {
	case "$1" in
		tageslauf | zyklus | cycle) echo 'ContributionDueCycleJob' ;;
		mahnwesen | mahnung | dunning) echo 'DunningLadderJob' ;;
		verfall | expiry) echo 'MandateExpiryJob' ;;
		austritt-zuweisungen | member-departure) echo 'MemberDepartureJob' ;;
		austritt-mandate | mandate-departure) echo 'MandateDepartureJob' ;;
		*) return 1 ;;
	esac
}

occ() {
	(cd "$COMPOSE_DIR" && docker compose exec -T -u www-data "$SERVICE" php occ "$@") 2>&1 | grep -v '^Profiler output' || true
}

mail_count() {
	curl -s -H "Host: ${MAILHOG_HOST}" "${MAILHOG_URL}/api/v2/messages" | grep -o '"total":[0-9]*' | head -1 | cut -d: -f2
}

# Tabelle der Job-Liste einmal holen (Spalten: | id | class | last_run | argument |)
job_table="$(occ background-job:list --limit 500)"

job_id() {
	local class="$1"
	# feste Zeichenkette statt Regex: der Klassenname enthält Backslashes; das Leerzeichen dahinter verhindert Teiltreffer
	echo "$job_table" | grep -F "BackgroundJob\\${class} " | awk -F'|' '{ gsub(/ /, "", $2); print $2; exit }' || true
}

if [[ "${1:-}" == "--list" ]]; then
	for name in "${SHORT_NAMES[@]}"; do
		class="$(job_class "$name")"
		printf '%-22s %-26s id=%s\n' "$name" "$class" "$(job_id "$class")"
	done
	exit 0
fi

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
	sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'
	exit 0
fi

selected=("$@")
if [[ ${#selected[@]} -eq 0 ]]; then
	selected=("${SHORT_NAMES[@]}")
fi

mails_before="$(mail_count || echo '?')"
for name in "${selected[@]}"; do
	if ! class="$(job_class "$name")"; then
		echo "Unbekannter Job: ${name} (erlaubt: ${SHORT_NAMES[*]}, --list)" >&2
		exit 2
	fi
	id="$(job_id "$class")"
	if [[ -z "$id" ]]; then
		echo "Job ${class} ist nicht registriert – ist die Vereinsbuchhaltung aktiviert?" >&2
		exit 1
	fi
	echo "-> ${class} (ID ${id})"
	output="$(occ background-job:execute "$id" --force-execute)"
	if grep -q 'Job executed!' <<<"$output"; then
		echo "   ausgeführt; der Cron startet ihn frühestens wieder: $(grep 'Next execution' <<<"$output" | tail -1 | sed 's/.*: *//')"
	else
		echo "$output" >&2
		echo "   FEHLER: ${class} wurde nicht ausgeführt." >&2
		exit 1
	fi
done

mails_after="$(mail_count || echo '?')"
echo "Mails in Mailhog: ${mails_before} -> ${mails_after} (${MAILHOG_URL}, Host ${MAILHOG_HOST})"
