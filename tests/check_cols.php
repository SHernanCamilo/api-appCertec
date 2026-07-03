<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
echo implode(', ', Illuminate\Support\Facades\Schema::getColumnListing('inv_ordenes_compra'));
echo "\n";
