#!/bin/sh
set -e

# 1. Suporte à porta dinâmica via $PORT (Cloud Run, Heroku, Render, etc.)
if [ -n "$PORT" ]; then
    sed -i "s/listen [0-9]\+;/listen $PORT;/g" /etc/nginx/nginx.conf
fi

# 2. Assegurar estrutura e permissões das pastas de armazenamento e cache
mkdir -p /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# 3. Injeção de variáveis de ambiente em runtime no arquivo .env
# Se o .env não existir ou estiver vazio, preenche com as variáveis do ambiente do container
if [ ! -f /var/www/html/.env ] || [ ! -s /var/www/html/.env ]; then
    echo "Injetando variáveis de ambiente em runtime no .env..."
    printenv | grep -E '^(APP_|DB_|KEYCLOAK_|SESSION_|CACHE_|QUEUE_|MAIL_|REDIS_|LOG_|VITE_|SANCTUM_|BCRYPT_|PORT|AWS_|FILESYSTEM_)' > /var/www/html/.env || true
fi
chown www-data:www-data /var/www/html/.env 2>/dev/null || true
chmod 660 /var/www/html/.env 2>/dev/null || true

# 4. Assegurar APP_KEY em runtime caso não tenha sido informada
if [ -z "$APP_KEY" ] && ! grep -q "^APP_KEY=.\+" /var/www/html/.env 2>/dev/null; then
    echo "AVISO: APP_KEY não informada. Gerando chave de aplicação em runtime..."
    GENERATED_KEY=$(php artisan key:generate --show)
    export APP_KEY="$GENERATED_KEY"
    echo "APP_KEY=$GENERATED_KEY" >> /var/www/html/.env
fi

# 5. Limpar caches antigos para garantir que as novas variáveis de runtime tenham efeito imediato
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

# 6. Executar migrations e seeders em runtime se configurado
if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "Executando migrations no banco..."
    php artisan migrate --force
fi

if [ "$RUN_SEEDER" = "true" ]; then
    echo "Executando seeders no banco..."
    php artisan db:seed --force
fi

# 7. Compilar o cache das configurações com as variáveis de ambiente de runtime injetadas
if [ "${APP_ENV:-production}" = "production" ] && [ -n "$APP_KEY" ]; then
    echo "Compilando cache de configurações com variáveis de runtime..."
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
fi

# 8. Executa o comando principal (supervisord por padrão)
exec "$@"
