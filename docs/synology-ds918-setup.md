# FamilienDMS auf Markus' Synology DS918+

Diese Anleitung ist auf die bekannten Angaben zugeschnitten:

- Synology DS918+
- DSM 7.4.1-90080 Update 1
- Adresse im Heimnetz: `192.168.178.15`
- Web Station mit Apache 2.4
- PHP 8.2
- Projektdateien: `web/FamilienDMS`
- MariaDB 10

Die IP-Adresse ist eine private Heimnetzadresse. Diese Anleitung geht deshalb zunächst davon aus, dass FamilienDMS nur im Heimnetz oder über ein VPN genutzt wird. Richte keine Router-Portweiterleitung auf diese Adresse ein, bevor HTTPS und ein sicherer Fernzugriff geklärt sind.

## 1. Projektdateien und Webroot

Lege die Repository-Dateien in den gemeinsamen Ordner `web/FamilienDMS`. Der Document Root des Apache-2.4-Profils muss ausschließlich auf dessen Unterordner `public` zeigen. In Web Station sollte die Zuordnung sinngemäß so aussehen:

```text
Host: 192.168.178.15 (oder später ein DNS-Name)
Backend-Server: Apache HTTP Server 2.4
PHP-Profil: PHP 8.2
Document Root: /volume1/web/FamilienDMS/public
```

`/volume1` ist nur ein Beispiel. Prüfe im DSM-Dateimanager, auf welchem Volume der gemeinsame Ordner `web` tatsächlich liegt. Verwende nicht `/volume1/web/FamilienDMS` als Document Root, weil dann auch Konfiguration und interne PHP-Dateien direkt abrufbar wären.

## 2. PHP-Profil

Öffne in Web Station die Einstellungen des verwendeten PHP-8.2-Profils und aktiviere mindestens:

- `pdo_mysql`
- `mbstring`
- `fileinfo`
- `openssl`

Sessions müssen ebenfalls verfügbar sein. Für die Installation sollte `display_errors` nicht öffentlich ausgegeben werden; Fehler gehören in ein nur für Administratoren zugängliches PHP-/Apache-Log.

## 3. Private Konfiguration und Dokumentenspeicher

Erstelle beides **außerhalb** von `web`, zum Beispiel:

```text
/volume1/@appdata/familiendms/config.php
/volume1/@appdata/familiendms/storage/
```

Ersetze `/volume1` durch das tatsächliche Volume. Kopiere `config/config.example.php` nach `config.php`. Trage dort den Datenbanknamen, den Datenbankbenutzer, das Passwort und den privaten Speicherpfad ein. `config.php` darf nicht ins GitHub-Repository gelangen. Schränke die Dateirechte so ein, dass nur Administratoren und der Apache/PHP-Prozess sie lesen können; der PHP-Prozess benötigt Schreibzugriff auf `storage/`.

Die App unterstützt `FAMILIENDMS_CONFIG` und `FAMILIENDMS_STORAGE` als Umgebungsvariablen. Falls dein DSM-PHP-Profil keine solchen Umgebungsvariablen für den Webprozess bereitstellt, kannst du in der außerhalb des Webroots liegenden `config.php` die konkreten privaten Werte setzen.

## 4. MariaDB 10

Erstelle eine eigene Datenbank mit Zeichensatz `utf8mb4` und einen eigenen Datenbankbenutzer nur für FamilienDMS. Verwende nicht den MariaDB-Administratorkonto für die Anwendung. Die App benötigt für den normalen Betrieb nur `SELECT`, `INSERT`, `UPDATE` und `DELETE`; führe das Schema mit einem administrativen Konto aus und entziehe danach etwaige zusätzliche Rechte.

Importiere:

```text
database/migrations/001_initial.sql
```

Die genaue MariaDB-Unterversion, der Hostname/Port und der unterstützte Weg zum Import hängen von der DSM-Paketkonfiguration ab. Verwende zunächst `127.0.0.1:3306`, sofern MariaDB lokal läuft und TCP-Verbindungen dort aktiviert sind. Zugangsdaten nicht in Chat oder GitHub eintragen.

## 5. Verbindung und HTTPS prüfen

Rufe im Heimnetz zunächst `http://192.168.178.15/` auf. Wenn die Seite nur mit `https://192.168.178.15/` erreichbar ist, prüfe im Browser das Schlosssymbol und ob das Zertifikat als gültig für genau diese Adresse angezeigt wird. „Ich denke schon“ reicht nicht als Prüfung: Bei einer IP-Adresse passt ein Zertifikat häufig nicht zum Namen. Für gültiges HTTPS ist ein passender DNS-Name mit Zertifikat oft einfacher.

Die Anwendung setzt das Secure-Attribut der Session-Cookies, wenn die Anfrage über HTTPS eintrifft. Für produktive Nutzung sollte ein gültiges HTTPS-Zertifikat aktiv sein.

## 6. Erstinstallation

1. Konfiguriere in Web Station den Apache-2.4-Host mit PHP 8.2 und Document Root `.../web/FamilienDMS/public`.
2. Prüfe im PHP-Profil die vier oben genannten Erweiterungen.
3. Lege private Konfiguration und Speicherordner außerhalb von `web` an und setze passende Rechte.
4. Importiere die SQL-Migration und trage die App-Datenbankverbindung in `config.php` ein.
5. Öffne `http://192.168.178.15/install.php` im Heimnetz und erstelle den ersten Administrator.
6. Entferne danach `public/install.php` oder sperre die Datei im Apache/Web-Station-Host.
7. Melde dich an und prüfe, dass `config.php` und private Speicherdateien nicht über eine URL abrufbar sind.
8. Richte verschlüsselte Backups von Datenbank und privatem Speicher ein und teste die Wiederherstellung.

## Noch zu prüfen

- Tatsächlicher Volume-Pfad für den gemeinsamen Ordner `web`
- Gültiges HTTPS-Zertifikat für die verwendete Adresse oder ein eigener DNS-Name
- Aktivierte PHP-Erweiterungen und Schreibrechte des Apache/PHP-Benutzers
- MariaDB-10-Unterversion sowie tatsächlicher Host/Port
- Ob Zugriff ausschließlich im Heimnetz/VPN erfolgen soll (empfohlen) oder von außen

