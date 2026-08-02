#ifndef CARD_IO_H
#define CARD_IO_H

// Define constants
#define MAX_CARD_COUNT 4       // Maximum number of allowed cards
#define HISTORY_SIZE 256        // Maximum number of history entries

struct CardData
{
    uint8_t uid[4];   // UID of the card
    uint32_t counter; // Counter value
} __attribute__((packed, aligned(4)));

struct HistoryData
{
    uint8_t uid[4];   // UID of the card
    uint32_t counter; // Counter value
    uint32_t timestamp;
} __attribute__((packed, aligned(4)));

// cardData should be packed and aligned to 4 bytes
#pragma pack(push, 4)
CardData allowedCards[MAX_CARD_COUNT];
HistoryData historyData[HISTORY_SIZE];
#pragma pack(pop)
int allowedCardCount = 0;
int historyCount = 0;
int historyIndex = 0;

// Function prototypes
/*
 * @brief Reads the card data
 * @param uid - the card UID (input)
 * @param counter - the counter value associated with the card (input)
 * @param acKey - the access control key needed to unlock the door (output)
 * @return true if the card data was read successfully, false otherwise
 */
boolean readCardData(uint8_t *uid, uint32_t counter, uint8_t *acKey);

/*
 * @brief Updates the card data
 * @param uid - the card UID (input)
 * @param counter - the new counter value associated with the card (input)
 * @param acKey - the access control key needed to unlock the door (input)
 * @return true if the card data was updated successfully, false otherwise
 */
boolean updateCardData(uint8_t *uid, uint32_t counter, uint8_t *acKey);

// function to find a card in the allowed cards list
int findCard(uint8_t *uid);
#endif // CARD_IO_H
