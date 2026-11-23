#!/usr/bin/env bash
# Lädt die Website per SFTP auf den Strato-Webspace oder schaltet auf eine gesicherte
# Version zurück. Wird von .github/workflows/deploy-ftp.yml aufgerufen.
#
# Vor jedem Schreiben werden die Dateien, die gleich überschrieben werden, vom Server
# nach _versionen/<Datum_Uhrzeit>/ gesichert. Es wird nie etwas auf dem Server gelöscht;
# Dateien, die nur dort liegen (Geschenkseite, secrets/*.json, cache/*.json), bleiben
# also unberührt.
#
# Umgebung: AKTION (hochladen|zurückschalten), STARTSEITE (alt…|neu…), VERSION (optional),
# TESTLAUF (true|false), SFTP_HOST, SFTP_PORT (optional), SFTP_USER, LFTP_PASSWORD,
# SERVER_DIR (Ordner der Website auf dem Webspace).
set -euo pipefail
export LC_ALL=C

BACKUP_DIR="_versionen"
RESTORE_SUFFIX="_vor-zurueckschalten"

host="${SFTP_HOST#*://}"; host="${host%/}"
url="sftp://$host${SFTP_PORT:+:$SFTP_PORT}"
dir="${SERVER_DIR:-.}"
work="$(pwd)/_work"
mkdir -p "$work"
src="$work/neu"
alt="$work/alt"
mkdir -p "$alt"

# Führt lftp-Befehle von stdin auf dem Server aus, im Ordner der Website.
lf() {
  { echo "set sftp:auto-confirm yes"
    echo "set net:timeout 30"
    echo "set net:max-retries 2"
    echo "set cmd:fail-exit yes"
    echo "cd \"$dir\""
    cat
  } | lftp --env-password -u "$SFTP_USER" "$url"
}

# Gesicherte Versionen, älteste zuerst.
versions() {
  lf <<< "cls -1 \"$BACKUP_DIR/\"" 2>/dev/null | sed 's|/$||; s|.*/||' | grep -E '^[0-9]{4}-' | sort || true
}

echo "Verbindung prüfen …"
lf <<< "cls -1 . >/dev/null"
if [ "$TESTLAUF" = "true" ]; then
  echo "Inhalt des Zielordners auf dem Server:"
  lf <<< "cls -1 -F ."
fi

stamp="$(TZ=Europe/Berlin date +%Y-%m-%d_%H-%M-%S)"

if [[ "$AKTION" == zur* ]]; then
  version="${VERSION:-}"
  if [ -z "$version" ]; then
    version="$(versions | grep -v -- "$RESTORE_SUFFIX" | tail -n 1 || true)"
  fi
  if [ -z "$version" ]; then
    echo "::error::Keine gesicherte Version gefunden."
    exit 1
  fi
  echo "Zurückschalten auf Version $version"
  lf <<< "mirror \"$BACKUP_DIR/$version\" \"$src\""
  stamp="$stamp$RESTORE_SUFFIX"
else
  mkdir -p "$src"
  git archive HEAD | tar -x -C "$src"
  rm -rf "$src/.github" "$src/.gitignore"
  if [[ "$STARTSEITE" == alt* ]]; then
    mv "$src/index_old.html" "$src/index.html"
    rm -f "$src/trauerreden.html" "$src/kontakt.html"
  else
    rm -f "$src/index_old.html"
  fi
fi

echo "Wird hochgeladen:"
(cd "$src" && find . -type f | sed 's|^\./||' | sort) | tee "$work/neu.txt"

# Welche dieser Dateien liegen schon auf dem Server? Nur die werden gesichert.
{
  echo "set cmd:fail-exit no"
  for entry in "$src"/* "$src"/.[!.]*; do
    [ -e "$entry" ] || continue
    name="$(basename "$entry")"
    if [ -d "$entry" ]; then echo "find \"$name\""; else echo "cls -1 \"$name\""; fi
  done
} | lf 2>/dev/null | grep -v '/$' | sed 's|^\./||' | sort -u > "$work/server.txt" || true
comm -12 "$work/neu.txt" "$work/server.txt" > "$work/sichern.txt"

echo "Wird vorher nach $BACKUP_DIR/$stamp gesichert:"
cat "$work/sichern.txt"

# Schutz vor einem falschen Zielordner: Dort muss schon eine Website liegen.
if ! grep -qx 'index.html' "$work/server.txt"; then
  echo "::error::Im Zielordner \"$dir\" liegt keine index.html. Falscher Ordner? Abbruch, nichts wurde geändert."
  exit 1
fi

if [ -s "$work/sichern.txt" ]; then
  while IFS= read -r f; do
    mkdir -p "$alt/$(dirname "$f")"
    echo "get \"$f\" -o \"$alt/$f\""
  done < "$work/sichern.txt" | lf
fi

if [ "$TESTLAUF" = "true" ]; then
  echo "Testlauf: Sicherung und Upload werden nicht ausgeführt."
  lf <<< "mirror -R --dry-run --verbose \"$src\" ."
else
  if [ -s "$work/sichern.txt" ]; then
    printf 'Require all denied\n' > "$work/htaccess"
    lf <<EOF
mkdir -p -f "$BACKUP_DIR"
put "$work/htaccess" -o "$BACKUP_DIR/.htaccess"
mirror -R "$alt" "$BACKUP_DIR/$stamp"
EOF
    echo "Gesichert als $stamp"
  fi
  lf <<< "mirror -R --verbose --parallel=4 \"$src\" ."
fi

echo "Gesicherte Versionen auf dem Server:"
versions
