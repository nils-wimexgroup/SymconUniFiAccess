# UniFi Access Grundriss

Zeigt die Türen eines [UniFi Access Controllers](../UniFiAccessController) als eigene Kachel auf
einem Grundriss an – mit Live-Status und Platzieren der Türen per Drag & Drop direkt in der
Visualisierung.

Es sind mehrere Instanzen möglich, zum Beispiel eine je Etage oder Gebäude. Jede Instanz hat ihr
eigenes Bild und ihre eigenen Türpositionen; alle greifen auf denselben Controller zu.

## Voraussetzungen

* IP-Symcon 7.1 oder neuer
* Eine konfigurierte Instanz *UniFi Access Controller*
* Ein Medienobjekt vom Typ Bild mit dem Grundriss

## Einrichtung

1. **Grundriss als Medienobjekt anlegen:** im Objektbaum *Objekt hinzufügen → Medienobjekt*,
   Typ *Bild*, und die Datei hochladen (PNG, JPG, WEBP, GIF oder SVG).
2. **Instanz anlegen:** *Instanz hinzufügen → UniFi Access Grundriss*.
3. Controller-Instanz und Medienobjekt auswählen, optional eine Überschrift vergeben,
   **Übernehmen**.
4. **Kachel einbinden:** in der Kachel-Visualisierung diese Instanz ins Layout ziehen –
   sie liegt dann neben der Status-Kachel des Controllers.
5. **Türen platzieren:** in der Kachel auf *Türen platzieren*, unten eine Tür auswählen und
   auf die passende Stelle im Grundriss klicken.

## Konfiguration

| Feld | Bedeutung |
|---|---|
| UniFi Access Controller | Instanz, deren Türen angezeigt werden |
| Grundriss-Bild | Medienobjekt mit dem Plan |
| Überschrift | optionaler Titel in der Kachel, z. B. „Erdgeschoss" |
| Türnamen anzeigen | Beschriftung neben den Punkten ein-/ausblenden |
| Nur platzierte Türen berücksichtigen | blendet nicht platzierte Türen aus der Auswahlliste aus – sinnvoll, wenn mehrere Etagen sich die Türen aufteilen |

Das Bild wird als `data:`-URI in die Kachel eingebettet. Dadurch braucht es weder einen Webhook
noch einen zusätzlichen Webserver, das Bild sollte aber nicht unnötig groß sein – unter 1 MB ist
eine gute Richtgröße. Übertragen wird es nur beim Laden der Kachel, nicht bei Statusänderungen.

## Darstellung

| Punkt | Bedeutung |
|---|---|
| grün | Tür geschlossen |
| orange | Tür offen, Alarmverzögerung läuft noch |
| rot, pulsierend | Sirenenalarm aktiv |
| grau | kein Türkontakt vorhanden |
| blass + „Wartung" | Sirene für diese Tür im Wartungsmodus abgeschaltet |

Bei offenen Türen zählt die Beschriftung die Offen-Dauer sekundengenau mit, ohne dafür den Server
zu fragen.

## Türen platzieren

Im Modus *Türen platzieren*:

* **Setzen:** Tür unten in der Liste anklicken, dann auf den Grundriss klicken
* **Verschieben:** gesetzte Tür mit der Maus ziehen
* **Entfernen:** gesetzte Tür anklicken ohne zu ziehen

Die Positionen liegen als Prozentwerte in einem Attribut der Instanz und überstehen einen Neustart.
Sie sind bewusst nicht an die Bildgröße gebunden – die Kachel skaliert das Bild, die Punkte
wandern mit.

## Öffentliche Funktionen

```php
UACFP_SetDoorPosition(int $InstanceID, string $DoorID, float $X, float $Y);  // Prozentwerte 0..100
UACFP_ClearDoorPositions(int $InstanceID);
UACFP_Refresh(int $InstanceID);   // wird vom Controller automatisch aufgerufen
```

## Zusammenspiel mit dem Controller

Die Grundriss-Instanz hält selbst keine Verbindung zum UniFi Access Controller. Sie liest den
Türzustand über `UAC_GetTileData()` aus der Controller-Instanz, und der Controller ruft nach jeder
Änderung `UACFP_Refresh()` auf allen Grundriss-Instanzen auf, die auf ihn zeigen. Es entsteht also
kein zusätzlicher Netzwerkverkehr zum Controller.
