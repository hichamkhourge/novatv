<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:ensure-no-pending-migrations', function () {
    /** @var \Illuminate\Database\Migrations\Migrator $migrator */
    $migrator = app('migrator');
    /** @var \Illuminate\Database\Migrations\DatabaseMigrationRepository $repository */
    $repository = app('migration.repository');

    if (! $repository->repositoryExists()) {
        $this->error('Migration repository does not exist.');

        return 1;
    }

    $migrationPaths = array_unique([
        database_path('migrations'),
        ...$migrator->paths(),
    ]);

    $pending = collect($migrator->getMigrationFiles($migrationPaths))
        ->filter(fn (string $file): bool => ! in_array($migrator->getMigrationName($file), $repository->getRan(), true))
        ->values()
        ->all();

    if ($pending === []) {
        $this->info('No pending migrations.');

        return 0;
    }

    $this->error('Pending migrations detected:');

    foreach ($pending as $file) {
        $this->line(' - ' . Str::afterLast($file, DIRECTORY_SEPARATOR));
    }

    return 1;
})->purpose('Fail when the current database has pending migrations');
