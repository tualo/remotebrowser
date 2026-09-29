# remotepdf


## docker setup


````
    apt update
    apt install chromium
    ./tm configuration --section browsershot --key noSandbox --value 1
    ./tm configuration --section browsershot --key chrome_path --value $(which chromium)
    ./tm configuration --section browsershot --key node_binary --value $(which node)
    ./tm configuration --section browsershot --key npm_binary --value $(which npm)
````

## Einstellungen

Alle Einstellungen liegen in der Sektion `browsershot`:

```
./tm configuration --section browsershot --key <key> --value <value>
```

| Key                      | Default | Beschreibung |
|--------------------------|---------|--------------|
| `use`                    | leer    | Leer: Report wird vorab lokal gerendert (PUG-Export, Ermittlung des `<title>`). Beliebiger Wert: Vorab-Rendering entfällt. |
| `remote_service`         | leer    | Basis-URL eines externen PDF-Dienstes (`POST /pdf` mit `{url, cookies}`). Schlägt der Dienst fehl, wird lokal per Browsershot gerendert. |
| `remote_service_timeout` | `3.0`   | Timeout für den Remote-Service in Sekunden. |
| `chrome_path`            | –       | Pfad zur Chrome/Chromium-Binary. |
| `node_binary`            | –       | Pfad zur Node-Binary. |
| `npm_binary`             | –       | Pfad zur npm-Binary. |
| `noSandbox`              | `0`     | `1`: Chrome mit `--no-sandbox` starten (nötig in Docker bzw. als root). |
| `useHeadless`            | `0`     | `1`: neuen Headless-Modus von Chrome verwenden. |

## Hinweise

- Der Browser ruft `/pugreporthtml/{tablename}/{template}/{id}` mit der Session des Aufrufers auf. Die PHP-Session wird dafür während der PDF-Erzeugung freigegeben, sonst blockiert der Session-Lock (`Navigation timeout`).
- Bei OAuth-Sitzungen wird statt des Session-Cookies ein Einmal-Token in die URL eingebaut und danach wieder entfernt.
- Der Server muss sich selbst unter `REQUEST_SCHEME://HTTP_HOST` erreichen können.
