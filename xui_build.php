#!/usr/bin/env php
<?php

/**
 * Simple XUI build: git pull sources, ionCube-encode prod trees, write *.tar.gz.
 *
 * Sources:  /home/install_build/{xui,xui_lb,xui_bin}
 * Packages: /home/install_build/packages/*.tar.gz
 * Bundles:  /home/install_build/xui_install.tar.gz
 *           /home/install_build/xui_install_dev.tar.gz
 * Publish:  copies main.tar.gz, loadbalancer.tar.gz, binaries.tar.gz
 *           into /home/install/packages/ (overwrite)
 */

declare(strict_types=1);

$staging  = '/home/install_build';
$packages = "$staging/packages";
$publish  = '/home/install/packages';
$ioncube  = '/home/ioncube/ioncube_encoder.sh';

$repos = [
	['dir' => "$staging/xui",     'branch' => 'sprite'],
	['dir' => "$staging/xui_lb",  'branch' => 'new_lb'],
	['dir' => "$staging/xui_bin", 'branch' => 'xui_bin'],
];

function out(string $message): void
{
	echo '[' . date('H:i:s') . "] $message\n";
}

function run(string $command): void
{
	out('+ ' . $command);
	passthru($command, $code);
	if ($code !== 0) {
		fwrite(STDERR, "Command failed (exit $code): $command\n");
		exit($code);
	}
}

out('=== XUI build start ===');

if (!is_dir($packages) && !mkdir($packages, 0777, true) && !is_dir($packages)) {
	fwrite(STDERR, "Unable to create packages directory: $packages\n");
	exit(1);
}

foreach ($repos as $repo) {
	$dir    = $repo['dir'];
	$branch = $repo['branch'];
	if (!is_dir($dir)) {
		fwrite(STDERR, "Missing source directory: $dir\n");
		exit(1);
	}
	out("Pulling $dir ($branch)...");
	run('bash -lc ' . escapeshellarg(
		'cd ' . escapeshellarg($dir) . ' && '
		. 'git stash || true && '
		. 'git fetch --all && '
		. 'git checkout ' . escapeshellarg($branch) . ' && '
		. 'git pull'
	));
}

if (!is_executable($ioncube) && !is_file($ioncube)) {
	fwrite(STDERR, "ionCube encoder not found: $ioncube\n");
	exit(1);
}

$build = <<<'SH'
set -euo pipefail

STAGING=__STAGING__
PACKAGES=__PACKAGES__
IONCUBE=__IONCUBE__
WORKDIR=$(mktemp -d)
cleanup() { rm -rf "$WORKDIR"; }
trap cleanup EXIT

mkdir -p "$PACKAGES"

echo "[+] Building main_dev.tar.gz..."
tar -czf "$PACKAGES/main_dev.tar.gz" -C "$STAGING/xui" .

echo "[+] ionCube encoding main -> main.tar.gz..."
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
tar -czf "$PACKAGES/main.tar.gz" -C "$ENCODED" .

echo "[+] Building loadbalancer_dev.tar.gz..."
tar -czf "$PACKAGES/loadbalancer_dev.tar.gz" -C "$STAGING/xui_lb" .

echo "[+] ionCube encoding loadbalancer -> loadbalancer.tar.gz..."
LB_STAGE_ENCODE="$WORKDIR/lb_stage_encode"
rsync -a "$STAGING/xui_lb/" "$LB_STAGE_ENCODE/" --exclude=".git"
rm -f "$LB_STAGE_ENCODE/.gitignore"

LB_ENCODED="$WORKDIR/lb_encoded"
"$IONCUBE" -74 "$LB_STAGE_ENCODE" -o "$LB_ENCODED" \
  --copy "config/" \
  --copy "content/" \
  --copy "tmp/" \
  --copy "includes/libs/" \
  --copy "includes/python/" \
  --encode "status" \
  --encode "tools" \
  --ignore "*.sock"

rm -rf "$LB_ENCODED/.git"
rm -f "$LB_ENCODED/.gitignore"
tar -czf "$PACKAGES/loadbalancer.tar.gz" -C "$LB_ENCODED" .

echo "[+] Building binaries.tar.gz..."
chmod +x "$STAGING/xui_bin/service" 2>/dev/null || true
tar -czf "$PACKAGES/binaries.tar.gz" \
  --exclude='.git' \
  --exclude='.gitignore' \
  -C "$STAGING/xui_bin" .

echo "[+] Building xui_install.tar.gz (prod)..."
BUNDLE="$WORKDIR/xui_install"
mkdir -p "$BUNDLE"
cp -a "$PACKAGES/binaries.tar.gz" "$BUNDLE/"
cp -a "$PACKAGES/loadbalancer.tar.gz" "$BUNDLE/"
cp -a "$PACKAGES/main.tar.gz" "$BUNDLE/"
cp -a "$STAGING/database.sql" "$BUNDLE/"
cp -a "$STAGING/install" "$BUNDLE/"
tar -czf "$STAGING/xui_install.tar.gz" -C "$BUNDLE" .

echo "[+] Building xui_install_dev.tar.gz..."
BUNDLE_DEV="$WORKDIR/xui_install_dev"
mkdir -p "$BUNDLE_DEV"
cp -a "$PACKAGES/binaries.tar.gz" "$BUNDLE_DEV/"
cp -a "$PACKAGES/loadbalancer_dev.tar.gz" "$BUNDLE_DEV/loadbalancer.tar.gz"
cp -a "$PACKAGES/main_dev.tar.gz" "$BUNDLE_DEV/main.tar.gz"
cp -a "$STAGING/database.sql" "$BUNDLE_DEV/"
cp -a "$STAGING/install" "$BUNDLE_DEV/"
tar -czf "$STAGING/xui_install_dev.tar.gz" -C "$BUNDLE_DEV" .

echo "[+] Built archives:"
ls -lh \
  "$PACKAGES/main.tar.gz" \
  "$PACKAGES/main_dev.tar.gz" \
  "$PACKAGES/loadbalancer.tar.gz" \
  "$PACKAGES/loadbalancer_dev.tar.gz" \
  "$PACKAGES/binaries.tar.gz" \
  "$STAGING/xui_install.tar.gz" \
  "$STAGING/xui_install_dev.tar.gz"
SH;

$build = str_replace(
	['__STAGING__', '__PACKAGES__', '__IONCUBE__'],
	[$staging, $packages, $ioncube],
	$build
);

out('Encoding and packing archives...');
run('bash -lc ' . escapeshellarg($build));

if (!is_dir($publish) && !mkdir($publish, 0777, true) && !is_dir($publish)) {
	fwrite(STDERR, "Unable to create publish directory: $publish\n");
	exit(1);
}

out("Publishing prod archives to $publish ...");
foreach (['main.tar.gz', 'loadbalancer.tar.gz', 'binaries.tar.gz'] as $archive) {
	$src = "$packages/$archive";
	$dst = "$publish/$archive";
	if (!is_file($src)) {
		fwrite(STDERR, "Missing built archive: $src\n");
		exit(1);
	}
	run('cp -f ' . escapeshellarg($src) . ' ' . escapeshellarg($dst));
}

out('=== XUI build completed ===');
