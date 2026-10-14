#!/usr/bin/env bash
# Account traffic for one server address, then show upload, download,
# byte totals, and client count grouped by ISP (origin ASN).
#
# Usage:
#   sudo bash lines_logger.sh SERVER_IP
#   sudo bash lines_logger.sh 103.161.34.31
#
# Upload is client to server. Download is server to client.
# Client count is distinct client addresses seen within 24 hours.

set -Eeuo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: sudo bash lines_logger.sh SERVER_IP"
    echo "Example: sudo bash lines_logger.sh 103.161.34.31"
    exit 1
fi

SERVER_IP="$1"

if [[ "$EUID" -ne 0 ]]; then
    echo "Run with: sudo bash lines_logger.sh ${SERVER_IP}"
    exit 1
fi

if [[ ! -r /etc/os-release ]]; then
    echo "Cannot identify operating system."
    exit 1
fi

. /etc/os-release
if [[ "${ID:-}" != "ubuntu" ]]; then
    echo "This script is intended for Ubuntu."
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

if is_ipv4 "$SERVER_IP"; then
    RULE_IN="ip daddr ${SERVER_IP} update @in4 { ip saddr timeout 24h counter }"
    RULE_OUT="ip saddr ${SERVER_IP} update @out4 { ip daddr timeout 24h counter }"
elif [[ "$SERVER_IP" =~ ^[0-9A-Fa-f:]+$ && "$SERVER_IP" == *:* ]]; then
    RULE_IN="ip6 daddr ${SERVER_IP} update @in6 { ip6 saddr timeout 24h counter }"
    RULE_OUT="ip6 saddr ${SERVER_IP} update @out6 { ip6 daddr timeout 24h counter }"
else
    echo "Server IP is not a valid address: ${SERVER_IP}"
    exit 1
fi

if ! hostname -I 2>/dev/null | tr ' ' '\n' | grep -qx "$SERVER_IP"; then
    echo "Warning: ${SERVER_IP} is not assigned to this host."
    echo "Counters stay empty unless this host owns that address."
fi

echo "[1/6] Installing dependencies..."
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y nftables golang-go

echo "[2/6] Creating directories..."
install -d -m 0755 /etc/ipmon /opt/ipmon /var/lib/ipmon

echo "[3/6] Creating nftables configuration..."
cat > /etc/ipmon/ipmon.nft <<NFT
table inet ipmon {
    set in4 {
        type ipv4_addr
        flags dynamic, timeout
        timeout 24h
        size 65535
    }
    set out4 {
        type ipv4_addr
        flags dynamic, timeout
        timeout 24h
        size 65535
    }
    set in6 {
        type ipv6_addr
        flags dynamic, timeout
        timeout 24h
        size 65535
    }
    set out6 {
        type ipv6_addr
        flags dynamic, timeout
        timeout 24h
        size 65535
    }

    chain account_input {
        type filter hook input priority filter
        policy accept
        iifname "lo" return
        ${RULE_IN}
    }

    chain account_output {
        type filter hook output priority filter
        policy accept
        oifname "lo" return
        ${RULE_OUT}
    }
}
NFT

echo "Validating nftables configuration..."
if ! nft -c -f /etc/ipmon/ipmon.nft; then
    echo "nftables rejected the rules for ${SERVER_IP}."
    exit 1
fi

echo "[4/6] Creating Go monitor..."
cat > /opt/ipmon/main.go <<'GO'
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"io/ioutil"
	"net"
	"net/http"
	"os"
	"os/exec"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

const (
	refreshInterval = 5 * time.Second
	cachePath       = "/var/lib/ipmon/asn-cache.json"
	cacheTTL        = 24 * time.Hour
	failTTL         = 10 * time.Minute
	maxISPRows      = 40
)

var counterRE = regexp.MustCompile(
	`counter\s+packets\s+([0-9]+)\s+bytes\s+([0-9]+)`,
)

type Counters struct {
	Received uint64
	Sent     uint64
}

type asnEntry struct {
	ASN  string `json:"asn"`
	Name string `json:"name"`
	At   int64  `json:"at"`
	Fail bool   `json:"fail"`
}

type ispRow struct {
	key              string
	label            string
	clients          int
	upload, download float64
	received, sent   uint64
}

type ripeOverview struct {
	Status string `json:"status"`
	Data   struct {
		ASNs []struct {
			ASN    int    `json:"asn"`
			Holder string `json:"holder"`
		} `json:"asns"`
	} `json:"data"`
}

var (
	serverIP     string
	mu           sync.Mutex
	cache        = map[string]asnEntry{}
	pending      = map[string]struct{}{}
	lookupStatus = "starting"
)

func canonical(host string) string {
	ip := net.ParseIP(strings.TrimSpace(host))
	if ip == nil {
		return ""
	}
	return ip.String()
}

func isPrivate(host string) bool {
	ip := net.ParseIP(host)
	if ip == nil {
		return false
	}
	if ip.IsUnspecified() || ip.IsLoopback() || ip.IsLinkLocalUnicast() {
		return true
	}
	if ip4 := ip.To4(); ip4 != nil {
		switch {
		case ip4[0] == 10:
			return true
		case ip4[0] == 172 && ip4[1] >= 16 && ip4[1] <= 31:
			return true
		case ip4[0] == 192 && ip4[1] == 168:
			return true
		case ip4[0] == 100 && ip4[1] >= 64 && ip4[1] <= 127:
			return true
		default:
			return false
		}
	}
	return len(ip) == net.IPv6len && ip[0]&0xfe == 0xfc
}

func fresh(entry asnEntry, now int64) bool {
	age := now - entry.At
	if entry.Fail {
		return age >= 0 && age < int64(failTTL/time.Second)
	}
	return age >= 0 && age < int64(cacheTTL/time.Second)
}

func consider(ip string) {
	if ip == "" || ip == serverIP || isPrivate(ip) {
		return
	}
	now := time.Now().Unix()
	mu.Lock()
	if entry, ok := cache[ip]; ok && fresh(entry, now) {
		mu.Unlock()
		return
	}
	pending[ip] = struct{}{}
	mu.Unlock()
}

func classify(ip string) (string, string) {
	if isPrivate(ip) {
		return "private", "Private network"
	}
	consider(ip)
	mu.Lock()
	entry, ok := cache[ip]
	mu.Unlock()
	if !ok {
		return "pending", "Resolving ASN"
	}
	switch entry.ASN {
	case "", "unknown":
		return "unknown", "Lookup failed"
	case "unannounced":
		return "unannounced", "Unannounced / not routed"
	}
	name := entry.Name
	if name == "" || name == "NA" {
		name = "AS" + entry.ASN
	}
	return "AS" + entry.ASN, "AS" + entry.ASN + "  " + name
}

func runNft(args ...string) (string, error) {
	ctx, cancel := context.WithTimeout(
		context.Background(), 5*time.Second,
	)
	defer cancel()

	cmd := exec.CommandContext(ctx, "nft", args...)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return "", fmt.Errorf(
			"nft %s: %w: %s",
			strings.Join(args, " "),
			err,
			strings.TrimSpace(string(out)),
		)
	}
	return string(out), nil
}

func readSet(name string) (map[string]uint64, error) {
	out, err := runNft(
		"-nn", "list", "set", "inet", "ipmon", name,
	)
	if err != nil {
		return nil, err
	}

	result := make(map[string]uint64)

	pos := strings.Index(out, "elements")
	if pos < 0 {
		return result, nil
	}

	openRel := strings.Index(out[pos:], "{")
	if openRel < 0 {
		return nil, fmt.Errorf("missing elements block in %s", name)
	}
	open := pos + openRel

	closeRel := strings.Index(out[open+1:], "}")
	if closeRel < 0 {
		return nil, fmt.Errorf("unterminated elements block in %s", name)
	}

	elements := out[open+1 : open+1+closeRel]
	for _, element := range strings.Split(elements, ",") {
		element = strings.TrimSpace(element)

		var ip string
		for _, field := range strings.Fields(element) {
			field = strings.Trim(field, "{};,")
			parsed := net.ParseIP(field)
			if parsed != nil {
				ip = parsed.String()
				break
			}
		}
		if ip == "" {
			continue
		}

		m := counterRE.FindStringSubmatch(element)
		if len(m) != 3 {
			return nil, fmt.Errorf(
				"no readable counter for %s in %s: %s",
				ip, name, element,
			)
		}

		n, err := strconv.ParseUint(m[2], 10, 64)
		if err != nil {
			return nil, err
		}
		result[ip] = n
	}

	return result, nil
}

func readCounters() (map[string]Counters, error) {
	names := []string{"in4", "out4", "in6", "out6"}
	sets := make([]map[string]uint64, len(names))

	for i, name := range names {
		var err error
		sets[i], err = readSet(name)
		if err != nil {
			return nil, err
		}
	}

	result := make(map[string]Counters)

	for ip, n := range sets[0] {
		c := result[ip]
		c.Received = n
		result[ip] = c
	}
	for ip, n := range sets[2] {
		c := result[ip]
		c.Received = n
		result[ip] = c
	}
	for ip, n := range sets[1] {
		c := result[ip]
		c.Sent = n
		result[ip] = c
	}
	for ip, n := range sets[3] {
		c := result[ip]
		c.Sent = n
		result[ip] = c
	}

	return result, nil
}

func delta(now, old uint64) uint64 {
	if now < old {
		return now
	}
	return now - old
}

func aggregate(
	current, previous map[string]Counters,
	elapsed time.Duration,
	first bool,
) []ispRow {
	groups := map[string]*ispRow{}
	seconds := elapsed.Seconds()

	for ip, now := range current {
		if ip == "" || ip == serverIP || net.ParseIP(ip).IsMulticast() {
			continue
		}
		key, label := classify(ip)
		row := groups[key]
		if row == nil {
			row = &ispRow{key: key, label: label}
			groups[key] = row
		}
		row.clients++
		if !first && seconds > 0 {
			old := previous[ip]
			row.upload += float64(delta(now.Received, old.Received)) * 8 / seconds / 1e6
			row.download += float64(delta(now.Sent, old.Sent)) * 8 / seconds / 1e6
		}
		row.received += now.Received
		row.sent += now.Sent
	}

	rows := make([]ispRow, 0, len(groups))
	for _, row := range groups {
		rows = append(rows, *row)
	}
	sort.Slice(rows, func(i, j int) bool {
		if rows[i].download != rows[j].download {
			return rows[i].download > rows[j].download
		}
		if rows[i].clients != rows[j].clients {
			return rows[i].clients > rows[j].clients
		}
		return rows[i].label < rows[j].label
	})
	return rows
}

func clip(s string, n int) string {
	if len(s) <= n {
		return s
	}
	if n < 4 {
		return s[:n]
	}
	return s[:n-3] + "..."
}

func display(
	current, previous map[string]Counters,
	elapsed time.Duration,
	first bool,
) {
	rows := aggregate(current, previous, elapsed, first)

	var total ispRow
	total.label = "TOTAL"
	ispCount := 0
	pendingClients := 0
	for _, row := range rows {
		total.clients += row.clients
		total.upload += row.upload
		total.download += row.download
		total.received += row.received
		total.sent += row.sent
		if strings.HasPrefix(row.key, "AS") {
			ispCount++
		}
		if row.key == "pending" {
			pendingClients = row.clients
		}
	}

	shown := rows
	hidden := 0
	if len(rows) > maxISPRows {
		hidden = len(rows) - maxISPRows
		shown = rows[:maxISPRows]
	}

	mu.Lock()
	status := lookupStatus
	mu.Unlock()

	fmt.Print("\033[H\033[2J")
	fmt.Printf(
		"ISP Monitor | server %s | %s | refresh %s\n",
		serverIP,
		time.Now().Format("2006-01-02 15:04:05"),
		refreshInterval,
	)
	fmt.Println("Upload: client to server. Download: server to client. Clients: addresses seen within 24h.")
	fmt.Println()
	fmt.Printf(
		"%-48s %8s %11s %11s %12s %12s\n",
		"ISP / ASN", "CLIENTS", "UP Mbps", "DOWN Mbps", "UP GB", "DOWN GB",
	)
	fmt.Println(strings.Repeat("-", 107))

	printRow := func(row ispRow) {
		fmt.Printf(
			"%-48s %8d %11.3f %11.3f %12.3f %12.3f\n",
			clip(row.label, 48),
			row.clients,
			row.upload,
			row.download,
			float64(row.received)/1e9,
			float64(row.sent)/1e9,
		)
	}
	for _, row := range shown {
		printRow(row)
	}
	fmt.Println(strings.Repeat("-", 107))
	printRow(total)

	fmt.Printf(
		"\n%d ISPs | %d clients",
		ispCount, total.clients,
	)
	if pendingClients > 0 {
		fmt.Printf(" | %d still resolving", pendingClients)
	}
	if hidden > 0 {
		fmt.Printf(" | %d more ISPs with lower download", hidden)
	}
	fmt.Printf(" | ASN: %s | Ctrl+C to exit\n", status)
}

func loadCache() {
	data, err := ioutil.ReadFile(cachePath)
	if err != nil {
		return
	}
	stored := map[string]asnEntry{}
	if json.Unmarshal(data, &stored) != nil {
		return
	}
	now := time.Now().Unix()
	mu.Lock()
	defer mu.Unlock()
	for ip, entry := range stored {
		if fresh(entry, now) {
			cache[ip] = entry
		}
	}
	if len(cache) > 0 {
		lookupStatus = "cached"
	}
}

func saveCache() {
	mu.Lock()
	snap := make(map[string]asnEntry, len(cache))
	for ip, entry := range cache {
		snap[ip] = entry
	}
	mu.Unlock()

	if os.MkdirAll("/var/lib/ipmon", 0755) != nil {
		return
	}
	blob, err := json.Marshal(snap)
	if err != nil {
		return
	}
	tmp := cachePath + ".tmp"
	if ioutil.WriteFile(tmp, blob, 0644) != nil {
		return
	}
	_ = os.Rename(tmp, cachePath)
}

func storeLookups(ips []string, found map[string]asnEntry, err error, source string) {
	now := time.Now().Unix()
	mu.Lock()
	defer mu.Unlock()
	if err != nil || found == nil {
		lookupStatus = "lookup failed"
		for _, ip := range ips {
			cache[ip] = asnEntry{
				ASN: "unknown", Name: "Lookup failed", At: now, Fail: true,
			}
		}
		return
	}
	lookupStatus = source
	for _, ip := range ips {
		entry, ok := found[ip]
		if !ok {
			cache[ip] = asnEntry{
				ASN: "unknown", Name: "Lookup failed", At: now, Fail: true,
			}
			continue
		}
		entry.At = now
		cache[ip] = entry
	}
}

func parseCymru(body string) map[string]asnEntry {
	found := map[string]asnEntry{}
	for _, line := range strings.Split(body, "\n") {
		if !strings.Contains(line, "|") {
			continue
		}
		parts := strings.Split(line, "|")
		if len(parts) < 7 {
			continue
		}
		asn := strings.TrimSpace(parts[0])
		asn = strings.TrimPrefix(asn, "AS")
		ip := canonical(parts[1])
		name := strings.TrimSpace(strings.Join(parts[6:], "|"))
		if ip == "" || asn == "" || asn == "AS" {
			continue
		}
		if asn == "NA" {
			found[ip] = asnEntry{
				ASN: "unannounced", Name: "Unannounced / not routed",
			}
			continue
		}
		if _, err := strconv.Atoi(asn); err != nil {
			continue
		}
		if name == "" || name == "NA" {
			name = "AS" + asn
		}
		found[ip] = asnEntry{ASN: asn, Name: name}
	}
	return found
}

func queryCymru(ips []string) (map[string]asnEntry, error) {
	dialer := net.Dialer{Timeout: 10 * time.Second}
	conn, err := dialer.Dial("tcp", "whois.cymru.com:43")
	if err != nil {
		return nil, err
	}
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(30 * time.Second))

	var body strings.Builder
	body.WriteString("begin\nverbose\n")
	for _, ip := range ips {
		body.WriteString(ip)
		body.WriteByte('\n')
	}
	body.WriteString("end\n")
	if _, err := io.WriteString(conn, body.String()); err != nil {
		return nil, err
	}

	out, err := ioutil.ReadAll(conn)
	if err != nil && len(out) == 0 {
		return nil, err
	}
	found := parseCymru(string(out))
	if len(found) == 0 {
		return nil, fmt.Errorf("whois.cymru.com returned no ASN records")
	}
	return found, nil
}

func ripeOne(client *http.Client, ip string) (asnEntry, error) {
	url := "https://stat.ripe.net/data/prefix-overview/data.json?resource=" + ip
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return asnEntry{}, err
	}
	req.Header.Set("User-Agent", "ipmon/1.0")
	resp, err := client.Do(req)
	if err != nil {
		return asnEntry{}, err
	}
	defer resp.Body.Close()
	payload, err := ioutil.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return asnEntry{}, err
	}
	if resp.StatusCode != http.StatusOK {
		return asnEntry{}, fmt.Errorf("ripe status %s", resp.Status)
	}
	var parsed ripeOverview
	if err := json.Unmarshal(payload, &parsed); err != nil {
		return asnEntry{}, err
	}
	if parsed.Status != "ok" || len(parsed.Data.ASNs) == 0 {
		return asnEntry{ASN: "unannounced", Name: "Unannounced / not routed"}, nil
	}
	first := parsed.Data.ASNs[0]
	name := strings.TrimSpace(first.Holder)
	asn := strconv.Itoa(first.ASN)
	if name == "" {
		name = "AS" + asn
	}
	return asnEntry{ASN: asn, Name: name}, nil
}

func queryRipe(ips []string) (map[string]asnEntry, error) {
	client := &http.Client{Timeout: 20 * time.Second}
	found := map[string]asnEntry{}
	var wg sync.WaitGroup
	var lock sync.Mutex
	sem := make(chan struct{}, 8)
	var firstErr error

	for _, ip := range ips {
		wg.Add(1)
		go func(ip string) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()
			entry, err := ripeOne(client, ip)
			lock.Lock()
			defer lock.Unlock()
			if err != nil {
				if firstErr == nil {
					firstErr = err
				}
				return
			}
			found[ip] = entry
		}(ip)
	}
	wg.Wait()
	if len(found) == 0 && firstErr != nil {
		return nil, firstErr
	}
	return found, nil
}

func resolveBatch(ips []string) {
	found, err := queryCymru(ips)
	source := "whois.cymru.com"
	if err != nil {
		var ripeErr error
		found, ripeErr = queryRipe(ips)
		source = "stat.ripe.net"
		if ripeErr != nil && len(found) == 0 {
			err = ripeErr
			found = nil
		} else {
			err = nil
		}
	}
	storeLookups(ips, found, err, source)
	saveCache()
}

func takePending(limit int) []string {
	mu.Lock()
	defer mu.Unlock()
	ips := make([]string, 0, limit)
	for ip := range pending {
		ips = append(ips, ip)
		delete(pending, ip)
		if len(ips) >= limit {
			break
		}
	}
	return ips
}

func resolverLoop() {
	for {
		ips := takePending(128)
		if len(ips) == 0 {
			time.Sleep(time.Second)
			continue
		}
		resolveBatch(ips)
	}
}

func main() {
	if len(os.Args) > 1 {
		serverIP = canonical(os.Args[1])
	}
	if serverIP == "" {
		serverIP = canonical(os.Getenv("IPMON_SERVER"))
	}
	if serverIP == "" {
		fmt.Fprintln(os.Stderr, "Usage: ipmon SERVER_IP")
		os.Exit(1)
	}

	loadCache()
	go resolverLoop()

	var previous map[string]Counters
	var last time.Time
	first := true

	for {
		current, err := readCounters()
		if err != nil {
			fmt.Fprintf(os.Stderr, "\nCannot read counters: %v\n", err)
			time.Sleep(refreshInterval)
			continue
		}

		now := time.Now()
		display(current, previous, now.Sub(last), first)

		previous = current
		last = now
		first = false
		time.Sleep(refreshInterval)
	}
}
GO

echo "[5/6] Building Go program..."
cd /opt/ipmon
gofmt -w main.go
go build -o /opt/ipmon/ipmon /opt/ipmon/main.go
chmod 0755 /opt/ipmon/ipmon

echo "[6/6] Loading monitoring rules for ${SERVER_IP}..."
echo "Reloading these rules resets the byte counters."
if nft list table inet ipmon >/dev/null 2>&1; then
    nft delete table inet ipmon
fi
nft -f /etc/ipmon/ipmon.nft

echo "Installation complete. Starting per-ISP dashboard..."
exec /opt/ipmon/ipmon "${SERVER_IP}"
