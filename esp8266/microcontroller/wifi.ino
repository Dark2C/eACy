unsigned long prevChkConnMillis = 0;
void sync()
{
    if (currentMillis - prevChkConnMillis >= gatewaySyncInterval)
    {
        for (int attempt = 0; attempt < 10; attempt++)
        {
            delay(100); // wait 100ms before checking the connection
            if (WiFi.status() == WL_CONNECTED)
            {
                getTime();
                _sync();
                return;
            }
        }
    }
}

void getTime()
{
    HTTPClient http;
    http.setReuse(false);
    unsigned long currMillis = millis();
    if (http.begin(client, gatewayAddress))
    {
        http.setTimeout(100);
        int httpCode = http.GET();
        if (httpCode > 0)
        {
            if (httpCode == HTTP_CODE_OK)
            {
                unsigned long duration = millis() - currMillis;
                String payload = http.getString();
                timestampFromServer = payload.toInt() + duration / 2000;
                lastTimeSync = millis() / 1000;
            }
        }
        http.end();
    }
}

void _sync()
{
    int payloadSize = allowedCardCount * sizeof(CardData) + historyCount * sizeof(HistoryData) + sizeof(int);
    uint8_t payload[payloadSize];
    memcpy(payload, &allowedCardCount, sizeof(int));
    memcpy(payload + sizeof(int), allowedCards, allowedCardCount * sizeof(CardData));
    memcpy(payload + sizeof(int) + allowedCardCount * sizeof(CardData), historyData, historyCount * sizeof(HistoryData));

    // encode the payload in base64
    String encodedPayload = base64_encode(payload, payloadSize);

    HTTPClient http;
    http.setReuse(false);
    if (http.begin(client, gatewayAddress))
    {
        // POST the payload to the server
        http.addHeader("Content-Type", "text/plain");
        http.setTimeout(2000);
        int httpCode = http.POST(encodedPayload);
        ESP.wdtFeed();
        // httpCode will be negative on error
        if (httpCode > 0)
        {
            // file found at server
            if (httpCode == HTTP_CODE_OK)
            {
                // String payload = http.getString();
                //  get the binary payload from the server and parse it, server response is a raw array of bytes
                payloadSize = http.getSize();
                uint8_t respPayload[payloadSize];
                // use getString and then copy the string to the byte array
                String resp = http.getString();
                for (int i = 0; i < payloadSize; i++)
                {
                    respPayload[i] = resp[i];
                }
                // parse the payload
                allowedCardCount = payloadSize / 8;
                memcpy(allowedCards, respPayload, allowedCardCount * sizeof(CardData));
                // print the list of allowed cards
                for (int i = 0; i < allowedCardCount; i++)
                {
                    Serial.printf("Card %d: %02X%02X%02X%02X\n", i, allowedCards[i].uid[0], allowedCards[i].uid[1], allowedCards[i].uid[2], allowedCards[i].uid[3]);
                }
                prevChkConnMillis = currentMillis;
            }
        }
        http.end();
    }
}

// function to encode a byte array in base64
String base64_encode(uint8_t *data, int length)
{
    const char *chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    String encoded = "";
    for (int i = 0; i < length; i += 3)
    {
        uint32_t octet_a = i < length ? data[i] : 0;
        uint32_t octet_b = i + 1 < length ? data[i + 1] : 0;
        uint32_t octet_c = i + 2 < length ? data[i + 2] : 0;
        uint32_t triple = (octet_a << 0x10) + (octet_b << 0x08) + octet_c;
        encoded += chars[(triple >> 3 * 6) & 0x3F];
        encoded += chars[(triple >> 2 * 6) & 0x3F];
        encoded += i + 1 < length ? chars[(triple >> 1 * 6) & 0x3F] : '=';
        encoded += i + 2 < length ? chars[(triple >> 0 * 6) & 0x3F] : '=';
    }
    return encoded;
}
