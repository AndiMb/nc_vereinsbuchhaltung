#!/usr/bin/env bash
#
# vbh-deploy.sh — installiert/aktualisiert die Nextcloud-App "Vereinsbuchhaltung"
# aus dem neuesten GitHub-Release. Fuer klassische Setups (Apache/nginx + PHP-FPM).
#
# Einmalig pro Server einrichten:
#   sudo curl -fsSL https://raw.githubusercontent.com/AndiMb/nc_vereinsbuchhaltung/main/deploy/vbh-deploy.sh -o /usr/local/sbin/vbh-deploy.sh
#   sudo chmod +x /usr/local/sbin/vbh-deploy.sh
#   # dann die drei Variablen unten (bzw. per Env) an den Server anpassen
#
# Aufruf:
#   sudo vbh-deploy.sh              # installiert die neueste Release-Version (falls neuer)
#   sudo vbh-deploy.sh --force      # auch bei gleicher Version neu ausrollen
#   sudo vbh-deploy.sh v0.10.18     # exakt diese Version ausrollen
#
set -euo pipefail

# ---- Konfiguration (per Env ueberschreibbar) --------------------------------
REPO="${VBH_REPO:-AndiMb/nc_vereinsbuchhaltung}"
NC_ROOT="${VBH_NC_ROOT:-/var/www/nextcloud}"   # Nextcloud-Installationsverzeichnis
WEB_USER="${VBH_WEB_USER:-www-data}"           # PHP-/Webserver-Nutzer
APPS_DIRNAME="${VBH_APPS_DIRNAME:-apps}"       # 'apps' oder 'custom_apps'
# -----------------------------------------------------------------------------

APP="vereinsbuchhaltung"
APPS_DIR="$NC_ROOT/$APPS_DIRNAME"
APP_DIR="$APPS_DIR/$APP"
OCC=(sudo -u "$WEB_USER" php "$NC_ROOT/occ")

log()  { printf '\033[1;34m[vbh]\033[0m %s\n' "$*"; }
err()  { printf '\033[1;31m[vbh] FEHLER:\033[0m %s\n' "$*" >&2; }
die()  { err "$*"; exit 1; }

[ "$(id -u)" = "0" ] || die "Bitte mit sudo/root ausfuehren."
command -v curl >/dev/null || die "curl wird benoetigt."
command -v tar  >/dev/null || die "tar wird benoetigt."
[ -f "$NC_ROOT/occ" ] || die "occ nicht gefunden unter $NC_ROOT/occ — VBH_NC_ROOT pruefen."
[ -d "$APPS_DIR" ]    || die "App-Verzeichnis $APPS_DIR existiert nicht — VBH_APPS_DIRNAME pruefen."

# ---- Zielversion bestimmen --------------------------------------------------
FORCE=0
WANT_TAG=""
for arg in "$@"; do
  case "$arg" in
    --force) FORCE=1 ;;
    v*)      WANT_TAG="$arg" ;;
    *)       die "Unbekanntes Argument: $arg" ;;
  esac
done

if [ -z "$WANT_TAG" ]; then
  log "Ermittle neuestes Release von $REPO ..."
  WANT_TAG=$(curl -fsSL "https://api.github.com/repos/$REPO/releases/latest" \
             | grep -oP '"tag_name":\s*"\K[^"]+') \
    || die "Konnte neuestes Release nicht ermitteln."
fi
[ -n "$WANT_TAG" ] || die "Keine Zielversion gefunden."
WANT_VER="${WANT_TAG#v}"
log "Zielversion: $WANT_TAG"

# ---- Bereits aktuell? -------------------------------------------------------
CUR_VER=""
if [ -f "$APP_DIR/appinfo/info.xml" ]; then
  CUR_VER=$(grep -oP '(?<=<version>)[^<]+' "$APP_DIR/appinfo/info.xml" || true)
  log "Installiert: ${CUR_VER:-unbekannt}"
fi
if [ "$CUR_VER" = "$WANT_VER" ] && [ "$FORCE" -ne 1 ]; then
  log "Version $WANT_VER ist bereits installiert. (--force zum Erzwingen)"
  exit 0
fi

# ---- Download + Pruefsumme --------------------------------------------------
TARBALL="$APP-$WANT_VER.tar.gz"
BASE="https://github.com/$REPO/releases/download/$WANT_TAG"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

log "Lade $TARBALL ..."
curl -fsSL "$BASE/$TARBALL"        -o "$TMP/$TARBALL"        || die "Download fehlgeschlagen: $BASE/$TARBALL"
curl -fsSL "$BASE/$TARBALL.sha256" -o "$TMP/$TARBALL.sha256" || die "Pruefsumme fehlt: $BASE/$TARBALL.sha256"

log "Pruefe SHA256 ..."
( cd "$TMP" && sha256sum -c "$TARBALL.sha256" >/dev/null ) || die "SHA256-Pruefung fehlgeschlagen!"

log "Entpacke ..."
tar -xzf "$TMP/$TARBALL" -C "$TMP"
[ -d "$TMP/$APP/appinfo" ] || die "Tarball enthaelt nicht das erwartete Verzeichnis $APP/."

# ---- Ausrollen (mit Backup + Rollback) --------------------------------------
STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP="$APPS_DIR/$APP.bak-$STAMP"

log "Wartungsmodus an ..."
"${OCC[@]}" maintenance:mode --on

rollback() {
  err "Fehler beim Ausrollen — versuche Rollback ..."
  if [ -d "$BACKUP" ]; then
    rm -rf "$APP_DIR"
    mv "$BACKUP" "$APP_DIR"
    err "Alter Stand wiederhergestellt."
  fi
  "${OCC[@]}" maintenance:mode --off || true
  die "Deploy abgebrochen. DB ggf. aus Backup pruefen."
}
trap rollback ERR

if [ -d "$APP_DIR" ]; then
  log "Sichere bisherigen Stand nach $(basename "$BACKUP") ..."
  mv "$APP_DIR" "$BACKUP"
fi

log "Installiere neue Version ..."
mv "$TMP/$APP" "$APP_DIR"
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 750 {} +
find "$APP_DIR" -type f -exec chmod 640 {} +

log "occ upgrade / enable ..."
"${OCC[@]}" app:enable "$APP" >/dev/null 2>&1 || true
"${OCC[@]}" upgrade || true   # No-op wenn keine Migration ansteht

trap - ERR
log "Wartungsmodus aus ..."
"${OCC[@]}" maintenance:mode --off

log "Fertig: $APP $WANT_VER ist aktiv."
log "Backup des Vorgaengers: $BACKUP  (nach Kontrolle loeschbar)"
