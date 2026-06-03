# Deploy en VPS con venv + systemd

Esta guía cubre la instalación manual de AreteIA en un VPS con Moodle ya instalado, sin Docker. El servicio Python corre con un virtualenv gestionado por systemd.

---

## Requisitos previos

- Moodle 4.x instalado y funcionando (con PHP-FPM)
- Python 3.10+
- Git
- Acceso root o sudo al servidor
- API key de OpenAI

---

## 1. Clonar el repositorio

```bash
cd /root
git clone https://github.com/vicente-astorga/AreteIA
cd areteia
```

---

## 2. Instalar el plugin en Moodle

Copiar el directorio del plugin al webroot de Moodle:

```bash
MOODLE_ROOT="/ruta/a/tu/moodle"   # ej: /home/usuario/domains/campus.ejemplo.com/public_html

cp -r /root/areteia/local/areteia "$MOODLE_ROOT/local/areteia"
chown -R www-data:www-data "$MOODLE_ROOT/local/areteia"   # o el usuario de PHP-FPM
```

Ejecutar el instalador CLI de Moodle para registrar el plugin:

```bash
php "$MOODLE_ROOT/admin/cli/upgrade.php" --non-interactive
```

Purgar caché:

```bash
php "$MOODLE_ROOT/admin/cli/purge_caches.php"
```

Reiniciar PHP-FPM (el nombre del servicio puede variar):

```bash
systemctl restart php8.1-fpm    # ajustar versión según la instalación
```

Luego entrar a Moodle como admin → Administración del sitio → Notificaciones para confirmar la instalación del plugin.

---

## 3. Configurar el servicio Python

### 3.1 Crear el virtualenv

```bash
cd /root/areteia/areteia_ai
python3 -m venv venv
source venv/bin/activate
pip install --upgrade pip
pip install -r requirements.txt
deactivate
```

### 3.2 Crear el archivo de entorno

El servicio soporta dos proveedores de LLM. Elegir uno de los dos bloques:

**Opción A — OpenAI (GPT)**
```bash
cat > /root/areteia/areteia_ai/.env << 'EOF'
LLM_PROVIDER=openai
OPENAI_API_KEY=sk-...          # reemplazar con tu clave real
OPENAI_MODEL=gpt-4.1-mini
ARETEIA_SYNC_PATH=/ruta/a/moodledata/areteia_sync   # ver sección 4
EOF
```

**Opción B — DashScope (Qwen / Alibaba)**
```bash
cat > /root/areteia/areteia_ai/.env << 'EOF'
LLM_PROVIDER=dashscope
DASHSCOPE_API_KEY=sk-...       # reemplazar con tu clave real
DASHSCOPE_MODEL=qwen-plus      # opcional, es el default
# DASHSCOPE_BASE_URL=...       # opcional, solo si usás endpoint corporativo
ARETEIA_SYNC_PATH=/ruta/a/moodledata/areteia_sync   # ver sección 4
EOF
```

En ambos casos, asegurar permisos restrictivos:
```bash
chmod 600 /root/areteia/areteia_ai/.env
```

### 3.3 Crear el servicio systemd

```bash
cat > /etc/systemd/system/areteia-ai.service << 'EOF'
[Unit]
Description=AreteIA — Servicio Python FastAPI
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/root/areteia/areteia_ai
EnvironmentFile=/root/areteia/areteia_ai/.env
ExecStart=/root/areteia/areteia_ai/venv/bin/python main.py
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
```

Habilitar e iniciar:

```bash
systemctl daemon-reload
systemctl enable areteia-ai
systemctl start areteia-ai
systemctl status areteia-ai
```

Verificar que el servicio responde:

```bash
curl http://localhost:8000/health
```

---

## 4. Configurar la ruta de sincronización (ARETEIA_SYNC_PATH)

Esta variable le indica al servicio Python dónde están los archivos sincronizados desde Moodle. Si Python y Moodle corren en el mismo servidor, apuntarla al directorio dentro del `moodledata`:

```bash
# Crear el directorio si no existe
mkdir -p /ruta/a/moodledata/areteia_sync
chown www-data:www-data /ruta/a/moodledata/areteia_sync
```

Actualizar en `.env`:

```
ARETEIA_SYNC_PATH=/ruta/a/moodledata/areteia_sync
```

Reiniciar el servicio:

```bash
systemctl restart areteia-ai
```

Con esto activado, la ingestión de documentos es una operación de lectura directa en disco (sin transferencia HTTP), lo cual acelera el proceso significativamente.

---

## 5. Configurar el plugin desde Moodle

1. Administración del sitio → Plugins → Bloques locales → AreteIA
2. Completar la URL del servicio de IA: `http://localhost:8000`
3. Guardar

---

## 6. Script de deploy continuo

El script `/root/areteia/scripts/deploy-vps.sh` automatiza los deploys posteriores:

```bash
#!/bin/bash
set -e

REPO_DIR="/root/areteia"
MOODLE_ROOT="/ruta/a/tu/moodle"
BRANCH="main"

echo "--- Actualizando código ---"
cd "$REPO_DIR"
git fetch origin
git checkout "$BRANCH"
git pull origin "$BRANCH"

echo "--- Actualizando plugin ---"
cp -r "$REPO_DIR/local/areteia" "$MOODLE_ROOT/local/areteia"
chown -R www-data:www-data "$MOODLE_ROOT/local/areteia"

echo "--- Upgrade y caché Moodle ---"
php "$MOODLE_ROOT/admin/cli/upgrade.php" --non-interactive
php "$MOODLE_ROOT/admin/cli/purge_caches.php"

echo "--- Reiniciando servicios ---"
systemctl restart php8.1-fpm
systemctl restart areteia-ai

echo "--- Deploy completado ---"
```

Dar permisos y ejecutar:

```bash
chmod +x /root/areteia/scripts/deploy-vps.sh
/root/areteia/scripts/deploy-vps.sh
```

---

## 7. Actualizar dependencias Python

Si cambia `requirements.txt` después de un pull:

```bash
cd /root/areteia/areteia_ai
source venv/bin/activate
pip install -r requirements.txt
deactivate
systemctl restart areteia-ai
```

---

## 8. Ver logs del servicio

```bash
journalctl -u areteia-ai -f          # en tiempo real
journalctl -u areteia-ai -n 100      # últimas 100 líneas
```

---

## Notas

- El servicio escucha en `localhost:8000` por defecto. No exponerlo directamente a internet; el plugin PHP lo llama internamente desde el servidor.
- La API key de OpenAI está en `.env` con permisos `600`. No commitear ese archivo.
- Si Moodle está en hosting compartido (cPanel, etc.) con PHP-FPM gestionado por el proveedor, el paso de `systemctl restart php8.1-fpm` puede ser innecesario o requerir otro método para invalidar el opcode cache (por ejemplo, tocar un archivo `.php` o usar `cachetool`).
