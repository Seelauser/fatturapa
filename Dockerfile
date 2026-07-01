FROM php:8.3-cli-alpine AS base

RUN apk add --no-cache curl libxml2-dev \
 && docker-php-ext-install mbstring dom pdo pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Install deps ──────────────────────────────────────────────
# The bundled Slim microservice + Openapi transport live in require-dev, so the
# server image installs WITH dev deps (the library itself needs only ext-dom).
FROM base AS deps
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --optimize-autoloader --no-interaction --no-progress --no-scripts

# ── Production runner ─────────────────────────────────────────
FROM base AS runner
WORKDIR /app
COPY --from=deps /app/vendor ./vendor
COPY . .

RUN adduser -D -u 1001 fatturapa
USER fatturapa
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
