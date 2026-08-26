import json
nb = json.load(open('demo.ipynb', encoding='utf-8'))
source = nb['cells'][12]['source']
new_method = '''    def aggregate_ciphertexts(self, valid_records):
        agg = b''
        for Mi in valid_records:
            device_id_b = Mi["ID"].encode()
            ts_b = struct.pack(">d", Mi["ts"])
            ctr_b = Mi["ctr"].to_bytes(8, 'big')
            nonce = Mi["nounce"]
            aad = Mi["AAD"]
            ci = Mi["Ci"]
            
            record = (
                len(device_id_b).to_bytes(4, 'big') + device_id_b +
                ts_b +
                ctr_b +
                len(nonce).to_bytes(4, 'big') + nonce +
                len(aad).to_bytes(4, 'big') + aad +
                len(ci).to_bytes(4, 'big') + ci
            )
            agg += record
        return agg'''
new_lines = [line + '\n' for line in new_method.split('\n') if line]
source[113:120] = new_lines
json.dump(nb, open('demo.ipynb', 'w', encoding='utf-8'), indent=1)
