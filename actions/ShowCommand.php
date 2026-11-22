<?php

declare(strict_types=1);

namespace DbTools\Action;

/**
 * Действие show-command: предпросмотр реальных команд mysql/mysqldump.
 *
 * Запуск: php start.php show-command [--kind=full|schema|data].
 * Печатает внутренние bash-команды дампа/импорта и внешние лаунчеры
 * транспорта (wsl.exe/ssh) с замаскированными секретами. Ничего не выполняет
 * и не подключается к серверу — удобно для отладки конфигурации и транспорта.
 */

use DbTools\Lib\Command;
use DbTools\Lib\Config;
use DbTools\Lib\Menu;

/**
 * Диагностическое действие: показывает, какие команды были бы запущены
 * экспортом и импортом при текущей конфигурации, без их выполнения.
 */
final class ShowCommand extends Action
{
    /**
     * Машинное имя действия для CLI.
     *
     * @return string всегда 'show-command'
     */
    public function name(): string
    {
        return 'show-command';
    }

    /**
     * Заголовок для интерактивного меню/справки.
     *
     * @return string описание действия
     */
    public function title(): string
    {
        return 'Show resolved mysql/mysqldump commands (masked)';
    }

    /**
     * Печатает команды дампа и импорта: внутреннюю (bash внутри транспорта)
     * и внешний лаунчер, а также напоминание о передаче пароля через MYSQL_PWD.
     *
     * Побочные эффекты: только вывод через Menu; ни процессы клиентов,
     * ни подключения к БД не выполняются, файлы не создаются.
     *
     * @param Config $cfg  конфигурация проекта/окружения (опция kind влияет на дамп)
     * @param array  $opts опции CLI (значения читаются через $cfg->opt)
     * @return int всегда 0 — предпросмотр не может «провалиться»
     */
    public function run(Config $cfg, array $opts): int
    {
        $t = $this->transport($cfg);
        $secrets = $cfg->secrets();
        $kind = (string) $cfg->opt('kind', 'full');

        // Переиспользуем реальные сборщики аргументов (ExportDump::buildArgs и
        // Action::clientBase), чтобы предпросмотр не мог разойтись с настоящим
        // запуском: любая правка логики экспорта/импорта сразу видна здесь.
        $dumpArgv = (new ExportDump())->buildArgs($cfg, $kind);
        $importArgv = array_merge($this->clientBase($cfg), ['--database=' . $cfg->db()['name']]);

        // Лаунчеры — то, как транспорт обернёт команды (например, wsl.exe bash -c
        // или ssh); показываем их отдельно от внутренних команд.
        $dumpLaunch = $t->launch($dumpArgv, $this->pwdEnv($cfg));
        $importLaunch = $t->launch($importArgv, $this->pwdEnv($cfg));

        // Каждый вывод проходит через Command::mask: пароли из MYSQL_PWD и
        // другие секреты конфигурации не должны попасть на экран/в лог.
        Menu::line('mysqldump (inner command, kind=' . $kind . '):');
        Menu::line('  ' . Command::mask(Command::bashString($dumpArgv, $this->pwdEnv($cfg)), $secrets));
        Menu::line('mysql import (inner command, SQL on stdin):');
        Menu::line('  ' . Command::mask(Command::bashString($importArgv, $this->pwdEnv($cfg)), $secrets));
        Menu::line();
        Menu::line('launcher (dump): ' . Command::mask(Command::display($dumpLaunch['cmd']), $secrets));
        Menu::line('launcher (import): ' . Command::mask(Command::display($importLaunch['cmd']), $secrets));
        Menu::line('Password is passed via MYSQL_PWD env, never in argv.');
        return 0;
    }
}
