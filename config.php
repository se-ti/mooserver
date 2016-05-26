<?
if (!defined('IN_MOOSE'))
    exit;

$mooSett = array(
    "base" => "dbase",
    "host" => "localhost",
    "user" => "user",
    "pwd" => "pwd",
    "charset" => "utf8",                // charset подключения у БД

    "timezone" => "Europe/Minsk",       // timezone according to http://php.net/manual/en/timezones.php

    "appName" => "Mooserver",
    "name" => "moosemaster",
    "mail" => "info@moosemaster",          // mail from
    "defaultMail" => "info@moosemaster",   // mail to

    "cookie" => "MOO_COOKIE",

    "blockAfter" => 4,                  //
    "blockTimeout" => 600,              // seconds,  60 -- гуманненько

    "minLogLevel" => 0 // мин уровень сообщений, попадающих в логи 0 - info, 1 - trace, 2 - debug, 3 - error, 4 - critical
);

$tinySett = $mooSett;
?>