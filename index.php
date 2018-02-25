<?php

define('ROOT', realpath(__DIR__ ));

spl_autoload_register('\App\Core\Kernel::classLoader');