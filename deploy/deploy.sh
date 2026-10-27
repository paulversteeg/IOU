# Plesk Git post-deploy action. Runs from the repository folder.
#   Live:    sh deploy/deploy.sh                  -> ~/iou
#   Staging: sh deploy/deploy.sh ../iou-staging   -> ~/iou-staging
#
# Copies site/ into the web root. connection.php is not in the repository
# and is never touched on live. On staging, connection.php is built from the
# live one plus staging_db.php (server only), and staging_guard.php is added.
set -e
DEST="${1:-../iou}"
LIVE="../iou"

mkdir -p "$DEST"
tar -C site -cf - . | tar -C "$DEST" -xf -

if [ "$DEST" != "$LIVE" ]; then
    cp deploy/staging_guard.php "$DEST/staging_guard.php"
    if [ -f "$DEST/staging_db.php" ]; then
        sed -e '1a require __DIR__ . "/staging_guard.php";' \
            -e '/new PDO/i require __DIR__ . "/staging_db.php";' \
            "$LIVE/connection.php" > "$DEST/connection.php"
    else
        echo "staging_db.php is missing in $DEST: connection.php not written" >&2
    fi
fi

echo "IOU deployed to $DEST $(date)"
