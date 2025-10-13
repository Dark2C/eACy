#ifndef KEY_GEN_VAL_H
#define KEY_GEN_VAL_H

// Function prototypes
// Function to generate the next key, it will be the HMAC-SHA256 hash of the concatenation of the UID and the counter with a shared key, truncated to 16 bytes
void generateNextKey(uint8_t *uid, uint32_t counter, uint8_t *key);
#endif // KEY_GEN_VAL_H