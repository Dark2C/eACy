#include <EEPROM.h>

#define AC_GREEN_LED 0 // D3
#define AC_RED_LED 2   // D4

#include <Arduino.h>
// including the SoftwareSerial library that implements serial communication on certain pins
#include <SoftwareSerial.h>

// including the library for software HSU(High-speed UART) and library for the PN532 module
#include <PN532_SWHSU.h>
#include <PN532.h>

#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClient.h>

WiFiClient client;

#include "card_io.h"
#include "key_gen_val.h"

SoftwareSerial SWSerial(4, 5); //(RX,TX) constructor for our SoftwareSerial library, in which we define the pins to which we will connect the module
// constructors for libraries that are used for communication with the module
PN532_SWHSU pn532swhsu(SWSerial); // constructor for High-speed UART
PN532 nfc(pn532swhsu);            // constructor for the PM532 library

char gatewayAddress[100];
unsigned long gatewaySyncInterval = 0;

char secret[64]; // Secret salt for the hashing function ("superSecret")

unsigned long timestampFromServer = 0;
unsigned long lastTimeSync = 0;

unsigned long ledTurnedOnMillis = 0;
boolean ledOn = false;
void turnOn(uint8_t led)
{
    digitalWrite(2 - led, LOW);
    digitalWrite(led, HIGH);
    ledOn = true;
    ledTurnedOnMillis = millis();
}

void setup(void)
{
    pinMode(AC_GREEN_LED, OUTPUT);
    pinMode(AC_RED_LED, OUTPUT);
    digitalWrite(AC_GREEN_LED, LOW);
    digitalWrite(AC_RED_LED, LOW);

    EEPROM.begin(512);
    Serial.begin(115200);                            ////serial communication initialization (speed 115200 baud)
    nfc.begin();                                     // initialization of communication with the module
    uint32_t versionFata = nfc.getFirmwareVersion(); // requesting the Firmware version and saving it to the versiondata variable
    // if we have not gotten a Firmware version from the module, displaying that the module was not found on the Serial monitor, staying in the indefinite while loop
    if (!versionFata)
    {
        Serial.println("PN532 module not found!");
        while (1)
            ;
    }
    // if communication is established, displaying the success message on the Serial monitor and configuring the module
    Serial.println("PN5 module found!");
    nfc.SAMConfig();

    WiFi.mode(WIFI_STA);
    char ssid[65], password[65];
    // get the SSID, the password and the secret from EEPROM
    for (int i = 0; i < 64; i++)
    {
        ssid[i] = EEPROM.read(i + 170);
        password[i] = EEPROM.read(i + 235);
        secret[i] = EEPROM.read(i + 100);
    }
    ssid[64] = '\0';
    password[64] = '\0';
    secret[64] = '\0';
    WiFi.begin(ssid, password);

    Serial.print("Connecting");
    unsigned long startAttemptTime = millis();
    while (WiFi.status() != WL_CONNECTED)
    {
        if (millis() - startAttemptTime > 10000) // 10 seconds timeout
        {
            Serial.println("\nFailed to connect to WiFi within 10 seconds.");
            break;
        }
        delay(500);
        Serial.print(".");
    }
    Serial.println();

    Serial.print("Connected, IP address: ");
    Serial.println(WiFi.localIP());

    // get the Gateway URL from EEPROM
    for (int i = 0; i < 100; i++)
    {
        gatewayAddress[i] = EEPROM.read(i);
    }
    int gtwSyncInt;
    EEPROM.get(300, gtwSyncInt);
    gatewaySyncInterval = gtwSyncInt * 1000;
}

unsigned long currentMillis = 0;
void (*runningModes[])() = {baseMode, initCardMode};
int currentMode = 0;
void loop()
{
    currentMillis = millis();

    if (ledOn && currentMillis - ledTurnedOnMillis >= 1000)
    {
        ledOn = false;
        digitalWrite(AC_GREEN_LED, LOW);
        digitalWrite(AC_RED_LED, LOW);
    }

    runningModes[currentMode]();
    shellHandler();
}

void baseMode()
{
    sync();
    rfidHandler();
}

void initCardMode()
{
    resetRfidHandler();
}
