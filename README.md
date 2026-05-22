# AreteIA

Plataforma educativa basada en Moodle 4.5 con un servicio de IA (RAG) que permite a los docentes ofrecer asistentes inteligentes por curso, alimentados con el material del propio curso.

## Arquitectura

```
┌─────────────┐     ┌─────────────┐     ┌──────────────┐
│   Nginx     │────▶│  Moodle     │────▶│  PostgreSQL  │
│  (reverse   │     │  PHP 8.1    │     │     13       │
│   proxy)    │     │  + Plugin   │     └──────────────┘
└─────────────┘     │  AreteIA    │     ┌──────────────┐
                    │             │────▶│    Redis      │
                    └──────┬──────┘     └──────────────┘
                           │
                    ┌──────▼──────┐
                    │ Python RAG  │
                    │ FastAPI     │
                    │ FAISS +     │
                    │ sentence-   │
                    │ transformers│
                    └─────────────┘
```

## Requisitos

- Docker Engine 20.10+
- Docker Compose v2
- 4 GB RAM mínimo
- 20 GB de disco libre

## Instalación rápida

```bash
# 1. Clonar el repositorio
git clone https://github.com/fvallad/areteia.git
cd areteia

# 2. Configurar variables de entorno
cp .env.example .env
nano .env   # Completar con tus valores

# 3. Crear volúmenes Docker
docker volume create areteia_db_data
docker volume create areteia_redis_data
docker volume create areteia_moodle_core

# 4. Crear directorios de datos
mkdir -p moodledata data/sync
chmod 777 moodledata

# 5. Levantar Moodle
docker compose -f docker-compose.moodle.yml up -d --build

# 6. Instalar Moodle (primera vez, esperar ~20 seg a que la DB arranque)
docker compose -f docker-compose.moodle.yml exec moodle \
    php /var/www/html/admin/cli/install_database.php \
    --lang=en \
    --adminuser=admin \
    --adminpass=password \
    --adminemail=admin@example.com \
    --fullname="AreteIA Moodle" \
    --shortname="AreteIA" \
    --agree-license

# 7. Levantar el servicio de IA
docker compose -f docker-compose.python.yml up -d --build
```

## Configuración

Toda la configuración se maneja a través del archivo `.env` (no se sube al repositorio).

### Variables principales

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `MOODLE_URL` | URL pública de Moodle | `https://mi-servidor.com` |
| `DB_PASS` | Contraseña de PostgreSQL | (cambiar obligatoriamente) |
| `MOODLE_ADMIN_PASS` | Contraseña del admin de Moodle | (cambiar obligatoriamente) |
| `HF_TOKEN` | Token de HuggingFace | `hf_...` |
| `DASHSCOPE_API_KEY` | API Key de DashScope (Qwen) | `sk-...` |
| `NGINX_PORT` | Puerto del servidor web | `8080` (default), `80` para producción |

### Proxy (opcional)

Si el servidor está detrás de un proxy corporativo:

```bash
# En .env
HTTP_PROXY=http://tu-proxy:puerto
HTTPS_PROXY=http://tu-proxy:puerto
```

También configurar el proxy del Docker daemon para poder hacer pull de imágenes:

```bash
sudo mkdir -p /etc/systemd/system/docker.service.d
sudo tee /etc/systemd/system/docker.service.d/proxy.conf << 'EOF'
[Service]
Environment="HTTP_PROXY=http://tu-proxy:puerto"
Environment="HTTPS_PROXY=http://tu-proxy:puerto"
Environment="NO_PROXY=localhost,127.0.0.1"
EOF
sudo systemctl daemon-reload
sudo systemctl restart docker
```

### SSL / Reverse Proxy

Si Moodle está detrás de un reverse proxy con HTTPS, el `entrypoint.sh` detecta automáticamente el header `X-Forwarded-Proto` y activa `sslproxy`. Solo asegurate de que `MOODLE_URL` comience con `https://`.

## Estructura del proyecto

```
areteia/
├── docker-compose.moodle.yml   # Moodle + PostgreSQL + Redis + Nginx
├── docker-compose.python.yml   # Servicio Python RAG
├── Dockerfile                  # Imagen de Moodle (PHP 8.1 + extensiones)
├── entrypoint.sh               # Genera config.php y ajusta permisos
├── conf/
│   ├── nginx.conf              # Configuración de Nginx
│   └── moodle.ini              # Configuración de PHP
├── areteia_ai/                 # Servicio de IA
│   ├── Dockerfile
│   ├── main.py                 # FastAPI app
│   ├── llm.py                  # Integración con LLMs
│   ├── schemas.py              # Modelos Pydantic
│   ├── requirements.txt
│   ├── brain/                  # Lógica de procesamiento
│   └── rag/                    # Motor RAG (FAISS + embeddings)
├── local/areteia/              # Plugin de Moodle
│   ├── version.php
│   ├── index.php
│   ├── lib.php
│   ├── classes/                # Clases PHP del plugin
│   ├── lang/                   # Traducciones
│   └── styles.css
├── .env.example                # Template de configuración
└── .gitignore
```

## Comandos útiles

```bash
# Ver estado de los contenedores
docker compose -f docker-compose.moodle.yml ps
docker compose -f docker-compose.python.yml ps

# Logs en tiempo real
docker compose -f docker-compose.moodle.yml logs -f moodle
docker compose -f docker-compose.python.yml logs -f python_rag

# Purgar cache de Moodle
docker compose -f docker-compose.moodle.yml exec moodle php admin/cli/purge_caches.php

# Entrar al contenedor de Moodle
docker compose -f docker-compose.moodle.yml exec moodle bash

# Entrar a PostgreSQL
docker compose -f docker-compose.moodle.yml exec db psql -U dbuser -d moodle

# Reiniciar todo
docker compose -f docker-compose.moodle.yml restart
docker compose -f docker-compose.python.yml restart

# Bajar todo (mantiene datos)
docker compose -f docker-compose.moodle.yml down
docker compose -f docker-compose.python.yml down

# Bajar todo Y borrar datos (⚠️ destructivo)
docker compose -f docker-compose.moodle.yml down
docker volume rm areteia_db_data areteia_redis_data areteia_moodle_core
```

## Cómo funciona el RAG

1. El docente sube material (PDFs, Word, PPTs) al curso a través del plugin AreteIA
2. El servicio Python procesa los documentos, los fragmenta y genera embeddings con `sentence-transformers` (modelo `intfloat/multilingual-e5-small`)
3. Los vectores se indexan con FAISS para búsqueda rápida por similitud
4. Cuando un alumno hace una pregunta, se buscan los fragmentos más relevantes del material
5. Los fragmentos se envían como contexto al LLM (Qwen vía DashScope) que genera una respuesta fundamentada en el contenido del curso

## Licencia

GNU GPL v3
