<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// These tests never touch the real database: they point the app at a
// temporary SQLite file and write backups to a temporary folder.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00', 'Asia/Bangkok'));
    $this->backupDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'expense-backup-test-'.bin2hex(random_bytes(6));
    $this->sourceFile = null;
    $this->originalConnection = config('database.default');
});

afterEach(function () {
    // Put the default connection back first, so the test framework rolls
    // back the right (in-memory) database.
    config(['database.default' => $this->originalConnection]);
    DB::disconnect('filedb');
    File::deleteDirectory($this->backupDir);

    if ($this->sourceFile !== null) {
        @unlink($this->sourceFile);
    }
});

function useTemporaryFileDatabase(object $test, int $rows = 3): string
{
    $path = tempnam(sys_get_temp_dir(), 'expense-src-');
    $test->sourceFile = $path;

    config([
        'database.connections.filedb' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true],
        'database.default' => 'filedb',
    ]);
    DB::purge('filedb');

    Schema::connection('filedb')->create('items', function (Blueprint $table) {
        $table->id();
        $table->string('label');
    });
    Schema::connection('filedb')->create('notes', function (Blueprint $table) {
        $table->id();
        $table->text('body');
    });

    for ($i = 1; $i <= $rows; $i++) {
        DB::connection('filedb')->table('items')->insert(['label' => "รายการ {$i}"]);
    }
    DB::connection('filedb')->table('notes')->insert(['body' => 'หมายเหตุ']);

    return $path;
}

function backupFiles(string $dir): array
{
    return is_dir($dir) ? glob($dir.DIRECTORY_SEPARATOR.'database-*.sqlite') : [];
}

test('it makes a verified copy and leaves the source database untouched', function () {
    $source = useTemporaryFileDatabase($this, rows: 3);
    $before = hash_file('sha256', $source);

    $this->artisan('app:backup-database', ['--dir' => $this->backupDir])
        ->expectsOutputToContain('ตรวจสอบไฟล์สำรองผ่านแล้ว')
        ->assertSuccessful();

    $files = backupFiles($this->backupDir);
    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->toBe('database-20261115-100000.sqlite');

    $copy = new PDO('sqlite:'.$files[0]);
    expect($copy->query('select count(*) from items')->fetchColumn())->toBe(3)
        ->and($copy->query('select count(*) from notes')->fetchColumn())->toBe(1)
        ->and($copy->query('PRAGMA integrity_check')->fetchColumn())->toBe('ok');
    unset($copy);

    expect(DB::connection('filedb')->table('items')->count())->toBe(3)
        ->and(hash_file('sha256', $source))->toBe($before);
});

test('it never overwrites an existing backup', function () {
    useTemporaryFileDatabase($this);

    $this->artisan('app:backup-database', ['--dir' => $this->backupDir])->assertSuccessful();
    $file = backupFiles($this->backupDir)[0];
    $hash = hash_file('sha256', $file);

    DB::connection('filedb')->table('items')->insert(['label' => 'เพิ่มภายหลัง']);

    // Same second, same file name: it must refuse.
    $this->artisan('app:backup-database', ['--dir' => $this->backupDir])
        ->expectsOutputToContain('ไม่เขียนทับ')
        ->assertFailed();

    expect(backupFiles($this->backupDir))->toBe([$file])
        ->and(hash_file('sha256', $file))->toBe($hash);
});

test('it refuses a temporary in-memory database and writes nothing', function () {
    $this->artisan('app:backup-database', ['--dir' => $this->backupDir])
        ->expectsOutputToContain('ไม่พบไฟล์ฐานข้อมูล SQLite')
        ->assertFailed();

    expect(backupFiles($this->backupDir))->toBe([])
        ->and(is_dir($this->backupDir))->toBeFalse();
});

test('it refuses any database that is not SQLite', function () {
    config([
        'database.connections.pretend' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'x', 'username' => 'x', 'password' => ''],
        'database.default' => 'pretend',
    ]);
    DB::purge('pretend');

    $this->artisan('app:backup-database', ['--dir' => $this->backupDir])
        ->expectsOutputToContain('รองรับเฉพาะฐานข้อมูล SQLite')
        ->assertFailed();

    expect(backupFiles($this->backupDir))->toBe([]);
});
