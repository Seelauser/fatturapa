FROM php:8.3-cli-alpine AS base

RUN apk add --no-cache curl \
 && docker-php-ext-install mbstring

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Install deps ──────────────────────────────────────────────
FROM base AS deps
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress 2>/dev/null || \
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts

# ── Production runner ─────────────────────────────────────────
FROM base AS runner
WORKDIR /app
COPY --from=deps /app/vendor ./vendor
COPY . .

RUN adduser -D -u 1001 fatturapa
USER fatturapa
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
