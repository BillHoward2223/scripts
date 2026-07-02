<?php

$envFile = __DIR__ . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

function logMessage($message, $logFile) {
    // return true;
    file_put_contents($logFile, "$message\n", FILE_APPEND | LOCK_EX);
}

function sendTelegramMessage($message, $botToken, $chatId, $logFile) {
    if ($chatId === '' || $chatId === null) {
        logMessage("Telegram notification skipped: chat ID not configured", $logFile);
        return false;
    }

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $payload = http_build_query([
        'chat_id' => $chatId,
        'text' => $message,
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        logMessage("Telegram notification failed: request error", $logFile);
        return false;
    }

    $data = json_decode($response, true);
    if (empty($data['ok'])) {
        logMessage("Telegram notification failed: " . ($data['description'] ?? $response), $logFile);
        return false;
    }

    logMessage("Telegram notification sent", $logFile);
    return true;
}



// Wrap command to ensure proper shell exit
$command = 'bash -lc "cd /home/xui && git stash && git stash clear && git clean -fd && git fetch --all && git checkout lb_sk && git pull"';

$logFile = "/home/webhook/xui_repo_worker/".date('Y-m-d H-i-s').".log";

$telegramBotToken = getenv('TG_BOT_TOKEN');
$telegramChatId = getenv('TG_BOT_CHAT_ID');

sendTelegramMessage('Building started...', $telegramBotToken, $telegramChatId, $logFile);

logMessage("=== START EXECUTION ===", $logFile);

logMessage("=== RUNNING PRE-COMMAND ===", $logFile);

// Sync the three source checkouts. No more derived lb_sk/db_sk branches:
// xui_lb now tracks its own independent branch, and db.tar.gz is no longer built here.
$preCommand = "
cd /home/install_progress/xui && \
sudo git fetch --all && \
sudo git checkout sprite && \
sudo git pull && \
cd /home/install_progress/xui_lb && \
sudo git fetch --all && \
sudo git checkout new_lb && \
sudo git pull && \
cd /home/install_progress/xui_bin_git && \
sudo git fetch --all && \
sudo git checkout xui_bin && \
sudo git pull
";

$mainCommand = <<<'SH'
bash -lc 'set -euo pipefail

STAGING=/home/install_progress
IONCUBE=/home/ioncube/ioncube_encoder.sh
WORKDIR=$(mktemp -d)

cleanup() { rm -rf "$WORKDIR"; }
trap cleanup EXIT

# ---- MAIN (xui/, sprite branch) ----
tar -czf "$STAGING/main_dev.tar.gz" -C "$STAGING/xui" .

STAGE_ENCODE="$WORKDIR/stage_encode"
rsync -a "$STAGING/xui/" "$STAGE_ENCODE/" --exclude=".git"
rm -f "$STAGE_ENCODE/.gitignore"

ENCODED="$WORKDIR/encoded"
"$IONCUBE" -74 "$STAGE_ENCODE" -o "$ENCODED" \
  --copy "backups/" \
  --copy "content/" \
  --copy "config/" \
  --copy "tmp/" \
  --copy "includes/libs/" \
  --copy "includes/js/" \
  --copy "includes/styles/" \
  --copy "includes/langs/" \
  --copy "includes/python/" \
  --encode "status" \
  --encode "tools" \
  --ignore "*.sock"

rm -rf "$ENCODED/.git"
rm -f "$ENCODED/.gitignore"

tar -czf "$STAGING/main.tar.gz" -C "$ENCODED" .

# ---- LOADBALANCER (xui_lb/, new_lb branch) ----
tar -czf "$STAGING/loadbalancer.tar.gz" -C "$STAGING/xui_lb" --exclude=".git" --exclude=".gitignore" .
tar -czf "$STAGING/loadbalancer_dev.tar.gz" -C "$STAGING/xui_lb" .

# ---- BINARIES: binaries.tar.gz is the persistent base (real compiled binaries);
# only the "service" script gets refreshed from xui_bin_git on every build. ----
rm -rf "$STAGING/xui_bin"
mkdir -p "$STAGING/xui_bin"
tar -xzf "$STAGING/binaries.tar.gz" -C "$STAGING/xui_bin"
cp -a "$STAGING/xui_bin_git/service" "$STAGING/xui_bin/service"
chmod +x "$STAGING/xui_bin/service"
tar -czf "$STAGING/binaries.tar.gz" -C "$STAGING/xui_bin" .

# ---- FINAL BUNDLES: binaries.tar.gz, database.sql, install, xds*, loadbalancer.tar.gz + main ----
build_xui_install_bundle() {
  local bundle_out="$1" main_archive="$2" main_name="$3" lb_archive="$4"
  local tmp f base
  tmp=$(mktemp -d)
  for f in "$STAGING"/*; do
    [ -f "$f" ] || continue
    base=$(basename "$f")
    case "$base" in
      main.tar.gz|main_dev.tar.gz|xui_install.tar.gz|xui_install_dev.tar.gz|loadbalancer.tar.gz|loadbalancer_dev.tar.gz) continue ;;
    esac
    cp -a "$f" "$tmp/"
  done
  cp -a "$main_archive" "$tmp/$main_name"
  cp -a "$lb_archive" "$tmp/loadbalancer.tar.gz"
  tar -czf "$bundle_out" -C "$tmp" .
  rm -rf "$tmp"
}

build_xui_install_bundle "$WORKDIR/xui_install.tar.gz" "$STAGING/main.tar.gz" main.tar.gz "$STAGING/loadbalancer.tar.gz"
build_xui_install_bundle "$WORKDIR/xui_install_dev.tar.gz" "$STAGING/main_dev.tar.gz" main.tar.gz "$STAGING/loadbalancer_dev.tar.gz"

# ---- Publish the final bundles to /home ----
mv -f "$WORKDIR/xui_install.tar.gz" /home/xui_install.tar.gz
mv -f "$WORKDIR/xui_install_dev.tar.gz" /home/xui_install_dev.tar.gz
'
SH;


exec($preCommand . " 2>&1", $output, $returnCode);

foreach ($output as $line) {
    logMessage("[PRE] " . $line, $logFile);
}

if ($returnCode !== 0) {
    logMessage("Pre-command FAILED (code: $returnCode)", $logFile);
    sendTelegramMessage("Pre-command FAILED (code: $returnCode)", $telegramBotToken, $telegramChatId, $logFile);
    exit;
}

logMessage("=== PRE-COMMAND COMPLETED ===", $logFile);

logMessage("=== RUNNING ENCODING AND BUILDING COMMANDS ===", $logFile);

$output = [];
exec($mainCommand . " 2>&1", $output, $returnCode);

foreach ($output as $line) {
    logMessage("[MAIN] " . $line, $logFile);
}

if ($returnCode !== 0) {
    logMessage("Main command FAILED (code: $returnCode)", $logFile);
    sendTelegramMessage("Main command FAILED (code: $returnCode)", $telegramBotToken, $telegramChatId, $logFile);
    exit;
}

logMessage("=== MAIN COMMAND COMPLETED ===", $logFile);

logMessage("=== ALL BUILDING COMMANDS COMPLETED ===", $logFile);

sendTelegramMessage('Building completed successfully!', $telegramBotToken, $telegramChatId, $logFile);
return;

/**
 * ============================
 * STEP 1: SSH EXECUTION
 * ============================
 */
// $servers = [
//     // ["host" => "195.62.32.215", "user" => "root", "pass" => "wang"],
//     // ["host" => "192.142.30.130", "user" => "root", "pass" => "wang"],
//     // ["host" => "193.35.224.124", "user" => "root", "pass" => "xui"],
//     // ["host" => "162.247.154.146", "user" => "root", "pass" => "xui"],
//     // ["host" => "188.241.219.133", "user" => "root", "pass" => "4349H(A*Sbd893"],
// ];

// $streams = [];

// foreach ($servers as $i => $server) {

//     $conn = @ssh2_connect($server['host'], 22);
//     if (!$conn) {
//         logMessage("[{$server['host']}] Connection failed", $logFile);
//         continue;
//     }

//     if (!@ssh2_auth_password($conn, $server['user'], $server['pass'])) {
//         logMessage("[{$server['host']}] Auth failed", $logFile);
//         continue;
//     }

//     $stdout = @ssh2_exec($conn, $command);
//     if (!$stdout) {
//         logMessage("[{$server['host']}] Command failed", $logFile);
//         continue;
//     }

//     $stderr = ssh2_fetch_stream($stdout, SSH2_STREAM_STDERR);

//     stream_set_blocking($stdout, false);
//     stream_set_blocking($stderr, false);

//     $streams[$i] = [
//         "host"   => $server['host'],
//         "stdout" => $stdout,
//         "stderr" => $stderr,
//         "output" => "",
//     ];

//     logMessage("[{$server['host']}] Command started", $logFile);
// }

// /**
//  * ============================
//  * STEP 2: ASYNC LOOP
//  * ============================
//  */

// $startTime = time();
// $timeout   = 60; // seconds

// while (!empty($streams)) {

//     // Timeout protection
//     if (time() - $startTime > $timeout) {
//         logMessage("GLOBAL TIMEOUT REACHED", $logFile);
//         break;
//     }

//     $read = [];

//     foreach ($streams as $data) {
//         $read[] = $data['stdout'];
//         $read[] = $data['stderr'];
//     }

//     $write = null;
//     $except = null;

//     $numChanged = stream_select($read, $write, $except, 5);

//     if ($numChanged === false) {
//         logMessage("stream_select error", $logFile);
//         break;
//     }

//     if ($numChanged === 0) {
//         continue;
//     }

//     foreach ($read as $rstream) {
//         foreach ($streams as $i => $data) {

//             $type = null;

//             if ($rstream === $data['stdout']) {
//                 $type = 'STDOUT';
//             } elseif ($rstream === $data['stderr']) {
//                 $type = 'STDERR';
//             } else {
//                 continue;
//             }

//             $chunk = fread($rstream, 8192);

//             if ($chunk !== false && strlen($chunk) > 0) {
//                 $streams[$i]['output'] .= $chunk;
//                 logMessage("[{$data['host']}][$type] " . trim($chunk), $logFile);
//             }

//             // Exit condition: BOTH streams finished
//             if (feof($data['stdout']) && feof($data['stderr'])) {

//                 logMessage("==== {$data['host']} DONE ====", $logFile);
//                 logMessage($streams[$i]['output'], $logFile);

//                 fclose($data['stdout']);
//                 fclose($data['stderr']);

//                 unset($streams[$i]);
//             }
//         }
//     }

//     logMessage("Active streams: " . count($streams), $logFile);
// }

// logMessage("=== ALL SERVERS COMPLETED ===", $logFile);
