#!/usr/bin/env python3
"""
Converts Kafa-Spark_Output.txt (Phase 3 output from demo.ipynb) to a raw
binary file whose layout matches phase4_batch_input_t exactly, so the SGX
demo can read it with a single fread() call.

Usage:
    python3 App/convert_input.py Kafa-Spark_Output.txt phase4_input.bin
"""
import sys
import ast
import struct
import hashlib

PHASE4_MAX_RECORDS    = 1000
PHASE4_RECORD_CT_SIZE = 128   # bytes per record slot in ct_agg


def main():
    if len(sys.argv) < 3:
        print(f"Usage: {sys.argv[0]} Kafa-Spark_Output.txt output.bin")
        sys.exit(1)

    with open(sys.argv[1]) as f:
        final_output = ast.literal_eval(f.read().strip())

    # Aggregate all records across epochs (chronological order, cap at max)
    all_records = []
    all_H_e     = []
    for e in sorted(final_output):
        d = final_output[e]
        for r in d['De']:
            if len(all_records) >= PHASE4_MAX_RECORDS:
                break
            all_records.append(r)
        all_H_e.extend(d['H_e'])

    if not all_records:
        print("ERROR: no records found in input file")
        sys.exit(1)

    n            = len(all_records)
    first_epoch  = min(final_output)
    epoch_id_u32 = first_epoch & 0xFFFFFFFF
    ce           = all_records[0]['ce']
    polref_str   = final_output[first_epoch]['PolRef']

    # epoch_challenge: deterministic 32-byte derivation from ce + epoch_id
    epoch_challenge = hashlib.sha256(
        ce.encode() + struct.pack('<I', epoch_id_u32)
    ).digest()

    polref_bytes = polref_str.encode()[:64].ljust(64, b'\x00')
    polref_len   = min(len(polref_str.encode()), 64)

    # batch_pre_digest = SHA256 of all per-record pre-digests concatenated
    batch_pre_digest = hashlib.sha256(
        b''.join(h.encode() for h in all_H_e)
    ).digest()

    # --- header fields ---
    buf  = struct.pack('<I', epoch_id_u32)   # uint32_t epoch_id
    buf += epoch_challenge                    # uint8_t  epoch_challenge[32]
    buf += polref_bytes                       # uint8_t  pol_ref[64]
    buf += struct.pack('<I', polref_len)      # uint32_t polref_len
    buf += batch_pre_digest                   # uint8_t  batch_pre_digest[32]
    buf += struct.pack('<I', n)               # uint32_t num_records

    # --- auth_records[1000] (124 bytes each) ---
    auth_records = bytearray(PHASE4_MAX_RECORDS * 124)
    for i, Mi in enumerate(all_records):
        off    = i * 124
        dev_id = Mi['ID'].encode()[:16].ljust(16, b'\x00')

        auth_records[off     : off+16] = dev_id
        auth_records[off+16  : off+24] = struct.pack('>d', Mi['ts'])
        auth_records[off+24  : off+28] = struct.pack('<I', Mi['ctr'] & 0xFFFFFFFF)
        # puf_response: deterministic stand-in derived from device + epoch;
        # App.cpp recomputes auth_token from all fields so enclave verifies OK
        auth_records[off+28  : off+60] = hashlib.sha256(dev_id + epoch_challenge).digest()
        auth_records[off+60  : off+92] = hashlib.sha256(Mi['Ci']).digest()
        # auth_token[32] left zero — overwritten by App.cpp before ECALL

    buf += bytes(auth_records)

    # --- ct_agg[128000] (128-byte slots) ---
    ct_agg = bytearray(PHASE4_MAX_RECORDS * PHASE4_RECORD_CT_SIZE)
    for i, Mi in enumerate(all_records):
        ci       = Mi['Ci']
        copy_len = min(len(ci), PHASE4_RECORD_CT_SIZE)
        base     = i * PHASE4_RECORD_CT_SIZE
        ct_agg[base : base + copy_len] = ci[:copy_len]

    buf += bytes(ct_agg)
    buf += struct.pack('<I', n * PHASE4_RECORD_CT_SIZE)  # uint32_t ct_agg_size

    with open(sys.argv[2], 'wb') as f:
        f.write(buf)

    print(f"Written {len(buf)} bytes  |  epoch_id={epoch_id_u32}"
          f"  records={n}  PolRef={polref_str}")


if __name__ == '__main__':
    main()
