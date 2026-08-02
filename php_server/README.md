# EACY Access Demo — PHP/MySQL

Webapp dimostrativa per un sistema di controllo accessi con firmware ESP8266. Include pannello amministrativo Bootstrap, login, gestione varchi, badge, anagrafica utenti, storico accessi, anomalie aggregate e log applicativi.

## Requisiti

- PHP 7.4 o successivo;
- estensione PDO MySQL;
- MySQL o MariaDB con supporto InnoDB;
- permessi di scrittura su `config.php` durante l’installazione;
- mod_rewrite, facoltativo, per usare gli URL brevi `/api/<id>`;
- HTTPS consigliato per il pannello amministrativo.

## Funzionalità principali

- gestione dei varchi e degli endpoint usati dal firmware;
- gestione dei badge tramite UID, contatore, stato e autorizzazioni;
- anagrafica utenti separata dagli account amministrativi;
- assegnazione del badge tramite ricerca nell’anagrafica e selezione del nominativo;
- storico degli ingressi e rilevazione delle anomalie;
- alert aggregati per tipo, badge e varco;
- log delle sole sincronizzazioni che producono nuovi eventi di accesso;
- profilo dell’amministratore e protezione CSRF delle operazioni amministrative.

## Installazione

1. Crea o abilita un database MySQL sul provider.
2. Carica il contenuto del pacchetto nella root pubblica del sito.
3. Verifica che PHP possa scrivere `config.php`.
4. Apri `https://TUO_DOMINIO/install.php`.
5. Inserisci host, database, utente, password e prefisso delle tabelle.
6. Scegli le credenziali dell’amministratore e avvia l’installazione.
7. Accedi da `login.php` e crea utenti anagrafici, varchi e badge.

L’installer salva la configurazione MySQL in `config.php`. L’installazione è considerata conclusa quando esiste la tabella `<prefisso>app_meta`.

Il database iniziale contiene soltanto l’account amministratore. Varchi, utenti anagrafici, badge e associazioni non vengono precompilati.

## Endpoint dispositivo

Endpoint breve per il varco con ID 1:

```text
http://TUO_DOMINIO/api/1
```

Alternativa senza rewrite:

```text
http://TUO_DOMINIO/api/device.php?id=1
```

Quando `DEVICE_API_KEY` non è vuota, la chiave deve essere aggiunta a entrambe le richieste GET e POST:

```text
http://TUO_DOMINIO/api/1?key=CHIAVE
```

Il GET restituisce il timestamp Unix in testo ASCII. Non verifica l’esistenza del varco e non ne aggiorna lo stato.

Il POST riceve il payload Base64 del lettore, verifica l’ID del varco e restituisce la sequenza binaria dei badge attivi autorizzati.

## Anagrafica e badge

La pagina `people.php` gestisce i nominativi assegnabili ai badge. Gli account amministrativi usati per il login restano separati.

In `badges.php` l’assegnatario viene scelto con un campo di ricerca che filtra un normale selettore HTML. Il selettore resta utilizzabile anche senza JavaScript. Gli utenti disattivati restano visibili e sono indicati come tali.

Eliminando un utente anagrafico, i badge associati diventano non assegnati grazie al vincolo `ON DELETE SET NULL`.

## Alert e log

Le anomalie con lo stesso tipo, UID e varco aggiornano una sola riga. A ogni nuova rilevazione aumentano il numero di occorrenze e la data dell’ultima rilevazione; un alert risolto viene riaperto.

Le sincronizzazioni senza nuovi eventi di accesso non vengono inserite nei log applicativi. Errori API, autenticazioni e operazioni amministrative continuano a essere registrati.

## Limite badge del firmware

Il server restituisce tutti i badge attivi autorizzati al varco. Il firmware fornito gestisce al massimo `FIRMWARE_MAX_BADGES` elementi; il limite è informativo e non viene applicato dal server.

## Installazioni esistenti

Questo pacchetto non include script di migrazione. `install.php` crea direttamente lo schema versione 2 per una nuova installazione, ma non aggiorna automaticamente un database precedente quando la tabella `app_meta` esiste già.

Prima di usare il pacchetto su un’installazione esistente, esegui un backup e prepara una migrazione compatibile con lo schema di partenza.

Per struttura, schema e flusso dell’API consulta `DEVELOPMENT.md`.
