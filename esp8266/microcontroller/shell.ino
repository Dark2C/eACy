void help()
{
    Serial.println("Available commands:");
    Serial.println("set gateway - set the gateway address (ex. http://192.168.1.100)");
    Serial.println("set secret - set the secret key used to generate the keys");
    Serial.println("set wifi - set the wifi credentials");
    Serial.println("set mode - set the running mode (0 - normal, 1 - card reset)");
    Serial.println("set sync frequency - set the frequency of syncing with the server");
    Serial.println("sync - syncronize the device with the server");
    Serial.println("get gateway - get the gateway address");
    Serial.println("conn status - get the connection status");
    Serial.println("reboot - reboot the device");
    Serial.println("_dump - dump the EEPROM memory");
    Serial.println("help - display this help message");
}

void setGateway()
{
    Serial.println("Send the gateway address (max 99 characters):");
    while (Serial.available() == 0)
        ESP.wdtFeed();
    String gateway = Serial.readString();
    if (gateway.length() < 99)
    {
        // get the bytes of the string and store them in the EEPROM at the address 0
        for (int i = 0; i < gateway.length(); i++)
        {
            EEPROM.write(i, gateway[i]);
        }
        EEPROM.write(gateway.length(), '\0');
        Serial.println("Gateway address stored!");
        if (!EEPROM.commit())
        {
            Serial.println("ERROR! EEPROM commit failed.");
        }
    }
    else
    {
        Serial.println("Cannot store the gateway address!");
    }
}

void setSecret()
{
    Serial.println("Send the secret key (max 63 characters):");
    while (Serial.available() == 0)
        ESP.wdtFeed();
    String scrt = Serial.readString();
    if (scrt.length() < 63)
    {
        // get the bytes of the string and store them in the EEPROM at the address 100
        for (int i = 0; i < scrt.length(); i++)
        {
            EEPROM.write(i + 100, scrt[i]);
        }
        EEPROM.write(scrt.length() + 100, '\0');
        Serial.println("Secret key stored!");
        if (!EEPROM.commit())
        {
            Serial.println("ERROR! EEPROM commit failed.");
        }
    }
    else
    {
        Serial.println("Cannot store the secret key!");
    }
}

void setWifi()
{
    Serial.println("Send the SSID (max 63 characters):");
    while (Serial.available() == 0)
        ESP.wdtFeed();
    String ssid = Serial.readString();
    if (ssid.length() < 63)
    {
        // get the bytes of the string and store them in the EEPROM at the address 165
        for (int i = 0; i < ssid.length(); i++)
        {
            EEPROM.write(i + 170, ssid[i]);
        }
        EEPROM.write(ssid.length() + 170, '\0');
        Serial.println("SSID stored!");
        Serial.println("Send the password (max 63 characters):");
        while (Serial.available() == 0)
            ESP.wdtFeed();
        String password = Serial.readString();
        if (password.length() < 63)
        {
            // get the bytes of the string and store them in the EEPROM at the address 235
            for (int i = 0; i < password.length(); i++)
            {
                EEPROM.write(i + 235, password[i]);
            }
            EEPROM.write(password.length() + 235, '\0');
            Serial.println("Password stored!");
            if (!EEPROM.commit())
            {
                Serial.println("ERROR! EEPROM commit failed.");
            }
        }
        else
        {
            Serial.println("Cannot store the password!");
        }
    }
    else
    {
        Serial.println("Cannot store the SSID!");
    }
}

void setRunningMode()
{
    Serial.println("Set the running mode:");
    while (Serial.available() == 0)
        ESP.wdtFeed();
    int mode = Serial.parseInt();
    if (mode >= 0 && mode <= 1)
    {
        currentMode = mode;
        Serial.println("Mode set!");
    }
    else
    {
        Serial.println("Invalid mode!");
    }
}

void setSyncFrequency()
{
    Serial.println("Send the sync frequency (in seconds):");
    while (Serial.available() == 0)
        ESP.wdtFeed();
    int frequency = Serial.parseInt();
    EEPROM.put(300, frequency);
    if (!EEPROM.commit())
    {
        Serial.println("ERROR! EEPROM commit failed.");
    }
    Serial.println("Sync frequency stored!");
}

void manualSync()
{
    _sync();
    Serial.println("Synced!");
}

void getGateway()
{
    Serial.println("Gateway address:");
    for (int i = 0; i < 99; i++)
    {
        char c = EEPROM.read(i);
        if (c == '\0')
        {
            break;
        }
        Serial.print(c);
    }
    Serial.println("");
}

void connStatus()
{
    if (WiFi.status() == WL_CONNECTED)
    {
        Serial.println("Connected to SSID: " + WiFi.SSID());
        Serial.print("ESP8266 IP: ");
        Serial.println(WiFi.localIP());
        Serial.print("Gateway IP: ");
        Serial.println(WiFi.gatewayIP());
    }
    else
    {
        Serial.println("Not connected!");
    }
}

void reboot()
{
    Serial.println("Rebooting...");
    delay(1000);
    ESP.restart();
}

void dumpMem()
{
    for (int i = 0; i < 512; i++)
    {
        Serial.print(EEPROM.read(i), HEX);
        Serial.print(" ");
    }
    Serial.println("");
}

struct Command
{
    const char *name;
    void (*function)();
};
Command commands[] = {
    {"set gateway", setGateway},
    {"set secret", setSecret},
    {"set wifi", setWifi},
    {"set mode", setRunningMode},
    {"set sync frequency", setSyncFrequency},
    {"sync", manualSync},
    {"get gateway", getGateway},
    {"conn status", connStatus},
    {"reboot", reboot},
    {"_dump", dumpMem},
    {"help", help},
};

void shellHandler()
{
    // get the number of characters in the buffer on the serial port
    int numChars = Serial.available();
    // if there are characters in the buffer
    if (numChars > 0)
    {
        // create a buffer to hold the characters
        uint8_t buffer[numChars];
        // read the characters from the buffer
        Serial.readBytes(buffer, numChars);
        boolean found = false;
        // iterate over the commands array to find the command if it exists
        for (int i = 0; i < sizeof(commands) / sizeof(commands[0]); i++)
        {
            // if the command exists
            if (memcmp(buffer, commands[i].name, numChars) == 0)
            {
                // call the function associated with the command
                found = true;
                commands[i].function();
                // break out of the loop
                break;
            }
        }
        // if the command was not found
        if (!found)
        {
            Serial.println("Command not found!");
        }
    }
}
