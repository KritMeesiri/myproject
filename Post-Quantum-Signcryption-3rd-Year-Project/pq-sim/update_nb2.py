import json
nb = json.load(open('demo.ipynb', encoding='utf-8'))
source = nb['cells'][15]['source']
new_method = '''    def parse_aggregated_ciphertext(self, Cagg_e):
        """Parse aggregated ciphertext back into individual encrypted records with metadata."""
        print(f"[HDFSClient {self.receiver_id}] Parsing aggregated ciphertext")
        
        messages = []
        offset = 0
        
        while offset < len(Cagg_e):
            # device_id
            if offset + 4 > len(Cagg_e):
                raise ValueError(f"Malformed aggregated ciphertext: incomplete device_id length at offset {offset}")
            len_id = int.from_bytes(Cagg_e[offset:offset+4], 'big')
            offset += 4
            device_id_b = Cagg_e[offset:offset+len_id]
            device_id = device_id_b.decode()
            offset += len_id
            
            # ts
            if offset + 8 > len(Cagg_e):
                raise ValueError(f"Malformed aggregated ciphertext: incomplete ts at offset {offset}")
            ts = struct.unpack(">d", Cagg_e[offset:offset+8])[0]
            offset += 8
            
            # ctr
            if offset + 8 > len(Cagg_e):
                raise ValueError(f"Malformed aggregated ciphertext: incomplete ctr at offset {offset}")
            ctr = int.from_bytes(Cagg_e[offset:offset+8], 'big')
            offset += 8
            
            # nonce
            if offset + 4 > len(Cagg_e):
                raise ValueError(f"Malformed aggregated ciphertext: incomplete nonce length at offset {offset}")
            len_nonce = int.from_bytes(Cagg_e[offset:offset+4], 'big')
            offset += 4
            nonce = Cagg_e[offset:offset+len_nonce]
            offset += len_nonce
            
            # aad
            if offset + 4 > len(Cagg_e):
                raise ValueError(f"Malformed aggregated ciphertext: incomplete AAD length at offset {offset}")
            len_aad = int.from_bytes(Cagg_e[offset:offset+4], 'big')
            offset += 4
            aad = Cagg_e[offset:offset+len_aad]
            offset += len_aad
            
            # ci
            if offset + 4 > len(Cagg_e):
                raise ValueError(f"Malformed aggregated ciphertext: incomplete Ci length at offset {offset}")
            len_ci = int.from_bytes(Cagg_e[offset:offset+4], 'big')
            offset += 4
            ci = Cagg_e[offset:offset+len_ci]
            offset += len_ci
            
            messages.append({
                "device_id": device_id,
                "ts": ts,
                "ctr": ctr,
                "nonce": nonce,
                "aad": aad,
                "ci": ci
            })
        
        if offset != len(Cagg_e):
            raise ValueError(f"Malformed aggregated ciphertext: trailing {len(Cagg_e) - offset} bytes after parsing all records")
        
        print(f"  Parsed {len(messages)} encrypted records from batch")
        return messages'''
new_lines = [line + '\n' for line in new_method.split('\n') if line]
source[75:110] = new_lines
json.dump(nb, open('demo.ipynb', 'w', encoding='utf-8'), indent=1)
