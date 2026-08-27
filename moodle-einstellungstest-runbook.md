# Moodle-Einstellungstest — Aufbau- & Härtungs-Runbook
**Verbandsgemeinde Kirchen · digitalisierter Einstellungstest für Azubis**

Dieses Runbook beschreibt den sauberen Neuaufbau einer gehärteten Moodle-Instanz
in Docker/Portainer, ausgelegt auf genau ein Szenario:

> Bewerber sitzen an bereitgestellten Laptops, melden sich mit vorab
> eingerichteten Zugangsdaten an, klicken sich durch **einen** Test und
> verlassen danach den Raum. Ein Komitee wertet die Ergebnisse über die
> Moodle-Statistiken aus.

Halte dieses Dokument zusammen mit dem `Dockerfile`, der `config.php` und
`docker-compose.yml` in einem **Git-Repository** — das ist gleichzeitig dein
Nachweis der Härtung und deine Wiederherstellungsgrundlage.

---

## 0. Warum kein Bitnami-Image

Bitnami hat sein Docker-Angebot umgestellt: versionierte Tags liegen jetzt im
unwartbaren `bitnamilegacy`-Repo, gepflegte Images gibt es nur noch im
kostenpflichtigen „Bitnami Secure Images"-Abo, frei bleibt praktisch nur ein
`:latest` **für Entwicklungszwecke**. Für ein behördlich freigegebenes
Prüfungssystem ist beides ungeeignet. Deshalb: **eigenes, gepinntes Image aus
offiziellem Moodle-Quellcode** (siehe `Dockerfile`). Volle Patch-Hoheit,
herstellerunabhängig, auditierbar.

---

## 1. Vor dem Aufbau zu entscheiden

| Entscheidung | Empfehlung fürs Prüfungssystem |
|---|---|
| Moodle-Version | **4.5 LTS** (`MOODLE_405_STABLE`) — Stabilität vor Features. Aktuelle LTS auf moodledev.io prüfen. |
| Konten pro Bewerber | **Ein eigenes Konto pro Bewerber** (Ergebniszuordnung, Nachweisbarkeit). |
| Browser-Absicherung | Kein SEB. Stattdessen im Quiz „Vollbild-Popup mit JavaScript-Sicherung" + Netz-Isolierung + physische Aufsicht. |
| Netz | Prüfungs-Laptops in **isoliertem VLAN**, das nur den Moodle-Server erreicht. |
| TLS | Phase 1 lokal per IP (HTTP) zum Aufbauen/Testen; Phase 2 produktiv über den vorhandenen Nginx Proxy Manager (HTTPS/Domain). |

---

## 2. Installation (Reihenfolge einhalten)

### 2.1 Dateien bereitstellen
Lege `Dockerfile`, `docker-entrypoint.sh`, `config.php`, `docker-compose.yml`
und `.env` (aus `.env.example`) zusammen in ein Git-Repo. Erzeuge **starke,
zufällige** Passwörter in der `.env`.

### 2.2 Stack in Portainer deployen
Portainer braucht für den `build:`-Schritt den Build-Kontext. Zwei Wege:

- **Empfohlen:** *Stacks → Add stack → Repository* und dein Git-Repo angeben.
  Portainer baut das Image dann selbst.
- **Alternativ:** Image einmal auf dem Host bauen
  (`docker build -t moodle-einstellungstest:MOODLE_405_STABLE .`) und im Compose
  den `build:`-Block durch die feste `image:`-Zeile ersetzen.

### 2.3 Datenbank per CLI installieren (einmalig)
Nachdem der Stack läuft, im **moodle**-Container die Installation ausführen —
so entsteht ein sauberer, skriptbarer Erstzustand:

```bash
docker exec -it <moodle-container> php admin/cli/install_database.php \
  --lang=de \
  --adminuser=admin \
  --adminpass='EIN_SEHR_STARKES_ADMINPASSWORT' \
  --adminemail='it@vg-kirchen.example' \
  --fullname='Einstellungstest VG Kirchen' \
  --shortname='ETVGK' \
  --agree-license
```

Die DB-Zugangsdaten und `wwwroot` zieht das Skript aus der gemounteten
`config.php`. Danach ist Moodle über `MOODLE_WWWROOT` erreichbar.

### 2.4 Phasenbetrieb: erst lokal per IP, später über den Nginx Proxy Manager

**Phase 1 — lokal per IP (HTTP), zum Aufbauen und Testen**
- In `.env` die Phase-1-Zeilen nutzen: `MOODLE_WWWROOT=http://<IP>:8080`,
  `MOODLE_COOKIESECURE=false`, `MOODLE_REVERSEPROXY=false`, `MOODLE_SSLPROXY=false`.
- Wichtig: Über reines HTTP **muss** `cookiesecure` aus sein — sonst kannst du
  dich nicht einloggen (der Browser sendet sichere Cookies nur über HTTPS).

**Phase 2 — produktiv über den vorhandenen Nginx Proxy Manager (HTTPS/Domain)**
1. In NPM einen Proxy-Host anlegen, der auf den Moodle-Container zeigt.
   - Damit NPM den Container erreicht, entweder den Host-Port `8080` verwenden
     (Ziel `http://<docker-host>:8080`) **oder** — sauberer — NPM und diesen
     Stack in ein **gemeinsames externes Docker-Netz** hängen und als Ziel den
     Servicenamen `moodle` Port `80` eintragen (dann den Host-Port-Mapping-Block
     in `docker-compose.yml` entfernen).
   - „Force SSL" aktivieren, Zertifikat (Let's Encrypt) in NPM ausstellen.
   - NPM setzt `X-Forwarded-*`-Header automatisch — genau die liest Moodle mit
     `reverseproxy=true`/`sslproxy=true`.
2. In `.env` auf die Phase-2-Zeilen umstellen:
   `MOODLE_WWWROOT=https://<domain>`, `MOODLE_COOKIESECURE=true`,
   `MOODLE_REVERSEPROXY=true`, `MOODLE_SSLPROXY=true`.
3. Stack neu deployen, dann **Caches leeren**:
   ```bash
   docker exec -it <moodle-container> php admin/cli/purge_caches.php
   ```

> Da sich mit dem Wechsel die `wwwroot` ändert, ist das Cache-Leeren Pflicht.
> Führe den **echten Test** möglichst in Phase 2 (HTTPS) durch. Falls ein
> Durchgang doch lokal über HTTP läuft, ist das nur vertretbar, weil das
> Prüfungsnetz isoliert ist — Zugangsdaten und Antworten liefen sonst
> unverschlüsselt über das LAN.

### 2.5 Cron prüfen
Der `cron`-Container ruft minütlich `admin/cli/cron.php` auf. In Moodle unter
*Website-Administration → Server → Aufgaben → Geplante Vollausführung* prüfen,
dass der Cron frisch durchläuft.

---

## 3. Härtung Teil A — bereits im Code festgeschrieben

Diese Werte stehen in `config.php` und sind in der Oberfläche **gesperrt**:

- `forcelogin`, `forceloginforprofiles` — kein anonymer Zugriff
- `opentowebcrawlers = false` — keine Suchmaschinen-Indexierung
- `cookiehttponly = true` — fest an (auch über HTTP sinnvoll)
- `cookiesecure` — über `MOODLE_COOKIESECURE` gesteuert (Phase 1 aus, Phase 2 an)
- `registerauth = ''`, `guestloginbutton = 0`, `authloginviaemail = 0`
  — keine Selbstregistrierung, kein Gastzugang
- `messaging = false` — Teilnehmer können sich nicht gegenseitig schreiben
- `enablewebservices = false` — keine offene API/App-Schnittstelle
- `cronclionly = true`, `preventexecpath = true`

Nichts davon musst du in der GUI nachziehen — es ist bereits gesetzt.

---

## 4. Härtung Teil B — in der Oberfläche einzustellen & abzuhaken

> Arbeite diese Liste als Checkliste ab und dokumentiere jeden Haken. Genau
> diese Dokumentation ist dein Nachweis für die Freigabe des digitalen Tests.

**Authentifizierung**
- [ ] *Plugins → Authentifizierung*: nur „Manuelle Konten" aktiv;
      „E-Mail-basierte Selbstregistrierung" und „Gastzugang" deaktivieren.
- [ ] *Website-Administration → Nutzer/innen → Bewerbungsverwaltung*: Registrierung „Keine".

**Sicherheit**
- [ ] *Sicherheit → Website-Sicherheitseinstellungen*:
  - Kontosperre nach X Fehlversuchen (`lockoutthreshold`, z. B. 5)
  - Passwort-Richtlinie aktiv
  - Session-Timeout niedrig (z. B. Testdauer + Puffer)
  - „Profile von Teilnehmer/innen nur für berechtigte Rollen sichtbar"
- [ ] *Sicherheit → HTTP-Sicherheit*: sichere Cookies bestätigt (bereits gesperrt).
- [ ] *Nutzer/innen → Datenschutzrichtlinien → Nutzerkennung anzeigen*: auf das
      Minimum reduzieren (keine E-Mail/ID an andere).

**Funktionen abschalten, die im Testbetrieb nichts zu suchen haben**
- [ ] *Erweiterte Funktionen*: Blogs, Kommentare, Badges, Tags, „Mobile App
      aktivieren" → aus.
- [ ] Messaging bleibt aus (bereits gesperrt).

**Startseite / Sichtbarkeit**
- [ ] Startseite so gestalten, dass **keine Kursliste** und keine Nutzerliste
      sichtbar ist. Der Testkurs ist **verborgen** und nur per manueller
      Einschreibung zugänglich (kein Gastzugang, kein Einschreibeschlüssel offen).
- [ ] Dashboard/Blöcke auf das Nötigste reduzieren; Nutzer dürfen das Dashboard
      nicht anpassen.

**Sprache & Theme**
- [ ] Standardsprache Deutsch erzwingen, Sprachwahlmenü ausblenden.
- [ ] Ein festes, schlichtes Theme; Nutzer dürfen kein eigenes Theme wählen.

---

## 5. Die Bewerber-Rolle (das wichtigste Härtungsstück)

Lege eine eigene Rolle an, statt der Standard-Rolle „Teilnehmer/in" zu
vertrauen: *Website-Administration → Nutzer/innen → Rechte → Rollen verwalten →
Neue Rolle anlegen*, Archetyp **Teilnehmer/in**, Name z. B. **„Prüfling"**.

Anschließend gezielt **entziehen** (Prevent):
- [ ] `moodle/course:viewparticipants` — sieht die anderen Bewerber nicht
- [ ] `moodle/site:sendmessage` — kann niemandem schreiben
- [ ] `moodle/user:viewdetails` (außerhalb des eigenen Kurses)
- [ ] `moodle/grade:viewall` / Noten anderer
- [ ] Blog-, Kommentar-, Tag-Fähigkeiten

**Erlauben** (Allow) nur:
- [ ] `mod/quiz:view` und `mod/quiz:attempt`
- [ ] Kurs betreten (`moodle/course:view` im Testkurs)

Bewerber werden in **genau diesen einen Kurs** mit der Rolle „Prüfling"
manuell eingeschrieben — nirgends sonst.

---

## 6. Bewerber-Konten anlegen (vorab)

Da die Zugangsdaten vorab eingearbeitet und übergeben werden: **pro Bewerber
ein eigenes Konto**, gebündelt per CSV-Upload.

1. *Website-Administration → Nutzer/innen → Nutzerliste → Nutzer/innen hochladen*.
2. CSV mit Spalten z. B. `username, password, firstname, lastname, email`.
   - Sprechende, aber neutrale Benutzernamen (z. B. `pruefling001`).
   - E-Mail darf eine Sammel-/Funktionsadresse sein, wenn keine echten
     Adressen genutzt werden sollen (Datenschutz).
3. Beim Upload: **„Änderung des Passworts erzwingen: Nie"** wählen — die
   Bewerber sollen sich mit dem übergebenen Passwort direkt einloggen können.
4. Konten optional erst zum Testtag entsperren.

> Tipp: Lege für die Ausgabe an die Bewerber ein sauberes Übergabeblatt an
> (Name, Benutzername, Passwort). Nach dem Test sind alle Passwörter verbraucht.

---

## 7. Der Test selbst (Quiz-Aktivität)

Diese Einstellungen stecken in der **Aktivität im Kurs**, nicht in den
Website-Einstellungen.

**Zeit**
- [ ] Öffnungs- und Schließzeit = Prüfungsfenster.
- [ ] Zeitbegrenzung setzen.
- [ ] „Wenn die Zeit abläuft": *Der Versuch wird automatisch abgegeben*.

**Versuche & Bewertung**
- [ ] Erlaubte Versuche: **1**.

**Frageverhalten**
- [ ] Frageverhalten: **Spätere Auswertung** (kein Feedback während des Versuchs).
- [ ] Antworten innerhalb der Fragen mischen: **Ja**.
- [ ] Fragenreihenfolge mischen (bzw. Zufallsfragen aus Kategorien ziehen).

**Überprüfungsoptionen — kritisch**
- [ ] In **allen vier Spalten** (während/direkt danach/später während offen/
      nach Schließung) praktisch **alles abschalten**: keine Punkte, keine
      richtigen Antworten, kein Feedback für die Bewerber.
- **Warum:** Bewerber sollen weder ihr Ergebnis noch die Musterlösung sehen —
  sonst sickern Antworten an spätere Durchgänge, und es gibt keine
  Diskussionen im Raum. Das Komitee wertet separat über die Statistik aus
  (Abschnitt 9).

**Weitere Beschränkungen des Versuchs**
- [ ] **Kennwort** für den Test — gibt der Aufsicht die Kontrolle über den Start.
- [ ] **Netzwerkadresse** auf das Prüfungs-Subnetz beschränken — nur die
      bereitgestellten Laptops können den Test überhaupt starten.
- [ ] **Browser-Sicherheit**: „Vollbild-Popup mit JavaScript-Sicherung"
      (ihr nutzt kein SEB — siehe Abschnitt 8, was diese Ebene ersetzt).

---

## 8. Laptop- & Netz-Härtung (ohne SEB)

Ihr setzt keinen Safe Exam Browser ein. Damit tragen **Netz-Isolierung,
Laptop-Lockdown und physische Aufsicht** die Betrugssicherheit — zusammen mit
den ausgeschalteten Überprüfungsoptionen (Abschnitt 7) und dem Einzelversuch.
Diese Ebenen ersetzen SEB in der Summe gut genug für einen beaufsichtigten Test
im Raum:

- [ ] Prüfungs-Laptops einheitlich imagen; USB/externe Medien sperren.
- [ ] Nur den Browser freigeben, den ihr braucht; andere Browser/Anwendungen
      und Task-Wechsel per OS-Kiosk-/Richtlinien einschränken, soweit möglich.
- [ ] **Netz-Isolierung**: Laptops in ein VLAN, das ausschließlich den
      Moodle-Server erreicht — kein Internet, keine Nachbar-Clients.
- [ ] Diese Isolierung deckt sich mit der **„Netzwerkadresse"-Beschränkung** im
      Quiz (Abschnitt 7): nur die bereitgestellten Laptops können den Test starten.
- [ ] Quiz-Browser-Sicherheit auf „Vollbild-Popup mit JavaScript-Sicherung" —
      verhindert versehentliches Wegklicken, ist aber **kein** harter Schutz;
      den liefert die Aufsicht plus Netz-Isolierung.
- [ ] Aufsicht im Raum ist gesetzt: sie hält das Test-Kennwort und beobachtet.

> Falls ihr später doch mehr Härte braucht (z. B. unbeaufsichtigte Durchgänge),
> ist Moodles native SEB-Integration der nächste Schritt — die Architektur hier
> bleibt dafür unverändert nutzbar.

---

## 9. Auswertung durch das Komitee

Nach dem Test wertet das Komitee **nicht** über die Bewerber-Ansicht aus,
sondern über die Auswertungswerkzeuge des Quiz:

- *Testkurs → Test → Ergebnisse → Bewertung*: Punktzahlen je Bewerber.
- *… → Antworten*: einzelne Antworten je Bewerber und Frage.
- *… → Statistik*: Kennzahlen je Frage (Schwierigkeit, Trennschärfe) — nützlich,
  um den Fragenkatalog über die Jahre zu verbessern.
- Export als CSV/Excel für die Akten.

Vergib dem Komitee eine Rolle mit **Leserechten auf Berichte/Bewertungen im
Testkurs** (z. B. „Bewerter/in" ohne Bearbeitungsrechte), damit niemand
versehentlich am Test schraubt.

---

## 10. Datenschutz (DSGVO) — kurz, aber verbindlich

Ihr verarbeitet als Behörde **Bewerberdaten**. Ohne Rechtsberatung ersetzen zu
wollen, gehört mindestens dazu:

- [ ] Rechtsgrundlage und Zweck dokumentiert; Eintrag im Verzeichnis von
      Verarbeitungstätigkeiten.
- [ ] **Löschkonzept**: Konten und Ergebnisse nach Abschluss des
      Auswahlverfahrens fristgerecht löschen (Moodle-Werkzeuge unter
      *Datenschutz und Richtlinien*).
- [ ] Zugriff auf Ergebnisse strikt auf das Komitee begrenzt (Rollen, s. o.).
- [ ] TLS erzwungen, Backups verschlüsselt/abgesichert.
- [ ] Betroffenenrechte (Auskunft/Löschung) bedienbar.

Stimm das mit eurem/eurer Datenschutzbeauftragten ab, bevor der erste echte
Durchgang läuft.

---

## 11. Backup & Wiederherstellbarkeit

Ein vollständiges Backup besteht aus **vier** Teilen:

1. **Datenbank** — Dump aus dem `db`-Container
   (`mysqldump`/`mariadb-dump` → in ein gesichertes Verzeichnis).
2. **moodledata** — das `moodledata`-Volume sichern.
3. **config.php** — im Git-Repo.
4. **Image-Definition** — `Dockerfile` + gepinnter `MOODLE_BRANCH` im Git-Repo.

- [ ] Vor dem Prüfungstag einen kompletten Snapshot ziehen.
- [ ] **Restore einmal testen** — ein Backup, das nie zurückgespielt wurde, ist
      kein Backup.

---

## 12. Ablauf am Prüfungstag (Kurz-Checkliste)

**Vorher**
- [ ] Snapshot/Backup gezogen.
- [ ] Cron läuft, Uhrzeit des Servers korrekt.
- [ ] Alle Bewerber-Konten aktiv, Übergabeblätter gedruckt.
- [ ] Testfenster (Öffnungs-/Schließzeit) korrekt gesetzt, Test-Kennwort bereit.
- [ ] Ein Laptop im Probelauf durch den kompletten Test geklickt.

**Während**
- [ ] Aufsicht gibt das Test-Kennwort frei/ein.
- [ ] Bewerber melden sich an, bearbeiten den Test, geben ab, verlassen den Raum.

**Nachher**
- [ ] Alle Versuche abgegeben (keine „offen"/„läuft" mehr).
- [ ] Komitee wertet über Ergebnisse/Antworten/Statistik aus, exportiert für die Akten.
- [ ] Nach Abschluss des Verfahrens: Löschkonzept ausführen.

---

## 13. Fragenkatalog übernehmen (aus der alten Instanz)

Auch wenn ihr neu aufsetzt: den **Fragenkatalog** musst du nicht neu tippen.

- Auf der **alten** Instanz: *Fragensammlung → Exportieren*, Format **Moodle XML**,
  **kategorienweise** exportieren (behält Fragetypen, Bilder, Feedback, Punkte).
- Auf der **neuen** Instanz: *Fragensammlung → Importieren*, gleiche
  Kategorienstruktur vorher anlegen, damit nicht alles in einer Sammelkategorie landet.

Die **Einstellungen/Härtung** dagegen bewusst **nicht** aus der alten Version
übernehmen — die baust du hier frisch gegen den aktuellen Stand auf (genau das
ist der Sinn dieses Runbooks).
