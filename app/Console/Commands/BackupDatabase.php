<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Makes a safe, verified copy of the SQLite database.
 *
 * Safety notes:
 * - Uses SQLite's own `VACUUM INTO`, which writes a consistent copy even
 *   while the app is running. The source database is only read.
 * - It never overwrites an existing backup and never deletes old ones.
 * - The copy is checked right away (integrity check + row count of every
 *   table compared with the source). A copy that fails the check is
 *   removed again so a broken file is never mistaken for a good backup.
 * - The default folder (storage/app/backups) is ignored by Git. Backups
 *   hold real company data and must never be committed.
 */
class BackupDatabase extends Command
{
    private const VERIFY_CONNECTION = 'backup_verify';

    /**
     * @var string
     */
    protected $signature = 'app:backup-database {--dir= : โฟลเดอร์ปลายทาง (ค่าเริ่มต้น storage/app/backups)}';

    /**
     * @var string
     */
    protected $description = 'สำรองฐานข้อมูล SQLite เป็นไฟล์ใหม่ พร้อมตรวจสอบความสมบูรณ์ของไฟล์สำรอง';

    public function handle(): int
    {
        $source = DB::connection();

        if ($source->getDriverName() !== 'sqlite') {
            $this->error('คำสั่งนี้รองรับเฉพาะฐานข้อมูล SQLite');

            return self::FAILURE;
        }

        $sourcePath = (string) $source->getDatabaseName();

        if ($sourcePath === '' || $sourcePath === ':memory:' || ! is_file($sourcePath)) {
            $this->error('ไม่พบไฟล์ฐานข้อมูล SQLite ที่จะสำรอง (ฐานข้อมูลอาจเป็นแบบชั่วคราวในหน่วยความจำ)');

            return self::FAILURE;
        }

        $dir = rtrim((string) ($this->option('dir') ?: storage_path('app/backups')), '/\\');

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error("สร้างโฟลเดอร์ปลายทางไม่ได้: {$dir}");

            return self::FAILURE;
        }

        $target = $dir.DIRECTORY_SEPARATOR.'database-'.now()->format('Ymd-His').'.sqlite';

        if (file_exists($target)) {
            $this->error("มีไฟล์สำรองชื่อนี้อยู่แล้ว ไม่เขียนทับ: {$target}");

            return self::FAILURE;
        }

        try {
            $source->statement('VACUUM INTO ?', [$target]);
        } catch (Throwable $e) {
            $this->discard($target);
            $this->error('สำรองฐานข้อมูลไม่สำเร็จ: '.$e->getMessage());

            return self::FAILURE;
        }

        $problem = $this->verify($source, $target);

        if ($problem !== null) {
            $this->discard($target);
            $this->error("ไฟล์สำรองไม่ผ่านการตรวจสอบ จึงลบไฟล์สำรองนี้ทิ้งแล้ว: {$problem}");

            return self::FAILURE;
        }

        @chmod($target, 0600);

        $this->info('สำรองฐานข้อมูลเรียบร้อย และตรวจสอบไฟล์สำรองผ่านแล้ว');
        $this->line('ไฟล์: '.$target);
        $this->line('ขนาด: '.number_format((int) filesize($target)).' ไบต์');
        $this->line('SHA-256: '.hash_file('sha256', $target));
        $this->line('ฐานข้อมูลจริงไม่ถูกแก้ไข และไฟล์สำรองนี้ห้ามนำเข้า Git');

        return self::SUCCESS;
    }

    /**
     * @return string|null what is wrong with the copy, or null when it is good
     */
    private function verify(Connection $source, string $target): ?string
    {
        config(['database.connections.'.self::VERIFY_CONNECTION => [
            'driver' => 'sqlite',
            'database' => $target,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        DB::purge(self::VERIFY_CONNECTION);

        try {
            $copy = DB::connection(self::VERIFY_CONNECTION);

            $integrity = array_map(fn (object $row) => (string) array_values((array) $row)[0], $copy->select('PRAGMA integrity_check'));

            if ($integrity !== ['ok']) {
                return 'integrity_check ไม่ผ่าน: '.implode('; ', $integrity);
            }

            $expected = $this->tableCounts($source);
            $actual = $this->tableCounts($copy);

            foreach ($expected as $table => $count) {
                if (($actual[$table] ?? null) !== $count) {
                    return "ตาราง {$table} มี {$count} แถวในต้นฉบับ แต่ในไฟล์สำรองมี ".($actual[$table] ?? 'ไม่พบตาราง').' แถว (ถ้ามีคนกำลังใช้งานระบบอยู่ ให้ลองสำรองใหม่อีกครั้ง)';
                }
            }

            if (array_diff_key($actual, $expected) !== []) {
                return 'ไฟล์สำรองมีตารางที่ไม่มีในต้นฉบับ';
            }

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        } finally {
            DB::purge(self::VERIFY_CONNECTION);
        }
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(Connection $connection): array
    {
        $counts = [];

        foreach ($connection->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name") as $row) {
            $name = (string) $row->name;
            $counts[$name] = $connection->table($name)->count();
        }

        return $counts;
    }

    private function discard(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
