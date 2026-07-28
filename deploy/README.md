# Deployment über GitHub Releases

Ablauf: **Du taggst lokal → GitHub Actions baut & veröffentlicht → jeder Server zieht per `vbh-deploy.sh`.**

## 1. Neue Version veröffentlichen (auf deinem Rechner)

```bash
# info.xml-<version> ist bereits gebumpt und committet
# CHANGELOG.md hat einen Abschnitt "## [0.10.18]"
git tag v0.10.18          # Tag == info.xml-Version, mit führendem "v"
git push origin main --tags
```

GitHub Actions (`.github/workflows/release.yml`) baut daraufhin Frontend + Composer-Autoloader,
packt `vereinsbuchhaltung-0.10.18.tar.gz` (+ `.sha256`) und hängt beides an ein Release.
Der Build **bricht ab**, wenn Tag und `info.xml`-Version nicht übereinstimmen.

## 2. Server einmalig einrichten (pro System, als root)

```bash
sudo curl -fsSL https://raw.githubusercontent.com/AndiMb/nc_vereinsbuchhaltung/main/deploy/vbh-deploy.sh \
  -o /usr/local/sbin/vbh-deploy && sudo chmod +x /usr/local/sbin/vbh-deploy
```

Falls dein Setup vom Standard abweicht, in `/etc/default/vbh-deploy` (oder direkt beim Aufruf) setzen:

```bash
# Beispiel
VBH_NC_ROOT=/var/www/nextcloud     # Nextcloud-Verzeichnis (Default)
VBH_WEB_USER=www-data              # Web-/PHP-Nutzer (Default)
VBH_APPS_DIRNAME=apps              # oder custom_apps
```

## 3. Ausrollen (pro System)

```bash
sudo vbh-deploy            # neueste Version, überspringt wenn schon aktuell
sudo vbh-deploy --force    # gleiche Version erneut ausrollen
sudo vbh-deploy v0.10.18   # bestimmte Version
```

Das Skript: prüft die neueste Release-Version → lädt Tarball + prüft SHA256 → Wartungsmodus an →
sichert den alten App-Ordner (`…bak-<zeitstempel>`) → entpackt neue Version → `occ upgrade` →
Wartungsmodus aus. Bei Fehler automatischer Rollback des App-Ordners.

## Optional: automatisch aktuell halten

Systemd-Timer oder Cron, z. B. wöchentlich:

```
# /etc/cron.d/vbh-deploy
30 4 * * 1 root /usr/local/sbin/vbh-deploy >> /var/log/vbh-deploy.log 2>&1
```

## Veröffentlichung im Nextcloud App Store

Derselbe Workflow signiert die App und meldet das Release an den Store – aber nur,
wenn die passenden GitHub-Secrets hinterlegt sind. Fehlen sie, wird wie bisher ein
unsigniertes Tarball gebaut und der `vbh-deploy.sh`-Weg funktioniert unverändert.

Benötigte Secrets (Repo → Settings → Secrets and variables → Actions):

| Secret | Inhalt | Wirkung |
| --- | --- | --- |
| `APP_PRIVATE_KEY` | kompletter Inhalt von `~/.nextcloud/certificates/vereinsbuchhaltung.key` | erzeugt `appinfo/signature.json` |
| `APP_PUBLIC_CRT` | kompletter Inhalt von `vereinsbuchhaltung.crt` | dito |
| `APPSTORE_TOKEN` | Token von https://apps.nextcloud.com/account/token | Upload ins Store-Verzeichnis |

Voraussetzung ist ein von Nextcloud signiertes Zertifikat – dafür einen CSR
(`openssl req -nodes -newkey rsa:4096 -keyout vereinsbuchhaltung.key -out
vereinsbuchhaltung.csr -subj "/CN=vereinsbuchhaltung"`) als Pull Request bei
[nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests)
einreichen und die App danach einmalig unter
https://apps.nextcloud.com/developer/apps/new registrieren.

**Die `.key` gehört nicht ins Repo** – sie ist der einzige Weg, künftige Updates
zu veröffentlichen. Geht sie verloren, muss ein neues Zertifikat beantragt werden.

Der Workflow bricht vor dem Bauen ab, wenn Tag und `info.xml` nicht zusammenpassen,
der `CHANGELOG.md`-Abschnitt zur Version fehlt oder die `info.xml` nicht gegen das
App-Store-Schema validiert.

## Wichtig

- **DB-Backup vor größeren Upgrades**: Migrationen sind nicht automatisch reversibel. Der
  Skript-Rollback stellt nur den *App-Ordner* wieder her, nicht das DB-Schema.
- Nach dem Deploy im Browser **hart neu laden** (Strg+F5), sonst cached er altes JS.
