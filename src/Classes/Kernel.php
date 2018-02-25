<?php

namespace App\Classes;

class Kernel {

    public static function classLoader($class) {
        $class = strtr($class, [
            'App' => 'src',
            '\\' => DIRECTORY_SEPARATOR
        ]);

        $path = ROOT . DIRECTORY_SEPARATOR . $class . '.php';

        if (is_file($path)) {
            include_once($path);
        }
    }
}