<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Origem da linha de log (FASE 1)
    |--------------------------------------------------------------------------
    |
    | `app` e `worker` rodam a MESMA imagem e o MESMO código, então a linha de
    | log não diz, por si, quem a escreveu. O docker-compose define LOG_SOURCE
    | por container (`app` no anchor, `worker` no override) e isto expõe o
    | valor como config, para o tap App\Logging\FormatLogsAsJson poder marcar
    | cada linha.
    |
    | O campo se chama `source` de propósito: é o nome que o log de acesso do
    | nginx ("source":"nginx") e o do gateway fake ("source":"gateway") já
    | usam. Com os quatro iguais, uma consulta por `source` no Loki vale para
    | os quatro processos, sem tradução.
    |
    | Por que uma env e não php_sapi_name(): o SAPI distingue fpm-fcgi de cli,
    | mas um `artisan` rodado dentro do container `app` também é cli e não é
    | worker. Quem sabe o papel do processo é quem o subiu, ou seja o compose.
    | A Fase 4 vai querer a mesma informação como OTEL_SERVICE_NAME.
    |
    */

    'source' => env('LOG_SOURCE', 'app'),

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "monthly", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'max_files' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'monthly' => [
            'driver' => 'monthly',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'max_files' => 3,
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        /*
        |----------------------------------------------------------------------
        | FASE 1 — este é o canal que o projeto usa (LOG_CHANNEL=stderr).
        |----------------------------------------------------------------------
        |
        | PASSO 1, FEITO: o log em JSON é uma variável de ambiente, não código.
        | Em src/.env:
        |
        |     LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter
        |
        | Isso dá uma linha JSON por evento, com message, level, channel,
        | datetime, context e extra. Repare na divisão: o que você passa em
        | Log::info('evento', [...]) cai em `context`, e o que a facade Context
        | publica cai em `extra`.
        |
        | PASSO 2, FEITO: o `tap` abaixo entrega o canal para
        | App\Logging\FormatLogsAsJson, que marca cada linha com `source` e
        | `host` e troca o formatter por App\Logging\LogLineFormatter. A linha
        | fica na mesma forma que a do nginx e a do gateway fake (`ts`,
        | `source`, `event`, `request_id` na RAIZ), e aí uma consulta só vale
        | para os quatro processos.
        |
        | O tap GANHA da env acima, porque roda depois dela: a env é o
        | fallback de quem comentar a linha do `tap`.
        */
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            // `?: null` e não só env(): com `LOG_STDERR_FORMATTER=` (vazio), o
            // env() devolve string VAZIA, e o LogManager decide por
            // isset($config['formatter']) — isset('') é TRUE. Ele cai em
            // app->make(''), que lança BindingResolutionException; o
            // LogManager captura a exceção e troca o canal pelo EMERGENCY
            // LOGGER, que escreve em storage/logs/laravel.log DENTRO do
            // container. Resultado: zero linha no `docker compose logs`, e
            // nada no output dizendo por quê. Com null, isset() é false e vale
            // o LineFormatter padrão.
            'formatter' => env('LOG_STDERR_FORMATTER') ?: null,
            // Argumentos de construtor do formatter, sem precisar de código.
            // ignoreEmptyContextAndExtra tira os "context":{} e "extra":{}
            // vazios, que são a maior parte do ruído das linhas do framework.
            // Ignorado quando 'formatter' é null.
            'formatter_with' => [
                'ignoreEmptyContextAndExtra' => true,
            ],
            'processors' => [PsrLogMessageProcessor::class],
            'tap' => [App\Logging\FormatLogsAsJson::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
