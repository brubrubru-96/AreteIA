#!/bin/bash
set -e

echo "=== Iniciando AreteIA (Moodle + FastAPI Python RAG + DB + Redis + Nginx) ==="
docker compose up -d

echo ""
echo "=== Estado de los contenedores ==="
docker compose ps
echo ""
echo "AreteIA está listo. URLs de acceso:"
echo "- Moodle: http://localhost:8080"
echo "- FastAPI RAG: http://localhost:8000"
echo "=========================================================================="
