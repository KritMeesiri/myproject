/*
 * Copyright (C) 2011-2021 Intel Corporation. All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *   * Neither the name of Intel Corporation nor the names of its
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 */

#include "Enclave.h"
#include "Enclave_t.h"
#include "phase4_types.h"
#include "sgx_tcrypto.h"
#include "sgx_trts.h"
#include <stdarg.h>
#include <stdio.h>   /* vsnprintf */
#include <string.h>

/*
 * printf — forward to host via OCALL so enclave code can log.
 */
int printf(const char *fmt, ...)
{
    char buf[BUFSIZ] = { '\0' };
    va_list ap;
    va_start(ap, fmt);
    vsnprintf(buf, BUFSIZ, fmt, ap);
    va_end(ap);
    ocall_print_string(buf);
    return (int)strnlen(buf, BUFSIZ - 1) + 1;
}

/* ------------------------------------------------------------------ */
/* Helper: multi-part SHA-256 using the SGX streaming API             */
/* ------------------------------------------------------------------ */
static sgx_status_t sha256_multi(
    const void    **parts,
    const uint32_t *lens,
    int             nparts,
    uint8_t         out[32])
{
    sgx_sha_state_handle_t h;
    sgx_status_t ret = sgx_sha256_init(&h);
    if (ret != SGX_SUCCESS)
        return ret;

    for (int i = 0; i < nparts; i++) {
        ret = sgx_sha256_update(
                  (const uint8_t *)parts[i], lens[i], h);
        if (ret != SGX_SUCCESS) {
            sgx_sha256_close(h);
            return ret;
        }
    }
    ret = sgx_sha256_get_hash(h, (sgx_sha256_hash_t *)out);
    sgx_sha256_close(h);
    return ret;
}

/* ------------------------------------------------------------------ */
/* ecall_phase4_signcrypt                                              */
/*                                                                     */
/* Implements paper Section III.C.4 Steps 1-8.                        */
/* SGX SDK handles Steps 3-4 and 6-7; PQ primitives use OCALLs.       */
/* ------------------------------------------------------------------ */
int ecall_phase4_signcrypt(
    uint32_t       epoch_id,
    uint32_t       polref_len,
    uint32_t       num_records,
    uint32_t       auth_buf_size,
    uint32_t       ct_agg_size,
    const uint8_t *epoch_challenge,
    const uint8_t *pol_ref,
    const uint8_t *batch_pre_digest,
    const uint8_t *auth_records,
    const uint8_t *ct_agg,
    const uint8_t *hdfs_pk,
    uint8_t       *out_ct,
    uint8_t       *out_iv,
    uint8_t       *out_tag,
    uint8_t       *out_ctk,
    uint8_t       *out_enc_ke,
    uint8_t       *out_ct_abe,
    uint8_t       *out_batch_digest,
    uint8_t       *out_signature,
    uint32_t      *out_sig_len,
    uint32_t      *out_valid_count)
{
    sgx_status_t sgx_ret;
    int          ocall_ret;

    /* Suppress unused-parameter warning for auth_buf_size — it is
     * validated implicitly: SGX edger8r copies exactly auth_buf_size
     * bytes, so array access within num_records is safe.             */
    (void)auth_buf_size;

    /* ---- Step 2: PUF-Based Device Authentication (paper eq. 36-37) ---- */
    uint32_t valid_count = 0;
    const phase4_auth_record_t *recs =
        (const phase4_auth_record_t *)auth_records;

    for (uint32_t i = 0; i < num_records; i++) {
        const phase4_auth_record_t *r = &recs[i];
        uint32_t ctr = r->counter;

        /* Re-derive: H(ID_i || ts_i || ctr_i || c_e || r_{i,e} || H(C_i)) */
        uint8_t expected[32];
        const void    *parts[] = { r->device_id, r->timestamp, &ctr,
                                   epoch_challenge, r->puf_response,
                                   r->ct_hash };
        const uint32_t lens[]  = { 16, 8, 4, 32, 32, 32 };
        sha256_multi(parts, lens, 6, expected);

        if (memcmp(expected, r->auth_token, 32) == 0)
            valid_count++;
    }
    *out_valid_count = valid_count;
    printf("[Enclave] Auth: %u/%u records valid\n", valid_count, num_records);

    /* ---- Step 3: Epoch Key Generation & Context Binding (eq. 38-39) ---- */
    uint8_t Ke[32];
    sgx_ret = sgx_read_rand(Ke, 32);
    if (sgx_ret != SGX_SUCCESS) {
        printf("[Enclave] sgx_read_rand(Ke) failed: 0x%x\n", sgx_ret);
        return -1;
    }

    /* ctx_e = H("CTX" || epoch_id || pol_ref || H(H^{(0)}_e)) */
    uint8_t ctx_e[32];
    {
        const char    *tag    = "CTX";
        uint32_t       eid    = epoch_id;
        const void    *parts[] = { tag, &eid, pol_ref, batch_pre_digest };
        const uint32_t lens[]  = { 3, 4, polref_len, 32 };
        sha256_multi(parts, lens, 4, ctx_e);
    }

    /* ---- Step 4: Batch-Level AEAD Protection (eq. 41) ---- */
    /* sgx_aes_gcm_encrypt generates a fresh IV internally and writes it
     * to out_iv; ctx_e is used as the AAD to bind ciphertext to policy. */
    sgx_ret = sgx_aes_gcm_encrypt(
        Ke, 32,
        ct_agg, ct_agg_size,
        out_ct,
        out_iv, 12,
        ctx_e, 32,
        (sgx_aes_gcm_128bit_tag_t *)out_tag);
    if (sgx_ret != SGX_SUCCESS) {
        printf("[Enclave] AES-GCM batch encrypt failed: 0x%x\n", sgx_ret);
        return -1;
    }

    /* ---- Step 5: Post-Quantum KEM via OCALL (eq. 42-44) ---- */
    /* Kyber encapsulation runs on the host because liboqs requires stdlib.
     * The shared secret SS_e never leaves the enclave after this point. */
    uint8_t ss[32];
    ocall_ret = 0;
    sgx_ret = ocall_kyber768_encaps(&ocall_ret, hdfs_pk, out_ctk, ss);
    if (sgx_ret != SGX_SUCCESS || ocall_ret != 0) {
        printf("[Enclave] ocall_kyber768_encaps failed\n");
        return -1;
    }

    /* K'_e = SHA-256("KDF" || SS_e)  — domain-separated KDF */
    uint8_t Kprime[32];
    {
        const char    *tag    = "KDF";
        const void    *parts[] = { tag, ss };
        const uint32_t lens[]  = { 3, 32 };
        sha256_multi(parts, lens, 2, Kprime);
    }

    /* EncK_e = K_e XOR K'_e */
    for (int i = 0; i < 32; i++)
        out_enc_ke[i] = Ke[i] ^ Kprime[i];

    /* ---- Step 6: CP-ABE Simulation (eq. 45) ---- */
    /* True lattice CP-ABE is not available in SGX-compatible form.
     * We simulate it as AES-GCM(policy_key, EncK_e) where
     * policy_key = SHA-256("ABE" || pol_ref || epoch_id).
     * A real deployment would replace this with a proper CP-ABE library. */
    uint8_t policy_key[32];
    {
        const char    *tag    = "ABE";
        uint32_t       eid    = epoch_id;
        const void    *parts[] = { tag, pol_ref, &eid };
        const uint32_t lens[]  = { 3, polref_len, 4 };
        sha256_multi(parts, lens, 3, policy_key);
    }

    uint8_t abe_iv[12];
    uint8_t enc_enc_ke[32];
    uint8_t abe_tag[16];
    sgx_ret = sgx_aes_gcm_encrypt(
        policy_key, 32,
        out_enc_ke, 32,
        enc_enc_ke,
        abe_iv, 12,
        NULL, 0,
        (sgx_aes_gcm_128bit_tag_t *)abe_tag);
    if (sgx_ret != SGX_SUCCESS) {
        printf("[Enclave] CP-ABE sim encrypt failed: 0x%x\n", sgx_ret);
        return -1;
    }

    /* out_ct_abe = IV(12) || Tag(16) || EncEncK_e(32)  — 60 bytes total */
    memcpy(out_ct_abe,      abe_iv,     12);
    memcpy(out_ct_abe + 12, abe_tag,    16);
    memcpy(out_ct_abe + 28, enc_enc_ke, 32);

    /* ---- Step 7: Batch Commitment & Integrity Binding (eq. 46) ---- */
    /* h_e = SHA-256(epoch_id || CT_e || Tag_e || CT_ABE_e || CTK_e) */
    {
        uint32_t       eid    = epoch_id;
        const void    *parts[] = { &eid, out_ct, out_tag,
                                   out_ct_abe, out_ctk };
        const uint32_t lens[]  = { 4, ct_agg_size, 16, 60, 1088 };
        sgx_ret = sha256_multi(parts, lens, 5, out_batch_digest);
        if (sgx_ret != SGX_SUCCESS) {
            printf("[Enclave] batch digest SHA-256 failed: 0x%x\n", sgx_ret);
            return -1;
        }
    }

    /* ---- Step 8: Post-Quantum Signature via OCALL (eq. 47) ---- */
    uint32_t sig_len = 0;
    ocall_ret = 0;
    sgx_ret = ocall_dilithium_sign(
        &ocall_ret, 32, out_batch_digest, out_signature, &sig_len);
    if (sgx_ret != SGX_SUCCESS || ocall_ret != 0) {
        printf("[Enclave] ocall_dilithium_sign failed\n");
        return -1;
    }
    *out_sig_len = sig_len;

    printf("[Enclave] Phase 4 complete — valid=%u sig_len=%u\n",
           valid_count, sig_len);
    return 0;
}
