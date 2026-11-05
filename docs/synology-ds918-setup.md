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

Lege die Repository-Dateien in den gemeinsamen Ordner `/volume1/web/FamilienDMS`. Für den gewünschten Unterpfad `http://192.168.178.15/FamilienDMS/public/install.php` muss der Document Root des Webportals `/volume1/web` sein. Die Anwendung verwendet automatisch den URL-Unterpfad für Navigation und Weiterleitungen. Das ist weniger sicher als ein Document Root direkt auf `public/`, deshalb blockieren `.htaccess`-Dateien Zugriffe auf interne Ordner. Apache muss `.htaccess`-Regeln ausführen können.

```text
Host: 192.168.178.15
Backend-Server: Apache HTTP Server 2.4
PHP-Profil: PHP 8.2
Document Root: /volume1/web
```

Prüfe nach dem Kopieren, dass `http://192.168.178.15/FamilienDMS/config/config.example.php` mit **403 Forbidden** abgewiesen wird. Bei einer anderen Antwort nicht mit dem Installer fortfahren; dann greift Apache die Sperrdatei nicht korrekt auf. Über das empfohlene Document Root `/volume1/web/FamilienDMS/public` wäre die URL stattdessen kürzer (`/install.php`) und die privaten Projektordner wären außerhalb des Document Roots.

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


Für die angeforderte HTTP-Entwicklungsadresse muss `allow_insecure_install` in `config/config.php` vorübergehend `true` sein und `force_secure_cookies` auf `false`, damit der Browser die Session-Cookies über HTTP zurücksendet. HTTP verschlüsselt keine Anmeldedaten oder Sitzungen; diese Betriebsart nur vorübergehend im vertrauenswürdigen Heimnetz nutzen, nicht über Internet oder öffentliches WLAN. Nach der Ersteinrichtung `allow_insecure_install` wieder auf `false` setzen und bevorzugt auf HTTPS zurückwechseln.

## 6. Erstinstallation

1. Für die bereits verwendete Adresse `http://192.168.178.15/FamilienDMS/public/install.php` muss der Document Root wie oben beschrieben `/volume1/web` sein. Ein Document Root direkt auf `.../FamilienDMS/public` ergibt eine kürzere Adresse ohne `/FamilienDMS/public`.
2. Prüfe im PHP-Profil die vier oben genannten Erweiterungen.
   Für den Dokumentenupload setze `upload_max_filesize` auf mindestens `20M` und `post_max_size` auf mindestens `24M`.
3. Lege private Konfiguration und Speicherordner außerhalb von `web` an und setze passende Rechte.
4. Importiere die SQL-Migration und trage die App-Datenbankverbindung in `config.php` ein.
5. Öffne `http://192.168.178.15/FamilienDMS/public/install.php` ausschließlich im Heimnetz und erstelle den ersten Administrator.
6. Entferne danach `public/install.php` oder sperre die Datei im Apache/Web-Station-Host.
7. Melde dich an und prüfe, dass `config.php` und private Speicherdateien nicht über eine URL abrufbar sind.
8. Richte verschlüsselte Backups von Datenbank und privatem Speicher ein und teste die Wiederherstellung.

Die vorhandene Migration `database/migrations/001_initial.sql` enthält bereits die Tabelle `documents`. Wenn sie bei der bestehenden Einrichtung importiert wurde, ist für die Dokumentenfunktionen kein erneuter SQL-Import nötig.

## Bestehende Installation aktualisieren

Die Datenbank und der bereits angelegte Benutzer bleiben bei einer Aktualisierung unverändert. Für das neue Layout und das Dokumentenarchiv nur diese Dateien auf der Synology ersetzen beziehungsweise ergänzen:

```text
app/bootstrap.php
app/upload.php
app/document_storage.php   (neu)
public/dashboard.php
public/documents.php       (neu)
public/download.php        (neu)
public/preview.php         (neu)
public/preview-file.php    (neu)
public/install.php
public/assets/app.css
```

`config/config.php`, `database/migrations/001_initial.sql` und die MariaDB-Daten nicht ersetzen oder erneut importieren. Danach im Browser die Anmeldeseite neu laden (bei Bedarf mit Strg+F5) und erneut anmelden. Falls statt einer leeren Seite ein Fehlercode `FDM-...` erscheint, diesen Code zusammen mit dem passenden Eintrag aus dem PHP-/Apache-Fehlerprotokoll prüfen. Zugangsdaten oder den vollständigen Inhalt der Konfiguration nicht weitergeben.

Die Dokumentenansicht erwartet die vorhandene `documents`-Tabelle und Schreibrechte des PHP-Prozesses auf den privaten Speicherordner. Für Uploads müssen `upload_max_filesize=20M` und `post_max_size=24M` gesetzt sein. Dokumente bleiben außerhalb des Webroots; sie werden über die Anmeldung geschützt heruntergeladen. PDFs haben eine Onlinevorschau im Browser. Es gibt derzeit keine Ordner/Kategorien oder Virenprüfung.

Nach erfolgreicher Anmeldung zusätzlich sicherstellen, dass `allow_insecure_install` in der privaten Konfiguration wieder `false` ist und `public/install.php` gesperrt oder entfernt wurde. Für die laufende Entwicklung über HTTP muss `force_secure_cookies` vorübergehend `false` bleiben; sobald HTTPS korrekt eingerichtet ist, wieder auf HTTPS umstellen.

## Noch zu prüfen

- Browser bestätigt ein gültiges HTTPS-Zertifikat ohne Warnung
- PHP-Erweiterungen `pdo_mysql`, `mbstring`, `fileinfo` und `openssl` im Profil prüfen (bisher nur vermutet)
- Schreibrechte des Apache/PHP-Benutzers für private Konfiguration und Speicherordner
- MariaDB-10-Unterversion und ob die Verbindung über `127.0.0.1:3308` möglich ist
- Ob Zugriff ausschließlich im Heimnetz/VPN erfolgen soll (empfohlen) oder von außen

