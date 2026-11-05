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
database/migrations/002_categories.sql
database/migrations/003_document_tags.sql
database/migrations/004_folders.sql
database/migrations/005_document_text.sql
database/migrations/006_document_suggestions.sql
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
4. Importiere `001_initial.sql`, `002_categories.sql`, `003_document_tags.sql`, `004_folders.sql` und `005_document_text.sql` genau in dieser Reihenfolge, dann trage die App-Datenbankverbindung in `config.php` ein.
5. Öffne `http://192.168.178.15/FamilienDMS/public/install.php` ausschließlich im Heimnetz und erstelle den ersten Administrator.
6. Entferne danach `public/install.php` oder sperre die Datei im Apache/Web-Station-Host.
7. Melde dich an und prüfe, dass `config.php` und private Speicherdateien nicht über eine URL abrufbar sind.
8. Richte verschlüsselte Backups von Datenbank und privatem Speicher ein und teste die Wiederherstellung.

Bei einer bestehenden Installation bereits ausgeführte Migrationen nicht erneut importieren. Bereits installierte Kategorien- und Tag-Migrationen bleiben unangetastet. Importiere fehlende Migrationen der Reihe nach: zuerst `004_folders.sql`, falls noch nicht installiert, danach einmalig `005_document_text.sql` und danach einmalig `006_document_suggestions.sql`. Diese Migration ergänzt bestätigte Metadaten und Prüfvermerke; Dateien, OCR-Texte und bestehende Ablagen bleiben unverändert. Die Texterkennungs-Migration erstellt eine Indexwarteschlange und reiht bestehende Dokumente zur Verarbeitung ein; sie verändert weder Benutzerkonten noch Originaldateien.

## Bestehende Installation aktualisieren

Vor dem Update Datenbank und privaten Dokumentenspeicher sichern. Datenbank und Benutzer bleiben erhalten. Importiere ausschließlich noch fehlende Migrationen. Da die OCR-Warteschlange bereits eingerichtet ist, importiere bei deiner bestehenden Installation jetzt nur `006_document_suggestions.sql`. Importiere keine Datei erneut, deren Änderung bereits in der Datenbank vorhanden ist. Ergänze oder ersetze anschließend die folgenden Anwendungsdateien auf der Synology:

```text
database/migrations/006_document_suggestions.sql (SQL genau einmal importieren)
app/document_suggestions.php (neu)
public/suggestions.php (neu)
app/bootstrap.php
public/documents.php
public/assets/app.css
```

`config/config.php`, bereits ausgeführte Migrationen und die MariaDB-Daten nicht ersetzen oder erneut importieren. Nach den noch fehlenden Migrationen die Seite neu laden (bei Bedarf mit Strg+F5) und erneut anmelden. Falls statt einer Seite ein Fehlercode `FDM-...` erscheint, diesen Code zusammen mit dem passenden Eintrag aus dem PHP-/Apache-Fehlerprotokoll prüfen. Zugangsdaten oder den vollständigen Inhalt der Konfiguration nicht weitergeben.

Die Dokumentenansicht erwartet `document_categories`, `folders`, `documents.folder_id`, `documents.sender`, `documents.document_date`, `documents.document_type` und `document_text`; dafür ist Migration 006 nötig. Für Uploads müssen `upload_max_filesize=20M` und `post_max_size=24M` gesetzt sein. Dokumente bleiben außerhalb des Webroots; Vorschauen und Downloads sind geschützt. PDFs und JPEG/PNG-Bilder haben eine Onlinevorschau. Die Inhaltssuche wird im Hintergrund ergänzt, nachdem der DSM-Auftrag läuft und die OCR-Werkzeuge verfügbar sind.

### Texterkennung einrichten

Dein laufender Container `tesseractshadow-tesseract4re-1` hat `deu` und `eng` geladen. Er hat keinen Port und keinen Ordner eingebunden; das ist in Ordnung. FamilienDMS streamt Bilder über `docker exec` direkt in Tesseract. Für PDFs braucht die App zusätzlich OCRmyPDF, da Tesseract selbst PDFs nicht als Eingabedatei liest.

1. Im Container Manager unter **Registrierung** nach `jbarlow83/ocrmypdf-alpine` suchen und das Image herunterladen. Laut OCRmyPDF-Projekt unterstützt es x86_64 und enthält standardmäßig deutsche und englische Sprachdaten.
2. Lege in DSM **Systemsteuerung → Aufgabenplaner** einen benutzerdefinierten Auftrag an. Verwende PHP 8.2 als CLI-Interpreter. Der DSM-Benutzer für den Auftrag muss die private Konfiguration lesen und den Docker-Dienst aufrufen dürfen. Gib dem Webserver selbst keinen Zugriff auf den Docker-Dienst.
3. Als Skript ausführen (den PHP-Pfad an deine Synology anpassen):

```sh
export FAMILIENDMS_CONFIG='/volume1/web/FamilienDMS/config/config.php'
export FAMILIENDMS_OCR_DOCKER='/usr/local/bin/docker'
export FAMILIENDMS_OCR_LANG='deu+eng'
export FAMILIENDMS_TESSERACT_CONTAINER='tesseractshadow-tesseract4re-1'
export FAMILIENDMS_OCR_IMAGE='jbarlow83/ocrmypdf-alpine'
/var/packages/PHP8.2/target/usr/local/bin/php82 /volume1/web/FamilienDMS/scripts/index_documents.php --limit=20
```

Führe den Auftrag zunächst manuell aus und prüfe die Ausgabe sowie das DSM-Auftragsprotokoll. Bei Erfolg steht dort zum Beispiel `Dokument 12: indexed`. Plane ihn danach wiederkehrend, zum Beispiel stündlich; neue Uploads und vorhandene Dokumente aus der Migration werden automatisch eingereiht. Der PDF-OCR-Lauf verarbeitet die ersten 20 Seiten mit einem Thread. Bei fehlgeschlagenen Einträgen kann derselbe Auftrag mit `--limit=1 --retry-failed` erneut laufen. Die OCR-Texte liegen in der Datenbank; sichere deshalb Datenbank und private Originaldateien gemeinsam. Nach einem erfolgreichen OCR-Lauf findest du Vorschläge für Absender, Datum, Dokumentart, Kategorie und Ordner unter `/FamilienDMS/public/suggestions.php`; prüfe und bestätige sie dort einzeln.

Nach erfolgreicher Anmeldung zusätzlich sicherstellen, dass `allow_insecure_install` in der privaten Konfiguration wieder `false` ist und `public/install.php` gesperrt oder entfernt wurde. Für die laufende Entwicklung über HTTP muss `force_secure_cookies` vorübergehend `false` bleiben; sobald HTTPS korrekt eingerichtet ist, wieder auf HTTPS umstellen.

## Noch zu prüfen

- Browser bestätigt ein gültiges HTTPS-Zertifikat ohne Warnung
- PHP-Erweiterungen `pdo_mysql`, `mbstring`, `fileinfo` und `openssl` im Profil prüfen (bisher nur vermutet)
- Schreibrechte des Apache/PHP-Benutzers für private Konfiguration und Speicherordner
- MariaDB-10-Unterversion und ob die Verbindung über `127.0.0.1:3308` möglich ist
- Ob Zugriff ausschließlich im Heimnetz/VPN erfolgen soll (empfohlen) oder von außen


## Passwort vergessen und Benutzerzugriff

Der Anmeldebildschirm enthält “Passwort vergessen?”. Der Benutzer sendet dort seinen Benutzernamen und informiert anschließend einen Administrator. In **Benutzerverwaltung** erscheint bei einem aktiven Konto der Hinweis **Rücksetzung angefragt**. Der Administrator setzt dort ein neues Passwort und teilt es dem Benutzer über einen getrennten, vertrauenswürdigen Weg mit. Es wird keine E-Mail versendet. Ein Passwort-Reset beendet bestehende Sitzungen des Kontos. Über dieselbe Verwaltung lassen sich andere Konten deaktivieren und wieder aktivieren; das eigene Konto und der letzte aktive Administrator sind vor Deaktivierung geschützt.

Importiere für diese Funktionen nach den vorhandenen Migrationen 001–006 die Datei database/migrations/007_account_recovery.sql genau einmal.
