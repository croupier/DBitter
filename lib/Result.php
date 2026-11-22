<?php

declare(strict_types=1);

namespace DbTools\Lib;

/**
 * Неизменяемый результат выполнения внешней команды: код возврата,
 * захваченные stdout/stderr, текст команды для диагностики и длительность.
 * Создаётся в Command::capture и Process::finish; проверяется вызывающим
 * кодом (транспорты, действия) через ok()/output().
 */
final class Result
{
    /**
     * @param int $code Код возврата процесса (0 — успех).
     * @param string $stdout Захваченный стандартный вывод.
     * @param string $stderr Захваченный вывод ошибок.
     * @param string $command Отображаемая командная строка (секреты замаскированы вызывающим).
     * @param float $elapsed Время выполнения в секундах (долях microtime).
     */
    public function __construct(
        public readonly int $code,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly string $command,
        public readonly float $elapsed = 0.0,
    ) {
    }

    /**
     * Успешно ли завершилась команда: код возврата строго равен 0.
     *
     * @return bool true при нулевом коде возврата.
     */
    public function ok(): bool
    {
        return $this->code === 0;
    }

    /**
     * Объединённый вывод для сообщений об ошибках и диагностики: stdout,
     * затем stderr (если непустой) через перевод строки, без краевых пробелов.
     *
     * @return string Объединённый вывод без краевых пробелов.
     */
    public function output(): string
    {
        return trim($this->stdout . ($this->stderr !== '' ? "\n" . $this->stderr : ''));
    }
}
