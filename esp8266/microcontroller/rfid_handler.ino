#define TIME_TRESHOLD 5000 // Time treshold to allow for the reading of the same card again
#define SLOTS_ON_CARD 45    // Maximum number of slots on the card to validate against (15 blocks of 3 keys each on Mifare Classic 1K)
uint8_t prevUid[4] = {0, 0, 0, 0}; // buffer for saving the previous card code
uint32_t lastRead = 0;             // we also store the time of the last card reading, because if same card is scanned after TIME_TRESHOLD, it will be read again

long rfidHandlerIntvl;
unsigned long prevRfidHandlerMillis = 0;


void rfidHandler()
{
    rfidHandlerIntvl = 0;
    if (currentMillis - prevRfidHandlerMillis >= rfidHandlerIntvl)
    {

        uint8_t readBlock[16];         // Array to store blocks read from the card
        
        boolean success, tampered;
        uint8_t uid[] = {0, 0, 0, 0, 0, 0, 0}; // buffer to which we save the code from the card
        uint8_t uidLength;                     // writing the UID code length (4 or 7 bytes)
        uint8_t currentblock;                  // Counter to keep track of which block we're on
        uint32_t now = millis();               // Current time
        // requesting the module to read the card (in the success variable, it saves whether there is a card, and if there is, saving the card code to the uid variable
        success = nfc.readPassiveTargetID(PN532_MIFARE_ISO14443A, &uid[0], &uidLength);
        // we only support Mifare Classic cards (4 byte UID)
        if (success && uidLength == 4)
        {
            // we proceed only if the card code is different from the previous one
            if (memcmp(uid, prevUid, 4) != 0 || now - lastRead > TIME_TRESHOLD)
            {
                memcpy(prevUid, uid, 4);
                lastRead = now;
                Serial.print("Card found! Time: ");
                Serial.println(now);
                Serial.print("UID Code: ");
                for (uint8_t i = 0; i < 4; i++)
                {
                    Serial.print(uid[i], HEX);
                }
                Serial.println("");

                // here we have the Access Control logic
                int card = findCard(uid);
                int delta = 0;
                if (card != -1)
                {
                    // we found the card, now we need to validate the key and we do so by computing the HMAC-SHA256 values from the current counter up to current counter + SLOTS_ON_CARD
                    uint8_t currentKey[16], expectedKey[16];
                    success = false;

                    
                    tampered = true;
                   
                    if (allowedCards[card].counter != 0xFFFFFFFF) {
                        for (int i = 0; i < SLOTS_ON_CARD; i++)
                        {
                            // read the next key from the card
                            if(!readCardData(uid, allowedCards[card].counter + i, currentKey)){
                                tampered = false; // card is not tampered but reader failed
                                break;
                            }
                            // generate the next key on our side
                            generateNextKey(allowedCards[card].uid, allowedCards[card].counter + i, expectedKey);
                            // compare the two keys
                            if (memcmp(currentKey, expectedKey, 16) == 0)
                            {
                                delta = i;
                                success = true;
                            } else break; // exit the loop as soon as we find a mismatch
                        }
                    }
                    
                    if (success)
                    {
                        // update the counter value in the allowedCards array
                        allowedCards[card].counter += delta;
                        // now we have to generate the next key and update the card data
                        uint8_t nextKey[16];
                        uint32_t nextCounter = allowedCards[card].counter + 1;
                        generateNextKey(uid, nextCounter, nextKey);

                        // At the end, we need to update the next block with the new key
                        success = updateCardData(uid, nextCounter, nextKey);
                        if (success)
                        {
                            allowedCards[card].counter = nextCounter;
                            // create a history entry
                            HistoryData entry;
                            memcpy(entry.uid, uid, 4);
                            entry.counter = nextCounter - 1;
                            entry.timestamp = timestampFromServer + (millis() / 1000) - lastTimeSync;
                            historyData[historyIndex++] = entry;
                            if (historyIndex >= HISTORY_SIZE)
                            {
                                historyIndex = 0;
                            }
                            historyCount++;
                            if (historyCount >= HISTORY_SIZE)
                            {
                                historyCount = HISTORY_SIZE;
                            }
                            rfidHandlerIntvl += TIME_TRESHOLD;
                            turnOn(AC_GREEN_LED);
                            Serial.print("Time to complete: ");
                            Serial.println(millis() - now);
                        }
                    } else if(tampered) {
                        // card presented an invalid key, possibly cloned or tampered with
                        // add an history entry with counter 0xFFFFFFFF to signal tampering and block the card
                        HistoryData entry;
                        memcpy(entry.uid, uid, 4);
                        entry.counter = 0xFFFFFFFF;
                        entry.timestamp = timestampFromServer + (millis() / 1000) - lastTimeSync;
                        historyData[historyIndex++] = entry;
                        if (historyIndex >= HISTORY_SIZE)
                        {
                            historyIndex = 0;
                        }
                        historyCount++;
                        if (historyCount >= HISTORY_SIZE)
                        {
                            historyCount = HISTORY_SIZE;
                        }
                    }
                }
                else
                {
                    Serial.println("Card not found!");
                    success = false;
                }
            }
            rfidHandlerIntvl += 400; // wait (at least) 400ms before checking again
            if (!success)
            {
                turnOn(AC_RED_LED);
            }
        }
        rfidHandlerIntvl += 100; // wait (at least) 100ms before checking again
        if (!success)
        {
            prevUid[0] = ~prevUid[0];
        }
    }
}

void resetRfidHandler()
{
    rfidHandlerIntvl = 0;
    if (currentMillis - prevRfidHandlerMillis >= rfidHandlerIntvl)
    {
        boolean success;
        uint8_t uid[] = {0, 0, 0, 0, 0, 0, 0}; // buffer to which we save the code from the card
        uint8_t uidLength;                     // writing the UID code length (4 or 7 bytes)
        uint8_t currentblock;                  // Counter to keep track of which block we're on
        uint32_t now = millis();               // Current time
        // requesting the module to read the card (in the success variable, it saves whether there is a card, and if there is, saving the card code to the uid variable
        success = nfc.readPassiveTargetID(PN532_MIFARE_ISO14443A, &uid[0], &uidLength);
        // we only support Mifare Classic cards (4 byte UID)
        if (success && uidLength == 4)
        {
            // we proceed only if the card code is different from the previous one
            if (memcmp(uid, prevUid, 4) != 0 || now - lastRead > TIME_TRESHOLD)
            {
                memcpy(prevUid, uid, 4);
                lastRead = now;
                if (formatCard(uid))
                {
                    turnOn(AC_GREEN_LED);
                    Serial.println("Card formatted!");
                    rfidHandlerIntvl += TIME_TRESHOLD;
                }
                else
                {
                    turnOn(AC_RED_LED);
                    Serial.println("Error formatting card!");
                }
            }
            rfidHandlerIntvl += 400; // wait (at least) 400ms before checking again
        }
        rfidHandlerIntvl += 100; // wait (at least) 100ms before checking again
        if (!success)
        {
            prevUid[0] = ~prevUid[0];
        }
    }
}
