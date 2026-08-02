#include "card_io.h"
#include "key_gen_val.h"

// Function to generate the next key, it will be the HMAC-SHA256 hash of the concatenation of the UID and the counter with a shared key, truncated to 16 bytes
void generateNextKey(uint8_t *uid, uint32_t counter, uint8_t *key)
{
    uint8_t msg[8];
    memcpy(msg, uid, 4);
    memcpy(msg + 4, &counter, sizeof(counter));

    size_t secret_len = strlen((const char*)secret);
    uint8_t full[32];

    br_hmac_key_context kc;
    br_hmac_context ctx;

    br_hmac_key_init(&kc, &br_sha256_vtable, secret, secret_len);
    br_hmac_init(&ctx, &kc, 0);
    br_hmac_update(&ctx, msg, sizeof(msg));
    br_hmac_out(&ctx, full);

    memcpy(key, full, 16);
}