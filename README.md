# UniFi Access (IP-Symcon Library)

Bibliothek zur Anbindung eines UniFi Access Controllers an IP-Symcon.

## Enthaltene Module

| Modul | Beschreibung |
|---|---|
| [UniFi Access Controller](UniFiAccessController) | Fragt die Türen eines UniFi Access Controllers per Developer-API ab, bildet sie im Objektbaum ab und kann pro Tür eine Sirene (Shelly 1 Gen4) auslösen, wenn die Tür zu lange offen steht |
| [UniFi Access Grundriss](UniFiAccessGrundriss) | Zeigt die Türen als eigene Kachel auf einem Grundriss, mit Platzieren per Drag & Drop. Mehrere Instanzen möglich, z. B. eine je Etage |

Beide Module bringen ihre eigene Kachel für die Kachel-Visualisierung mit (HTML-SDK, ab
IP-Symcon 7.1). Eine zusätzliche Lizenz oder ein Fremdmodul wird dafür nicht benötigt.

## Installation

**Modules-Instanz in IP-Symcon:** Kern-Instanzen → *Modules* → **+** → Pfad bzw. Repository dieser
Bibliothek eintragen.

**Manuell:** Ordner `UniFiAccess` nach `<IP-Symcon-Verzeichnis>/modules/` kopieren und IP-Symcon
neu starten.
