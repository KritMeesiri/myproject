#ifndef _PHASE4_TYPES_H_
#define _PHASE4_TYPES_H_

#include <stdint.h>

/* Algorithm buffer sizes (Kyber768, ML-DSA-65) */
#define KYBER768_PK_LEN        1184
#define KYBER768_SK_LEN        2400
#define KYBER768_CT_LEN        1088
#define KYBER768_SS_LEN        32
#define MLDSA65_PK_LEN         1952
#define MLDSA65_SK_LEN         4032
#define MLDSA65_SIG_LEN        3309

/* Demo batch dimensions */
#define PHASE4_MAX_RECORDS     1000
#define PHASE4_RECORD_CT_SIZE  128   /* per-record ciphertext size in demo */

/*
 * Per-record authentication payload — matches paper eq. (11):
 *   Auth_i = H(ID_i || ts_i || ctr_i || c_e || r_{i,e} || H(C_i))
 * All fields are kept together so the enclave can re-derive and compare.
 */
typedef struct {
    uint8_t  device_id[16];    /* ID_i   */
    uint8_t  timestamp[8];     /* ts_i   */
    uint32_t counter;          /* ctr_i  */
    uint8_t  puf_response[32]; /* r_{i,e} — held in secure device storage */
    uint8_t  ct_hash[32];      /* H(C_i) */
    uint8_t  auth_token[32];   /* Auth_i  */
} phase4_auth_record_t;        /* 124 bytes, no implicit padding */

/*
 * All epoch-batch inputs grouped into one struct.
 * App fills this with OQS_randombytes(), fixes the sizing fields,
 * then recomputes auth_tokens before handing it to the ECALL.
 */
typedef struct {
    uint32_t             epoch_id;
    uint8_t              epoch_challenge[32];
    uint8_t              pol_ref[64];
    uint32_t             polref_len;
    uint8_t              batch_pre_digest[32];  /* H(H^{(0)}_e) */
    uint32_t             num_records;
    phase4_auth_record_t auth_records[PHASE4_MAX_RECORDS];
    uint8_t              ct_agg[PHASE4_MAX_RECORDS * PHASE4_RECORD_CT_SIZE];
    uint32_t             ct_agg_size;
} phase4_batch_input_t;

#endif /* _PHASE4_TYPES_H_ */
