# UniFi Access Controller

IP-Symcon-Modul, das einen UniFi Access Controller über die Developer-API abfragt, alle Türen
im Objektbaum abbildet und pro Tür eine Sirene (Shelly 1 Gen4) auslösen kann, wenn die Tür
länger als eine einstellbare Zeit offen steht.

## Voraussetzungen

* IP-Symcon 7.1 oder neuer (wegen der eigenen Visualisierungs-Kachel / HTML-SDK)
* UniFi Access mit aktivierter Developer-API (Port 12445)
* API-Token aus UniFi Access: **Einstellungen → Allgemein → Erweitert → API-Token**
* Optional: Shelly 1 Gen4 (oder ein anderer Shelly der Gen2+ Reihe) als Sirenenschalter

## Installation

1. In IP-Symcon: **Kern-Instanzen → Modules → Hinzufügen** und das Repository/Verzeichnis angeben.
   Alternativ den Ordner `UniFiAccess` nach `<IP-Symcon>/modules/` kopieren und IP-Symcon neu starten.
2. Neue Instanz anlegen: **Instanz hinzufügen → UniFi Access Controller**.
3. Instanz sinnvoll benennen, z. B. nach dem Standort des Controllers.

## Konfiguration

### UniFi Access Controller

| Feld | Bedeutung |
|---|---|
| Instanz aktiv | Schaltet die Abfrage komplett ab, ohne die Instanz zu löschen |
| IP-Adresse / Hostname | Adresse des Access Controllers (ohne `https://`) |
| Port | Standard `12445` (Developer-API) |
| API-Token | Bearer-Token aus UniFi Access |
| Abfrageintervall | Sekunden zwischen zwei Abfragen, `0` = nur manuell |
| Timeout | Timeout der HTTP-Anfrage in Millisekunden |
| TLS-Zertifikat prüfen | Aus lassen, solange der Controller ein selbstsigniertes Zertifikat nutzt |

### Sirene (Shelly 1 Gen4)

Jede Tür hat ihren eigenen Shelly. IP-Adresse und Bezeichnung werden deshalb pro Tür in der
Türliste eingetragen (siehe unten). Hier stehen nur die Einstellungen, die für alle Shellys gelten:

| Feld | Bedeutung |
|---|---|
| Relais-Kanal | Beim Shelly 1 Gen4 immer `0` |
| Benutzer / Passwort | Nur nötig, wenn im Shelly die Authentifizierung aktiv ist. Benutzer ist bei Gen2+ immer `admin` |
| Maximale Sirenenlauffzeit | Nach dieser Zeit schaltet die Sirene ab; sie geht erst wieder an, wenn die Tür zwischenzeitlich geschlossen war. `0` = unbegrenzt |
| Shelly-Zustand abfragen alle | Intervall der Rückfrage des tatsächlichen Relaiszustands, `0` = keine zyklische Abfrage |

Der Wert wird zusätzlich als `toggle_after` an den Shelly übergeben, damit die Sirene auch dann
abschaltet, wenn IP-Symcon in der Zwischenzeit ausfällt.

### Türen / Sirenenalarm

Über **Türen vom Controller laden** wird die Liste mit allen Türen des Controllers befüllt
(danach **Übernehmen** klicken). Pro Zeile:

| Spalte | Bedeutung |
|---|---|
| Tür-ID | ID aus der UniFi-API (nicht ändern) |
| Name | Nur zur Orientierung |
| Alarm | Sirenenalarm für diese Tür aktivieren |
| Offen länger als | Verzögerung in Sekunden, bis die Sirene auslöst |
| Shelly IP-Adresse | Shelly, der die Sirene dieser Tür schaltet – ohne Adresse gibt es keinen Alarm |
| Bezeichnung der Sirene | Klartextname, z. B. „Sirene Lagerhalle" |

Die Bezeichnung wird überall anstelle der IP-Adresse verwendet: im Objektbaum, in der Kachel und in
den Logmeldungen. Ohne Bezeichnung erscheint die IP.

Mehrere Türen dürfen sich eine Sirene teilen – dann genügt es, die Bezeichnung in einer der Zeilen
einzutragen. Abgeschaltet wird erst, wenn keine der beteiligten Türen mehr im Alarmzustand ist.

## Objektbaum

Auf Instanzebene:

| Variable | Typ | Bedeutung |
|---|---|---|
| Letzte Abfragedauer | Float (ms) | Dauer der letzten API-Abfrage |
| Zeitpunkt der letzten Abfrage | Integer (Unix) | Wird bei jedem Abfrageversuch gesetzt, auch bei Fehlern |
| Letzter Fehler | String | Text des zuletzt aufgetretenen Fehlers (wird bewusst nicht geleert) |
| Letzter Fehler (Zeitpunkt) | Integer (Unix) | Wann dieser Fehler auftrat |

Ob aktuell ein Problem besteht, zeigt der Instanzstatus (rotes Symbol) bzw. der Vergleich von
"Zeitpunkt der letzten Abfrage" und "Letzter Fehler (Zeitpunkt)".

Pro Tür wird eine Kategorie angelegt mit:

| Variable | Typ | Quelle |
|---|---|---|
| Name | String | `name` |
| Typ (type) | String | `type` |
| Schlossrelais (door_lock_relay_status) | String | `door_lock_relay_status` (`lock` / `unlock`) |
| Türposition (door_position_status) | String | `door_position_status` (`open` / `close`) |
| Tür offen | Boolean | abgeleitet, nur wenn ein Türkontakt vorhanden ist |
| Offen seit | Integer (Unix) | nur bei aktiviertem Alarm |
| Sirenenalarm | Boolean | nur bei aktiviertem Alarm |

Die Wartungsschalter **Sirene aktiv: &lt;Türname&gt;** liegen bewusst direkt unter der Instanz und
nicht in der Tür-Kategorie: nur so lassen sie sich über `EnableAction` überall schalten
(Objektbaum, Standard-Kachel, Skript). Gleichzeitig hat man alle stummgeschalteten Türen an
einer Stelle im Blick.

Ohne Türkontakt liefert die API kein `door_position_status`; dann entfallen "Tür offen" und
der Alarm für diese Tür.

## Visualisierung

Das Modul bringt eine eigene Kachel mit (HTML-SDK). In der Kachel-Visualisierung einfach die
Instanz in das Layout ziehen – es sind keine einzelnen Variablen nötig.

**Reiter „Status"** – alle Türen auf einen Blick:

* Ampelpunkt und „Abfrage vor x s" für den Zustand der API-Verbindung, bei Fehlern zusätzlich der Fehlertext
* pro Tür: geschlossen / offen mit sekundengenau mitlaufender Dauer / ALARM, dazu verriegelt bzw. entriegelt
* Türen ohne Türkontakt werden als solche gekennzeichnet
* Schalter **Sirene / Wartung** je Tür (siehe unten)
* Schaltfläche „Aktualisieren" für eine sofortige Abfrage

**Reiter „Verlauf"** – Protokoll der Öffnungen, neueste zuerst:

| Spalte | Inhalt |
|---|---|
| Tür | Name der Tür |
| Geöffnet / Geschlossen | Zeitpunkte, laufende Öffnungen stehen als „offen" |
| Dauer | wie lange die Tür offen war (bzw. bereits ist) |
| Sirene | ob während dieser Öffnung die Sirene ausgelöst hat (Zeile rot hinterlegt) |

Für eine Darstellung auf einem Grundriss gibt es das eigenständige Modul
[UniFi Access Grundriss](../UniFiAccessGrundriss) – das liefert eine zweite Kachel, die neben
dieser liegt.

Über das Dropdown lässt sich auf eine einzelne Tür filtern. Die Anzahl der aufbewahrten Einträge
ist einstellbar (Standard 200), „Verlauf leeren" verwirft das Protokoll.

Der Verlauf liegt in einem Attribut der Instanz und übersteht einen Neustart. Für Langzeitauswertung
und IPS-Diagramme zusätzlich die Option **„Tür offen" und „Sirenenalarm" im Archiv protokollieren**
aktivieren – das Modul setzt das Logging dann selbst.

## Shelly-Rückfrage (hat die Sirene wirklich geschaltet?)

Ein abgesetzter Schaltbefehl heißt nicht, dass der Shelly ihn auch ausgeführt hat. Das Modul fragt
deshalb per `Switch.GetStatus` den tatsächlichen Relaiszustand ab und vergleicht ihn mit dem Sollwert:

* **direkt nach jedem Schaltvorgang** – genau dann ist die Frage interessant
* **zyklisch** im eingestellten Intervall (Standard 60 s)
* **auf Knopfdruck** über „Shellys abfragen" im Formular oder „Sirenen prüfen" in der Kachel

Die Abfrage läuft in einem eigenen Timer. Ein nicht erreichbarer Shelly bremst dadurch die
Türabfrage nicht aus, auch wenn er ins Timeout läuft.

Im Objektbaum entsteht eine Kategorie **Sirenen** mit je einem Unterordner pro Shelly, benannt nach
der eingetragenen Bezeichnung:

| Variable | Bedeutung |
|---|---|
| Erreichbar | ob der Shelly geantwortet hat |
| Relais (Ist) | tatsächlicher Zustand laut Shelly |
| Relais (Soll) | was das Modul geschaltet haben will |
| Abweichung | Ist ≠ Soll – der Schaltbefehl ist nicht angekommen |
| Antwortzeit | Dauer der letzten Abfrage in ms |
| Letzte Prüfung | Zeitpunkt |
| Letzter Fehler | Fehlertext, wenn der Shelly nicht antwortet |

In der Kachel steht das Ganze als Abschnitt „Sirenen" unter der Türliste, mit Bezeichnung als
Überschrift und IP-Adresse darunter. Nicht erreichbare Shellys und Abweichungen sind rot
hinterlegt, zusätzlich landet beides als Warnung im IPS-Meldungslog. Je Sirene gibt es dort auch
die Schaltflächen **EIN** und **AUS**, um sie einzeln zu testen.

Abgefragt wird jede in der Türliste eingetragene Adresse, jede genau einmal – auch wenn sich
mehrere Türen einen Shelly teilen.

## Wartungsmodus (Sirene je Tür abschalten)

Für jede überwachte Tür gibt es direkt unter der Instanz die Variable
**Sirene aktiv: &lt;Türname&gt;** (Ident `SirenEnabled_<Tür-ID>`, Profil `~Switch`).
Steht sie auf *Aus*, löst die Sirene für diese Tür nicht aus – unabhängig davon, wie lange die Tür
offen steht. Ein bereits laufender Alarm wird sofort beendet. Alle anderen Türen sind davon nicht
betroffen; eine gemeinsam genutzte Sirene bleibt an, solange eine andere Tür noch Alarm meldet.

Schalten geht über die Kachel, direkt über die Variable oder per Skript:

```php
UAC_SetSirenEnabled(12345, '<Tür-ID>', false);   // Wartung: Sirene aus
UAC_SetSirenEnabled(12345, '<Tür-ID>', true);    // wieder scharf
```

Damit ein vergessener Wartungsmodus nicht dauerhaft bestehen bleibt, kann unter
**Verlauf und Wartung** eine automatische Rückstellung nach x Minuten gesetzt werden
(0 = keine automatische Rückstellung). Jedes Ein- und Ausschalten landet im IPS-Meldungslog.

## Zeitverhalten des Alarms

Der Offen-Zustand wird im Abfrageintervall erkannt. Sobald eine überwachte Tür offen ist, läuft
zusätzlich ein Sekundentimer, der die Verzögerung sekundengenau auswertet. Die effektive
Auslöseverzögerung liegt also zwischen `x` und `x + Abfrageintervall` Sekunden – für ein enges
Zeitfenster das Abfrageintervall entsprechend klein wählen (z. B. 5 s).

## Öffentliche Funktionen

```php
UAC_Poll(int $InstanceID);                  // Abfrage sofort ausführen, liefert bool
UAC_CheckAlarms(int $InstanceID);           // Alarmlogik neu auswerten
UAC_TestConnection(int $InstanceID);        // Verbindungstest mit Türliste
UAC_LoadDoors(int $InstanceID);             // Türen in die Konfigurationsliste laden
UAC_CleanupDoors(int $InstanceID);          // verwaiste Türen aus dem Objektbaum entfernen
UAC_TestSiren(int $InstanceID, string $Host, bool $On);  // einzelne Sirene testweise schalten
UAC_CheckSirens(int $InstanceID);           // Ist-Zustand aller Shellys abfragen
UAC_ShowSirenStatus(int $InstanceID);       // dito, mit Textausgabe

UAC_SetSirenEnabled(int $InstanceID, string $DoorID, bool $Enabled);  // Wartungsmodus je Tür
UAC_GetEventLog(int $InstanceID);           // Verlauf als JSON-String
UAC_ClearEventLog(int $InstanceID);         // Verlauf löschen
UAC_GetTileData(int $InstanceID);           // aktueller Türzustand als JSON (nutzt das Grundriss-Modul)
```

## Verwendete Schnittstellen

* UniFi Access: `GET https://<controller>:12445/api/v1/developer/doors`
  mit Header `Authorization: Bearer <token>`
* Shelly Gen2+ schalten: `POST http://<shelly>/rpc` mit `{"method":"Switch.Set","params":{"id":0,"on":true,"toggle_after":300}}`
* Shelly-Authentifizierung: Digest SHA-256. Die Challenge liefert der Shelly bei HTTP
  ausschließlich im `WWW-Authenticate`-Header, der Body der 401 ist leer. Der `nonce` ist Base64
  und darf nicht in eine Zahl gewandelt werden, `nc` ist Pflichtfeld.
  Woraus `ha2` gebildet wird, lässt die Shelly-Doku offen („depends on the transport type") und
  zeigt `dummy_method:dummy_uri` nur am WebSocket-Beispiel. Das Modul probiert deshalb beim ersten
  authentifizierten Aufruf `dummy_method:dummy_uri` und `POST:/rpc` durch und merkt sich die
  akzeptierte Variante; danach ist es wieder genau eine Anfrage.
* Shelly Gen2+ abfragen: `POST http://<shelly>/rpc` mit `{"method":"Switch.GetStatus","params":{"id":0}}`
