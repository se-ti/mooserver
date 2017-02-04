<?
/**
 * Created by Alexander Purikov for mooServer project
 * 2014 - 2015
 */
define('IN_MOOSE', true);
define('TINY_API_LOGIN', true);

header("Content-Type: text/html html; charset=UTF-8");

require_once "config.php";
require_once "php/auth.php";
require_once "php/dbase.php";
require_once "php/moosesms.php";
require_once "php/common.php";

global $auth;
global $db;

$db = new CMooseDb();
$auth = new CMooseAuth($db);

try
{
    sms();
    makeResponse(true);
    
}
catch(Exception $e)
{
	makeResponse(false, $e->getMessage());
    Log::e($db, $auth, "gate", $e->getMessage());
}

CScheduler::safeRun($db, $auth);
exit;
// that's all!


function makeResponse($success, $message = null)
{
	$r = array('payload' => 
		array('success' => $success,
			'error' => $message));

	echo json_encode($r);
}

function sms2()  // todo add filtering
{
    global $db, $auth;
	
	  $f = fopen("log.log", "a+");
    if ($f === false)
        throw new Exception("Error opening!");

    //fprintf($f, "%s, %s, %s, %s, %s, %s, %s\n", $gate, $from, $time, $stime, $timeNow, $stn, $body);
    
	
	foreach($_POST as $query_string_variable => $value)
		{
		fprintf($f, "$query_string_variable  = $value, ");
   //echo "$query_string_variable  = $value <Br />";
		}
	fprintf($f, "\n");
	fclose($f);
}

function sms()  // todo add filtering
{
    global $db, $auth;

	$device_id = $_POST['device_id'];
    $secret = $_POST['secret'];
    $body = @$_POST['message'];    // !!! add no body for not set
    $from = @$_POST['from'];
    $time = @$_POST['sent_timestamp'];
    //$tn = @$_GET['t2'];
    //$sTime = @$_GET['s'];
    //$stn = @$_GET['s2'];

    //if (@$_GET['log'] != null)
		
		//rawlog ( $_POST);
        mylog($device_id, $body, $from, $time, '' );

	$time = filter_var ( $time, FILTER_VALIDATE_INT,
		array('options' => array('min_range' => 946684810, 'max_range' => 4102444810)));
    
	if ( is_null ( $device_id ) || !is_string ( $device_id ) )
		throw new Exception("Invalid sms() 'device_id' argument\n");
	
	if ( is_null ( $secret ) || !is_string ( $secret ) )
		throw new Exception("Invalid sms() 'secret' argument\n");
	
	if ( is_null ( $from ) || !is_string ( $from ) )
		throw new Exception("Invalid sms() 'from' argument\n");
	
	if ( is_null ( $body ) || !is_string ( $body ) )
		throw new Exception("Invalid sms() 'body' argument\n");
		
	
	if (is_numeric($time))
        $time /= 1000;  // боремся с ненужными микросекундами
	
		
    $res = $auth->gateLogin($db, $device_id, $secret);
    if ($res !== true)
		throw new Exception("Login as '$device_id' error: '$res'\n");

	$msg = CMooseSMS::CreateFromText($body, $time);	
		
    $res = $db->AddData($auth, $from, $msg);
	
    Log::t($db, $auth, "addSms", "via gate for '$from' " . CMooseTools::addSmsMessage($res));
    return $res;
}

function mylog($device_id, $body, $from, $time, $secret)
{
    $f = fopen("log.log", "a+");
    if ($f === false)
        throw new Exception("Error opening log!");

    fprintf($f, "%s, %s, %s, %s, %s\n", $device_id, $from, $time, $body, $secret);
    fclose($f);
}

function rawlog($array)
{
    $f = fopen("log.log", "a+");
    if ($f === false)
        throw new Exception("Error opening log!");
	foreach($array as $key => $value)
		{
		fprintf($f, "%s-%s;", $key, $value);
		}
	fprintf($f, "\n");
		
    fclose($f);
}
?>