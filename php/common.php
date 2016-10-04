<?
/**
 * Created by Serge Titov for mooServer project
 * 2014 - 2016
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


// думать про версии кеша, подгрузку по данных (чуть-чуть + все остальное)
function getData()
{
    global $db, $auth;

    $t1 = microtime(true);
    $ids = CMooseTools::safeIds();
    if ($ids == null)
        return array();

    $start = CMooseTools::safeTime('start');
    $end = CMooseTools::safeTime('end');

    $clientStamps = CMooseTools::safeStamps(@$_POST['stamps']);
    $stamps = $db->GetMooseTimestamps($auth, $ids);

    $retrieveIds = [];
    $useCache = [];
    foreach ($ids as $id)
    {
        if (isset($clientStamps[$id]) && $stamps != null && isset($stamps[$id]) && $clientStamps[$id] >= $stamps[$id])
            $useCache[] = ['id' => $id, 'useCache' => true];
        else
            $retrieveIds[] = $id;
    }

    $t2 = microtime(true);
    $mData = $db->GetMooseTracks($auth, $retrieveIds, $start, $end);
    $t3 = microtime(true);
    $aData = $db->GetMooseActivity($auth, $retrieveIds, $start, $end);
    $t4 = microtime(true);


    $i = 0;
    $idx = [];
    if ($mData != null)
        foreach ($mData as &$moose)
        {
            $mId = $moose['id'];
            $idx[$mId] = $i++;
            if ($stamps != null && isset($stamps[$mId]))
                $moose['stamp'] =  $stamps[$mId];
        }

    $t5 = microtime(true);

    // add activity to data
    if ($aData != null)
        foreach ($aData as $activity)
        {
            $mId = $activity['id'];
            $act = $activity['activity'];
            if (isset($idx[$mId]))
                $mData[$idx[$mId]]['activity'] = $act;
            else
            {
                $mData[] = array('id' => $mId, 'activity' => $act);
                if ($stamps != null && isset($stamps[$mId]))
                    $mData[$i]['stamps'] = $stamps[$mId];
                $idx[$mId] = $i++;
            }
        }

    $t6 = microtime(true);

    // add cache marks
    foreach ($useCache as $res)
        $mData[] = $res;

    //Log::d($db, $auth, 'times', sprintf("total: %4.0f tracks: %4.0f act %4.0f ms", ($t6-$t1) * 1000, ($t3-$t2) * 1000, ($t4-$t3) * 1000));

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

	$ids = CMooseTools::safeIds();
	if ($ids == null)
		return array();

	return $db->GetMooseActivity($auth, $ids, CMooseTools::safeTime('start'), CMooseTools::safeTime('end'));
}

function getBeaconData($forExport = false)
{
	global $db;
    global $auth;

	$ids = CMooseTools::safeIds();
	if ($ids == null)
		$ids = array();

    $all = @$_POST['all'] == 'true' && $auth->isLogged();

	return $db->GetBeaconStat($auth, $ids, CMooseTools::safeTime('start'), CMooseTools::safeTime('end'), $all, $forExport);
}

class CMooseTools
{
    public static function safeIds()
    {
        $ids = @$_POST['ids'];
        if ($ids == null)
            return null;

        return filter_var($ids, FILTER_VALIDATE_INT, FILTER_FORCE_ARRAY | FILTER_REQUIRE_ARRAY);
    }

    public static function safeTime($param)
    {
        return isset($_POST[$param]) ? strtotime($_POST[$param]) : null;
    }

    public static function safeStamps($stamps)
    {
        //$stamps =;
        if (!is_array($stamps))
            return [];

        $def = [
            'id' => ['filter'    => FILTER_VALIDATE_INT,
                'options'   => ['min_range' => 1]],

            'stamp' => ['filter'    => FILTER_VALIDATE_INT,
                'options'   => ['min_range' => 1]]
        ];

        foreach ($stamps as $st)
        {
            $f = filter_var_array($st, $def);
            if ($f['id']!= null && $f['id'] !== false && $f['stamp']!= null && $f['stamp'] !== false)
                $res[$f['id']] = $f['stamp'];
        }

        return $res;
    }

    public static function addSmsMessage($res)
    {
        $msg = "sms added. Raw sms id: {$res['rawSms']}. ";
        $msg .= (@$res['error'] != null) ? "Message parse error: {$res['error']}" : "Sms id: {$res['sms']}";

        return $msg;
    }

    public static function csvEscape($cell, $forceText = false)
    {
        $pfx = $forceText ? '=' : '';
        return $forceText == false && preg_match("/[\";\n]/im", $cell) != 1 ?      // if contains quote, semicolon or new line
            $cell :
            ($pfx. '"' . str_replace('"', '""', $cell) . '"');
    }
}

?>