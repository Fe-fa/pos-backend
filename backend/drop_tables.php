<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::statement('SET FOREIGN_KEY_CHECKS = 0');

$tables = DB::select('SHOW TABLES');

foreach ($tables as $table) {
    foreach ((array)$table as $tableName) {
        DB::statement("DROP TABLE IF EXISTS `$tableName`");
        echo "Dropped: $tableName\n";
    }
}

DB::statement('SET FOREIGN_KEY_CHECKS = 1');

echo "All tables dropped.\n";