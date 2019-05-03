<?php
/**
 * Created by PhpStorm.
 * User: Serge
 * Date: 10.01.2017
 * Time: 10:53
 */
if (!defined('IN_MOOSE'))
    exit;

//CMooService::listFolder($db, $auth, './data/', ['txt']);
class CMooService
{
    public static function listFolder($db, $auth, $folder, $allowedExts)
    {
        $file_parts = [];
        $ext = '';

        $l = strlen($folder);
        if ($l == 0  || substr($folder, $l-1, 1) != '/')
            $folder .= '/';

        //пробуем открыть папку
        $dir_handle = @opendir($folder) or die("There is an error with your directory! '$folder'");
        while ($file = readdir($dir_handle))	//поиск по файлам
        {
            if ($file == '.' || $file == '..')
                continue;	//пропустить ссылки на другие папки

            $file_parts = explode('.', $file);	//разделить имя файла и поместить его в массив
            $ext = strtolower(array_pop($file_parts));	//последний элеменет - это расширение
            $name = array_shift($file_parts);
            if (in_array($ext, $allowedExts))
                self::filter($folder.$file, $folder.$name . ".csv");
        }

        closedir($dir_handle);	//закрыть папку
    }

    // https://ask.libreoffice.org/en/question/1671/is-there-a-command-line-tool-to-convert-documents-to-plain-text-files/
    // https://ask.libreoffice.org/en/question/2641/convert-to-command-line-parameter/
    private static function filter($in, $out)
    {
        $fin = fopen($in, "rt");
        if ($fin == FALSE)
            die($in);

        $fout = fopen($out, "wt");
        if ($fout == FALSE)
            die($out);

        while (($line = fgets($fin)) !== false)
        {
            $m = preg_match('/[а-я]/ui', iconv('cp1251', 'utf8', $line));
            if (strpos($line, "sms;deliver;") === 0 || strpos($line, ";") === 0 || $m != false)
                fwrite($fout, ($m != false ? ';' : '') . $line);
        }

        fclose($fin);
        fclose($fout);
    }
}