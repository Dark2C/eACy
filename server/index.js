const express = require('express');
const fs = require('fs');
const app = express();
const PORT = 3000;


let databaseJson = fs.readFileSync('database.json', 'utf8');
let database = JSON.parse(databaseJson);
let history = [];
let anomalies = [];

app.use(express.text());
app.get('/api/:id', (req, res) => {
    res.send(Math.round(Date.now() / 1000).toString());
});
app.post('/api/:id', (req, res) => {
    const id = req.params.id;
    // id must be present in the list of access control systems
    if (!database.accessControlSystems.find(system => system.id === parseInt(id))) {
        res.status(400).send('Invalid access control system id');
        return;
    }
    const body = Buffer.from(req.body, 'base64');
    const allowedCardCount = body.readUInt32LE(0);
    // every 8 bytes group contains a record with 4 bytes for the uid and 4 bytes for the counter (little endian)
    const allowedCards = [];
    for (let i = 4; i < 4 + allowedCardCount * 8; i += 8) {
        allowedCards.push({
            uid: body.subarray(i, i + 4).toString('hex').toUpperCase(),
            counter: body.readUInt32LE(i + 4)
        });
    }
    // after the allowed cards, there is the history of the access control system, each record is composed by 4 bytes for the uid, 4 bytes for the counter and 4 bytes for the timestamp (little endian)
    const acHistory = [];
    for (let i = 4 + allowedCardCount * 8; i < body.length; i += 12) {
        acHistory.push({
            uid: body.subarray(i, i + 4).toString('hex').toUpperCase(),
            timestamp: body.readUInt32LE(i + 8) * 1000,
            counter: body.readUInt32LE(i + 4),
            ac: id
        });
    }
    console.log(`Received card data for access control system with id ${id}`);
    console.log('Allowed cards:', allowedCards);
    console.log('History:', acHistory);
    // update card information
    database.cards.forEach(card => {
        const allowedCard = allowedCards.find(c => c.uid === card.uid);
        if (allowedCard) {
            if (!card.enabled && card.counter !== allowedCard.counter) {
                anomalies.push({
                    uid: card.uid,
                    timestamp: Date.now(),
                    message: 'Card counter changed without enabling the card',
                    ac: id
                });
            } else {
                card.counter = Math.max(card.counter, allowedCard.counter);
            }
        }
    });
    // update history by merging the received history and the current one, then sort it by timestamp and remove duplicates (same timestamp, uid and counter)
    history = [...history, ...acHistory]
        .sort((a, b) => a.counter - b.counter)
        .sort((a, b) => parseInt(a.uid, 16) - parseInt(b.uid, 16))
        .sort((a, b) => a.timestamp - b.timestamp)
        .filter((record, index, array) => {
            return index === 0 || record.uid !== array[index - 1].uid || record.counter !== array[index - 1].counter || record.date !== array[index - 1].date;
        });
    // check for anomalies in the history (card counter not monotonic increasing for the same card)
    const cardCounters = {};
    history.forEach(record => {
        if (!cardCounters[record.uid]) {
            cardCounters[record.uid] = [];
        }
        cardCounters[record.uid].push(record.counter);
    });
    for (const [uid, counters] of Object.entries(cardCounters)) {
        for (let i = 1; i < counters.length; i++) {
            if (counters[i] < counters[i - 1]) {
                anomalies.push({
                    uid,
                    timestamp: Date.now(),
                    message: 'Card counter not monotonic increasing',
                    ac: id
                });
                break;
            }
        }
    }

    // in case of counter = 0xFFFFFFFF, this is a sentinel value to indicate that the counter on the card has been detected to be out of the sync from the reader, so add a record to the anomalies
    acHistory.forEach(record => {
        if (record.counter === 0xFFFFFFFF) {
            anomalies.push({
                uid: record.uid,
                timestamp: Date.now(),
                message: 'Card counter out of sync detected from reader',
                ac: id
            });
        }
    });

    // get the list of allowed and enabled cards for the specified access control system
    const allowedCardsForSystem = database.cards.filter(card => card.allowedSystems.includes(parseInt(id)) && card.enabled);
    // send the list of allowed and enabled cards as binary response
    const response = Buffer.alloc(allowedCardsForSystem.length * 8);
    for (let i = 0; i < allowedCardsForSystem.length; i++) {
        response.write(allowedCardsForSystem[i].uid, i * 8, 4, 'hex');
        response.writeUInt32LE(allowedCardsForSystem[i].counter, i * 8 + 4);
    }
    res.send(response);
});

app.listen(PORT, () => {
    console.log(`Server listening at http://localhost:${PORT}`);
});


let lastModified = fs.statSync('database.json').mtimeMs;


// every second check if the database.json file has been modified
setInterval(() => {
    fs.stat('database.json', (err, stats) => {
        if (err) {
            console.error(err);
            return;
        }
        if (stats.mtimeMs !== lastModified) {
            lastModified = stats.mtimeMs;
            // database is changed, reload it
            databaseJson = fs.readFileSync('database.json', 'utf8');
            database = JSON.parse(databaseJson);
            console.log('Database reloaded');
        } else {
            // database is not changed on disk, check if it has been changed by another process
            const serializedDatabase = JSON.stringify(database, null, 2);
            if (serializedDatabase !== databaseJson) {
                databaseJson = serializedDatabase;
                fs.writeFileSync('database.json', databaseJson);
                console.log('Database saved to disk');
            }
        }
    });
}, 1000);

// every second, serialize the history and anomalies arrays into a test file, sorted by timestamp
let lastLogFile = "";
setInterval(() => {
    let newLogFile = "";
    const logRows = [
        ...JSON.parse(JSON.stringify(history.map(record => ({
            date: new Date(record.timestamp).toISOString().replace('T', ' ').replace(/\.\d+Z/, ''),
            type: 'HISTORY',
            uid: record.uid,
            ac: record.ac,
            message: "Counter: " + record.counter
        })))), ...JSON.parse(JSON.stringify(anomalies.map(anomaly => ({
            date: new Date(anomaly.timestamp).toISOString().replace('T', ' ').replace(/\.\d+Z/, ''),
            type: 'ANOMALY',
            uid: anomaly.uid,
            ac: anomaly.ac,
            message: anomaly.message
        }))))];
    logRows.sort((a, b) => a.date.localeCompare(b.date));
    logRows.forEach(row => {
        newLogFile += `${row.date} ${row.type} ${row.uid} (AC: ${row.ac}) ${row.message}\n`;
    });
    // if the log file has changed, save it to disk by appending the new rows
    if (newLogFile !== lastLogFile) {
        lastLogFile = newLogFile;
        fs.writeFileSync('log.txt', newLogFile);
        console.log('Log file updated');
    }
}, 1000);