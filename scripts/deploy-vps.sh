#!/bin/bash
# Deploy script para VPS bare-metal (sin Docker)
# Uso: bash /root/areteia/scripts/deploy-vps.sh [branch]
# Ejemplo: bash deploy-vps.sh hotfix/fix-algo
set -e

REPO_DIR="/root/areteia"
BRANCH="${1:-main}"
MOODLE="/home/citepcloud.net.ar/domains/campus.citepcloud.net.ar/public_html"
PHP="php81"
FPM_SERVICE="php81-php-fpm"
AI_SERVICE="areteia-ai"

VENV="$REPO_DIR/.venv"
REQ="$REPO_DIR/areteia_ai/requirements.txt"
REQ_HASH_FILE="$VENV/.requirements_hash"

echo "--- [1/6] Git pull ---"
cd "$REPO_DIR"
git fetch origin
git checkout "$BRANCH"
git reset --hard "origin/$BRANCH"

# El git reset --hard de arriba puede reescribir este mismo script en disco.
# Bash puede seguir ejecutando bytes viejos ya bufferados si no se relanza.
# Re-ejecutamos desde el archivo ya actualizado para evitar correr una mezcla
# de versión vieja/nueva del script.
if [ -z "$ARETEIA_DEPLOY_REEXEC" ]; then
    export ARETEIA_DEPLOY_REEXEC=1
    exec bash "$0" "$BRANCH"
fi

echo "--- [1b/6] Venv Python ---"
CURRENT_HASH=$(md5sum "$REQ" 2>/dev/null | cut -d' ' -f1)
SAVED_HASH=$(cat "$REQ_HASH_FILE" 2>/dev/null || echo "")
if [ ! -f "$VENV/bin/uvicorn" ] || [ "$CURRENT_HASH" != "$SAVED_HASH" ]; then
    echo "Recreando venv (uvicorn ausente o requirements cambiaron)..."
    python3.11 -m venv "$VENV" --clear
    "$VENV/bin/pip" install --upgrade pip
    "$VENV/bin/pip" install -r "$REQ" --index-url https://pypi.org/simple/
    echo "$CURRENT_HASH" > "$REQ_HASH_FILE"
else
    echo "Venv OK, sin cambios."
fi

echo "--- [2/6] Copiar plugin PHP a Moodle (preservando areteia.ini) ---"
INI_BACKUP="/tmp/areteia.ini.bak"
cp "$MOODLE/local/areteia/areteia.ini" "$INI_BACKUP" 2>/dev/null || true
rm -rf "$MOODLE/local/areteia"
cp -r "$REPO_DIR/local/areteia" "$MOODLE/local/areteia"
if [ -f "$INI_BACKUP" ]; then
    cp "$INI_BACKUP" "$MOODLE/local/areteia/areteia.ini"
    rm -f "$INI_BACKUP"
fi

echo "--- [3/6] Purgar caché de Moodle ---"
"$PHP" "$MOODLE/admin/cli/purge_caches.php"

echo "--- [3b/6] Upgrade Moodle (registra capabilities nuevas) ---"
"$PHP" "$MOODLE/admin/cli/upgrade.php" --non-interactive

echo "--- [4/6] Reiniciar PHP-FPM (limpia opcache) ---"
systemctl restart "$FPM_SERVICE"

echo "--- [5/6] Reiniciar servicio AI ---"
systemctl restart "$AI_SERVICE"

echo "--- [6/6] Estado de servicios ---"
systemctl is-active "$FPM_SERVICE" && echo "PHP-FPM: OK" || echo "PHP-FPM: FALLO"
systemctl is-active "$AI_SERVICE"  && echo "AI:      OK" || echo "AI:      FALLO"

echo ""
echo "✓ Deploy completado. Abrir sesión nueva en el navegador (incógnito o ?unlock=2)."
