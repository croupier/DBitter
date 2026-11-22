<?php

/**
 * Юнит-тесты сборки аргументов mysqldump/mysql (ExportDump::buildArgs и
 * Action::clientBase). Проверяют флаги для каждого вида дампа, проброс
 * where/ignore-table/extra/tables и два инварианта безопасности:
 * пароль НИКОГДА не попадает в argv (только через MYSQL_PWD env),
 * а mysql_extra применяется только к клиенту mysql, но не к mysqldump.
 * Команды не запускаются — конфиг из фикстуры tests/_data/const_db.php.
 */

declare(strict_types=1);

namespace DbTools\Tests\Unit;

use DbTools\Action\Action;
use DbTools\Action\ExportDump;
use DbTools\Lib\Config;
use PHPUnit\Framework\TestCase;

/**
 * Проверки argv без исполнения: детерминированный конфиг (conf=const,
 * target=local) позволяет ассертить точный набор флагов на любой машине.
 */
final class ExportArgsTest extends TestCase
{
    /**
     * Собирает Config на детерминированной фикстуре const_db.php
     * (transport=local, чтобы не зависеть от настроек WSL/SSH машины)
     * и накладывает переданные CLI-переопределения.
     *
     * @param array<string,mixed> $cli дополнительные CLI-опции теста
     */
    private function cfg(array $cli = []): Config
    {
        return new Config(dirname(__DIR__, 2), array_merge([
            'conf' => 'const',
            'config' => __DIR__ . '/../_data/const_db.php',
            'target' => 'local',
        ], $cli));
    }

    /**
     * kind=schema: только структура (--no-data), без --single-transaction
     * (данные не читаются — консистентный снапшот не нужен); имя БД
     * из конфига присутствует в argv.
     */
    public function testSchemaKindUsesNoData(): void
    {
        $argv = (new ExportDump())->buildArgs($this->cfg(), 'schema');
        $this->assertContains('--no-data', $argv);
        $this->assertNotContains('--single-transaction', $argv);
        $this->assertContains('it_database', $argv);
    }

    /**
     * kind=data: только данные (--no-create-info), CREATE TABLE не
     * выгружаются; --no-data быть не должно (взаимоисключающие флаги).
     */
    public function testDataKindUsesNoCreateInfo(): void
    {
        $argv = (new ExportDump())->buildArgs($this->cfg(), 'data');
        $this->assertContains('--no-create-info', $argv);
        $this->assertNotContains('--no-data', $argv);
    }

    /**
     * kind=full: консистентный снапшот InnoDB (--single-transaction),
     * --no-tablespaces (не требует привилегии PROCESS) и --hex-blob
     * (бинарные данные в hex — безопасны при импорте).
     */
    public function testFullKindUsesSingleTransaction(): void
    {
        $argv = (new ExportDump())->buildArgs($this->cfg(), 'full');
        $this->assertContains('--single-transaction', $argv);
        $this->assertContains('--no-tablespaces', $argv);
        $this->assertContains('--hex-blob', $argv);
    }

    /**
     * Проброс пользовательских опций: --where, повторяемый --ignore-table,
     * повторяемый --extra (сырые флаги mysqldump) и список таблиц через
     * запятую (пробелы вокруг имён обрезаются).
     */
    public function testWhereIgnoreTableExtraAndTables(): void
    {
        $argv = (new ExportDump())->buildArgs($this->cfg([
            'where' => 'id > 10',
            'ignore-table' => ['it_database.wp_logs'],
            'extra' => ['--column-statistics=0'],
            'tables' => 'wp_posts, wp_options',
        ]), 'full');
        $this->assertContains('--where=id > 10', $argv);
        $this->assertContains('--ignore-table=it_database.wp_logs', $argv);
        $this->assertContains('--column-statistics=0', $argv);
        $this->assertContains('wp_posts', $argv);
        $this->assertContains('wp_options', $argv);
    }

    /**
     * Выключатели routines/triggers/events=false должны убирать
     * соответствующие флаги из argv (по умолчанию они включены профилем).
     */
    public function testRoutinesTriggersEventsToggle(): void
    {
        $argv = (new ExportDump())->buildArgs($this->cfg([
            'routines' => false, 'triggers' => false, 'events' => false,
        ]), 'full');
        $this->assertNotContains('--routines', $argv);
        $this->assertNotContains('--triggers', $argv);
        $this->assertNotContains('--events', $argv);
    }

    /**
     * Регрессия (фикс ревью #4): mysql_extra — флаги клиента mysql
     * (например --skip-column-names), они НЕ должны попадать в argv
     * mysqldump, который такие флаги не понимает и падал бы.
     * clientBase protected — вызывается через рефлексию с dump=false/true.
     */
    public function testMysqlExtraAppliesToClientNotDump(): void
    {
        $cfg = $this->cfg(['mysql_extra' => ['--skip-column-names']]);
        $m = new \ReflectionMethod(Action::class, 'clientBase');
        /** @var list<string> $client */
        $client = $m->invoke(new ExportDump(), $cfg, false);
        /** @var list<string> $dump */
        $dump = $m->invoke(new ExportDump(), $cfg, true);
        $this->assertContains('--skip-column-names', $client);
        $this->assertNotContains('--skip-column-names', $dump);
    }

    /**
     * Инвариант безопасности: пароль никогда не кладётся в argv —
     * он виден в списке процессов любому пользователю системы.
     * Пароль передаётся только через переменную окружения MYSQL_PWD;
     * в базовом argv есть --user, но нет значения пароля из фикстуры.
     */
    public function testClientBaseNeverPutsPasswordInArgv(): void
    {
        $m = new \ReflectionMethod(Action::class, 'clientBase');
        /** @var list<string> $client */
        $client = $m->invoke(new ExportDump(), $this->cfg(), false);
        $this->assertNotContains('it_secret_pw', $client);
        $this->assertContains('--user=it_user', $client);
    }
}
