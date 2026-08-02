#include "card_io.h"
#define RETRY_COUNT 20

uint8_t key[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF}; // Key for reading and writing to the card

boolean readCardData(uint8_t *uid, uint32_t counter, uint8_t *acKey)
{
    uint8_t currentblock = 4 * (1 + (counter / 3) % 15) + counter % 3;
    boolean success = false;
    for (int attempt = 0; attempt < RETRY_COUNT; attempt++)
    {
        success = nfc.mifareclassic_AuthenticateBlock(uid, 4, currentblock, 1, key);
        if (success)
        {
            success = nfc.mifareclassic_ReadDataBlock(currentblock, acKey);
        }
        if (success)
        {
            break;
        }
        delay(5);
    }
    return success;
}

boolean updateCardData(uint8_t *uid, uint32_t counter, uint8_t *acKey)
{
    uint8_t currentblock = 4 * (1 + (counter / 3) % 15) + counter % 3;
                uint8_t verify[16];
    boolean success;
    for (int attempt = 0; attempt < RETRY_COUNT; attempt++)
    {
        success = nfc.mifareclassic_AuthenticateBlock(uid, 4, currentblock, 1, key);
        if (success)
        {
            success = nfc.mifareclassic_WriteDataBlock(currentblock, acKey);
            if (success)
            {
                //read back the block to verify the write operation
                success = nfc.mifareclassic_ReadDataBlock(currentblock, verify);
                if (success && memcmp(verify, acKey, 16) == 0)
                {
                    success = true;
                    break;
                }
                else
                {
                    success = false;
                }
            }
        }
        if (success)
        {
            break;
        }
        delay(5);
    }
    return success;
}

// function to find a card in the allowed cards list
int findCard(uint8_t *uid)
{
    for (int i = 0; i < allowedCardCount; i++)
    {
        if (memcmp(allowedCards[i].uid, uid, 4) == 0)
        {
            return i;
        }
    }
    return -1;
}

boolean formatCard(uint8_t *uid)
{
    boolean success = true;
    uint8_t data[16];
    for (uint8_t i = 4; i < 64; i++)
    {
        for(int attempt = 0; attempt < RETRY_COUNT; attempt++)
        {
            if (i % 4 != 3)
            {
                if (i % 4 == 0 || !success) {
                  success = nfc.mifareclassic_AuthenticateBlock(uid, 4, i, 1, key);
                }
                if (success)
                {
                    if (i == 5)
                    {
                        memset(data, 0, 16);
                    }
                    else if (i == 4)
                    {
                        generateNextKey(uid, 0, data);
                    }
                    success = nfc.mifareclassic_WriteDataBlock(i, data);
                    if (!success)
                    {
                        Serial.print("[formatCard]: error on Write (block ");
                        Serial.print(i);
                        Serial.println(")");
                    }
                }
                else
                {
                    Serial.print("[formatCard]: error on Auth (block ");
                    Serial.print(i);
                    Serial.println(")");
                }
            }
            if (success)
            {
                break;
            }
            delay(5);
        }
        if (!success)
        {
            break;
        }
    }
    return success;
}
