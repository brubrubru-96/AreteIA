#!/bin/bash
# Deploy script para VPS bare-metal (sin Docker)
# Uso: bash /root/areteia/scripts/deploy-vps.sh
set -e

REPO_DIR="/root/areteia"
BRANCH="propuesta-fix-prompt-building"
MOODLE="/home/citepcloud.net.ar/domains/campus.citepcloud.net.ar/public_html"
PHP="php81"
FPM_SERVICE="php81-php-fpm"
AI_SERVICE="areteia-ai"

echo "--- [1/6] Git pull ---"
cd "$REPO_DIR"
git pull origin "$BRANCH"

echo "--- [2/6] Copiar plugin PHP a Moodle ---"
rm -rf "$MOODLE/local/areteia"
cp -r "$REPO_DIR/local/areteia" "$MOODLE/local/areteia"

echo "--- [3/6] Purgar caché de Moodle ---"
"$PHP" "$MOODLE/admin/cli/purge_caches.php"

echo "--- [4/6] Reiniciar PHP-FPM (limpia opcache) ---"
systemctl restart "$FPM_SERVICE"

echo "--- [5/6] Reiniciar servicio AI ---"
systemctl restart "$AI_SERVICE"

echo "--- [6/6] Estado de servicios ---"
systemctl is-active "$FPM_SERVICE" && echo "PHP-FPM: OK" || echo "PHP-FPM: FALLO"
systemctl is-active "$AI_SERVICE"  && echo "AI:      OK" || echo "AI:      FALLO"

echo ""
echo "✓ Deploy completado. Abrir sesión nueva en el navegador (incógnito o ?unlock=2)."
