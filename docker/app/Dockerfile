# ---------------------------------------------------------------------------
# Imagem do Laravel (php-fpm). A mesma imagem roda o container `app` e o `worker`.
# O código NÃO é copiado para a imagem: ./src é bind mount, para editar sem rebuild.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm

# install-php-extensions resolve dependências de sistema de cada extensão.
# Na Fase 4 a instalação do ext-opentelemetry vira uma linha aqui.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
      pdo_pgsql \
      pgsql \
      redis \
      pcntl \
      bcmath \
      intl \
      opcache \
      @composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# opcache ligado mas revalidando sempre: em dev, editar PHP tem efeito imediato.
RUN { \
      echo "opcache.enable=1"; \
      echo "opcache.validate_timestamps=1"; \
      echo "opcache.revalidate_freq=0"; \
    } > /usr/local/etc/php/conf.d/zz-opcache-dev.ini \
 && { \
      echo "memory_limit=512M"; \
      echo "display_errors=Off"; \
      echo "log_errors=On"; \
      echo "error_log=/dev/stderr"; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

# php-fpm: logs do worker no stderr do container, senão o log desaparece.
RUN { \
      echo "[global]"; \
      echo "error_log = /proc/self/fd/2"; \
      echo "[www]"; \
      echo "catch_workers_output = yes"; \
      echo "decorate_workers_output = no"; \
      echo "clear_env = no"; \
    } > /usr/local/etc/php-fpm.d/zz-docker-logs.conf

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /var/www/html

COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm", "--nodaemonize"]
