sudo apt-get update
sudo apt-get install -y curl python3

python3 - <<'PY'
import json
import urllib.request
import urllib.parse

for country in ("GB", "NL"):
    params = urllib.parse.urlencode({
        "country_code": country,
        "is_public": "true",
        "status": 2,
        "page_size": 100
    })
    url = "https://atlas.ripe.net/api/v2/probes/?" + params

    print(f"\n=== {country} ===")
    try:
        with urllib.request.urlopen(url, timeout=20) as r:
            data = json.load(r)

        count = 0
        for p in data.get("results", []):
            address = p.get("address_v4")
            asn = p.get("asn_v4")
            if address and asn:
                print(f"IP={address} ASN={asn} ProbeID={p.get('id')}")
                count += 1
                if count == 5:
                    break

        if count == 0:
            print("No matching IPv4 probes returned; check API response.")
    except Exception as e:
        print("API error:", e)
PY