<?php

declare(strict_types=1);

namespace DbTools\Transport;

use DbTools\Lib\Fs;

/**
 * Транспорт «ssh»: mysql/mysqldump выполняются на удалённом хосте через
 * SSH-клиент (по умолчанию ssh; бинарник настраивается, можно указать иной
 * клиент, например plink, или полный путь к ssh.exe). Команда доставляется
 * удалённой оболочкой через `bash -c`, env сворачивается в эту строку.
 */
final class Ssh extends Transport
{
    /**
     * Имя транспорта для логов и диагностики.
     *
     * @return string Всегда 'ssh'.
     */
    public function name(): string
    {
        return 'ssh';
    }

    /**
     * Строка назначения user@host для SSH-клиента.
     * Пользователь по умолчанию root; host обязателен — без него подключение
     * невозможно, поэтому сразу бросаем понятное исключение.
     *
     * @return string Назначение вида 'user@host'.
     * @throws \RuntimeException Если в конфиге транспорта не задан host.
     */
    private function destination(): string
    {
        $user = (string) ($this->tconfig['user'] ?? 'root');
        $host = (string) ($this->tconfig['host'] ?? '');
        if ($host === '') {
            throw new \RuntimeException('ssh transport needs "host" in config');
        }
        return $user . '@' . $host;
    }

    /**
     * Собирает argv SSH-клиента: `<ssh> -T -p <port> [-i key] [-o option ...] user@host`.
     *
     * -T запрещает выделение псевдо-TTY: запуск неинтерактивный, stdin
     * остаётся каналом для данных (SQL при импорте). Порт по умолчанию 22.
     * Ключ (-i) — для входа по ключу вместо пароля. Произвольные опции из
     * transport.options передаются как `-o <option>` (синтаксис OpenSSH):
     * так задают, например, StrictHostKeyChecking=no для первого подключения
     * к новому хосту без интерактивного подтверждения отпечатка.
     *
     * @return list<string> Аргументы ssh-клиента; bash-строка команды добавляется в launch().
     * @throws \RuntimeException Если не задан host (через destination()).
     */
    private function launcher(): array
    {
        $ssh = (string) ($this->tconfig['ssh'] ?? 'ssh');
        $cmd = [$ssh, '-T', '-p', (string) ($this->tconfig['port'] ?? 22)];
        if (!empty($this->tconfig['key'])) {
            $cmd[] = '-i';
            $cmd[] = (string) $this->tconfig['key'];
        }
        foreach ($this->tconfig['options'] ?? [] as $option) {
            $cmd[] = '-o';
            $cmd[] = (string) $option;
        }
        $cmd[] = $this->destination();
        return $cmd;
    }

    /**
     * Оборачивает команду целевой стороны в удалённый запуск через ssh.
     *
     * «Сворачивание пароля»: env (включая MYSQL_PWD) превращается в
     * присваивания KEY='value' внутри строки `bash -c` (viaBash). Пароль
     * не попадает ни в argv ssh, ни в аргументы mysql на удалённой стороне
     * и передаётся только внутри шифрованного SSH-канала; в логах строка
     * маскируется (Command::mask).
     *
     * @param list<string> $argv Аргументы команды на удалённом хосте.
     * @param array<string,string> $env Переменные окружения, сворачиваемые в удалённую bash-строку.
     * @return array{cmd:string|list<string>,env:array<string,string>} Массив-команда для proc_open; env пуст.
     * @throws \RuntimeException Если не задан host.
     */
    public function launch(array $argv, array $env = []): array
    {
        return $this->viaBash($this->launcher(), $argv, $env);
    }

    /**
     * PDO из локального PHP не используется: сервер находится на удалённом
     * хосте и может быть закрыт firewall'ом. SQL-действия выполняются через
     * launch() и бинарник mysql на удалённой стороне.
     *
     * @return bool Всегда false.
     */
    public function pdoReachable(): bool
    {
        return false;
    }

    /**
     * Диагностика: наличие SSH-клиента локально, затем probe `mysql --version`
     * на удалённом хосте — он же проверяет связность, аутентификацию и
     * доступность mysql, то есть весь путь реальной команды.
     *
     * @return array{ok:bool,info:string} ok — транспорт готов; info — версия mysql или текст ошибки.
     */
    public function doctor(): array
    {
        if (!Fs::hasBinary((string) ($this->tconfig['ssh'] ?? 'ssh'))) {
            return ['ok' => false, 'info' => 'ssh binary not found'];
        }
        $probe = $this->versionProbe($this->mysqlBin());
        return ['ok' => $probe['ok'], 'info' => $probe['info']];
    }
}
