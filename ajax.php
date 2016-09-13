<?
/**
 * Created by Serge Titov for mooServer project
 * 2014 - 2015
 */
define('IN_MOOSE', true);

require_once "config.php";
require_once "php/auth.php";
require_once "php/dbase.php";
require_once "php/common.php";
require_once "php/moosesms.php";

header("Content-Type: application/json; charset=windows-1251");

global $auth;
global $db;

$db = new CMooseDb();
$auth = new CMooseAuth($db);

if (!isset($_GET['m']))
    dieError('no method name');

$mName = $_GET['m'];

$methods = array('sendSMS' => 'addSeries', // ajaxName => functionName
    'deleteSms' => 'deleteSms',
    'reassignSms' => 'reassignSms',
    'togglePoint' => 'togglePoint',

    'getProfile' => 'getProfile',
    'changePwd' => 'changePwd',
    'changeName' => 'changeName',

    'getSms' => 'getSms',               // see common
    'getData' => 'getData',             // see common
    'getBeaconData' => 'getBeaconData', // see common
    'getGateData' => 'getGateData',
    'getLogs' => 'getLogs',
    'log' => 'addLog',
    'getMoose' => 'getMooses',
    'getMooseRoot' => 'getMooses',
    'getUsers' => 'getUsers',
    'addMoose' => 'addMoose',

    'addUser' => 'addUser',
    'toggleUser' => 'toggleUser',
    'addGroup' => 'addOrg',
    'toggleGroup' => 'toggleOrg',
    'addGate' => 'addGate',
    'toggleGate' => 'toggleGate',
    'addBeacon' => 'addPhone',
    'toggleBeacon' => 'toggleBeacon',

    'verifyToken' => 'verifyToken',
    'login' => 'login',

    //'test' => 'test'
    );

$method = @$methods[$mName];
if ($method == null)
    dieError("Method '$mName' not supported");

$needComp = $mName == 'getData' || $mName == 'getBeaconData' || $mName == 'getGateData';

try {
    if ($needComp)
        ob_start('ob_gzhandler');
    
    echo json_encode($method());
    
    if ($needComp)
        ob_end_flush();
}
catch (Exception $e)
{
    $db->rollback();
    if ($needComp)
        ob_end_clean();
    
    Log::e($db, $auth, $mName, $e->getMessage());
    dieError($e->getMessage());
}

/* that's all! */


function test()
{
    //get_timezone();
    /*return date_default_timezone_get();
    return '';*/
    //return CTinyAuth::BaseHref();
    //return print_r($GLOBALS, false);
}


function getMooses()
{
	global $db, $auth;
	return array( 
		'mooses' => $db->GetMooses($auth, false),
		'phones' => $db->GetPhones($auth, false),
		'rights' => getRights());
}


// думать про версионность, таймстемпы, не передавать лишнего, вырезку по времени

function login()
{
	global $auth, $db;

    $forget = filter_var(@$_POST['forget'], FILTER_VALIDATE_BOOLEAN);
	$logout = filter_var(@$_POST['logout'], FILTER_VALIDATE_BOOLEAN);
	if (!$logout)
	{
		$login = filter_var(@$_POST['login'], FILTER_SANITIZE_EMAIL);
        if (!is_string($login))
            dieError('Не задан логин');

        if ($forget)
            $res = $auth->requestRestore($db, $login);
        else
        {
            $pwd = @$_POST['pass'];
            unset($_POST['pass']);
            if (!is_string($pwd))
                dieError('Не задан пароль');

            $res = $auth->tryLogin($db, $login, $pwd);
        }
		if ($res !== true)
            dieError(is_string($res) ? $res : "Неправильная пара логин-пароль");
	}
	else
		$res = $auth->tryLogout($db);

	return ['res' => $res, 'data' => getMooses()];
}

function getProfile()
{
    global $auth, $db;
    $res = array('login' => $auth->login(),
                 'name' => $auth->name(),
                'isSuper' => $auth->isSuper(),
                'canAdmin' => $auth->canAdmin(),
                'canFeed'  => $auth->canFeed(),
                'orgs' => $db->GetUserOrgs($auth)
        );

    return $res;
}

function tokenType()
{
    $ttype = varNotEmpty('ttype', true, 'Не задан тип токена');
    switch ($ttype)
    {
        case 'verify': return CTinyDb::Verify; break;
        case 'forget': return CTinyDb::ResetPwd; break;
        case 'ooooppptt': return CTinyDb::OptOut; break;
        default: dieError('Недопустимый тип токена');
    }
}

function verifyToken()
{
    global $auth, $db;

    $uid = checkId(@$_POST['uid'], 'Недопустимый ид пользователя');
    $token = varNotEmpty('token', true, 'Не задан токен');
    $ttype = tokenType();

    $res = $db->VerifyToken($auth, $uid, $token, $ttype, false);

    if ($res === false)
        dieError('Неправильный или просроченный токен');

    return array('res' => $res);
}

function resetPassword()
{
    global $auth, $db;

    $uid = checkId(@$_POST['uid'], 'Недопустимый ид пользователя');
    $token = varNotEmpty('token', true, 'Не задан токен');
    $ttype = tokenType();
    $new = varNotEmpty('newpwd', true, 'Не задан новый пароль');

    $res  = $auth->resetPassword($db, $uid, $token, $ttype, $new);
    if ($res !== true)
        dieError($res);

    return array('res' => $res);
}

function changePwd()
{
    global $auth, $db;

    $ttype = @$_POST['ttype'];
    if ($ttype !== null)
        return resetPassword();

    if (!$auth->isLogged())
        dieError(CTinyDb::ErrCRights);

    $old = @$_POST['old'];
    if (!is_string($old))
        dieError('Не задан старый пароль');

    $new = @$_POST['newpwd'];
    if (!is_string($new))
        dieError('Не задан новый пароль');

    $res = $auth->ChangePassword($db, $old, $new);
    if ($res !== true)
        dieError($res);

    return array('res' => $res);
}

function changeName()
{
    global $auth, $db;
    if (!$auth->isLogged())
        dieError(CTinyDb::ErrCRights);
    $name = varNotEmpty('name', false, 'Недопустимое имя пользователя');
    return array('res' => $db->ChangeName($auth, $name));
}

function getUsers()
{
    global $auth, $db;
    if (!$auth->canAdmin())
        dieError(CTinyDb::ErrCRights);

    $all = true; //filter_var(@$_POST['all'], FILTER_VALIDATE_BOOLEAN);

    $res = $db->GetUsers($auth, $all);
    $res['mooses'] = $db->GetMooses($auth, true);
    $res['phones'] = $db->GetPhones($auth, true);
    return $res;
}

function addMoose()
{
	global $auth, $db;
	if (!$auth->canAdmin())
        dieError(CTinyDb::ErrCRights);

    $name = varNotEmpty('name', true, "Не задано имя животного");
    $phoneId = checkId(@$_POST['phoneId'], "Недопустимый id телефона", true);
    $demo = filter_var(@$_POST['demo'], FILTER_VALIDATE_BOOLEAN);
    $org = validateIdArray(@$_POST['groups'], false, 1);


    $id = @$_POST['id'];
    if ($id == null)
	    return array("id" => $db->AddMoose($auth, $phoneId, $name, $demo, count($org) >  0 ? $org[0] : null));

    $id = checkId($id, 'Недопустимый id животного');
    $res = $db->UpdateMoose($auth, $id, $phoneId, $name, $demo, count($org) >  0 ? $org[0] : null);
    return array('id' => $res);
}

function addPhone()
{
    global $auth, $db;
    if (!$auth->canAdmin())
        dieError(CTinyDb::ErrCRights);

    $phone = varNotEmpty('phone', true, "Не задан телефон");
    $mooseId = checkId(@$_POST['moose'], "Недопустимый id животного", true);
    $demo = filter_var(@$_POST['demo'], FILTER_VALIDATE_BOOLEAN);
    $org = validateIdArray(@$_POST['groups'], false, 1);

    $id = @$_POST['id'];
    if ($id == null)
        return array("id" => $db->AddPhone($auth, $mooseId, $phone, $demo, count($org) >  0 ? $org[0] : null));

    $id = checkId($id, 'Недопустимый id телефона');
    $res = $db->UpdatePhone($auth, $id, $mooseId, $phone, $demo, count($org) >  0 ? $org[0] : null);
    return array('id' => $res);
}


function addUser() // todo verify all!!!
{
    global $auth, $db;
    if (!$auth->canAdmin())
        dieError(CTinyDb::ErrCRights);

    $title = varNotEmpty('login', true, "Пустой логин");
    $name = varNotEmpty('name', false, "Недопустимое имя");
    $orgs = validateIdArray(@$_POST['groups'], true);


    $isSuper = filter_var(@$_POST['super'], FILTER_VALIDATE_BOOLEAN);
    $canAdmin = filter_var(@$_POST['admin'], FILTER_VALIDATE_BOOLEAN);
    $canFeed = filter_var(@$_POST['feed'], FILTER_VALIDATE_BOOLEAN);

    $id = @$_POST['id'];
    if ($id == null)
    {
        $res = $auth->AddUser($db, $title, $name, $orgs, $isSuper, $canAdmin, $canFeed);
        if (!is_numeric($res))
            dieError($res);

        return array('id' => $res);
    }

    $id = checkId($id, 'Недопустимый id пользователя');

    $res = $auth->UpdateUser($db, $id, $title, $name, $orgs, $isSuper, $canAdmin, $canFeed);
    return array('id' => $id);
}

function addOrg()
{
    global $auth, $db;
    if (!$auth->canAdmin())
        dieError(CTinyDb::ErrCRights);

    $title = varNotEmpty('login', true, "Не задано название группы");
    $name = varNotEmpty('name', false, "Недопустимый комментарий");

    $id = @$_POST['id'];
    if ($id == null)
        return array("id" => $db->CreateGroup($auth, $title, $name));

    $id = checkId($id, 'Недопустимый id группы');

    return array("id" => $db->UpdateGroup($auth, $id, $title, $name));
}

function toggleBeacon()
{
    global $auth, $db;
    if (!$auth->canAdmin())
        dieError(CTinyDb::ErrCRights);

    $del = filter_var(@$_POST['del'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($del === null)
        dieError("Недопустимое значение del");

    $id = checkId(@$_POST['id'], 'недопустимый id прибора');
    return $db->TogglePhone($auth, $id, $del);
}

function commonUGToggle($isGroup, $errInvalidId)
{
    global $auth, $db;
    if (!$auth->canAdmin())
        dieError(CTinyDb::ErrCRights);

    $del = filter_var(@$_POST['del'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($del === null)
        dieError("Недопустимое значение del!!!");

    $id = checkId(@$_POST['id'], $errInvalidId);

    $db->ToggleUserGroup($auth, $id, $del, $isGroup, $errInvalidId);
    return array();
}

function toggleOrg()
{
    return commonUGToggle(true, "Недопустимый id организации");
}

function toggleGate()
{
    return commonUGToggle(false, "Недопустимый id гейта");
}

function toggleUser()
{
    return commonUGToggle(false, "Недопустимый id пользователя");
}

function addGate()
{
    global $auth, $db;
    if (!$auth->canAdmin())
        dieError(CTinyDb::ErrCRights);

    $title = varNotEmpty('login', true, "Не задано название гейта");
    $name = varNotEmpty('name', false, "Недопустимый комментарий");
    $orgs = validateIdArray(@$_POST['groups'], true);

    $id = @$_POST['id'];
    if ($id == null)
        return array("id" => $auth->AddGate($db, $title, $name, $orgs));

    $id = checkId($id, "Недопустимый id гейта", false);

    $res = $auth->UpdateGate($db, $id, $title, $name, $orgs);
    return array('id' => $id);
}

function addSeries()
{
	global $auth, $db;

	if (!$auth->canFeed())
		dieError(CTinyDb::ErrCRights);

    $phone = varNotEmpty('phone', true, "Не задан телефон");
    $sms = varNotEmpty('sms', true, "Не задан текст сообщения");

    $time = @$_POST['time'];
	if ($time == null)
		$time = time();
	else if (!is_string($time) || strtotime($time) == false)
		dieError("Невозможно разобрать время");
	else 
		$time = strtotime($time);

	$msg = new CMooseSMS($sms, $time);

	$res = $db->AddData($auth, $phone, $msg);
    Log::t($db, $auth, "addSms", "via UI " . addSmsMessage($res));
	return $res;
}

function deleteSms()
{
    global $auth, $db;

    if (!$auth->isRoot())
        dieError(CTinyDb::ErrCRights);

    $smsId = checkId(@$_POST['rawSmsId'], 'Недопустимый id sms', false);
    $db->DeleteRawSms($auth, $smsId);

    return array('res' => true);
}

function reassignSms()
{
    global $auth, $db;

    if (!$auth->isSuper())
        dieError(CTinyDb::ErrCRights);

    $moose = checkId($_POST['moose'], 'Недопустимый id животного', true);
    $smsIds = validateIdArray($_POST['smsIds'], true, -1, "Недопустимый список смс");

    return $db->ReassignSmses($auth, $smsIds, $moose);
}

function togglePoint()
{
    global $auth, $db;

    if (!$auth->canAdmin())
        dieError(CTinyDb::ErrCRights);

    $mooseId = checkId(@$_POST['mooseId'], 'Недопустимый id животного', true);
    $rawSms = checkId(@$_POST['rawSmsId'], 'Недопустимый id sms', true);
    if ($mooseId === null && $rawSms === null)
        dieError('Недопустимый id животного');

    $valid = filter_var(@$_POST['valid'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($valid === null)
        dieError('недопустимое значение активности');
    $time = varNotEmpty('time', true, 'не указана точка');

    return $mooseId !== null ? $db->TogglePoint($auth, $mooseId, $time, $valid) : $db->TogglePoint2($auth, $rawSms, $time, $valid);
}

function getGateData()
{
    global $auth, $db;
    if (!$auth->canAdmin() && !$auth->canFeed())
        dieError(CTinyDb::ErrCRights);

    $limit = checkId(@$_POST['limit'], 'Недопустимое значение предела', true);
    $justErr =  @$_POST['errors'] === 'true';
    $phoneIds = validateIdArray(@$_POST['phoneIds'], false, -1, "Недопустимый список приборов");

    return $db->GetGateData($auth, $limit, $justErr, $phoneIds);
}

function getLogs()
{
    global $auth, $db;
    if (!$auth->canAdmin())
        dieError(CTinyDb::ErrCRights);

    $limit = checkId(@$_POST['limit'], 'Недопустимое значение предела', true);
    if ($limit == null)
        $limit = 100;

    $levels = @$_POST['levels'];
    if ($levels != null)
    {
        $levels = filter_var($levels, FILTER_VALIDATE_INT, array('options' => array('min_range' => CTinyDb::LogInfo, 'max_range' => CTinyDb::LogCritical), 'flags' => FILTER_FORCE_ARRAY | FILTER_REQUIRE_ARRAY));
        if ($levels === false)
            dieError('недопустимые уровни логов');
    }

    $ops = @$_POST['ops'];
    if ($ops != null && (!is_array($ops) || count($ops) != count(array_filter($ops))))
        dieError('недопустимая операция');
    
    $search = @$_POST['search'];

    return $db->GetLogs($auth, $levels, $ops, $search, $limit);
}

function addLog()
{
    global $auth, $db;

    $message = $title = varNotEmpty('message', true, "Не указано сообщение");
    $level = filter_var($_POST['level'], FILTER_VALIDATE_INT, array('options' => array('min_range' => CTinyDb::LogInfo, 'max_range' => CTinyDb::LogCritical)));
    if ($level === false)
        dieError('недопустимый уровень логов');
    
    $db->AddLogRecord($auth, $level, 'webClient', $message);
    return 'ok';
}

/*function resetDb()
{
	global $auth;
	if (!$auth->canAdmin())
		dieError(CTinyDb::ErrCRights);

    die;
	global $mooSett;
	$res = array();
	
	$req = sprintf("mysql -p%s -h%s -u%s -D%s < ", $mooSett['pwd'], $mooSett['host'], $mooSett['user'], $mooSett['base']);
	system($req . "tinydb.sql", $res['tiny_create']);
	system($req . "moose.sql", $res['create']);
	return($res);
}*/

function varNotEmpty($name, $strict, $errmsg)   // todo add filtering
{
    $var = @$_POST[$name];

    if ($strict === true  && ($var == null || !is_string($var)) ||
        $strict === false && ($var != null && !is_string($var)) )
        dieError($errmsg);

    return $var;
}

function checkId($id, $err, $canBeNull = false)
{
    if ($id == null && $canBeNull == true)
        return $id;

    $id = filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
    if ($id === false)
        dieError($err);
    return $id;
}

function validateIdArray($orgs, $strict, $maxCount = -1, $errMsg = "Недопустимый список организаций")
{
    if ($orgs == null && $strict == false)
        return $orgs;

    $orgs = filter_var($orgs, FILTER_VALIDATE_INT, FILTER_FORCE_ARRAY | FILTER_REQUIRE_ARRAY);
    if ($orgs === false || count($orgs) == 0 && $strict == true || $maxCount >=0 && count($orgs) > $maxCount)
        dieError($errMsg);

    return $orgs;
}

function makeError($msg)
{
	return json_encode(array('error' => $msg));
}

function dieError($msg)
{
    die(makeError($msg));
}
?>
