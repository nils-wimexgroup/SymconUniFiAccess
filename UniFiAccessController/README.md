# UniFi Access Controller

IP-Symcon-Modul, das einen UniFi Access Controller über die Developer-API abfragt, alle Türen
im Objektbaum abbildet und pro Tür eine Sirene auslösen kann, wenn die Tür länger als eine
einstellbare Zeit offen steht.

Geschaltet wird die Sirene über eine **schaltbare Variable einer anderen Instanz** – typischerweise
das Relais eines Shelly 1 Gen4. Wie dieser Shelly angebunden ist (MQTT, HTTP, welches Modul auch
immer), ist für dieses Modul unerheblich: es kennt nur die Variable.

## Voraussetzungen

* IP-Symcon 7.1 oder neuer (wegen der eigenen Visualisierungs-Kachel / HTML-SDK)
* UniFi Access mit aktivierter Developer-API (Port 12445)
* API-Token aus UniFi Access: **Einstellungen → Allgemein → Erweitert → API-Token**
* Optional pro Tür: eine Instanz mit schaltbarer Boolean-Variable als Sirenenschalter
  (z. B. Shelly 1 Gen4 als MQTT- oder HTTP-Instanz)

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

### Sirene

Jede Tür hat ihren eigenen Shelly und damit ihre eigene Relais-Variable. Die wird deshalb pro Tür
in der Türliste ausgewählt (siehe unten). Hier steht nur, was für alle Sirenen gilt:

| Feld | Bedeutung |
|---|---|
| Maximale Sirenenlaufzeit | Nach dieser Zeit schaltet die Sirene ab; sie geht erst wieder an, wenn die Tür zwischenzeitlich geschlossen war. `0` = unbegrenzt |

Ein zyklisches Abfragen der Shellys ist nicht nötig: das Modul meldet sich per `VM_UPDATE` für
Änderungen der Relais-Variablen an und übernimmt jeden Zustandswechsel sofort – egal wer ihn
ausgelöst hat.

> **Hinweis zum Ausfall von IP-Symcon:** Die frühere HTTP-Anbindung hat die maximale Laufzeit
> zusätzlich als `toggle_after` im Shelly hinterlegt, so dass die Sirene auch bei einem Ausfall von
> IP-Symcon abschaltete. Über eine generische Variable ist das nicht möglich. Wer diese Absicherung
> braucht, richtet sie im Shelly selbst ein (z. B. Auto-Off in der Switch-Konfiguration).

### Türen / Sirenenalarm

Über **Türen vom Controller laden** wird die Liste mit allen Türen des Controllers befüllt
(danach **Übernehmen** klicken). Pro Zeile:

| Spalte | Bedeutung |
|---|---|
| Tür-ID | ID aus der UniFi-API (nicht ändern) |
| Name | Nur zur Orientierung |
| Alarm | Sirenenalarm für diese Tür aktivieren |
| Offen länger als | Verzögerung in Sekunden, bis die Sirene auslöst |
| Relais-Variable der Sirene | Schaltbare Boolean-Variable, die die Sirene dieser Tür schaltet – ohne Variable gibt es keinen Alarm |
| Bezeichnung der Sirene | Klartextname, z. B. „Sirene Lagerhalle" |

Die Variable muss vom Typ Boolean sein und eine Aktion hinterlegt haben (`VariableAction` bzw.
`VariableCustomAction`) – sonst lehnt das Modul sie mit einer Fehlermeldung ab. Geschaltet wird über
`RequestAction`, also über genau denselben Weg wie ein Klick im Objektbaum.

Bleibt die Bezeichnung leer, nimmt das Modul den Namen der Instanz, zu der die Variable gehört, und
notfalls den Namen der Variablen selbst.

Mehrere Türen dürfen sich eine Sirene teilen – dieselbe Variable in mehreren Zeilen genügt.
Abgeschaltet wird erst, wenn keine der beteiligten Türen mehr im Alarmzustand ist.

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

## Sirenenzustand (hat die Sirene wirklich geschaltet?)

Ein abgesetzter Schaltbefehl heißt nicht, dass die Sirene auch wirklich läuft. Das Modul vergleicht
deshalb laufend seinen Sollwert mit dem Istwert der Relais-Variablen:

* **Istwert** ist der aktuelle Wert der Variablen (`GetValue`)
* **Erreichbarkeit** ergibt sich aus dem Status der Instanz, zu der die Variable gehört
  (aktiv = Status 102) – ein Shelly, der sich per MQTT abgemeldet hat, fällt damit sofort auf
* aktualisiert wird bei jeder Änderung der Variablen (`VM_UPDATE`), nach jedem eigenen
  Schaltvorgang und auf Knopfdruck über „Ist-Zustand anzeigen" im Formular

Im Objektbaum entsteht eine Kategorie **Sirenen** mit je einem Unterordner pro Sirene, benannt nach
der eingetragenen Bezeichnung:

| Variable | Bedeutung |
|---|---|
| Erreichbar | ob die Instanz hinter der Variablen aktiv ist |
| Relais (Ist) | aktueller Wert der Relais-Variablen |
| Relais (Soll) | was das Modul geschaltet haben will |
| Abweichung | Ist ≠ Soll – der Schaltbefehl ist nicht angekommen |
| Letzte Meldung | Zeitpunkt der letzten Zustandsänderung |
| Letzter Fehler | Fehlertext, wenn das Schalten fehlgeschlagen ist |

In der Kachel steht das Ganze als Abschnitt „Sirenen" unter der Türliste, mit Bezeichnung als
Überschrift und der Variablen-ID darunter. Nicht erreichbare Sirenen und Abweichungen sind rot
hinterlegt, zusätzlich landet beides als Warnung im IPS-Meldungslog. Je Sirene gibt es dort auch
die Schaltflächen **EIN** und **AUS**, um sie einzeln zu testen.

Verwaltet wird jede in der Türliste eingetragene Variable, jede genau einmal – auch wenn sich
mehrere Türen eine Sirene teilen.

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
UAC_TestSiren(int $InstanceID, int $VariableID, bool $On);  // einzelne Sirene testweise schalten
UAC_CheckSirens(int $InstanceID);           // Ist-Zustand aller Sirenen einlesen
UAC_ShowSirenStatus(int $InstanceID);       // dito, mit Textausgabe

UAC_SetSirenEnabled(int $InstanceID, string $DoorID, bool $Enabled);  // Wartungsmodus je Tür
UAC_GetEventLog(int $InstanceID);           // Verlauf als JSON-String
UAC_ClearEventLog(int $InstanceID);         // Verlauf löschen
UAC_GetTileData(int $InstanceID);           // aktueller Türzustand als JSON (nutzt das Grundriss-Modul)
```

## Verwendete Schnittstellen

* UniFi Access: `GET https://<controller>:12445/api/v1/developer/doors`
  mit Header `Authorization: Bearer <token>`
* Sirene schalten: `RequestAction(<Relais-Variable>, true|false)` – die eigentliche Kommunikation
  mit dem Gerät macht das Modul, dem die Variable gehört
* Sirenenzustand lesen: `GetValue(<Relais-Variable>)` und
  `IPS_GetInstance(<Instanz der Variablen>)['InstanceStatus']`
