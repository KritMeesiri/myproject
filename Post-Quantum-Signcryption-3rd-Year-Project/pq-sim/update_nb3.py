import json
nb = json.load(open('demo.ipynb', encoding='utf-8'))
source = nb['cells'][15]['source']
new_method = '''    def decrypt_records(self, encrypted_records, SSe):
        """Decrypt individual records to plaintext using the shared secret."""
        print(f"[HDFSClient {self.receiver_id}] Decrypting individual records")
        
        plaintexts = []
        for record in encrypted_records:
            device_id = record["device_id"]
            ts = record["ts"]
            ctr = record["ctr"]
            nonce = record["nonce"]
            aad = record["aad"]
            ci = record["ci"]
            
            # Derive Ki
            device_id_bytes = device_id.encode()
            ts_bytes = struct.pack(">d", ts)
            Ki = HKDF(
                algorithm=hashes.SHA256(),
                length=32,
                salt=None,
                info=b'IoT data encryption',
            ).derive(device_id_bytes + ts_bytes + ctr.to_bytes(8, 'big') + SSe)
            
            # Decrypt
            try:
                aead = ChaCha20Poly1305(Ki)
                plaintext_bytes = aead.decrypt(nonce, ci, aad)
                plaintext = plaintext_bytes.decode()
                plaintexts.append({
                    "device_id": device_id,
                    "ts": ts,
                    "ctr": ctr,
                    "plaintext": plaintext
                })
            except Exception as e:
                print(f"  ERROR: Failed to decrypt record for {device_id} ctr {ctr} - {e}")
                plaintexts.append({
                    "device_id": device_id,
                    "ts": ts,
                    "ctr": ctr,
                    "plaintext": None,
                    "error": str(e)
                })
        
        print(f"  Decrypted {len([p for p in plaintexts if p['plaintext'] is not None])} records successfully")
        return plaintexts'''
new_lines = [line + '\n' for line in new_method.split('\n') if line]
source[142:142] = new_lines
json.dump(nb, open('demo.ipynb', 'w', encoding='utf-8'), indent=1)
