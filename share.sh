#!/bin/sh
# Hardlink a donor Wings volume onto a new server volume. Same filesystem only.
set -e

donor_uuid="$1"
target_uuid="$2"

IFS=',;'
# shellcheck disable=SC2086
set -- ${PRM_VOLUMES:-} /data /var/lib/pterodactyl/volumes /peto-volumes /srv/daemon-data /srv/pterodactyl/volumes
unset IFS

SRC=""
DATA=""
for root in "$@"; do
  [ -n "$root" ] || continue
  [ -d "$root" ] || continue
  if [ -d "$root/$donor_uuid/game" ] || [ -f "$root/$donor_uuid/game/cs2.sh" ] || [ -d "$root/$donor_uuid/steamapps" ]; then
    SRC="$root/$donor_uuid"
    DATA="$root"
    break
  fi
done

if [ -z "$SRC" ]; then
  for root in "$@"; do
    [ -n "$root" ] || continue
    [ -d "$root/$donor_uuid" ] || continue
    SRC="$root/$donor_uuid"
    DATA="$root"
    break
  done
fi

if [ -z "$SRC" ] || [ ! -d "$SRC" ]; then
  echo "donor missing: $donor_uuid" >&2
  exit 1
fi

DST="$DATA/$target_uuid"

i=0
while [ ! -d "$DST" ] && [ "$i" -lt 40 ]; do
  sleep 0.25
  i=$((i + 1))
done
if [ ! -d "$DST" ]; then
  mkdir -p "$DST"
fi

for dir in game steamcmd steamapps Steam .steam; do
  if [ -d "$SRC/$dir" ]; then
    rm -rf "$DST/$dir"
    cp -al "$SRC/$dir" "$DST/$dir"
    echo "hardlinked $dir"
  fi
done

if [ -d "$SRC/egg" ]; then
  rm -rf "$DST/egg"
  cp -a "$SRC/egg" "$DST/egg"
else
  mkdir -p "$DST/egg"
fi

ts=$(date +%s)
printf 'state=done\nts=%s\n' "$ts" > "$DST/egg/.daemon-status"
touch "$DST/.daemon-managed"

for rel in game/csgo/cfg game/csgo/addons; do
  if [ -e "$SRC/$rel" ]; then
    rm -rf "$DST/$rel"
    mkdir -p "$(dirname "$DST/$rel")"
    cp -a "$SRC/$rel" "$DST/$rel"
    echo "unique $rel"
  fi
done

if [ -d "$DST/game/csgo/maps" ]; then
  mkdir -p "$DST/game/csgo/maps/_server"
  echo "unique maps/_server"
fi

chown -R 988:988 "$DST" 2>/dev/null || true
echo "share ok $donor_uuid -> $target_uuid ($DATA)"
