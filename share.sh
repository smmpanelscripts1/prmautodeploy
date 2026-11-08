#!/bin/sh
# Runs as root inside alpine with /data = PETO-FILES/volumes
set -e
SRC="/data/$1"
DST="/data/$2"

if [ ! -d "$SRC" ]; then
  echo "donor missing: $SRC" >&2
  exit 1
fi
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
echo "share ok $1 -> $2"
