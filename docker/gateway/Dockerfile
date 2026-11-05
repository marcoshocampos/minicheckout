# ---------------------------------------------------------------------------
# Gateway de pagamento fake. PHP puro, sem framework: o arquivo todo cabe
# na cabeça e as falhas são explícitas.
# ---------------------------------------------------------------------------
FROM php:8.4-cli

WORKDIR /app

# ATENÇÃO: o servidor embutido do PHP é single-process por padrão.
# Sem isto, o gateway serializaria as chamadas e esconderia a condição de
# corrida que o teste da Fase 3 (20 requisições simultâneas) precisa expor.
ENV PHP_CLI_SERVER_WORKERS=10

RUN { \
      echo "display_errors=Off"; \
      echo "log_errors=On"; \
      echo "error_log=/dev/stderr"; \
    } > /usr/local/etc/php/conf.d/zz-gateway.ini

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public", "/app/public/index.php"]
