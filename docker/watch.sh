#!/bin/bash

# Script de watch para hot reload do Hyperf
# Monitora mudanças em arquivos PHP e reinicia o servidor automaticamente

set -e

APP_DIR="/var/www"
CACHE_DIR="${APP_DIR}/runtime/container"
PID_FILE="${APP_DIR}/runtime/hyperf.pid"

echo "🔥 Hot Reload ativado para Hyperf"
echo "📁 Monitorando: ${APP_DIR}/app"
echo "🛑 Pressione Ctrl+C para parar"
echo ""

# Função para limpar cache e reiniciar
restart_server() {
    echo ""
    echo "🔄 Detectada mudança! Reiniciando servidor..."
    
    # Matar processo anterior se existir
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE" 2>/dev/null || echo "")
        if [ ! -z "$PID" ] && kill -0 "$PID" 2>/dev/null; then
            echo "🛑 Parando servidor anterior (PID: $PID)..."
            kill "$PID" 2>/dev/null || true
            sleep 1
        fi
    fi
    
    # Limpar cache
    echo "🧹 Limpando cache..."
    rm -rf "${CACHE_DIR}"/* 2>/dev/null || true
    
    # Aguardar um pouco
    sleep 1
    
    # Reiniciar servidor
    echo "🚀 Iniciando servidor..."
    cd "$APP_DIR"
    php bin/hyperf.php start > /dev/null 2>&1 &
    
    echo "✅ Servidor reiniciado!"
    echo ""
}

# Reiniciar na primeira vez
restart_server

# Monitorar mudanças em arquivos PHP
inotifywait -m -r -e modify,create,delete,move \
    --include '\.(php)$' \
    --exclude 'runtime|vendor|\.git' \
    "${APP_DIR}/app" \
    "${APP_DIR}/config" \
    2>/dev/null | while read -r event; do
    restart_server
done

