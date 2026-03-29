# Optional: build this service on DigitalOcean App Platform (Docker) or any container host.
# Set env vars (APP_KEY, DB_*, etc.) in the platform. Run migrations via a one-off job or release phase.
FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

ENV PORT=8080
EXPOSE 8080

# 0.0.0.0 is required so the platform health checks can reach the app.
CMD ["sh", "-c", "php artisan migrate --force && php artisan config:cache && php artisan route:cache && exec php artisan serve --host=0.0.0.0 --port=${PORT}"]
