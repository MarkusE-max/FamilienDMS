# FamilienDMS auf Markus' Synology DS918+

Diese Anleitung ist auf die bekannten Angaben zugeschnitten:

- Synology DS918+
- DSM 7.4.1-90080 Update 1
- Adresse im Heimnetz: `192.168.178.15`
- Web Station mit Apache 2.4
- PHP 8.2
- Projektdateien: `/volume1/web/FamilienDMS`
- MariaDB 10
- MariaDB-Port: `3308`

Die IP-Adresse ist eine private Heimnetzadresse. Diese Anleitung geht deshalb zunächst davon aus, dass FamilienDMS nur im Heimnetz oder über ein VPN genutzt wird. Richte keine Router-Portweiterleitung auf diese Adresse ein, bevor HTTPS und ein sicherer Fernzugriff geklärt sind.

## 1. Projektdateien und Webroot

Lege die Repository-Dateien in den gemeinsamen Ordner `/volume1/web/FamilienDMS`. Der Document Root des Apache-2.4-Profils muss ausschließlich auf dessen Unterordner `public` zeigen. In Web Station sollte die Zuordnung sinngemäß so aussehen:

```text
Host: 192.168.178.15 (oder später ein DNS-Name)
Backend-Server: Apache HTTP Server 2.4
PHP-Profil: PHP 8.2
Document Root: /volume1/web/FamilienDMS/public
```

Verwende nicht `/volume1/web/FamilienDMS` als Document Root, weil dann auch Konfiguration und interne PHP-Dateien direkt abrufbar wären.

## 2. PHP-Profil

Öffne in Web Station die Einstellungen des verwendeten PHP-8.2-Profils und aktiviere mindestens:

- `pdo_mysql`
- `mbstring`
- `fileinfo`
- `openssl`

Sessions müssen ebenfalls verfügbar sein. Für die Installation sollte `display_errors` nicht öffentlich ausgegeben werden; Fehler gehören in ein nur für Administratoren zugängliches PHP-/Apache-Log.

## 3. Private Konfiguration und Dokumentenspeicher

Erstelle den Dokumentenspeicher **außerhalb** von `web`, zum Beispiel:

```text
/volume1/@appdata/familiendms/storage/
```

Für eine einfache Einrichtung ohne DSM-Umgebungsvariable lege die aktive Konfiguration als `/volume1/web/FamilienDMS/config/config.php` an. Diese Datei liegt außerhalb des Document Roots (`public/`) und wird von Git ignoriert. Kopiere dazu `config/config.example.php` und trage Datenbankname, Datenbankbenutzer, Passwort und den privaten Speicherpfad ein. Schränke die Dateirechte so ein, dass nur Administratoren und der Apache/PHP-Prozess sie lesen können. Wenn du die Konfiguration stattdessen ganz außerhalb von `web` ablegen willst, setze `FAMILIENDMS_CONFIG` auf ihren absoluten Pfad.

Der PHP-Prozess benötigt Schreibzugriff auf den privaten Ordner `storage/`. Synology Web Station verwendet dafür die Gruppe `http`; gib dieser Gruppe nur die nötigen Rechte auf Document Root und Speicherordner.

Die App unterstützt `FAMILIENDMS_CONFIG` und `FAMILIENDMS_STORAGE` als Umgebungsvariablen. Die Standardkonfiguration wird ohne `FAMILIENDMS_CONFIG` unter `config/config.php` im Projektverzeichnis gesucht.

## 4. MariaDB 10

Erstelle eine eigene Datenbank mit Zeichensatz `utf8mb4` und einen eigenen Datenbankbenutzer nur für FamilienDMS. Verwende nicht den MariaDB-Administratorkonto für die Anwendung. Die App benötigt für den normalen Betrieb nur `SELECT`, `INSERT`, `UPDATE` und `DELETE`; führe das Schema mit einem administrativen Konto aus und entziehe danach etwaige zusätzliche Rechte.

Importiere:

```text
database/migrations/001_initial.sql
```

Die genaue MariaDB-Unterversion und der unterstützte Weg zum Import hängen von der DSM-Paketkonfiguration ab. Für diese Installation ist Port `3308` angegeben. Wenn MariaDB auf derselben Synology läuft und TCP-Verbindungen aktiviert sind, trage in der privaten `config.php` `127.0.0.1` als Host und `3308` als Port ein. Zugangsdaten nicht in Chat oder GitHub eintragen.

## 5. Verbindung und HTTPS prüfen

`https://192.168.178.15` ist laut Rückmeldung erreichbar. Prüfe im Browser noch, ob ein Schloss ohne Zertifikatswarnung angezeigt wird und das Zertifikat als gültig für genau diese Adresse gilt. Bei einer IP-Adresse passt ein Zertifikat häufig nicht zum Namen. Für gültiges HTTPS ist ein passender DNS-Name mit Zertifikat oft einfacher.

Die Anwendung setzt das Secure-Attribut der Session-Cookies, wenn die Anfrage über HTTPS eintrifft. Für produktive Nutzung sollte ein gültiges HTTPS-Zertifikat aktiv sein.

Wenn DSM einen Reverse Proxy verwendet, der HTTPS annimmt und intern per HTTP an Apache weiterleitet, kann PHP die HTTPS-Anfrage ohne Proxy-Header nicht erkennen. In diesem Fall darf `trust_proxy_headers` in `config/config.php` nur dann auf `true` gesetzt werden, wenn die Reverse-Proxy-Regel `X-Forwarded-Proto` zuverlässig auf `https` setzt und der Webdienst nicht direkt über einen zweiten, ungeschützten Weg erreichbar ist. Bei direktem HTTPS-Web-Portal bleibt die Einstellung `false`.

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

- Browser bestätigt ein gültiges HTTPS-Zertifikat ohne Warnung
- PHP-Erweiterungen `pdo_mysql`, `mbstring`, `fileinfo` und `openssl` im Profil prüfen (bisher nur vermutet)
- Schreibrechte des Apache/PHP-Benutzers für private Konfiguration und Speicherordner
- MariaDB-10-Unterversion und ob die Verbindung über `127.0.0.1:3308` möglich ist
- Ob Zugriff ausschließlich im Heimnetz/VPN erfolgen soll (empfohlen) oder von außen

