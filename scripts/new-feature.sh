#!/usr/bin/env bash
set -e
[ -z "$1" ] && { echo "Usage: ./scripts/new-feature.sh <feature-name>"; exit 1; }
DEST="context/features/$1"
[ -d "$DEST" ] && { echo "$DEST already exists"; exit 1; }
cp -r context/features/_template "$DEST"
echo "Created $DEST. Add it to context/platform/relations.md."
