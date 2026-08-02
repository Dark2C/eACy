# EACY Access Demo — note di sviluppo

## 1. Obiettivo

L’applicazione gestisce un sistema dimostrativo di controllo accessi basato su PHP, MySQL e firmware ESP8266. Il pannello amministrativo permette di configurare varchi, utenti anagrafici, badge, autorizzazioni, ingressi, anomalie e log.

## 2. Requisiti

- PHP 7.4 o successivo;
- estensione PDO MySQL;
- MySQL o MariaDB con tabelle InnoDB;
- HTTPS consigliato per il pannello amministrativo;
- rewrite opzionale per gli URL `/api/<id>`;
- accesso alle CDN di Bootstrap e Bootstrap Icons usate dall’interfaccia.

## 3. Struttura

```text
.
├── .htaccess
├── README.md
├── DEVELOPMENT.md
├── config.php
├── install.php
├── login.php
├── logout.php
├── index.php
├── devices.php
├── people.php
├── badges.php
├── events.php
├── anomalies.php
├── logs.php
├── profile.php
├── api/
│   └── device.php
├── assets/
│   ├── app.css
│   └── app.js
└── includes/
    ├── bootstrap.php
    ├── db.php
    ├── device_protocol.php
    ├── functions.php
    ├── header.php
    └── footer.php
```

## 4. Configurazione

Le costanti applicative sono definite in `config.php`:

- `APP_NAME`, `APP_TIMEZONE` e `SESSION_NAME`;
- credenziali MySQL e `TABLE_PREFIX`;
- `DEVICE_API_KEY`, facoltativa;
- `MAX_API_BODY_BYTES` per il body Base64;
- `FIRMWARE_MAX_BADGES`, limite informativo non applicato lato server.

Durante una nuova installazione, `install.php` verifica la connessione e riscrive `config.php` con i dati inseriti.

## 5. Tabelle

### `users`

Account amministrativi usati per login, sessione e risoluzione delle anomalie.

### `directory_users`

Anagrafica dei nominativi assegnabili ai badge:

- `full_name`;
- `email` e `phone` facoltativi;
- `notes`;
- `active`;
- date di creazione e modifica.

Questa tabella è distinta dagli account amministrativi.

### `access_points`

Configurazione dei varchi. Durante un POST valido vengono aggiornati `last_seen_at`, `last_sync_at` e `last_ip`.

Il campo `enabled` è attualmente informativo: l’endpoint POST verifica che il varco esista, ma non rifiuta la sincronizzazione di un varco disattivato.

### `badges`

Contiene UID, assegnatario, contatore, stato, note e data dell’ultima osservazione. `assignee_id` punta a `directory_users` con `ON DELETE SET NULL`.

### `badge_access`

Relazione molti-a-molti fra badge e varchi. La coppia `(badge_id, access_point_id)` è la chiave primaria.

### `access_events`

Storico degli eventi ricevuti. La chiave univoca globale usa `badge_uid`, `counter` e `raw_timestamp`; il varco non fa parte della chiave.

Il risultato viene classificato come:

- `GRANTED` per un UID presente nella tabella `badges`;
- `ANOMALY` per il contatore sentinella `4294967295`;
- `UNKNOWN` per un UID non registrato.

La classificazione `GRANTED` indica la presenza del badge in anagrafica e non verifica, in questa fase, stato o autorizzazione al varco.

### `anomalies`

Gli alert sono identificati da una fingerprint deterministica composta da tipo, varco e UID. La fingerprint è univoca. Una nuova rilevazione aggiorna `last_seen_at`, incrementa `occurrences` e riapre l’alert se era risolto.

### `system_logs`

Contiene attività amministrative, autenticazioni, errori API e sincronizzazioni che hanno prodotto almeno un nuovo evento di accesso.

### `app_meta`

Tabella marker creata per ultima dall’installer. Contiene almeno `installed_at` e `schema_version`, impostata a `2` nelle nuove installazioni.

## 6. Flusso dispositivo

La chiave `DEVICE_API_KEY`, quando configurata, viene verificata prima della distinzione fra GET e POST e si applica quindi a entrambi i metodi.

### GET

Restituisce il timestamp Unix arrotondato come testo ASCII. L’ID non viene verificato e il contatto non aggiorna lo stato del varco.

### POST

1. Verifica la chiave API opzionale.
2. Verifica l’esistenza del varco.
3. Controlla dimensione e validità del payload Base64.
4. Decodifica badge presenti sul lettore e storico.
5. Aggiorna metadati del varco e contatori dei badge osservati.
6. Unisce lo storico ricevuto a quello memorizzato e rimuove i duplicati globali.
7. Inserisce i nuovi eventi.
8. Aggiorna gli alert per contatori non monotoni o fuori sincronia.
9. Seleziona i badge attivi autorizzati al varco.
10. Registra un log API soltanto quando sono stati inseriti nuovi eventi.
11. Esegue il commit e restituisce la risposta binaria.

Le modifiche a varco, badge, eventi, anomalie e log della sincronizzazione vengono eseguite usando la stessa connessione e transazione PDO. Gli errori vengono registrati dopo il rollback.

## 7. Protocollo binario

Il body del POST è Base64. Dopo la decodifica:

```text
4 byte  numero di badge presenti sul lettore, uint32 little-endian
N × 8   UID da 4 byte + contatore uint32 little-endian
M × 12  UID da 4 byte + contatore + timestamp uint32 little-endian
```

La risposta è una concatenazione di record da 8 byte:

```text
UID da 4 byte + contatore uint32 little-endian
```

Le funzioni pure di decodifica, serializzazione, merge, deduplicazione e controllo dei contatori sono raccolte in `includes/device_protocol.php`.

## 8. Anomalie aggregate

`create_anomaly()` calcola la fingerprint con:

```text
tipo | access_point_id | badge_uid
```

La funzione cerca la riga con un lock transazionale. Se la trova, aggiorna la stessa riga; altrimenti ne crea una nuova. Lo schema aggiunge un indice univoco sulla fingerprint per proteggere il comportamento in presenza di richieste concorrenti.

Per il contatore sentinella, gli UID vengono prima raccolti in un insieme: più record dello stesso badge nello stesso payload producono un solo aggiornamento dell’alert in quella sincronizzazione.

## 9. Log di sincronizzazione

`api/device.php` conta le righe effettivamente inserite in `access_events`. Il log con sorgente `API` viene creato soltanto quando `new_events > 0`.

Le sincronizzazioni che aggiornano esclusivamente metadati del varco, contatori o risposta badge non generano un log informativo. Gli errori API continuano a essere registrati.

## 10. Campo assegnatario ricercabile

`badges.php` carica gli utenti da `directory_users` e rende:

- un input di ricerca per nome, email o telefono;
- un normale `<select name="assignee_id">` con l’opzione “Nessun assegnatario”.

`assets/app.js` filtra le opzioni del selettore senza sostituire il controllo nativo. Senza JavaScript, l’elenco completo rimane selezionabile. Il pulsante di azzeramento rimuove filtro e assegnatario.

Gli utenti disattivati rimangono nell’elenco con l’indicazione “disattivo”. Al salvataggio, il server verifica che l’ID ricevuto esista in `directory_users`.

## 11. Installazione e aggiornamenti

`install.php` crea lo schema versione 2, l’account amministrativo e infine `app_meta`.

Se trova già la tabella `<prefisso>app_meta`, considera l’applicazione installata, salva eventualmente la nuova configurazione di connessione e non esegue modifiche allo schema.

Il pacchetto non include script di migrazione né test automatici. L’aggiornamento di database precedenti deve quindi essere gestito separatamente, dopo un backup completo.

## 12. Sicurezza

- query preparate per i valori dinamici;
- escaping HTML con `e()`;
- token CSRF sulle operazioni amministrative;
- password salvate con `password_hash()`;
- cookie di sessione `HttpOnly` e `SameSite=Lax`, con flag `Secure` quando la richiesta usa HTTPS;
- chiave API opzionale per le richieste del dispositivo;
- errori PHP non mostrati dall’endpoint dispositivo.
