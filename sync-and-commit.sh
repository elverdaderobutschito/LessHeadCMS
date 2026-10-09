#!/usr/bin/env bash
set -euo pipefail

# ── Konfiguration ──────────────────────────────────────────────────────────
SOURCE_DIR="/home/butsch/LessHeadCMS-dev/deploy"
TARGET_DIR="$HOME/LessHeadCMS-public"
BRANCH="main"

# ── Eingabe prüfen ─────────────────────────────────────────────────────────
if [ $# -eq 0 ]; then
  echo "Verwendung: $0 \"Commit-Nachricht\""
  exit 1
fi
COMMIT_MSG="$1"

# ── Sync ───────────────────────────────────────────────────────────────────
echo "→ Synchronisiere deploy/ nach $TARGET_DIR ..."
rsync -av --delete \
  --exclude='config.php' \
  --exclude='.gitignore' \
  --exclude='.git' \
  --exclude='sync-and-commit.sh' \
  --exclude='composer.json' \
  "$SOURCE_DIR/" "$TARGET_DIR/"

cd "$TARGET_DIR"

# ── Sicherheitsprüfung 0: sind wir wirklich in einem Git-Repository? ───────
if [ ! -d .git ]; then
  echo "FEHLER: $TARGET_DIR ist kein Git-Repository (.git fehlt). Abbruch."
  echo "        Falls das unerwartet ist: neu klonen mit"
  echo "        git clone https://github.com/elverdaderobutschito/LessHeadCMS.git \"$TARGET_DIR\""
  exit 1
fi

# ── Sicherheitsprüfung 1: config.php darf nur den Platzhalter enthalten ────
if [ ! -f config.php ]; then
  echo "FEHLER: config.php fehlt im Zielordner. Abbruch."
  exit 1
fi
if ! grep -q "CHANGE_ME_TO_A_LONG_RANDOM_STRING" config.php; then
  echo "FEHLER: config.php enthält nicht den erwarteten Platzhalter – möglicherweise ein echter Token."
  echo "        Nichts wurde committet. Bitte config.php von Hand prüfen."
  exit 1
fi

# ── Staging ────────────────────────────────────────────────────────────────
git add -A

# ── Sicherheitsprüfung 2: keine unerwarteten Dateien in data/ oder media/ ──
UNEXPECTED=$(git diff --cached --name-only | grep -E '^(data|media)/' | grep -v -E '\.htaccess$' || true)
if [ -n "$UNEXPECTED" ]; then
  echo "FEHLER: Unerwartete Dateien in data/ oder media/ im Staging-Bereich gefunden:"
  echo "$UNEXPECTED"
  echo "Abbruch, nichts wurde committet. Bitte prüfen und ggf. 'git reset' ausführen."
  exit 1
fi

# ── Sicherheitsprüfung 3: config.php darf nie mit committet werden ─────────
if git diff --cached --name-only | grep -qx "config.php"; then
  echo "FEHLER: config.php steht im Staging-Bereich. Abbruch – das darf nicht committet werden."
  git reset config.php
  exit 1
fi

# ── Gibt es überhaupt etwas zu committen? ──────────────────────────────────
if git diff --cached --quiet; then
  echo "Keine Änderungen seit dem letzten Commit. Nichts zu tun."
  exit 0
fi

# ── Commit und Push ─────────────────────────────────────────────────────────
echo "→ Committe: $COMMIT_MSG"
git commit -m "$COMMIT_MSG"

echo "→ Push nach origin/$BRANCH ..."
git push origin "$BRANCH"

echo "✓ Fertig."
