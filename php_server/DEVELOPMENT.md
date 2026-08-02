# EACY Access Demo — note di sviluppo

## 1. Obiettivo

L'applicazione gestisce un sistema dimostrativo di controllo accessi basato su PHP, MySQL e firmware ESP8266. Il pannello amministrativo configura varchi, utenti anagrafici, badge, autorizzazioni, ingressi, anomalie e log.

## 2. Struttura

```text
.
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

## 3. Configurazione

Le costanti applicative sono definite in `config.php`:

- `APP_NAME`, `APP_TIMEZONE` e `SESSION_NAME`;
- credenziali MySQL e `TABLE_PREFIX`;
- `MAX_API_BODY_BYTES` per il body Base64.

Non è prevista autenticazione delle richieste edge nella fase demo. L'endpoint mostrato dal pannello usa HTTP e non aggiunge parametri di chiave.

## 4. Tabelle

### `users`

Account amministrativi usati per login, sessione e risoluzione delle anomalie.

### `directory_users`

Anagrafica dei nominativi assegnabili ai badge. È distinta dagli account amministrativi.

### `access_points`

Configurazione dei varchi con nome, posizione, descrizione e metadati dell'ultimo collegamento. Non esiste un flag amministrativo di abilitazione: un varco presente è considerato operativo e il pannello lo classifica online o offline in base a `last_seen_at`.

Durante un POST valido vengono aggiornati `last_seen_at`, `last_sync_at`, `last_ip` e `updated_at`.

### `badges`

Contiene UID, assegnatario, contatore, stato, note e data dell'ultima osservazione. `updated_at` viene modificato soltanto dalle operazioni amministrative; l'API aggiorna `counter` e `last_seen_at` senza alterarlo.

Il massimo contatore ordinario è `4294967294`. Il valore `4294967295` è riservato alla sentinella del protocollo.

### `badge_access`

Relazione molti-a-molti fra badge e varchi. La coppia `(badge_id, access_point_id)` è la chiave primaria.

### `access_events`

Storico degli eventi ricevuti. La chiave univoca è:

```text
(access_point_id, badge_uid, counter, raw_timestamp)
```

Il varco fa parte dell'identità: due lettori possono produrre record con UID, contatore e timestamp identici senza che uno venga eliminato.

L'indice `idx_event_uid_time (badge_uid, raw_timestamp, counter)` supporta il caricamento mirato dello storico degli UID presenti nella sincronizzazione corrente.

Il risultato viene classificato come:

- `GRANTED` per un UID presente nella tabella `badges`;
- `ANOMALY` per il contatore sentinella;
- `UNKNOWN` per un UID non registrato.

`GRANTED` descrive l'esito locale prodotto dal varco e non una rivalutazione retroattiva del backend.

### `anomalies`

Ogni riga rappresenta un singolo evento o episodio anomalo. La fingerprint è univoca e include tipo, varco, UID e dati specifici dell'evidenza. Il replay è un no-op e non riapre una riga risolta.

### `system_logs`

Contiene attività amministrative, autenticazioni, errori API e sincronizzazioni che hanno prodotto almeno un nuovo evento di accesso.

Non esiste una tabella `app_meta`: l'installer rileva l'installazione verificando la presenza dell'insieme completo delle tabelle applicative e di almeno un account amministrativo.

## 5. Flusso dispositivo

### GET

Restituisce il timestamp Unix arrotondato come testo ASCII. L'ID non viene verificato e il contatto non aggiorna il varco.

### POST

1. Verifica l'esistenza del varco.
2. Controlla dimensione e validità del payload Base64.
3. Decodifica badge presenti sul lettore e storico.
4. Avvia una transazione e blocca soltanto la riga del varco con `SELECT ... FOR UPDATE`, serializzando le sincronizzazioni dello stesso dispositivo.
5. Carica soltanto i badge citati dal payload corrente.
6. Segnala la sentinella nella configurazione locale e impedisce che venga salvata come contatore.
7. Aggiorna i contatori ordinari osservati.
8. Carica da `access_events` soltanto lo storico degli UID contenuti nella coda ricevuta; non usa un lock globale della tabella.
9. Unisce e deduplica lo storico usando varco, UID, contatore e timestamp.
10. Inserisce i nuovi eventi tramite l'indice univoco del database.
11. Crea in modo idempotente le anomalie inferite o segnalate dall'edge.
12. Seleziona i badge attivi autorizzati al varco con contatore non sentinella.
13. Registra un log API soltanto se sono stati inseriti nuovi eventi.
14. Esegue il commit e restituisce la risposta binaria.

## 6. Protocollo binario

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

Le funzioni pure si trovano in `includes/device_protocol.php`.

## 7. Sentinella e monotonicità

Sono definite due costanti di protocollo:

```text
DEVICE_COUNTER_MAX = 4294967294
DEVICE_COUNTER_OUT_OF_SYNC = 4294967295
```

La sentinella:

- genera `CARD_OUT_OF_SYNC` quando compare nello storico;
- genera lo stesso tipo di alert, con fingerprint distinta, quando compare nella configurazione locale;
- non aggiorna `badges.counter`;
- non viene serializzata nella risposta;
- viene saltata da `protocol_non_monotonic_transitions()` e non diventa il riferimento precedente.

Una sequenza `4294967295 → 25` non genera quindi un falso `COUNTER_NOT_MONOTONIC`. Se la sequenza ordinaria circostante è realmente decrescente, per esempio `3 → sentinella → 2`, viene comunque rilevata la transizione `3 → 2`.

## 8. Deduplicazione

`protocol_history_merge_and_deduplicate()` considera duplicati soltanto record con gli stessi:

```text
access_point_id + UID + counter + timestamp
```

Lo schema MySQL applica la stessa identità mediante `uq_event`, rendendo l'inserimento idempotente anche in presenza di sincronizzazioni concorrenti.

Per il controllo di monotonicità il contatore rimane associato al badge attraverso tutti i varchi; il varco corrente e quello precedente vengono conservati nella fingerprint della transizione.

## 9. Anomalie event-based

`create_anomaly()` calcola una fingerprint SHA-1 da:

```text
tipo | access_point_id | badge_uid | parti specifiche dell'evento
```

L'inserimento usa `ON DUPLICATE KEY UPDATE id = id`. Una fingerprint già presente non modifica date o stato.

Le parti specifiche principali sono:

- `CARD_OUT_OF_SYNC` da storico: sorgente `history`, sentinella e timestamp;
- `CARD_OUT_OF_SYNC` da configurazione: sorgente `allowed_cards` e sentinella;
- `DISABLED_BADGE_ACCESS_AFTER_SYNC`: contatore e timestamp dell'accesso;
- `COUNTER_NOT_MONOTONIC`: varco, contatore e timestamp del record precedente e corrente;
- `DISABLED_BADGE_STILL_CONFIGURED`: `badges.updated_at` dell'episodio amministrativo.

La cancellazione fisica di un'anomalia elimina la fingerprint e consente alla coda del lettore di ricrearla. La risoluzione conserva invece la riga e rende il replay innocuo.

## 10. Installazione durante lo sviluppo

`install.php` crea direttamente lo schema corrente e l'account amministrativo. Non sono presenti versioni dello schema o script di migrazione: prima del rilascio ufficiale gli adeguamenti vengono gestiti manualmente, ricreando o modificando il database secondo necessità.

## 11. Sicurezza del pannello

- query preparate per i valori dinamici;
- escaping HTML con `e()`;
- token CSRF sulle operazioni amministrative;
- password salvate con `password_hash()`;
- cookie di sessione `HttpOnly` e `SameSite=Lax`;
- errori PHP non mostrati dall'endpoint dispositivo.

La comunicazione edge non è autenticata né cifrata in questa fase demo.
