<?
/**
 * Created by Serge Titov for mooServer project
 * 2014 - 2015
 */

if (!defined('IN_MOOSE'))
	exit;

function getRights()
{
	global $auth;
	$name = $auth->name();
	if ($name == null || $name == '')
		$name = $auth->login();
	$res = array('user' => $name,
             'id' => $auth->id(),
		     'isLogged' => $auth->isLogged());

	if ($auth->isRoot())
		$res['isRoot'] = true;
    if ($auth->isSuper())
        $res['isSuper'] = true;
	if ($auth->canAdmin())
		$res['canAdmin'] = true;
	if ($auth->canFeed())
		$res['canFeed'] = true;

	return $res;
}

// думать про версионность, таймстемпы, не передавать лишнего
function getData()
{
	global $db, $auth;

    $t1 = microtime(true);
	$ids = _safeIds();
	if ($ids == null)
		return array();

	$start = _safeTime('start');
	$end = _safeTime('end');

    $t2 = microtime(true);
	$mData = $db->GetMooseTracks($auth, $ids, $start, $end);
    $t3 = microtime(true);
	$aData = $db->GetMooseActivity($auth, $ids, $start, $end);
    $t4 = microtime(true);

	// add activity to data
	$i = 0;
	$idx = array();
	foreach ($mData as &$moose)
		$idx[$moose['id']] = $i++;

    $t5 = microtime(true);

	foreach ($aData as $activity)
	{
		$mId = $activity['id'];
		$act = $activity['activity'];
		if (isset($idx[$mId]))
			$mData[$idx[$mId]]['activity'] = $act;
		else
			$mData[] = array('id' => $mId, 'activity' => $act);
	}

    $t6 = microtime(true);

    //Log::d($db, $auth, 'times', "total: '" . ($t6-$t1) . "' tracks: '" . ($t3-$t2) . "' act: '" . ($t4-$t3));

	return $mData;
}

function getSms()
{
    global $auth, $db;
	// todo: аккуратнее проверить права, с учетом demo и т.п.
    /*if (!$auth->canAdmin() && !$auth->canFeed())
        dieError(CTinyDb::ErrCRights);*/

    $smsId = checkId(@$_POST['rawSmsId'], 'Недопустимый id sms', false);

    $res = array('track' => $db->GetSmsTrack($auth, $smsId),
        'activity' => $db->GetSmsActivity($auth, $smsId)
    );

    return $res;
}

function getActivity()
{
	global $db, $auth;

	$ids = _safeIds();
	if ($ids == null)
		return array();

	return $db->GetMooseActivity($auth, $ids, _safeTime('start'), _safeTime('end'));
}

function getBeaconData($forExport = false)
{
	global $db;
    global $auth;

	$ids = _safeIds();
	if ($ids == null)
		$ids = array();

    $all = @$_POST['all'] == 'true' && $auth->isLogged();

	return $db->GetBeaconStat($auth, $ids, _safeTime('start'), _safeTime('end'), $all, $forExport);
}

function csvEscape($cell, $forceText = false)
{
    $pfx = $forceText ? '=' : '';
    return $forceText == false && preg_match("/[\";\n]/im", $cell) != 1 ?      // if contains quote, semicolon or new line
        $cell :
        ($pfx. '"' . str_replace('"', '""', $cell) . '"');
}


/************************************** service functions *********************************************/

function _safeIds()
{
	$ids = @$_POST['ids'];
	if ($ids == null)
		return null;

    return filter_var($ids, FILTER_VALIDATE_INT, FILTER_FORCE_ARRAY | FILTER_REQUIRE_ARRAY);
}

function _safeTime($param)
{
	return isset($_POST[$param]) ? strtotime($_POST[$param]) : null;
}

function addSmsMessage($res)
{
    $msg = "sms added. Raw sms id: {$res['rawSms']}. ";
    $msg .= (@$res['error'] != null) ? "Message parse error: {$res['error']}": "Sms id: {$res['sms']}";

    return $msg;
}

?>