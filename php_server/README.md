# EACY Access Demo — PHP/MySQL

Webapp dimostrativa per un sistema di controllo accessi con firmware ESP8266. Include pannello amministrativo Bootstrap, login, gestione varchi, badge, anagrafica utenti, storico accessi, anomalie deduplicate per evento e log applicativi.

## Requisiti

- PHP 7.4 o successivo;
- estensione PDO MySQL;
- MySQL o MariaDB con supporto InnoDB;
- permessi di scrittura su `config.php` durante l'installazione;
- rewrite facoltativo per usare gli URL brevi `/api/<id>`.

## Funzionalità principali

- gestione dei varchi e degli endpoint usati dal firmware;
- stato del varco derivato esclusivamente dall'ultimo contatto: online oppure offline;
- gestione dei badge tramite UID, contatore, stato e autorizzazioni;
- anagrafica utenti separata dagli account amministrativi;
- storico degli ingressi con deduplicazione per varco ed evento;
- anomalie event-based deduplicate tramite fingerprint;
- log delle sole sincronizzazioni che producono nuovi eventi di accesso;
- profilo amministratore e protezione CSRF delle operazioni amministrative.

## Installazione

1. Crea o abilita un database MySQL.
2. Carica il contenuto del pacchetto nella root pubblica del sito.
3. Verifica che PHP possa scrivere `config.php`.
4. Apri `http://TUO_DOMINIO/install.php`.
5. Inserisci host, database, utente, password e prefisso delle tabelle.
6. Scegli le credenziali dell'amministratore e avvia l'installazione.
7. Accedi da `login.php` e crea utenti anagrafici, varchi e badge.

L'installer salva la configurazione MySQL in `config.php` e considera conclusa l'installazione quando sono presenti tutte le tabelle applicative richieste e almeno un account amministrativo. Non esistono tabelle di versione dello schema né migrazioni automatiche: durante lo sviluppo gli eventuali adeguamenti del database vengono eseguiti manualmente.

Il database iniziale contiene soltanto l'account amministratore. Varchi, utenti anagrafici, badge e associazioni non vengono precompilati.

## Endpoint dispositivo

Endpoint breve per il varco con ID 1:

```text
http://TUO_DOMINIO/api/1
```

Alternativa senza rewrite:

```text
http://TUO_DOMINIO/api/device.php?id=1
```

Il GET restituisce il timestamp Unix arrotondato in testo ASCII. Non verifica l'esistenza del varco e non aggiorna il suo stato.

Il POST riceve il payload Base64 del lettore, verifica l'ID del varco, registra gli eventi e restituisce la sequenza binaria dei badge attivi autorizzati.

## Contatori e valore sentinella

Il valore `4294967295` è riservato dal protocollo come sentinella di fuori sincronizzazione e non è un contatore ordinario.

- il massimo contatore configurabile dal pannello è `4294967294`;
- una sentinella nello storico genera `CARD_OUT_OF_SYNC`;
- una sentinella nella lista locale dei badge viene segnalata ma non viene salvata in `badges.counter`;
- la sentinella è esclusa dai controlli di monotonicità;
- il server non la restituisce mai nella configurazione binaria del lettore.

## Deduplicazione degli eventi

La coda circolare del lettore può ritrasmettere gli stessi record a ogni sincronizzazione. Un evento è identificato da:

```text
access_point_id + badge_uid + counter + raw_timestamp
```

Lo stesso record ritrasmesso dallo stesso varco viene ignorato. Un record con UID, contatore e timestamp identici ma proveniente da un altro varco resta invece un evento distinto.

Per analizzare la monotonicità il backend carica soltanto lo storico degli UID presenti nel payload corrente, senza scansionare o bloccare l'intera tabella `access_events`.

## Alert

Ogni evento o episodio anomalo ha una fingerprint deterministica. Il replay della stessa evidenza non crea duplicati e non riapre un alert già risolto.

Un badge disabilitato può comparire ancora nel payload durante la prima sincronizzazione successiva alla modifica: questa è una divergenza temporanea attesa. Se resta configurato anche dopo una sincronizzazione già completata, viene aperta `DISABLED_BADGE_STILL_CONFIGURED`.

Gli accessi già concessi dal varco restano classificati `GRANTED`, anche se al momento della ricezione il badge risulta disabilitato sul gestionale. L'eventuale divergenza viene rappresentata da un'anomalia separata.

Per struttura, schema e flusso dell'API consulta `DEVELOPMENT.md`.
