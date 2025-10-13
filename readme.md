# eACy

**eACy** è un prototipo sperimentale di *Access Control System* compatibile con infrastrutture **MIFARE Classic** che introduce un meccanismo di autenticazione basato su **HMAC** e contatori sincronizzati per garantire integrità, autenticità e rilevazione dei cloni (*fork detection*), senza modifiche hardware ai varchi esistenti.

## Descrizione

I sistemi di controllo accessi tradizionali basati su **MIFARE Classic** soffrono di gravi vulnerabilità dovute alla compromissione dell'algoritmo **CRYPTO1** e alla diffusione di “**magic card**” con UID riscrivibile.  
eACy affronta il problema spostando la fiducia dal tag fisico al software applicativo, trattando il badge come un semplice canale di trasporto non fidato.

Il sistema implementa un protocollo compatibile con l'infrastruttura esistente, che calcola e verifica codici **HMAC(UID || contatore)** memorizzati sul tag.  
Il contatore funge da stato condiviso tra badge e backend, consentendo la rilevazione di cloni e la protezione contro attacchi di replay.

## Architettura

eACy si compone di tre moduli principali:

- **Reader Module (ESP8266 + PN532)**  
  Interroga il badge, calcola l'HMAC e valida la finestra di contatori ammessi. Gestisce la comunicazione con il backend e segnala accesso consentito o negato.

- **Badge (MIFARE Classic 1K)**  
  Memorizza ciclicamente i valori HMAC relativi agli ultimi stati del contatore. Non conserva segreti: funge solo da canale di sincronizzazione.

- **Backend**  
  Mantiene la base di dati dei badge, le chiavi condivise e lo stato del contatore per ciascun utente. Fornisce servizi REST al reader e aggiorna i contatori dopo ogni accesso valido.

### Flusso di autenticazione

1. Il lettore legge l'UID e il blocco dati del tag.  
2. Verifica la presenza di un HMAC valido nella finestra di risincronizzazione.  
3. In caso positivo, aggiorna il contatore e notifica il backend.  
4. Il backend conferma e memorizza il nuovo stato, invalidando i precedenti.  
5. Eventuali incongruenze di stato permettono di rilevare cloni (fork detection).

## Principi di sicurezza

- **HMAC-SHA256** con chiave simmetrica condivisa  
- **Contatore monotono** con finestra di risincronizzazione (modello HOTP)  
- **Protezione anti-replay**  
- **Fork detection** per cloni concorrenti  
- **Compatibilità retroattiva** con impianti MIFARE Classic esistenti  

## Riconoscimenti
Questo progetto è stato **premiato come migliore nuova proposta** al **NeaPolis Innovation Technology Day 2024**, evento dedicato all'innovazione tecnologica e alla divulgazione, organizzato da **ST Microelectronics**.  
Maggiori dettagli sull'evento: https://www.pepite.info/blog/xiii-edizione-del-neapolis-innovation-technology-day/
