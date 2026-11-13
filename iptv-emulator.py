#!/usr/bin/env python3
"""
IPTV panel stream tester (XUI.one / Xtream Codes API)

- Loads the channel list from a panel with url + line username/password
- Click a channel -> measures the stream (no playback): connect time, TTFB,
  download speed, bitrate, stalls, errors
- Optional proxies (one per viewer, round-robin) to simulate several viewers
- Optional extra lines (user:pass) so viewers don't all share one line

Install:  pip install flask requests "requests[socks]"
Run:      python iptv_tester.py     ->  open http://127.0.0.1:8080

Only use it against panels you own or are authorized to test.
"""
import re
import threading
import time
import uuid
from urllib.parse import urljoin, urlparse

import requests
from flask import Flask, jsonify, request

app = Flask(__name__)
UA = "VLC/3.0.20 LibVLC/3.0.20"
JOBS = {}


# ----------------------------------------------------------------- panel API
def norm_panel(p):
    p = p.strip().rstrip("/")
    if not re.match(r"^https?://", p):
        p = "http://" + p
    return p


def api(panel, user, pw, action=None, proxy=None, **extra):
    params = {"username": user, "password": pw}
    if action:
        params["action"] = action
    params.update(extra)
    proxies = {"http": proxy, "https": proxy} if proxy else None
    r = requests.get(panel + "/player_api.php", params=params, timeout=30,
                     headers={"User-Agent": UA}, proxies=proxies)
    r.raise_for_status()
    return r.json()


@app.post("/api/channels")
def channels():
    d = request.json
    panel, user, pw, proxy = norm_panel(d["panel"]), d["user"], d["pass"], d.get("proxy") or None
    try:
        info = api(panel, user, pw, proxy=proxy)
        ui = info.get("user_info", {})
        if str(ui.get("auth", 1)) == "0":
            return jsonify(error="Authentication failed (wrong username/password)"), 401
        cats = api(panel, user, pw, "get_live_categories", proxy)
        streams = api(panel, user, pw, "get_live_streams", proxy)
        if not isinstance(cats, list):
            cats = []
        if not isinstance(streams, list):
            return jsonify(error="Panel returned no channel list (line expired/banned or API disabled?)"), 502
    except Exception as e:
        return jsonify(error=f"{type(e).__name__}: {e}"), 502
    return jsonify(
        user_info={k: ui.get(k) for k in ("status", "exp_date", "max_connections", "active_cons")},
        categories={str(c["category_id"]): c["category_name"] for c in cats},
        channels=[{"id": s["stream_id"], "name": s["name"], "cat": str(s.get("category_id")),
                   "num": s.get("num")} for s in streams],
    )


# ------------------------------------------------------------------ measuring
def new_stats():
    return dict(status="starting", error="", http=None, host="", connect_ms=None, ttfb_ms=None,
                bytes=0, elapsed=0.0, speed_kbps=0.0, peak_kbps=0.0, current_kbps=0.0,
                bitrate_kbps=0.0, stalls=0, proxy="", line="")


def measure_ts(sess, url, s, duration, stop):
    t0 = time.time()
    r = sess.get(url, stream=True, timeout=(10, 15), allow_redirects=True)
    s["http"] = r.status_code
    s["host"] = urlparse(r.url).netloc
    s["connect_ms"] = r.elapsed.total_seconds() * 1000
    if r.status_code != 200:
        raise RuntimeError(f"HTTP {r.status_code}")
    s["status"] = "running"
    first = None
    last_t = last_b = 0
    base = None
    prev = None
    for chunk in r.iter_content(65536):
        now = time.time()
        if first is None:
            first = last_t = now
            s["ttfb_ms"] = (now - t0) * 1000
        if prev and now - prev > 2:
            s["stalls"] += 1
        prev = now
        s["bytes"] += len(chunk)
        el = now - first
        s["elapsed"] = el
        if el > 0:
            s["speed_kbps"] = s["bytes"] * 8 / el / 1000
        if now - last_t >= 1:
            cur = (s["bytes"] - last_b) * 8 / (now - last_t) / 1000
            s["current_kbps"] = cur
            s["peak_kbps"] = max(s["peak_kbps"], cur)
            last_t, last_b = now, s["bytes"]
        # steady bitrate: ignore the initial burst (first 3 s)
        if el >= 3 and base is None:
            base = (el, s["bytes"])
        if base and el > base[0]:
            s["bitrate_kbps"] = (s["bytes"] - base[1]) * 8 / (el - base[0]) / 1000
        elif not base:
            s["bitrate_kbps"] = s["speed_kbps"]
        if el >= duration or stop.is_set():
            break
    r.close()
    if first is None:
        raise RuntimeError("No data received")


def measure_hls(sess, url, s, duration, stop):
    t0 = time.time()

    def get(u):
        r = sess.get(u, timeout=(10, 15))
        r.raise_for_status()
        return r

    r = get(url)
    s["http"] = r.status_code
    s["host"] = urlparse(r.url).netloc
    s["connect_ms"] = r.elapsed.total_seconds() * 1000
    s["ttfb_ms"] = (time.time() - t0) * 1000
    pl_url, txt = r.url, r.text
    if "#EXT-X-STREAM-INF" in txt:  # master playlist -> pick highest BANDWIDTH
        best, best_bw, lines = None, -1, txt.splitlines()
        for i, ln in enumerate(lines):
            m = re.search(r"BANDWIDTH=(\d+)", ln)
            if ln.startswith("#EXT-X-STREAM-INF") and m and i + 1 < len(lines):
                if int(m.group(1)) > best_bw:
                    best_bw, best = int(m.group(1)), lines[i + 1].strip()
        if not best:
            raise RuntimeError("Empty master playlist")
        pl_url = urljoin(pl_url, best)
        txt = None
    s["status"] = "running"
    seen, media_dur, dl_time, start = set(), 0.0, 0.0, time.time()
    while time.time() - start < duration and not stop.is_set():
        if txt is None:
            txt = get(pl_url).text
        lines = txt.splitlines()
        txt = None
        target = 2.0
        m = re.search(r"#EXT-X-TARGETDURATION:(\d+)", "\n".join(lines))
        if m:
            target = float(m.group(1))
        segs, dur = [], None
        for ln in lines:
            ln = ln.strip()
            if ln.startswith("#EXTINF:"):
                dur = float(ln[8:].split(",")[0])
            elif ln and not ln.startswith("#") and dur is not None:
                segs.append((urljoin(pl_url, ln), dur))
                dur = None
        new = [x for x in segs if x[0] not in seen]
        for seg_url, seg_dur in new:
            if time.time() - start >= duration or stop.is_set():
                break
            seen.add(seg_url)
            ts = time.time()
            sr = sess.get(seg_url, stream=True, timeout=(10, 15))
            sr.raise_for_status()
            n = 0
            for chunk in sr.iter_content(65536):
                n += len(chunk)
            took = max(time.time() - ts, 1e-6)
            dl_time += took
            media_dur += seg_dur
            s["bytes"] += n
            s["current_kbps"] = n * 8 / took / 1000
            s["peak_kbps"] = max(s["peak_kbps"], s["current_kbps"])
            if took > seg_dur:
                s["stalls"] += 1  # segment slower than real time -> buffer underrun
            s["speed_kbps"] = s["bytes"] * 8 / dl_time / 1000
            s["bitrate_kbps"] = s["bytes"] * 8 / media_dur / 1000
            s["elapsed"] = time.time() - start
        if not new:
            time.sleep(min(target / 2, 1.0))
        s["elapsed"] = time.time() - start


def run_viewer(job, s, url, proxy, duration, fmt):
    sess = requests.Session()
    sess.headers["User-Agent"] = UA
    if proxy:
        sess.proxies = {"http": proxy, "https": proxy}
    try:
        (measure_hls if fmt == "m3u8" else measure_ts)(sess, url, s, duration, job["stop"])
        s["status"] = "stopped" if job["stop"].is_set() else "done"
    except Exception as e:
        s["status"] = "error"
        s["error"] = f"{type(e).__name__}: {e}"[:200]
    finally:
        sess.close()


@app.post("/api/test/start")
def start():
    d = request.json
    panel = norm_panel(d["panel"])
    lines = [(d["user"], d["pass"])]
    for ln in (d.get("extra_lines") or "").splitlines():
        if ":" in ln.strip():
            u, p = ln.strip().split(":", 1)
            lines.append((u, p))
    proxies = [p.strip() for p in (d.get("proxies") or "").splitlines() if p.strip()]
    n = max(1, min(int(d.get("viewers", 1)), 500))
    duration = max(3, int(d.get("duration", 15)))
    stagger = max(0, int(d.get("stagger_ms", 0))) / 1000
    fmt = d.get("fmt", "ts")
    sid = d["stream_id"]
    job = {"stop": threading.Event(), "viewers": []}
    jid = uuid.uuid4().hex[:8]
    JOBS[jid] = job

    def launcher():
        threads = []
        for i in range(n):
            if job["stop"].is_set():
                break
            u, p = lines[i % len(lines)]
            proxy = proxies[i % len(proxies)] if proxies else None
            s = new_stats()
            s["proxy"] = re.sub(r"//[^@/]*@", "//***@", proxy) if proxy else "direct"
            s["line"] = u
            job["viewers"].append(s)
            url = f"{panel}/live/{u}/{p}/{sid}.{fmt}"
            t = threading.Thread(target=run_viewer, args=(job, s, url, proxy, duration, fmt), daemon=True)
            t.start()
            threads.append(t)
            if stagger:
                time.sleep(stagger)
        for t in threads:
            t.join()
        job["done"] = True

    threading.Thread(target=launcher, daemon=True).start()
    return jsonify(id=jid)


@app.get("/api/test/<jid>")
def status(jid):
    job = JOBS.get(jid)
    if not job:
        return jsonify(error="unknown job"), 404
    return jsonify(viewers=job["viewers"], done=job.get("done", False))


@app.post("/api/test/<jid>/stop")
def stop(jid):
    if jid in JOBS:
        JOBS[jid]["stop"].set()
    return jsonify(ok=True)


# ------------------------------------------------------------------------ UI
PAGE = r"""<!doctype html><html><head><meta charset="utf-8"><title>IPTV Panel Tester</title>
<style>
*{box-sizing:border-box}body{margin:0;font:14px system-ui,sans-serif;background:#12151a;color:#dfe3ea;display:flex;height:100vh}
aside{width:380px;border-right:1px solid #262c36;display:flex;flex-direction:column;padding:12px;gap:8px}
main{flex:1;padding:12px;overflow:auto}
input,textarea,select,button{background:#1c212a;color:#dfe3ea;border:1px solid #2f3744;border-radius:6px;padding:7px;font:inherit;width:100%}
button{cursor:pointer;background:#2b6cf0;border:0;font-weight:600}button.sec{background:#2a313d}button.red{background:#c43b3b}
textarea{resize:vertical;font:12px monospace}label{font-size:12px;color:#8b95a5}.row{display:flex;gap:8px}.row>*{flex:1}
#list{flex:1;overflow:auto;border:1px solid #262c36;border-radius:6px}
.ch{padding:6px 8px;cursor:pointer;border-bottom:1px solid #1d222b}.ch:hover{background:#1c2430}.ch.sel{background:#243653}
.ch small{color:#8b95a5}table{border-collapse:collapse;width:100%;font-size:12.5px}
th,td{padding:5px 8px;border-bottom:1px solid #262c36;text-align:right;white-space:nowrap}th:first-child,td:first-child,td.l,th.l{text-align:left}
th{color:#8b95a5;font-weight:500;position:sticky;top:0;background:#12151a}
.cards{display:flex;gap:10px;margin:10px 0;flex-wrap:wrap}.card{background:#1c212a;border-radius:8px;padding:10px 14px;min-width:140px}
.card b{display:block;font-size:20px}.card span{font-size:11px;color:#8b95a5}.err{color:#ff7b7b}.ok{color:#5fd38d}#msg{color:#ff7b7b;font-size:12px}
</style></head><body>
<aside>
 <label>Panel URL (with port)</label><input id=panel placeholder="http://panel.example.com:8080">
 <div class=row><div><label>Username</label><input id=user></div><div><label>Password</label><input id=pass></div></div>
 <label>Proxy for loading the list (optional)</label><input id=lproxy placeholder="http://user:pass@host:port or socks5://host:port">
 <button onclick="load()">Load channel list</button><div id=msg></div>
 <div class=row><input id=q placeholder="Search..." oninput=render()><select id=cat onchange=render()></select></div>
 <div id=list></div>
</aside>
<main>
 <h3 id=title style="margin:0 0 8px">Select a channel</h3>
 <div class=row>
  <div><label>Viewers</label><input id=viewers type=number value=1 min=1></div>
  <div><label>Duration (s)</label><input id=dur type=number value=15 min=3></div>
  <div><label>Stagger (ms)</label><input id=stag type=number value=200></div>
  <div><label>Format</label><select id=fmt><option value=ts>MPEG-TS (.ts)</option><option value=m3u8>HLS (.m3u8)</option></select></div>
 </div>
 <div class=row style="margin-top:8px">
  <div><label>Proxies / VPS, one per line (viewers use them round-robin; empty = direct)</label><textarea id=proxies rows=4 placeholder="http://user:pass@1.2.3.4:3128&#10;socks5://user:pass@5.6.7.8:1080"></textarea></div>
  <div><label>Extra lines user:pass (optional, round-robin; avoids max-connections limit)</label><textarea id=extra rows=4></textarea></div>
 </div>
 <div class=row style="margin-top:8px"><button id=go onclick=start() disabled>Start test</button><button class=red onclick=stopJob()>Stop</button></div>
 <div class=cards id=cards></div>
 <table><thead><tr><th>#</th><th class=l>Line</th><th class=l>Proxy</th><th class=l>Status</th><th>HTTP</th><th>Connect ms</th><th>TTFB ms</th><th>MB</th>
 <th>Avg speed kbps</th><th>Peak kbps</th><th>Bitrate kbps</th><th>Stalls</th><th class=l>Edge host</th></tr></thead><tbody id=rows></tbody></table>
</main>
<script>
let D=null,sel=null,job=null,timer=null;const $=id=>document.getElementById(id);
const esc=s=>String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const post=(u,b)=>fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)}).then(r=>r.json());
const creds=()=>({panel:$('panel').value,user:$('user').value,pass:$('pass').value});
async function load(){$('msg').textContent='Loading...';
 const r=await post('/api/channels',{...creds(),proxy:$('lproxy').value});
 if(r.error){$('msg').textContent=r.error;return}
 D=r;const ui=r.user_info;$('msg').style.color='#5fd38d';
 $('msg').textContent=`${r.channels.length} channels | status ${ui.status} | connections ${ui.active_cons}/${ui.max_connections}`;
 $('cat').innerHTML='<option value="">All categories</option>'+Object.entries(r.categories).map(([k,v])=>`<option value="${esc(k)}">${esc(v)}</option>`).join('');render()}
function render(){if(!D)return;const q=$('q').value.toLowerCase(),c=$('cat').value;
 $('list').innerHTML=D.channels.filter(x=>(!c||x.cat===c)&&x.name.toLowerCase().includes(q)).slice(0,500)
 .map(x=>`<div class="ch ${sel&&sel.id==x.id?'sel':''}" onclick="pick(${x.id})">${esc(x.name)}<br><small>${esc(D.categories[x.cat]||'')} · id ${x.id}</small></div>`).join('')}
function pick(id){sel=D.channels.find(x=>x.id==id);$('title').textContent=sel.name+' (id '+sel.id+')';$('go').disabled=false;render()}
async function start(){if(!sel)return;stopJob();$('rows').innerHTML='';
 const r=await post('/api/test/start',{...creds(),stream_id:sel.id,viewers:$('viewers').value,duration:$('dur').value,
  stagger_ms:$('stag').value,fmt:$('fmt').value,proxies:$('proxies').value,extra_lines:$('extra').value});
 job=r.id;clearInterval(timer);timer=setInterval(poll,1000);poll()}
function stopJob(){if(job)post('/api/test/'+job+'/stop',{})}
const f=(v,d=0)=>v==null?'-':Number(v).toFixed(d);
async function poll(){const r=await fetch('/api/test/'+job).then(r=>r.json());if(!r.viewers)return;const V=r.viewers;
 $('rows').innerHTML=V.map((v,i)=>`<tr><td>${i+1}</td><td class=l>${esc(v.line)}</td><td class=l>${esc(v.proxy)}</td>
 <td class="l ${v.status=='error'?'err':v.status=='done'?'ok':''}" title="${esc(v.error)}">${v.status}${v.error?': '+esc(v.error.slice(0,40)):''}</td>
 <td>${v.http??'-'}</td><td>${f(v.connect_ms)}</td><td>${f(v.ttfb_ms)}</td><td>${f(v.bytes/1e6,2)}</td><td>${f(v.speed_kbps)}</td>
 <td>${f(v.peak_kbps)}</td><td>${f(v.bitrate_kbps)}</td><td>${v.stalls}</td><td class=l>${esc(v.host)}</td></tr>`).join('');
 const act=V.filter(v=>v.status=='running'),okv=V.filter(v=>v.bytes>0),avg=k=>okv.length?okv.reduce((a,v)=>a+v[k],0)/okv.length:0;
 const card=(a,b)=>`<div class=card><b>${a}</b><span>${b}</span></div>`;
 $('cards').innerHTML=card(act.length+'/'+V.length,'running / total')+card(V.filter(v=>v.status=='error').length,'errors')
  +card(f(V.reduce((a,v)=>a+(v.status=='running'?v.current_kbps:0),0)/1000,2)+' Mbps','total live throughput')
  +card(f(avg('bitrate_kbps')),'avg bitrate kbps')+card(f(avg('speed_kbps')),'avg speed kbps')+card(V.reduce((a,v)=>a+v.stalls,0),'stalls');
 if(r.done)clearInterval(timer)}
</script></body></html>"""


@app.get("/")
def index():
    return PAGE


if __name__ == "__main__":
    print("Open http://103.161.34.31:8080")
    app.run(host="0.0.0.0", port=8080, threaded=True)
