#!/usr/bin/env bash
# For each endpoint:
#   Prefix            network block containing the IP
#   ASN / Holder      network announcing the prefix, and its operator
#   ISP / Org         provider name from geolocation data
#   Datacenter        hosting flag, whois netname, and facility description
#   Location          city/region/country (a clue, not the physical route)
# Then an MTR report from this host: hop, loss, and latency.

set -euo pipefail

SERVER_IP="${1:-103.161.34.31}"
VIEWER_IP="${2:-185.217.39.5}"
MTR_CYCLES="${MTR_CYCLES:-3}"

for cmd in python3 curl mtr; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "Missing required command: $cmd" >&2
        exit 1
    fi
done

python3 - "$SERVER_IP" "$VIEWER_IP" <<'PY'
import json
import socket
import sys
import urllib.parse
import urllib.request

server_ip, viewer_ip = sys.argv[1], sys.argv[2]
WHOIS_KEYS = ("inetnum", "netname", "descr", "country", "org", "status")


def fetch(url, timeout=25):
    req = urllib.request.Request(url, headers={"User-Agent": "asn-finder/1.0"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.load(resp)


def ripe(endpoint, resource):
    query = urllib.parse.urlencode({"resource": resource})
    body = fetch(f"https://stat.ripe.net/data/{endpoint}/data.json?{query}")
    if body.get("status") != "ok":
        raise RuntimeError(f"RIPE {endpoint} failed for {resource}")
    return body.get("data") or {}


def whois_fields(ip):
    found = {key: [] for key in WHOIS_KEYS}
    for record in ripe("whois", ip).get("records") or []:
        for field in record:
            key = (field.get("key") or "").lower()
            if key in found and field.get("value"):
                found[key].append(field["value"])
    return {key: values[0] if values else "" for key, values in found.items()}


def geoloc(ip):
    resources = ripe("geoloc", ip).get("located_resources") or []
    if not resources:
        return {}
    locations = resources[0].get("locations") or []
    return locations[0] if locations else {}


def ipapi(ip):
    fields = "status,message,country,countryCode,regionName,city,isp,org,as,asname,hosting,query"
    body = fetch(f"http://ip-api.com/json/{urllib.parse.quote(ip)}?fields={fields}")
    if body.get("status") != "success":
        return {}
    return body


def reverse_dns(ip):
    try:
        return socket.gethostbyaddr(ip)[0]
    except OSError:
        return ""


def fmt(value):
    if value is None or value == "":
        return "-"
    return str(value)


def report(role, ip):
    overview = ripe("prefix-overview", ip)
    asns = overview.get("asns") or []
    asn_nums = [str(item.get("asn")) for item in asns if item.get("asn") is not None]
    holders = [item.get("holder") for item in asns if item.get("holder")]
    prefix = overview.get("resource") or ""
    whois = whois_fields(ip)
    geo = geoloc(ip)
    isp = ipapi(ip)
    rdns = reverse_dns(ip)

    hosting = isp.get("hosting")
    if hosting is True:
        facility = "hosting / datacenter IP"
    elif hosting is False:
        facility = "not flagged as hosting"
    else:
        facility = "unknown"

    location_bits = [isp.get("city"), isp.get("regionName"), isp.get("country")]
    location = ", ".join(bit for bit in location_bits if bit) or "-"
    ripe_place = ", ".join(
        bit for bit in (geo.get("city"), geo.get("country")) if bit
    ) or "-"
    lat, lon = geo.get("latitude"), geo.get("longitude")
    coords = f"{lat}, {lon}" if lat is not None and lon is not None else "-"

    print(f"===== {role} {ip} =====")
    print(f"Prefix:       {fmt(prefix)}")
    print(f"ASN:          {fmt(', '.join('AS' + n for n in asn_nums))}")
    print(f"Holder:       {fmt('; '.join(holders))}")
    print(f"ISP:          {fmt(isp.get('isp'))}")
    print(f"Org:          {fmt(isp.get('org') or isp.get('asname'))}")
    print(f"Datacenter:   {facility}")
    print(f"Netname:      {fmt(whois.get('netname'))}")
    print(f"Description:  {fmt(whois.get('descr'))}")
    print(f"Country:      {fmt(whois.get('country'))} (whois)")
    print(f"Location:     {location} (ip-api)")
    print(f"RIPE geoloc:  {ripe_place} [{coords}]")
    print(f"Reverse DNS:  {fmt(rdns)}")
    print(f"Announced:    {fmt(overview.get('announced'))}")
    print()


for role, ip in (("SERVER", server_ip), ("VIEWER", viewer_ip)):
    try:
        report(role, ip)
    except Exception as exc:
        print(f"===== {role} {ip} =====")
        print(f"Lookup failed: {exc}")
        print()
PY

host_ips="$(hostname -I 2>/dev/null || true)"
target="$VIEWER_IP"
target_role="VIEWER"
if [[ " $host_ips " == *" $VIEWER_IP "* && " $host_ips " != *" $SERVER_IP "* ]]; then
    target="$SERVER_IP"
    target_role="SERVER"
fi

echo "===== ROUTE $(hostname) -> ${target_role} ${target} ====="
echo "Loss and latency are measured from this host. Times are milliseconds."
mtr -n -r -w -c "$MTR_CYCLES" "$target"
