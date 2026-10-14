#!/usr/bin/env bash
# Usage:
#   ./routes_finder.sh [SERVER_IP] [ISP_IP ...]
#   ./routes_finder.sh [SERVER_IP] isps.txt
#
# Each ISP_IP is an address on that provider. The script prints prefix, ASN,
# holder, ISP, and datacenter for the server and every ISP address, then the
# MTR path from this host to each address (per-hop loss and latency), then a
# comparison of loss and latency to each ISP.

set -euo pipefail

SERVER_IP="103.161.34.31"
ISP_IPS=(
    "185.217.39.5"
    "91.194.69.20"
    "178.238.155.116"
    "86.20.17.64"
    "91.244.245.249"
    "82.174.239.250"
    "82.139.83.218"
    "193.0.0.165"
)
MTR_CYCLES="${MTR_CYCLES:-3}"

for cmd in python3 curl mtr; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "Missing required command: $cmd" >&2
        exit 1
    fi
done

if [[ $# -ge 1 ]]; then
    SERVER_IP="$1"
    shift
fi

if [[ $# -eq 1 && -f "$1" ]]; then
    mapfile -t ISP_IPS < <(grep -Ev '^[[:space:]]*(#|$)' "$1" | awk '{print $1}')
elif [[ $# -ge 1 ]]; then
    ISP_IPS=("$@")
fi

if [[ ${#ISP_IPS[@]} -eq 0 ]]; then
    echo "Give at least one ISP address." >&2
    exit 1
fi

is_ipv4() {
    local ip="$1" octet
    [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
    IFS=. read -r o1 o2 o3 o4 <<< "$ip"
    for octet in "$o1" "$o2" "$o3" "$o4"; do
        if (( octet > 255 )); then
            return 1
        fi
    done
}

if ! is_ipv4 "$SERVER_IP"; then
    echo "Server IP is not IPv4: $SERVER_IP" >&2
    exit 1
fi
for ip in "${ISP_IPS[@]}"; do
    if ! is_ipv4 "$ip"; then
        echo "ISP address is not IPv4: $ip" >&2
        exit 1
    fi
done

names_file="$(mktemp)"
summary_file="$(mktemp)"
trap 'rm -f "$names_file" "$summary_file"' EXIT

python3 - "$names_file" "$SERVER_IP" "${ISP_IPS[@]}" <<'PY'
import json
import socket
import sys
import urllib.parse
import urllib.request

names_path, server_ip, *isp_ips = sys.argv[1:]
WHOIS_KEYS = ("inetnum", "netname", "descr", "country", "org", "status")


def fetch(url, timeout=25):
    req = urllib.request.Request(url, headers={"User-Agent": "routes-finder/1.0"})
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
    whois = whois_fields(ip)
    geo = geoloc(ip)
    isp = ipapi(ip)
    hosting = isp.get("hosting")
    if hosting is True:
        facility = "hosting / datacenter IP"
    elif hosting is False:
        facility = "not flagged as hosting"
    else:
        facility = "unknown"
    def place(value):
        text = "" if value is None else str(value).strip()
        if text in {"", "?", "None", "unknown"}:
            return ""
        return text

    location = ", ".join(
        bit for bit in (place(isp.get("city")), place(isp.get("regionName")), place(isp.get("country"))) if bit
    ) or "-"
    ripe_place = ", ".join(bit for bit in (place(geo.get("city")), place(geo.get("country"))) if bit) or "-"
    lat, lon = geo.get("latitude"), geo.get("longitude")
    coords = "-"
    if lat is not None and lon is not None and not (float(lat) == 0.0 and float(lon) == 0.0):
        coords = f"{lat}, {lon}"
    isp_name = isp.get("isp") or (holders[0] if holders else "")

    print(f"===== {role} {ip} =====")
    print(f"Prefix:       {fmt(overview.get('resource'))}")
    print(f"ASN:          {fmt(', '.join('AS' + n for n in asn_nums))}")
    print(f"Holder:       {fmt('; '.join(holders))}")
    print(f"ISP:          {fmt(isp_name)}")
    print(f"Org:          {fmt(isp.get('org') or isp.get('asname'))}")
    print(f"Datacenter:   {facility}")
    print(f"Netname:      {fmt(whois.get('netname'))}")
    print(f"Description:  {fmt(whois.get('descr'))}")
    print(f"Country:      {fmt(whois.get('country'))} (whois)")
    print(f"Location:     {location} (ip-api)")
    print(f"RIPE geoloc:  {ripe_place} [{coords}]")
    print(f"Reverse DNS:  {fmt(reverse_dns(ip))}")
    print(f"Announced:    {fmt(overview.get('announced'))}")
    print()
    return isp_name


names = {}
try:
    report("SERVER", server_ip)
except Exception as exc:
    print(f"===== SERVER {server_ip} =====")
    print(f"Lookup failed: {exc}")
    print()

for ip in isp_ips:
    try:
        names[ip] = report("ISP", ip)
    except Exception as exc:
        print(f"===== ISP {ip} =====")
        print(f"Lookup failed: {exc}")
        print()
        names[ip] = ""

with open(names_path, "w", encoding="utf-8") as handle:
    for ip in isp_ips:
        handle.write(f"{ip}\t{names.get(ip, '')}\n")
PY

host_ips="$(hostname -I 2>/dev/null || true)"
if [[ " $host_ips " != *" $SERVER_IP "* ]]; then
    echo "This host ($(hostname)) does not own $SERVER_IP."
    echo "MTR below is the path from this host to each ISP, not from the server itself."
    echo
fi

lookup_name() {
    awk -F '\t' -v ip="$1" '$1 == ip { print $2; exit }' "$names_file"
}

for ip in "${ISP_IPS[@]}"; do
    isp_name="$(lookup_name "$ip")"
    label="${isp_name:-unknown}"
    echo "===== ROUTE ${SERVER_IP} -> ${label} ${ip} ====="
    echo "Loss and latency are measured from this host. Times are milliseconds."
    if ! mtr_json="$(mtr -n --json -c "$MTR_CYCLES" "$ip" 2>&1)"; then
        echo "mtr failed for $ip"
        echo "$mtr_json"
        printf '%s\t%s\t-\t-\t-\t-\t-\tfailed\n' "$label" "$ip" >> "$summary_file"
        echo
        continue
    fi
    MTR_JSON="$mtr_json" python3 - "$summary_file" "$label" "$ip" <<'PY'
import json
import os
import sys

summary_path, label, target = sys.argv[1:]
raw = os.environ.get("MTR_JSON", "")
try:
    report = json.loads(raw)["report"]
except (json.JSONDecodeError, KeyError, TypeError):
    print(raw)
    print(f"mtr did not return a report for {target}")
    with open(summary_path, "a", encoding="utf-8") as handle:
        handle.write(f"{label}\t{target}\t-\t-\t-\t-\t-\tbad-report\n")
    sys.exit(0)

mtr = report.get("mtr") or {}
hubs = report.get("hubs") or []
src = mtr.get("src") or "this-host"

def num(hub, key):
    try:
        return float(hub.get(key))
    except (TypeError, ValueError):
        return 0.0

print(f"HOST: {src:<16} Loss%   Snt   Last   Avg  Best  Wrst StDev")
destination = None
for hub in hubs:
    host = hub.get("host") or "???"
    sent = int(num(hub, "Snt"))
    print(
        f"{int(num(hub, 'count')):3}.|-- {host:<16}"
        f"{num(hub, 'Loss%'):6.1f}% {sent:4} "
        f"{num(hub, 'Last'):6.1f} {num(hub, 'Avg'):5.1f} "
        f"{num(hub, 'Best'):5.1f} {num(hub, 'Wrst'):5.1f} {num(hub, 'StDev'):5.1f}"
    )
    if host == target:
        destination = hub

if destination is None:
    status = "no-reply"
    loss = avg = best = worst = "-"
else:
    status = "ok"
    loss = f"{num(destination, 'Loss%'):.1f}"
    avg = f"{num(destination, 'Avg'):.1f}"
    best = f"{num(destination, 'Best'):.1f}"
    worst = f"{num(destination, 'Wrst'):.1f}"

with open(summary_path, "a", encoding="utf-8") as handle:
    handle.write(
        f"{label}\t{target}\t{loss}\t{avg}\t{best}\t{worst}\t{len(hubs)}\t{status}\n"
    )
print()
PY
done

echo "===== LOSS AND LATENCY: ${SERVER_IP} -> each ISP ====="
printf '%-28s %-16s %7s %8s %8s %8s %5s\n' \
    "ISP" "IP" "Loss%" "Avg ms" "Best" "Wrst" "Hops"
printf '%-28s %-16s %7s %8s %8s %8s %5s\n' \
    "----------------------------" "----------------" "-------" "--------" "--------" "--------" "-----"
while IFS=$'\t' read -r label ip loss avg best worst hops status; do
    if [[ "$status" != "ok" ]]; then
        loss="$status"
    fi
    printf '%-28s %-16s %7s %8s %8s %8s %5s\n' \
        "$label" "$ip" "$loss" "$avg" "$best" "$worst" "$hops"
done < "$summary_file"
