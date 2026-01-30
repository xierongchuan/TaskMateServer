# ---------------- STAGE: build ----------------
FROM docker.io/dunglas/frankenphp:1-php8.4 AS build

# 1) Системные библиотеки для сборки расширений
RUN apt-get update -y \
  && apt-get install -y --no-install-recommends \
  git unzip zip \
  libzip-dev libonig-dev libxml2-dev libicu-dev \
  libpng-dev libjpeg-dev libfreetype6-dev \
  libmemcached-tools \
  libpq-dev postgresql-client \
  curl \
  $PHPIZE_DEPS \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# 2) PHP-расширения: PostgreSQL, GD и т.д.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) \
  mbstring exif pcntl bcmath zip intl sockets \
  pdo pdo_pgsql gd \
  && docker-php-ext-install pgsql

# 3) Redis через PECL
RUN pecl install redis-6.2.0 \
  && docker-php-ext-enable redis

# 4) Composer (официальный образ)
COPY --from=docker.io/library/composer:latest /usr/bin/composer /usr/bin/composer

# 5) Копирование composer-файлов и установка зависимостей
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction --no-scripts

# 6) Копирование кодовой базы
COPY . .

# 7) Права на каталоги
RUN chown -R www-data:www-data storage storage/framework bootstrap/cache \
  && chmod -R 755 storage storage/framework bootstrap/cache

# ---------------- STAGE: runner ----------------
FROM docker.io/dunglas/frankenphp:1-php8.4 AS runner

WORKDIR /app

# Устанавливаем минимальные runtime-зависимости (только библиотеки, без dev-пакетов)
RUN apt-get update -y \
  && apt-get install -y --no-install-recommends \
  libpng16-16t64 libjpeg62-turbo libfreetype6 \
  libzip5 libonig5 libxml2 libicu76 \
  libpq5 \
  supervisor \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

# Копируем скомпилированные PHP-расширения из build (вместо пересборки)
COPY --from=build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=build /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Копируем артефакты и код из build
COPY --from=build /app /app
COPY --from=build /usr/bin/composer /usr/bin/composer

# Копируем Caddyfile
COPY Caddyfile /etc/caddy/Caddyfile

# Копируем кастомный php.ini для увеличения лимитов загрузки файлов
COPY docker/php.ini /usr/local/etc/php/conf.d/99-taskmate.ini

# Права
RUN chown -R www-data:www-data storage storage/framework bootstrap/cache \
  && chmod -R 755 storage storage/framework bootstrap/cache

# Создаем директорию для логов supervisor
RUN mkdir -p /var/log/supervisor && chown -R www-data:www-data /var/log/supervisor

# Переменные окружения для FrankenPHP
ENV SERVER_NAME=":8000"

# Документирование порта
EXPOSE 8000

# FrankenPHP как точка входа
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
