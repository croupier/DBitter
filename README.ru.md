# DBitter

Универсальный CLI-инструмент миграции MySQL-баз.

[English](README.md) | **Русский**

DBitter выгружает и импортирует дампы MySQL, создаёт базы и пользователей,
диагностирует подключение — где бы ни жил ваш MySQL: на хосте, внутри WSL,
в Docker-контейнере, на удалённом SSH-сервере или через обычное PDO-соединение.
Реквизиты БД берутся из конфигурации самого проекта (`wp-config.php`, Laravel
`.env`, произвольные const-файлы) — дублировать ничего не нужно.

Работает как composer-пакет (`vendor/bin/dbitter`) или как автономная папка,
которую можно положить рядом с любым проектом — или уровнем выше. Автономному
режиму Composer не нужен вовсе: у инструмента собственный автозагрузчик классов.

## Возможности

- **7 действий**: `doctor`, `export`, `import`, `create-db`, `create-user`, `drop-db`, `show-command`
- **5 транспортов**: `local`, `wsl`, `docker`, `ssh`, `pdo` (PDO — только SQL-действия, без дампов)
- **Экстракторы конфигов**: `wp` (WordPress), `laravel` (.env), `env`, `const` — с автоопределением
- **Дампы**: full / только схема / только данные, сжатие gzip или zstd, разбиение на части по MiB, манифесты, retention по количеству и возрасту
- **Безопасность по умолчанию**: пароли никогда не попадают в аргументы процессов (используются `MYSQL_PWD` / файлы SQL-скриптов); конфиги проекта *парсятся*, а не `require`-ятся; `--dry-run` печатает замаскированные команды
- **Интерактивное меню** при запуске без действия; любой вопрос можно заранее закрыть CLI-флагом
- **Логирование**: `--quiet`, `--verbose`, `--log=path`

## Требования

- PHP >= 8.3 (CLI) с `ext-zlib`
- Клиенты `mysql` / `mysqldump`, доступные через выбранный транспорт (на хосте, в WSL, в контейнере или на удалённом сервере)
- Опционально: бинарник `zstd` для zstd-сжатия; `ext-pdo_mysql` для транспорта `pdo`

## Установка

### Как composer-пакет

```bash
composer require croupier/dbitter
vendor/bin/dbitter doctor
```

### Автономно

Склонируйте или скопируйте папку DBitter рядом с проектом (или куда-то выше него)
и запускайте:

```bash
php start.php doctor
```

Для работы `composer install` не нужен — Composer требуется только для тестов.
`php start.php` и `bin/dbitter` — равнозначные точки входа.

## Быстрый старт

```bash
dbitter doctor                          # проверка окружения и подключения
dbitter export                          # дамп БД проекта (по умолчанию gzip)
dbitter export --kind=schema --compress=none
dbitter import                          # импорт последнего дампа
dbitter import --file=dump/backup.sql.gz --database=staging
dbitter create-db --name=newdb --collation=utf8mb4_unicode_ci
dbitter create-user --name=newdb --grant=ALL   # аккаунт из конфига проекта
dbitter drop-db --name=olddb            # разрушающее действие, спросит подтверждение
dbitter show-command export             # показать итоговые команды, пароли замаскированы
```

Полный список опций — `dbitter --help`; запуск `dbitter` без действия открывает
интерактивное меню.

## Конфигурация

DBitter находит **корень проекта**, поднимаясь от текущего рабочего каталога
вверх до первого маркера: `dbitter.php`, `wp-config.php`, `artisan`, `.env`
или `composer.json`. Каталог самого инструмента пропускается, обход
останавливается на границе `.git`. Если ничего не найдено — legacy-фолбэк:
родительский каталог инструмента (классическая standalone-раскладка).

Настройки сливаются из шести слоёв (от старшего приоритета к младшему):

1. **CLI-флаги** — `--host=`, `--target=`, `--kind=`, …
2. **`dbitter.local.php`** — корень проекта, в gitignore; пароли и локальные особенности
3. **`dbitter.php`** — корень проекта, коммитимый профиль (без секретов!)
4. **`config.local.php`** — папка инструмента, в gitignore (standalone-режим)
5. **`config.php`** — профиль папки инструмента (standalone-режим; в composer-пакет не входит)
6. **Дефолты транспорта**

Сами реквизиты БД извлекаются из конфига проекта, выбранного ключом `conf`
(`wp` | `laravel` | `env` | `const`). Если `conf` не задан, DBitter определит тип
сам по маркерам в корне проекта (`wp-config.php`, `artisan`, `.env`).
Явный путь к файлу — `--config=path` (или `conf_path` в профиле). Слой может
сбросить унаследованное значение через `null` (например, `'conf_path' => null`).

Пример `dbitter.php` (корень проекта, коммитится):

```php
<?php
return [
    'conf' => 'wp',
    'target' => 'wsl',
    'dump' => [
        'compress' => 'gzip',
        'retention-keep' => 10,
    ],
];
```

Пример `dbitter.local.php` (корень проекта, gitignored):

```php
<?php
return [
    'conf_path' => 'D:/sites/myproject/wp-config.php',
    'admin' => ['user' => 'root', 'pass' => 'secret'],
    'transport' => [
        'wsl' => ['distro' => 'Ubuntu', 'user' => 'root'],
    ],
];
```

При `conf=const` реквизиты читаются из PHP-файла, возвращающего массив
(`['db' => [...]]` или плоский): `dbitter.local.php` → `dbitter.php` →
`config.local.php` инструмента; побеждает первый файл с именем БД. Этот файл
исполняется (`require`), поэтому указывайте только собственный конфиг.

Дампы по умолчанию складываются в `<project>/dump` (в legacy-фолбэке —
`<tool>/dump`); переопределяется через `dump.dir`. Имена дампов и манифестов
используют имя каталога проекта.

### Транспорты

| Target   | Как запускаются клиенты                                    | Ключевые настройки                    |
|----------|------------------------------------------------------------|---------------------------------------|
| `local`  | `mysql`/`mysqldump` из PATH хоста (или явные пути)         | `mysql`, `mysqldump`                  |
| `wsl`    | `wsl.exe -d <distro> -u <user> -- mysql …`                 | `distro`, `user`, пути к бинарникам   |
| `docker` | `docker exec` в контейнере или compose-сервисе             | `container` или `service` (+ `compose_file`) |
| `ssh`    | удалённый шелл через `ssh` (ключевая авторизация)          | `host`, `port`, `user`, `key`, `options` |
| `pdo`    | PDO-соединение из PHP, без внешних клиентов — только SQL-действия (`doctor`, `create-db`, `create-user`, `drop-db`); экспорт/импорт недоступны | расширение `pdo_mysql`, host/port/user/pass из конфига проекта |

## Безопасность

- Пароли передаются через `MYSQL_PWD` или временные файлы SQL-скриптов — никогда
  через `argv`, поэтому не утекают в списки процессов и историю шелла.
- `wp-config.php` и другие конфиги проекта парсятся как текст, а не исполняются.
- `--dry-run` и `show-command` маскируют секреты в выводе.
- Реальные реквизиты держите в `dbitter.local.php` / `config.local.php`
  (gitignored), не в коммитимых профилях.

## Разработка и тесты

```bash
composer install
composer test          # юнит-тесты
composer test:coverage # юнит-тесты с покрытием (нужен Xdebug)
DBT_IT=1 composer test-it   # интеграционные тесты на живом MySQL
```

Интеграционные тесты пропускаются без `DBT_IT=1`; им нужен доступный сервер MySQL
и админские реквизиты в `config.local.php`.

## Лицензия

[MIT](LICENSE) — история изменений в [CHANGELOG.md](CHANGELOG.md).
