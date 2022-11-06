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

global $auth;
global $db;

$db = new CMooseDb();
$auth = new CMooseAuth($db);

$errcode = $_FILES['import']['error'][0];   // check all errors!
if ($errcode != 0)
{
    if ($errcode == UPLOAD_ERR_NO_FILE && $auth->isSuper())
        die(json_encode(uploadPlts($db, $auth)));

    dieError("Error: $errcode Message: " . CMooseTools::uploadErrors($errcode));
}

$names = $_FILES['import']['tmp_name'];
$upload = $_FILES['import']['name'];


if (!is_array($names))
{
    $names = [$names];
    $upload = [$upload];
}

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

    $res = uploadPlts($db, $auth, $set);
}
else
    foreach ($names as $name)
    {
        $isPlt = 'plt' == strtolower(pathinfo($upload[$i], PATHINFO_EXTENSION));
        $res = $isPlt ? uploadPlts($db, $auth, [$name => $upload[0]]) : parseFile($name, $upload[$i], $i);
        $i++;
    }

echo json_encode($res);
return;                     // that's all !


function uploadPlts(CMooseDb $db, CMooseAuth $auth, array $set = null)
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
    try
    {
        foreach ($set as $path => $v)
        {
            if (is_int($path))
                $path = $defaultPath . $v;

            $warn = CScheduler::uploadPlt($db, $auth, $path, $v, $phone, $moose);
            if ($warn != null && count($warn) > 0)
            {
                Log::t($db, $auth, "import plt", "$v\n" . implode("\n", $warn));
                $warn[0] = (count($res['log']) > 0 ? "\n" : '') . "файл: $v\n" . $warn[0];
                $res['log'] = array_merge($res['log'], $warn);
            }
            $proc[] = $v;
        }
    }
    catch (Exception $e)
    {
        $db->rollback();        // todo фигня :( -- addSMS уже закоммитила всё, что могла

        Log::e($db, $auth, "import plt", $e->getMessage());
        $res['ok'] = false;
        $res['error'] = $e->getMessage();
        return $res;
    }

    $res['status'] = implode(', ', $proc);
    return $res;
}

function parseFile($name, $uploadName)
{
	global $auth, $db;
	$SuccessCounter = 0;

	$Log = [];
	
    $result = [
        'ok' => false,
        'log' => [],
        'error' => null,
        'status' => null
    ];
	
	if (!$auth->canAdmin() && !$auth->canFeed())
        dieError("You have no rights to import csv files.");

    $putdata = fopen($name, "r");
    if ($putdata === false) {
        Log::e($db, $auth, "import", "Can't open file: '$name'");
        $result['status'] = $result['error'] = "Error opening file '$name'";

        return $result;
    }

    for ( $StringCounter = 1; $StringArray = fgetcsv ( $putdata, 300, ';' ); $StringCounter++)
		{
		if ( !array_key_exists( '2', $StringArray ) || !array_key_exists( '5', $StringArray ) || !array_key_exists( '7', $StringArray ) )
			{
			$Log ['Skipped'][]=$StringCounter;
			continue;
			}

		if ( !mb_check_encoding ( $StringArray[2], 'UTF-8') || !mb_check_encoding ( $StringArray[3], 'UTF-8') || !mb_check_encoding ( $StringArray[5], 'UTF-8') || !mb_check_encoding ( $StringArray[7], 'UTF-8'))
		{
			$Log [ 'Wrong encoding -- use UTF-8!' ][] = $StringCounter;
			continue;
		}			
			
        $timeString = str_replace('.', '-', $StringArray[5]);
		$SmsTime = strtotime($timeString); // Change '.' to '-' to make strtotime think date is in american notation
		
		if ( $SmsTime === false )
			{
				$Log ["Can't parse time"][] = $StringCounter;
                Log::e($db, $auth, "import", "Can't parse time: '$timeString'");
			continue;
			}

		$msg = CMooseSMS::CreateFromText( $StringArray[7], $SmsTime );
		if ( ! $msg->IsValid () )
			{
				$Log [ 'SMS Error: '.$msg->GetErrorMessage() ][] = $StringCounter;
			continue;
			}
		
		try
			{
			$Moose = $StringArray[3];
			/*if ( $Moose != '' )
			    $Log [ 'Moose name: '. $Moose][] = $StringCounter;
			else 
				$Log [ 'Moose name empty'][] = $StringCounter;*/
			if ( $Moose == '' )
                $Moose = null;
            $DBResult = $db->AddData($auth, $StringArray[2]/*$from*/, $msg, $Moose);

			//$DBResult = $db->AddData($auth, $StringArray[2]/*$from*/, $msg, 'Moose5');
			}
		catch (Exception $e )
			{
				$Log [ $e->getMessage() ][] = $StringCounter;
                Log::e($db, $auth, "import", $e->getMessage());
			continue;
			}
		//$out .= $DBResult['error'];
		//	$out .= ";";
		//$out .= "$StringArray[0]; $StringArray[2]; ";
		//$out .= str_replace ( '.', '-', $StringArray[5]);
		//$out .= ";";
		//$out .= date ( DATE_RFC2822, $SmsTime );
		//$out .= ";";
		//$out .= $StringArray[7];
		$SuccessCounter++;
		//$out .= ": ok$CR";
		}

    fclose($putdata);

    $res = unlink($name);
    if ($res == false)
        $result['error'] = "Error unlink file '$name'";
    else
        $result['ok'] = true;

	/*
	$result['log'][] = 'Strings '. PackStringList ( array ( 1,3,5 ));
	$result['log'][] = 'Strings '. PackStringList ( array ( 1,2,3,5 ));
	$result['log'][] = 'Strings '. PackStringList ( array ( 2,4,5,7 ));
	$result['log'][] = 'Strings '. PackStringList ( array ( 2,4,5,6,8 ));
	$result['log'][] = 'Strings '. PackStringList ( array ( 1,3,4,5 ));
	*/
	
	foreach ( $Log as $ErrorText => $StringList )
		{
		$result['log'][] = 'Lines '. PackStringList ( $StringList ). ': '. $ErrorText;
		}

    $msg = "Успешно импортировано $SuccessCounter строк из файла '$uploadName'";
    $result['status'] = $msg;
    Log::t($db, $auth, "import", "$msg. Ошибок: " . count($Log));

    return $result;
}

function PackStringList ( $StringListArray )
{
	$PreviousStringNumber = -9;
	$StringInSequence = 0;
	$Result = $StringListArray[0];

	foreach ( $StringListArray as $StringNumber )
	{
		if ( $StringInSequence == 0)
		{
			$PreviousStringNumber = $StringNumber;
			$StringInSequence = 1;
		continue;
		}
			
		if ( $StringNumber != $PreviousStringNumber + 1)
		{
			if ( $StringInSequence == 2 )
			{
				$Result.=', ';
				$Result.=$PreviousStringNumber;
			}
			elseif ( $StringInSequence > 2 )
			{
				$Result.='-';
				$Result.=$PreviousStringNumber;
			}
			
			$Result.=', ';	
			$Result.=$StringNumber;	
			
			$StringInSequence = 1;
		}
		else
		{
			$StringInSequence++;
		}	
		
		$PreviousStringNumber = $StringNumber;
	}
	if ( $StringInSequence == 2 )
		{
		$Result.=', ';
		$Result.=$PreviousStringNumber;
		}
	elseif ( $StringInSequence > 2 )
		{
		$Result.='-';
		$Result.=$PreviousStringNumber;
		}
	return $Result;
}

function dieError($msg)
{
    die (json_encode(['error' => $msg]));
}
?>