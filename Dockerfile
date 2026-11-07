# ==========================================
# 1. Build do Frontend (Vue 3 + Vite + Tailwind)
# ==========================================
FROM node:22-alpine AS frontend
WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build

# ==========================================
# 2. Instalação das dependências PHP (Composer)
# ==========================================
FROM composer:2 AS vendor
WORKDIR /app

COPY composer.json composer.lock ./
COPY app/ app/
COPY database/ database/

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

# ==========================================
# 3. Imagem Final de Produção (PHP 8.2-FPM + Nginx)
# ==========================================
FROM php:8.2-fpm-alpine AS production

# Instalar o instalador oficial de extensões PHP
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Instalar Nginx, Supervisor, utilitários e extensões PHP necessárias para o Laravel
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    bash \
    && install-php-extensions \
    pdo_pgsql \
    pgsql \
    pdo_sqlite \
    pdo_mysql \
    bcmath \
    mbstring \
    opcache \
    zip \
    intl \
    pcntl \
    redis

# Configurações do Nginx, Supervisor, PHP, PHP-FPM e Entrypoint
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# Copiar a aplicação para a imagem
COPY . .

# Copiar dependências do Composer e os assets compilados pelo Vite
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build

# Descoberta de pacotes do Laravel e permissões
RUN php artisan package:discover --ansi \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

