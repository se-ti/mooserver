<?php
/**
 * Created by Alexander Purikov for mooServer project
 * 2014 - 2015
 */
define('IN_MOOSE', true);

require_once "config.php";
require_once "php/auth.php";
require_once "php/dbase.php";
require_once "php/moosesms.php";
require_once "php/common.php";
require_once "php/excel/XLSXReader.php";
require_once "php/excel/reader.php";

global $auth;
global $db;

$db = new CMooseDb();
$auth = new CMooseAuth($db);
$test = @$_POST['commit'] !== 'commit';

$errcode = $_FILES['import']['error'][0];   // check all errors!
if ($errcode != 0)
{
    if ($errcode == UPLOAD_ERR_NO_FILE && $auth->isSuper())
        die(json_encode(uploadPlts($db, $auth, $test)));

    dieError("Error: $errcode Message: " . CMooseTools::uploadErrors($errcode));
}

$names = $_FILES['import']['tmp_name'];
$upload = $_FILES['import']['name'];
if (!is_array($names))
{
    $names = [$names];
    $upload = [$upload];
}

$res = [];
$i = 0;
if (count($names) > 1)
{
    if ('plt' != strtolower(pathinfo($upload[0], PATHINFO_EXTENSION)))
        dieError("Импорт нескольких файлов пока не поддерживается"); // чтобы не склеивать результаты

    $set = [];
    foreach ($names as $name)
    {
        $set[$name] = $upload[$i];
        $i++;
    }

    $res = uploadPlts($db, $auth, $test, $set);
}
else
    foreach ($names as $name)
    {
        $isPlt = 'plt' == strtolower(pathinfo($upload[$i], PATHINFO_EXTENSION));
        $res = $isPlt ? uploadPlts($db, $auth, $test, [$name => $upload[$i]]) : parseFile($name, $upload[$i], $test);
        $i++;
    }

echo json_encode($res);
return;                     // that's all !

function uploadPlts(CMooseDb $db, CMooseAuth $auth, $test, array $set = null)
{
    $defaultPath = './data/current/';
    $phone = '+7-000-212-85-06';
    $moose = 'Лимпа';

    if ($set == null)
        $set = [];

    $res = [
        'ok' => true,
        'log' => [],
        'error' => null,
        'status' => null
    ];

    $proc = [];
    $archive = [];
    $testMark = $test ? ' - test' : '';
    try
    {
        $db->beginTran();
        foreach ($set as $path => $v)
        {
            if (is_int($path))
                $path = $defaultPath . $v;

            $cur = CScheduler::uploadPlt($db, $auth, $path, $v, $phone, $moose);
            $warn = $cur['warn'];
            if ($warn != null && count($warn) > 0)
            {
                Log::st($auth, "import plt", "$v$testMark\n" . implode("\n", $warn));
                $warn[0] = (count($res['log']) > 0 ? "\n" : '') . "файл: $v\n" . $warn[0];
                $res['log'] = array_merge($res['log'], $warn);
            }

            if ($cur['min'] != null)
                foreach ($archive as $a)
                    if ($a['min'] != null && $a['min'] < $cur['max'] && $a['max'] > $cur['min'])
                    {
                        $res['log'][] = $msg = "треки '{$a['name']}' и '{$cur['name']}' пересекаются во времени";
                        Log::st($auth, "import plt", $msg);
                    }

            $archive[] = $cur;
            $proc[] = $v;
        }

        if ($test)
            $db->rollback();    // фигня откатывается всё, что уехало в лог :(
        else
            $db->commit();

        Log::t($db, $auth, "import plt",  ($test ? 'Тест импорта': 'Импорт'  ). ' из ' . implode(', ', $proc) . "\nПредупреждений: " . count($res['log']));
    }
    catch (Exception $e)
    {
        $db->rollback();

        Log::e($db, $auth, "import plt", $e->getMessage());
        $res['ok'] = false;
        $res['error'] = $e->getMessage();
        return $res;
    }

    $res['status'] = implode(', ', $proc);
    return $res;
}

function parseFile($name, $uploadName, $test)
{
	global $auth, $db;
	$Log = [];
    $result = [
        'ok' => false,
        'log' => [],
        'error' => null,
        'status' => null
    ];
	
	if (!$auth->canAdmin() && !$auth->canFeed())
        dieError("You have no rights to import csv files.");

    $db->beginTran();

	$ext = strtolower(pathinfo($uploadName, PATHINFO_EXTENSION));
    if ('xls' == $ext)
        $SuccessCounter = parseXls($db, $auth, $name, $Log);
	else if ('xlsx' == $ext)
        $SuccessCounter = parseXlsX($db, $auth, $name, $Log);
	else
        $SuccessCounter = parseCSV($db, $auth, $name, $Log);

    if ($test)
        $db->rollback();    // фигня откатывается всё, что уехало в лог :(
    else
        $db->commit();

    if ($SuccessCounter === false)
    {
        $result['status'] = $result['error'] = "Error opening file '$name'";
        return $result;
    }



	/*
	$result['log'][] = 'Strings '. PackStringList ( array ( 1,3,5 ));
	$result['log'][] = 'Strings '. PackStringList ( array ( 1,2,3,5 ));
	$result['log'][] = 'Strings '. PackStringList ( array ( 2,4,5,7 ));
	$result['log'][] = 'Strings '. PackStringList ( array ( 2,4,5,6,8 ));
	$result['log'][] = 'Strings '. PackStringList ( array ( 1,3,4,5 ));
	*/
	
	foreach ($Log as $ErrorText => $StringList)
		$result['log'][] = 'Lines '. PackStringList($StringList). ': '. $ErrorText;

    $msg = ($test ? 'Тест: ' : '') . "Успешно импортировано $SuccessCounter строк из файла '$uploadName'";
    $result['status'] = $msg;
    $result['ok'] = true;
    Log::t($db, $auth, "import", "$msg. Ошибок: " . count($Log));

    return $result;
}

function parseCSV(CMooseDb $db, CMooseAuth $auth, $name, array $log)
{
    $putdata = fopen($name, "r");
    if ($putdata === false)
    {
        Log::e($db, $auth, "import", "Can't open file: '$name'");
        return false;
    }

    $SuccessCounter = 0;
    for ($line = 1; $StringArray = fgetcsv($putdata, 300, ';'); $line++)
        if (ProcessCSVRow($db, $auth, $StringArray, $line, $log))
            $SuccessCounter++;

    fclose($putdata);

    return $SuccessCounter;
}

function parseXlsX(CMooseDb $db, CMooseAuth $auth, $name, array $log)
{
    $xlsx = new XLSXReader($name);
    if ($xlsx->getSheetCount() < 1)
        return false;

    $res = [ 'cn' => $xlsx->getSheetCount(),
        'names' => $xlsx->getSheetNames() ];

    $parsed = 0;
    $data = $xlsx->getSheetData($res['names'][1]);
    $cn = count($data);
    for ($i = 0; $i < $cn; $i++)
        if (ProcessCSVRow($db, $auth, $data[$i], $i, $log))
            $parsed++;

    return $parsed;
}

function parseXls(CMooseDb $db, CMooseAuth $auth, $name, array $log)
{
    $xls = new Spreadsheet_Excel_Reader($name);

    // Set output Encoding.
    //$xls->setOutputEncoding('CP1251');
    $rc = $xls->rowcount($sheet_index=0);
    $cc = $xls->colcount($sheet_index=0);

    $parsed = 0;
    for ($i = 1; $i <= $rc; $i++)
    {
        $row = [];
        $has = false;
        for ($j = 1; $j <= $cc; $j++) {
            $v = $xls->val($i, $j);
            $has = $has || ($v != '');
            $row[] = $v;
        }

        if ($i == 0)
            Log::st($auth, 'import', print_r($row, 1));

        if ($has && ProcessCSVRow($db, $auth, $row, $i, $log))
            $parsed++;
    }

    return $parsed;
}

// expects format
/*
 * 2 - phone
 * 3 - nickname
 * 5 - time
 * 7 - sms text
 * */
function ProcessCSVRow(CMooseDb $db, CMooseAuth $auth, array $data, $line, array $log)
{
    if (!array_key_exists( '2', $data) || !array_key_exists('5', $data) || !array_key_exists('7', $data))
    {
        $log['Skipped'][] = $line;
        return false;
    }

    if (!mb_check_encoding($data[2], 'UTF-8') || !mb_check_encoding($data[3], 'UTF-8') || !mb_check_encoding ($data[5], 'UTF-8') || !mb_check_encoding ($data[7], 'UTF-8'))
    {
        $log['Wrong encoding -- use UTF-8!'][] = $line;
        return false;
    }

    $timeString = str_replace('.', '-', $data[5]);
    $SmsTime = strtotime($timeString); // Change '.' to '-' to make strtotime think date is in american notation
    if ($SmsTime === false)
    {
        $log["Can't parse time"][] = $line;
        Log::e($db, $auth, "import", "Can't parse time: '$timeString', line: $line");
        return false;
    }

    $msg = CMooseSMS::CreateFromText($data[7], $SmsTime);
    if (!$msg->IsValid())
    {
        $log ['SMS Error: '.$msg->GetErrorMessage()][] = $line;
        return false;
    }

    try
    {
        $Moose = $data[3];
        /*if ( $Moose != '' )
            $Log [ 'Moose name: '. $Moose][] = $StringCounter;
        else
            $Log [ 'Moose name empty'][] = $StringCounter;*/
        if ($Moose == '')
            $Moose = null;

        $DBResult = $db->AddData($auth, $data[2]/*$from*/, $msg, $Moose);

        //$DBResult = $db->AddData($auth, $StringArray[2]/*$from*/, $msg, 'Moose5');
    }
    catch (Exception $e)
    {
        $log[$e->getMessage()][] = $line;
        Log::se($auth, "import", "line $line: " . $e->getMessage());
        return false;
    }
    //$out .= $DBResult['error'];
    //	$out .= ";";
    //$out .= "$StringArray[0]; $StringArray[2]; ";
    //$out .= str_replace ( '.', '-', $StringArray[5]);
    //$out .= ";";
    //$out .= date ( DATE_RFC2822, $SmsTime );
    //$out .= ";";
    //$out .= $StringArray[7];
    //$out .= ": ok$CR";
    return true;
}

function PackStringList ($StringListArray)
{
	$PreviousStringNumber = -9;
	$StringInSequence = 0;
	$Result = $StringListArray[0];

	foreach ($StringListArray as $StringNumber)
	{
		if ($StringInSequence == 0)
		{
			$PreviousStringNumber = $StringNumber;
			$StringInSequence = 1;
		    continue;
		}
			
		if ($StringNumber != $PreviousStringNumber + 1)
		{
			if ($StringInSequence == 2)
				$Result .= ', ' . $PreviousStringNumber;
			else if ($StringInSequence > 2)
				$Result .= '-'.$PreviousStringNumber;

			$Result .= ', '.$StringNumber;
			
			$StringInSequence = 1;
		}
		else
		{
			$StringInSequence++;
		}	
		
		$PreviousStringNumber = $StringNumber;
	}

	if ($StringInSequence == 2)
		$Result .= ', ' . $PreviousStringNumber;
	else if ($StringInSequence > 2)
		$Result .= '-' . $PreviousStringNumber;

	return $Result;
}

function dieError($msg)
{
    die (json_encode(['error' => $msg]));
}
?>