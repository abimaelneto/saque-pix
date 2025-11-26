#!/bin/bash

# Script para rodar o cron job de saques agendados em paralelo
# Use este script em um terminal separado quando rodar make dev

echo "⏰ Iniciando Cron Job de Saques Agendados"
echo "=========================================="
echo ""
echo "Este script processa saques agendados a cada minuto"
echo "Pressione Ctrl+C para parar"
echo ""

while true; do
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ⏰ Executando cron job..."
    
    docker-compose exec -T app php bin/hyperf.php withdraw:process-scheduled 2>&1 | while IFS= read -r line; do
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] $line"
    done
    
    # Aguardar até o próximo minuto
    sleep 60
done

