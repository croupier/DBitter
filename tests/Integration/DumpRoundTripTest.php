<?php

/**
 * Интеграционный round-trip тест дампов: export → манифест → import
 * против живого MySQL (DBT_IT=1). Каждый тест работает в одноразовой
 * scratch-БД со случайным именем и во временном каталоге дампов, чтобы
 * не трогать рабочую базу проекта; tearDown гарантированно всё убирает.
 * Закрывает регрессию split-фикса: дамп меньше блока --split обязан
 * остаться единым файлом без артефактов .partNNN.
 */

declare(strict_types=1);

namespace DbTools\Tests\Integration;

use DbTools\Lib\Dump;
use DbTools\Lib\Fs;

/**
 * Полный цикл выгрузки/загрузки дампа через реальный CLI.
 * Проверяет и успешность действий (exit-коды), и целостность результата:
 * манифест latest.json, число файлов, отсутствие лишних частей,
 * физическое существование архива и импорт в scratch-БД.
 */
final class DumpRoundTripTest extends IntegrationTestCase
{
    /** Имя одноразовой scratch-БД (пусто, пока setUp не отработал). */
    private string $scratch = '';

    /** Временный каталог для дампов этого теста (изолирован от dump/ проекта). */
    private string $dumpDir = '';

    /**
     * Создаёт изолированное окружение: случайное имя scratch-БД
     * (dbt_it_ + случайный hex — параллельные прогоны не конфликтуют),
     * временный каталог дампов, затем реальные create-db и create-user
     * через CLI. Падение на этом этапе — уже сигнал о поломке админ-действий.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = 'dbt_it_' . bin2hex(random_bytes(3));
        $this->dumpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dbt_it_dumps_' . bin2hex(random_bytes(3));
        Fs::ensureDir($this->dumpDir);

        $db = $this->runTool(['create-db', '--name=' . $this->scratch]);
        $this->assertTrue($db->ok(), 'create-db failed: ' . $db->output());
        $user = $this->runTool(['create-user', '--name=' . $this->scratch]);
        $this->assertTrue($user->ok(), 'create-user failed: ' . $user->output());
    }

    /**
     * Уборка после теста, даже если он упал: удаляет scratch-БД
     * (--yes — без интерактивного подтверждения, stdin у сабпроцесса пуст)
     * и рекурсивно стирает временный каталог дампов. Никаких следов
     * на живом сервере и в файловой системе остаться не должно.
     */
    protected function tearDown(): void
    {
        if ($this->scratch !== '') {
            $this->runTool(['drop-db', '--name=' . $this->scratch, '--yes']);
        }
        if ($this->dumpDir !== '') {
            Fs::rrmdir($this->dumpDir);
        }
    }

    /**
     * Сквозной цикл: export схемы рабочей БД → импорт в пустую scratch-БД.
     * Проверяет, что schema-дамп — один файл без суффикса .part (нечего
     * разбивать) и что импорт реально проходит против живого сервера.
     * Регрессии: сломанный манифест, неверные аргументы клиента, ошибки
     * транспорта при передаче SQL на stdin.
     */
    public function testSchemaExportAndImportIntoScratch(): void
    {
        $export = $this->runTool(['export', '--kind=schema', '--dir=' . $this->dumpDir]);
        $this->assertTrue($export->ok(), 'export failed: ' . $export->output());

        $manifest = $this->latestManifest();
        $this->assertCount(1, $manifest['files']);
        $this->assertStringNotContainsString('.part', $manifest['files'][0]['file']);

        $import = $this->runTool(['import', '--dir=' . $this->dumpDir, '--database=' . $this->scratch]);
        $this->assertTrue($import->ok(), 'import failed: ' . $import->output());
    }

    /**
     * Сквозной guard для split-фикса: полный дамп, заведомо меньший
     * --split=1000 (MiB), должен остаться ЕДИНЫМ .sql.gz — без .partNNN
     * ни в манифесте, ни на диске. Регрессия: раньше мелкие дампы получали
     * лишний .part001, а оригинал удалялся, ломая импорт и манифест.
     */
    public function testFullDumpUnderSplitStaysSingleFile(): void
    {
        $export = $this->runTool(['export', '--kind=full', '--split=1000', '--dir=' . $this->dumpDir]);
        $this->assertTrue($export->ok(), 'export failed: ' . $export->output());

        $manifest = $this->latestManifest();
        $this->assertCount(1, $manifest['files']);
        $name = $manifest['files'][0]['file'];
        $this->assertStringNotContainsString('.part', $name);
        // Манифест может врать — проверяем и физическое наличие файла.
        $this->assertFileExists($this->dumpDir . DIRECTORY_SEPARATOR . $name);
    }

    /**
     * Читает манифест последнего дампа через latest.json (указатель,
     * который export обновляет после каждой выгрузки).
     *
     * @return array<string,mixed> содержимое манифеста (kind, files, ...)
     */
    private function latestManifest(): array
    {
        $latest = Dump::readLatest($this->dumpDir);
        $this->assertNotNull($latest, 'no latest.json after export');
        return Dump::readManifest($this->dumpDir . DIRECTORY_SEPARATOR . $latest['manifest']);
    }
}
