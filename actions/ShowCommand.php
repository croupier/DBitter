<?php

declare(strict_types=1);

namespace DbTools\Action;

/**
 * show-command action: preview of the real mysql/mysqldump commands.
 *
 * Run: php start.php show-command [--kind=full|schema|data].
 * Prints the inner bash commands for dump/import and the transport launchers
 * (wsl.exe/ssh) with masked secrets. Executes nothing and connects to no
 * server — handy for debugging the configuration and the transport.
 */

use DbTools\Lib\Command;
use DbTools\Lib\Config;
use DbTools\Lib\Menu;

/**
 * Diagnostic action: shows which commands the export and import would run
 * under the current configuration, without executing them.
 */
final class ShowCommand extends Action
{
    /**
     * Machine action name for the CLI.
     *
     * @return string always 'show-command'
     */
    public function name(): string
    {
        return 'show-command';
    }

    /**
     * Title for the interactive menu/help.
     *
     * @return string description of the action
     */
    public function title(): string
    {
        return 'Show resolved mysql/mysqldump commands (masked)';
    }

    /**
     * Prints the dump and import commands: the inner one (bash inside the transport)
     * and the outer launcher, plus a reminder that the password goes via MYSQL_PWD.
     *
     * Side effects: output through Menu only; no client processes,
     * no database connections are started and no files are created.
     *
     * @param Config $cfg  project/environment configuration (the kind option affects the dump)
     * @param array  $opts CLI options (values are read via $cfg->opt)
     * @return int always 0 — a preview cannot "fail"
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
