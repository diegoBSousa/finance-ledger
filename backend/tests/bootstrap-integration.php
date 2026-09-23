<?php

declare(strict_types=1);

use Tests\Integration\Support\MysqlDatabase;

require dirname(__DIR__).'/vendor/autoload.php';

MysqlDatabase::migrate();
