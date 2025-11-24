#!/bin/bash

# Script simples de watch usando polling (compatível com macOS)
# Monitora mudanças e reinicia o servidor

# Não usar set -e aqui, pois queremos continuar mesmo se kill falhar
set +e

APP_DIR="/var/www"
CACHE_DIR="${APP_DIR}/runtime/container"
PID_FILE="${APP_DIR}/runtime/hyperf.pid"
LAST_CHECK=0

# Função de cleanup ao sair
cleanup() {
    echo ""
    echo "🛑 Parando servidor..."
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE" 2>/dev/null || echo "")
        if [ ! -z "$PID" ] && kill -0 "$PID" 2>/dev/null; then
            kill "$PID" 2>/dev/null || true
        fi
    fi
    # Também matar processos hyperf que possam ter ficado órfãos
    pkill -f "hyperf.php start" 2>/dev/null || true
    echo "✅ Servidor parado"
    exit 0
}

trap cleanup SIGINT SIGTERM

echo "🔥 Hot Reload ativado (modo polling)"
echo "📁 Monitorando: ${APP_DIR}/app e ${APP_DIR}/config"
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
            # Forçar kill se ainda estiver rodando
            if kill -0 "$PID" 2>/dev/null; then
                kill -9 "$PID" 2>/dev/null || true
            fi
        fi
    fi
    
    # Matar qualquer processo hyperf órfão
    pkill -f "hyperf.php start" 2>/dev/null || true
    sleep 1
    
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

# Obter timestamp inicial (para não reiniciar na primeira execução)
LAST_CHECK=$(find "${APP_DIR}/app" "${APP_DIR}/config" -type f -name "*.php" -exec stat -c "%Y" {} \; 2>/dev/null | sort -n | tail -1 || echo "0")

# Iniciar servidor na primeira vez
restart_server

# Loop de polling (verifica a cada 2 segundos)
while true; do
    # Verificar se algum arquivo PHP foi modificado
    CURRENT_CHECK=$(find "${APP_DIR}/app" "${APP_DIR}/config" -type f -name "*.php" -exec stat -c "%Y" {} \; 2>/dev/null | sort -n | tail -1 || echo "0")
    
    if [ "$CURRENT_CHECK" != "$LAST_CHECK" ] && [ "$CURRENT_CHECK" != "0" ] && [ "$LAST_CHECK" != "0" ]; then
        restart_server
        LAST_CHECK=$CURRENT_CHECK
    elif [ "$LAST_CHECK" = "0" ] && [ "$CURRENT_CHECK" != "0" ]; then
        # Primeira vez que detecta arquivos, apenas atualizar
        LAST_CHECK=$CURRENT_CHECK
    fi
    
    sleep 2
done

