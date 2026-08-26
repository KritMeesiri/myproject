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
#include <iostream>
#include <cstdio>
#include <time.h>
#include <stddef.h>
#include <stdbool.h>
#include <stdint.h>
#include <stdio.h>
#include <string.h>
#include <stdlib.h>
#include <assert.h>
#include <oqs/oqs.h>
#include <oqs/common.h>
#include <openssl/sha.h>
# include <unistd.h>
# include <pwd.h>
# include <errno.h>
# define MAX_PATH FILENAME_MAX

#include "sgx_urts.h"
#include "App.h"
#include "Enclave_u.h"
#include "phase4_types.h"

/* Global EID shared by multiple threads */
sgx_enclave_id_t global_eid = 0;

/* ------------------------------------------------------------------ */
/* Host-side key storage for OCALL implementations                    */
/* ------------------------------------------------------------------ */
static uint8_t g_kyber_pk[KYBER768_PK_LEN];
static uint8_t g_kyber_sk[KYBER768_SK_LEN];
static uint8_t g_mldsa_pk[MLDSA65_PK_LEN];
static uint8_t g_mldsa_sk[MLDSA65_SK_LEN];
double t0, tt;

typedef struct _sgx_errlist_t {
    sgx_status_t err;
    const char *msg;
    const char *sug; /* Suggestion */
} sgx_errlist_t;

/* Error code returned by sgx_create_enclave */
static sgx_errlist_t sgx_errlist[] = {
    {
        SGX_ERROR_UNEXPECTED,
        "Unexpected error occurred.",
        NULL
    },
    {
        SGX_ERROR_INVALID_PARAMETER,
        "Invalid parameter.",
        NULL
    },
    {
        SGX_ERROR_OUT_OF_MEMORY,
        "Out of memory.",
        NULL
    },
    {
        SGX_ERROR_ENCLAVE_LOST,
        "Power transition occurred.",
        "Please refer to the sample \"PowerTransition\" for details."
    },
    {
        SGX_ERROR_INVALID_ENCLAVE,
        "Invalid enclave image.",
        NULL
    },
    {
        SGX_ERROR_INVALID_ENCLAVE_ID,
        "Invalid enclave identification.",
        NULL
    },
    {
        SGX_ERROR_INVALID_SIGNATURE,
        "Invalid enclave signature.",
        NULL
    },
    {
        SGX_ERROR_OUT_OF_EPC,
        "Out of EPC memory.",
        NULL
    },
    {
        SGX_ERROR_NO_DEVICE,
        "Invalid SGX device.",
        "Please make sure SGX module is enabled in the BIOS, and install SGX driver afterwards."
    },
    {
        SGX_ERROR_MEMORY_MAP_CONFLICT,
        "Memory map conflicted.",
        NULL
    },
    {
        SGX_ERROR_INVALID_METADATA,
        "Invalid enclave metadata.",
        NULL
    },
    {
        SGX_ERROR_DEVICE_BUSY,
        "SGX device was busy.",
        NULL
    },
    {
        SGX_ERROR_INVALID_VERSION,
        "Enclave version was invalid.",
        NULL
    },
    {
        SGX_ERROR_INVALID_ATTRIBUTE,
        "Enclave was not authorized.",
        NULL
    },
    {
        SGX_ERROR_ENCLAVE_FILE_ACCESS,
        "Can't open enclave file.",
        NULL
    },
    {
        SGX_ERROR_MEMORY_MAP_FAILURE,
        "Failed to reserve memory for the enclave.",
        NULL
    },
};

/* Check error conditions for loading enclave */
void print_error_message(sgx_status_t ret)
{
    size_t idx = 0;
    size_t ttl = sizeof sgx_errlist/sizeof sgx_errlist[0];

    for (idx = 0; idx < ttl; idx++) {
        if(ret == sgx_errlist[idx].err) {
            if(NULL != sgx_errlist[idx].sug)
                fprintf(stderr,"Info: %s\n", sgx_errlist[idx].sug);
            fprintf(stderr,"Error: %s\n", sgx_errlist[idx].msg);
            break;
        }
    }

    if (idx == ttl)
        fprintf(stderr,"Error code is 0x%X. Please refer to the \"Intel SGX SDK Developer Reference\" for more details.\n", ret);
}

/* Initialize the enclave:
 *   Call sgx_create_enclave to initialize an enclave instance
 */
int initialize_enclave(void)
{
    sgx_status_t ret = SGX_ERROR_UNEXPECTED;

    /* Call sgx_create_enclave to initialize an enclave instance */
    /* Debug Support: set 2nd parameter to 1 */
    ret = sgx_create_enclave(ENCLAVE_FILENAME, SGX_DEBUG_FLAG, NULL, NULL, &global_eid, NULL);
    if (ret != SGX_SUCCESS) {
        print_error_message(ret);
        return -1;
    }

    return 0;
}

/* OCall functions */
void ocall_print_string(const char *str)
{
    /* Proxy/Bridge will check the length and null-terminate
     * the input string to prevent buffer overflow.
     */
    fprintf(stderr,"%s", str);
}

/* ------------------------------------------------------------------ */
/* OCALL: Kyber768 encapsulation using liboqs                         */
/* Paper eq. 42 — Kyber.Encaps(PK_S) → (CTK_e, SS_e)                */
/* ------------------------------------------------------------------ */
int ocall_kyber768_encaps(const uint8_t *pk, uint8_t *ct, uint8_t *ss)
{
    OQS_STATUS rc = OQS_KEM_kyber_768_encaps(ct, ss, pk);
    return (rc == OQS_SUCCESS) ? 0 : -1;
}

/* ------------------------------------------------------------------ */
/* OCALL: ML-DSA-65 signing using liboqs                              */
/* Paper eq. 47 — Dilithium.Sign(SK_sig, h_e) → sigma_e              */
/* g_mldsa_sk holds the enclave's signing key provisioned at startup. */
/* ------------------------------------------------------------------ */
int ocall_dilithium_sign(uint32_t msg_len, const uint8_t *msg,
                         uint8_t *sig, uint32_t *sig_len)
{
    size_t slen = 0;
    OQS_STATUS rc = OQS_SIG_ml_dsa_65_sign(
        sig, &slen, msg, (size_t)msg_len, g_mldsa_sk);
    *sig_len = (uint32_t)slen;
    return (rc == OQS_SUCCESS) ? 0 : -1;
}

/* ------------------------------------------------------------------ */
/* Utility: print first 8 bytes of a buffer as hex                   */
/* ------------------------------------------------------------------ */
static void print_hex(const char *label, const uint8_t *buf, size_t len)
{
    size_t show = (len < 8) ? len : 8;
    fprintf(stderr,"  %-26s ", label);
    for (size_t i = 0; i < show; i++)
        fprintf(stderr,"%02x", buf[i]);
    fprintf(stderr,"... (%zu B)\n", len);
}

/* -------------------------------------------------------------------*/
/* Timing Helper                                                      */
/* -------------------------------------------------------------------*/
static double time_ms(void)
{
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return ts.tv_sec * 1000.0 + ts.tv_nsec / 1e6;
}


/* ------------------------------------------------------------------ */
/* Phase 4 demo                                                        */
/* ------------------------------------------------------------------ */
static void run_phase4_demo(int argc, char *argv[])
{
    fprintf(stderr,"\n=== Phase 4: TEE-Assisted PQ Signcryption Demo ===\n\n");
    /* Step 1 — Generate keypairs on the host ----------------------- */
    fprintf(stderr,"[App] Generating Kyber768 keypair...\n");
    t0 = time_ms();
    if (OQS_KEM_kyber_768_keypair(g_kyber_pk, g_kyber_sk) != OQS_SUCCESS) {
        fprintf(stderr,"[App] ERROR: Kyber768 keygen failed\n");
        return;
    }
    tt += time_ms() - t0;
    print_hex("Kyber768 PK", g_kyber_pk, KYBER768_PK_LEN);

    fprintf(stderr,"[App] Generating ML-DSA-65 keypair...\n");
    t0 = time_ms();
    if (OQS_SIG_ml_dsa_65_keypair(g_mldsa_pk, g_mldsa_sk) != OQS_SUCCESS) {
        fprintf(stderr,"[App] ERROR: ML-DSA-65 keygen failed\n");
        return;
    }
    tt += time_ms() - t0;
    print_hex("ML-DSA-65 PK", g_mldsa_pk, MLDSA65_PK_LEN);

    /* Step 2 — Load batch input from binary file produced by convert_input.py */
    phase4_batch_input_t batch;
    memset(&batch, 0, sizeof(batch));
    if (argc < 2) {
        fprintf(stderr, "[App] Usage: %s <phase4_input.bin>\n", argv[0]);
        fprintf(stderr, "[App] Generate with: python3 App/convert_input.py Kafa-Spark_Output.txt phase4_input.bin\n");
        return;
    }
    FILE *fp = fopen(argv[1], "rb");
    if (!fp) {
        fprintf(stderr, "[App] ERROR: cannot open '%s': %s\n", argv[1], strerror(errno));
        return;
    }
    size_t nr = fread(&batch, 1, sizeof(batch), fp);
    fclose(fp);
    if (nr != sizeof(batch)) {
        fprintf(stderr, "[App] ERROR: expected %zu bytes, read %zu\n", sizeof(batch), nr);
        return;
    }

    /* Recompute valid auth_tokens using the same formula as the enclave:
     *   Auth_i = SHA-256(ID_i || ts_i || ctr_i || c_e || r_{i,e} || H(C_i))  */
    for (uint32_t i = 0; i < batch.num_records; i++) {
        phase4_auth_record_t *r = &batch.auth_records[i];

        uint8_t auth_input[16 + 8 + 4 + 32 + 32 + 32]; /* 124 bytes */
        memcpy(auth_input,      r->device_id,          16);
        memcpy(auth_input + 16, r->timestamp,           8);
        memcpy(auth_input + 24, &r->counter,            4);
        memcpy(auth_input + 28, batch.epoch_challenge, 32);
        memcpy(auth_input + 60, r->puf_response,       32);
        memcpy(auth_input + 92, r->ct_hash,            32);
        SHA256(auth_input, 124, r->auth_token);
    }

    fprintf(stderr,"[App] Batch built: epoch_id=%u, records=%u, ct_agg=%u B\n",
           batch.epoch_id, batch.num_records, batch.ct_agg_size);
    print_hex("epoch_challenge", batch.epoch_challenge, 32);
    print_hex("batch_pre_digest", batch.batch_pre_digest, 32);
    t0 = time_ms();
    /* Step 3 — Allocate output buffers ----------------------------- */
    uint8_t *out_ct = (uint8_t *)malloc(batch.ct_agg_size);
    if (!out_ct) { fprintf(stderr,"[App] ERROR: malloc failed\n"); return; }

    uint8_t  out_iv[12]               = {0};
    uint8_t  out_tag[16]              = {0};
    uint8_t  out_ctk[KYBER768_CT_LEN] = {0};
    uint8_t  out_enc_ke[32]           = {0};
    uint8_t  out_ct_abe[60]           = {0};
    uint8_t  out_batch_digest[32]     = {0};
    uint8_t  out_signature[MLDSA65_SIG_LEN] = {0};
    uint32_t out_sig_len    = 0;
    uint32_t out_valid_count = 0;

    /* Step 4 — Call the ECALL, unpacking fields from batch --------- */
    fprintf(stderr,"[App] Entering ecall_phase4_signcrypt...\n");
    int          retval = 0;
    sgx_status_t status = ecall_phase4_signcrypt(
        global_eid, &retval,
        /* scalar inputs */
        batch.epoch_id,
        batch.polref_len,
        batch.num_records,
        (uint32_t)(batch.num_records * sizeof(phase4_auth_record_t)),
        batch.ct_agg_size,
        /* buffer inputs */
        batch.epoch_challenge,
        batch.pol_ref,
        batch.batch_pre_digest,
        (const uint8_t *)batch.auth_records,
        batch.ct_agg,
        g_kyber_pk,
        /* buffer outputs */
        out_ct,
        out_iv,
        out_tag,
        out_ctk,
        out_enc_ke,
        out_ct_abe,
        out_batch_digest,
        out_signature,
        /* scalar outputs */
        &out_sig_len,
        &out_valid_count);

    if (status != SGX_SUCCESS || retval != 0) {
        print_error_message(status);
        fprintf(stderr,"[App] ERROR: ecall_phase4_signcrypt failed (sgx=0x%x ret=%d)\n",
               status, retval);
        free(out_ct);
        return;
    }
    tt += time_ms() - t0;

    /* Step 5 — Print results --------------------------------------- */
    fprintf(stderr,"\n[App] Results:\n");
    fprintf(stderr,"  Valid records:             %u / %u\n",
           out_valid_count, batch.num_records);
    print_hex("CT_e (encrypted batch)",   out_ct,           batch.ct_agg_size);
    print_hex("IV",                        out_iv,           12);
    print_hex("AES-GCM Tag",               out_tag,          16);
    print_hex("CTK_e (Kyber768 CT)",       out_ctk,          KYBER768_CT_LEN);
    print_hex("EncK_e",                    out_enc_ke,       32);
    print_hex("CT_ABE (simulated)",        out_ct_abe,       60);
    print_hex("h_e (batch digest)",        out_batch_digest, 32);
    fprintf(stderr,"  Signature length:          %u B\n", out_sig_len);

    /* Step 6 — Verify sigma_e against h_e with the public key ------ */
    OQS_STATUS vret = OQS_SIG_ml_dsa_65_verify(
        out_batch_digest, 32,
        out_signature, (size_t)out_sig_len,
        g_mldsa_pk);
    fprintf(stderr,"\n[App] Signature verification: %s\n\n",
           (vret == OQS_SUCCESS) ? "PASS" : "FAIL");

    free(out_ct);
}

/* Application entry */
int SGX_CDECL main(int argc, char *argv[])
{
    (void)(argc);
    (void)(argv);

    /* Initialize the enclave */
    if(initialize_enclave() < 0){
        fprintf(stderr,"Enter a character before exit ...\n");
        getchar();
        return -1;
    }

    /* Phase 4 demo */
    run_phase4_demo(argc, argv);

    /* Utilize edger8r attributes */
    edger8r_array_attributes();
    edger8r_pointer_attributes();
    edger8r_type_attributes();
    edger8r_function_attributes();

    /* Utilize trusted libraries */
    ecall_libc_functions();
    ecall_libcxx_functions();
    ecall_thread_functions();

    /* Destroy the enclave */
    sgx_destroy_enclave(global_eid);

    fprintf(stderr,"Info: SampleEnclave successfully returned.\n");
    fprintf(stderr,"Total Time = %.5f ms\n", tt);
    double t_records = tt / PHASE4_MAX_RECORDS;
    fprintf(stderr,"Total Time Amortised per-records = %.5f ms\n", t_records);
    std::cout << tt;
    return 0;
}
