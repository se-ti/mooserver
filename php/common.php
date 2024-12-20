<?php
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
	$res = ['user' => $name,
             'id' => $auth->id(),
		     'isLogged' => $auth->isLogged()];

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

// думать про версии кеша
function getData($forceLoadAll = false, $skipActivity = false)
{
    global $db, $auth;

    $t1 = microtime(true);
    $ids = CMooseTools::safeIds();
    if ($ids == null)
        return [];

    $start = CMooseTools::safeTime('start');
    $end = CMooseTools::safeTime('end');

    $clientStamps = CMooseTools::safeStamps(@$_POST['stamps']);
    $stamps = $db->GetMooseTimestamps($auth, $ids);

    if ($forceLoadAll == false && @$_POST['forceLoadAll'] == 'true')
        $forceLoadAll = true;

    $retrieveIds = [];
    $useCache = [];
    $allowCache = true;
    foreach ($ids as $id)
    {
        if ($allowCache && isset($clientStamps[$id]) && $stamps != null && isset($stamps[$id]) && $clientStamps[$id] >= $stamps[$id])
            $useCache[] = ['id' => $id, 'useCache' => true];
        else
            $retrieveIds[] = $id;
    }

    $t2 = microtime(true);
    $mData = $db->GetMooseTracks($auth, $retrieveIds, $start, $end);
    $t3 = microtime(true);

    $i = 0;
    $idx = [];
    $activityIds = [];
    if ($mData != null)
        foreach ($mData as &$moose)
        {
            $mId = $moose['id'];
            $idx[$mId] = $i++;
            if ($stamps != null && isset($stamps[$mId]))
                $moose['stamp'] =  $stamps[$mId];

            if ($forceLoadAll || count($moose['track']) < 2000) // ~ 80 days // можно придумать хитрую логику на случай, когда много мелких треков дают много активности
                $activityIds[] = $mId;
            else
                $moose['delayLoad'] = true;
        }

    $t4 = microtime(true);
    $aData = $skipActivity ? null : $db->GetMooseActivity($auth, $activityIds, $start, $end);
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
                $mData[] = ['id' => $mId, 'activity' => $act];
                if ($stamps != null && isset($stamps[$mId]))
                    $mData[$i]['stamps'] = $stamps[$mId];
                $idx[$mId] = $i++;
            }
        }

    $t6 = microtime(true);

    // add cache marks
    foreach ($useCache as $res)
        $mData[] = $res;

    //Log::d($db, $auth, 'times', sprintf("data total: %4.0f tracks: %4.0f act %4.0f ms", ($t6-$t1) * 1000, ($t3-$t2) * 1000, ($t5-$t4) * 1000));

    return $mData;
}

function delayedActivity()
{
    global $db, $auth;

    $t1 = microtime(true);
    $activityIds = CMooseTools::safeIds();
    if ($activityIds == null)
        return [];

    $start = CMooseTools::safeTime('start');
    $end = CMooseTools::safeTime('end');

    return $db->GetMooseActivity($auth, $activityIds, $start, $end);
}

function getSms()
{
    global $auth, $db;
	// todo: аккуратнее проверить права, с учетом demo и т.п.
    /*if (!$auth->canAdmin() && !$auth->canFeed())
        dieError(CTinyDb::ErrCRights);*/

    $smsId = checkId(@$_POST['rawSmsId'], 'Недопустимый id sms', false);

    $res = ['track' => $db->GetSmsTrack($auth, $smsId),
        'activity' => $db->GetSmsActivity($auth, $smsId)];

    if ($auth->canAdmin() && @$_POST['diag'] == 1 && ($sms = $db->GetSms($auth, $smsId)) != null && ($parsed = CMooseSMS::CreateFromText($sms['text'], $sms['stamp'], 1)) != null)
        $res['diagnostics'] = $parsed->diag;

    return $res;
}

function getActivity()
{
	global $db, $auth;

	$ids = CMooseTools::safeIds();
	if ($ids == null)
		return [];

	return $db->GetMooseActivity($auth, $ids, CMooseTools::safeTime('start'), CMooseTools::safeTime('end'));
}

function getBeaconData($forExport = false)
{
	global $db;
    global $auth;

	$ids = CMooseTools::safeIds();
	if ($ids == null)
		$ids = [];

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

        $res = [];

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
        $msg = "sms added. Raw sms id: {$res['rawSms']}.";
        if (isset($res['sms']))
            $msg .= " Sms id: {$res['sms']}";
        if (isset($res['error']))
            $msg .= " Message parse error: {$res['error']}";

        return $msg;
    }

    public static function csvEscape($cell, $forceText = false)
    {
        $pfx = $forceText ? '=' : '';
        return $forceText == false && preg_match("/[\";\n]/im", $cell) != 1 ?      // if contains quote, semicolon or new line
            $cell :
            ($pfx. '"' . str_replace('"', '""', $cell) . '"');
    }

    public static function uploadErrors($code)
    {
        return @[
            UPLOAD_ERR_OK => 'There is no error, the file uploaded with success',
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',]
            [$code];
    }
}


class CScheduler
{
    const TimestampFile = 'timestamp.log';

    public static function safeRun(CMooseDb $db, CMooseAuth $auth)
    {
        // fastcgi_finish_request();                   // "отпускаем" ответ клиенту до запуска safeRun  // todo не работает на продакшене
        try
        {
            if (!self::canRun($auth))
                return;

            self::payload($db, $auth);

            self::markSuccess();
            Log::t($db, $auth, "scheduler", 'successful launch!');
        }
        catch (Exception $e)
        {
            Log::e($db, $auth, "scheduler", $e->getMessage());
        }
    }

    private static function payload(CMooseDb $db, $auth)
    {
        $db->SimplifyGateLogs($auth);
        // self::addSampleSms($db, $auth, './data/assy20120604-20130111.csv');
        // self::uploadPlt($db, $auth, './data/yaminga20121017-20121029a.plt', 'yaminga20121017-20121029a.plt', '+7-916-212-85-06', 'Яминга', false);
    }

    private static function canRun(CMooseAuth $auth)
    {
        global $mooSett;
        if (empty($mooSett['timestamp']) || !$auth->isSuper())
            return false;

        $mtime = filemtime($mooSett['timestamp']);
        if ($mtime == false)
            return true;

        $work = true;
        if ($work)
            $flag = date('j m Y') != date('j m Y', $mtime); // не сегодня
        else
            $flag = time() > $mtime + 30; //

        return $flag; // при массовых проверках выполнится столько действий, сколько будет запросов между этим if и отработкой первого markSuccess
    }

    private static function markSuccess()
    {
        global $mooSett;
        touch($mooSett['timestamp']);
    }

    public static function importFile(CMooseDb $db, CMooseAuth $auth, $file, $delim = ';')
    {
        if (!$auth->isSuper())
            return;

        $data = fopen($file, "r");
        if ($data == false)
            throw new Exception("error opening file '$file'");

        for ($line = 1; $tokens = fgetcsv($data, 300, $delim); $line++)
        {
            if (count($tokens) < 8)
            {
                Log::t($db, $auth, 'importFile', "short line: $line");
                continue;
            }

            $phone = $tokens[2];
            $fMoose = iconv('cp1251', 'utf8', $tokens[3]);
            $tm = self::parseTime($tokens[5]);
            $msg = $tokens[7];

            if ($tm == false)
            {
                Log::e($db, $auth, 'importFile', "can't parse time '$tokens[5]' at line: $line");
                continue;
            }

            self::addTextSms($db, $auth, $phone, $tm, $fMoose, $msg, false);
        }

        fclose($data);
    }

    // todo: работает, но есть вопросы:
    // -- не из-под супера не сработает -- ну и пусть
    // -- возможны проблемы с високосными годами
    private static function addSampleSms(CMooseDb $db, CMooseAuth $auth, $file)
    {
        if (!$auth->isSuper())
            return;

        $data = fopen($file, "r");
        if ($data == false)
            throw new Exception("error opening file '$file'");

        $ref = null;
        $addHead = null;
        for ($line = 1; $tokens = fgetcsv($data, 300, ';'); $line++)
        {
            if ($ref == null)
            {
                $ref = self::tryGetRef($tokens);
                continue;
            }

            if (count($tokens) < 8)
                continue;

            $phone = $ref['phone'] != '' ? $ref['phone'] : $tokens[2];
            $fMoose = $tokens[3] != '' ? $tokens[3] : $ref['moose']; // ???
            $tm = self::parseTime($tokens[5]);
            $msg = $tokens[7];

            if ($tm == false)
                continue;

            if ($addHead !== false && $tm < $ref['start'])
            {
                $addHead = $addHead || self::addTextSms($db, $auth, $phone, $tm + $ref['delay'], $fMoose, $msg, true);
                continue;
            }

            // есть задержка -- дельта между получением и добавлением смс
            // нужно добавить все смс у которых задержка больше, чем дельта, и меньше, чем была в прошлый синк

            $tm += $ref['delay'];       // hack, чтобы смс казалась современной
            if ($tm < time() && $tm >= $ref['prevSync'])
                self::addTextSms($db, $auth, $phone, $tm, $fMoose, $msg, false);
        }

        fclose($data);

        if ($ref == null)
            throw new Exception('addSms: no correct init string');
    }

    private static function tryGetRef($tokens)
    {
        global $mooSett;
        if (count($tokens) < 5 || $tokens[0] != 'name-phone-firstDate-targetYear')
            return null;

        $r = [];
        $r['moose'] = $tokens[1] != '' ? iconv('cp1251', 'utf8', $tokens[1]) : null;
        $r['phone'] = iconv('cp1251', 'utf8', $tokens[2]);
        $ref = self::parseTime($tokens[3]);
        if ($ref == false)
            return null;

        $st = mktime(0,0,0, date('m', $ref), date('d', $ref), $tokens[4]);// а как с високосными годами?
        if ($st == false || $st <  $ref)
            return null;
        $r['start'] = $ref;
        $r['delay'] = $st - $ref;

        $last = filemtime($mooSett['timestamp']);
        //$last = strtotime('2017-01-10 10:32');    // test
        $r['prevSync'] = $last != false ? ($last - 5) : $st; // 5 c -- зазор на выполнение скрипта, чтобы не терялись смс, пришедшие точно между запуском scheduler и markSuccess.

        return $r;
    }

    private static function addTextSms(CMooseDb $db, CMooseAuth $auth, $phone, $time, $moose, $text, $tryHead)
    {
        $sms = CMooseSMS::CreateFromText($text, $time);
        if (!$sms->IsValid()) {
            Log::e($db, $auth, 'scheduler', "bad message: '$text', err: " . $sms->GetErrorMessage());
            return false;
        }
        return self::addSms($db, $auth, $phone, $moose, $sms, $tryHead);
    }

    private static function addSms(CMooseDb $db, CMooseAuth $auth, $phone, $moose, CMooseSMS $sms, $tryHead)
    {
        try
        {
            $res = $db->AddData($auth, $phone, $sms, $moose, true);
            Log::st($auth, "addSms", "via scheduler for '$phone' " . CMooseTools::addSmsMessage($res));
        }
        catch (Exception $e)
        {
            $mess = $e->getMessage();
            if (!$tryHead || strpos($mess, CMooseDb::ErrDupSms) != 0)
                Log::se($auth, "scheduler", $mess);
            return false;
        }

        return true;
    }

    private static function parseTime($tm)
    {
        return strtotime(str_replace('.', '-', $tm)); // Change '.' to '-' to make strtotime think date is in american notation
    }

    public static function uploadPlt(CMooseDb $db, CMooseAuth $auth, $path, $file, $phone, $moose)
    {
        if (!$auth->isSuper())
            throw new Exception(CTinyDb::ErrCRights);

        $data = fopen($path, "r");
        if ($data === false)
            throw new Exception("error opening file '$path' for $file");

        $res = [
            'name' => $file,
            'min' => null,
            'max' => null,
            'warn' => []
        ];

        $cn = 1;
        $last = null;
        $quant = 100;
        $points = [];
        try
        {
            for ($line = 1; $tokens = fgetcsv($data, 300, ','); $line++)
            {
                if (count($tokens) < 7 || $tokens[0] == 0)
                    continue;

                $pt = self::lineFromPlt($tokens);
                if ($res['min'] == null || $res['min'] > $pt[2])
                    $res['min'] = $pt[2];
                if ($res['max'] == null || $res['max'] < $pt[2])
                    $res['max'] = $pt[2];

                if ($last != null && $pt[2] < $last[2])
                {
                    $prev = $line-1;
                    $res['warn'][] = "Время пошло назад: " . date('D, d M Y H:i:s P', $pt[2]). " строки $prev-$line";
                    $last = $pt;
                    continue;
                }

                $points[] = $last = $pt;
                if ((++$cn) % $quant == 0)
                {
                    $sms = CMooseSMS::artificialSms(time(), "$file - " . $cn / $quant);
                    $sms->points = $points;
                    if (!self::addSms($db, $auth, $phone, $moose, $sms, false))
                        throw new Exception("Error adding sms: '$file', line $line");
                    $points = [];
                    sleep(1);
                }
            }
        }
        finally
        {
            fclose($data);
        }

        if (count($points) != 0)
        {
            $sms = CMooseSMS::artificialSms(time(), "$file - " . ceil($cn / $quant));
            $sms->points = $points;
            if (!self::addSms($db, $auth, $phone, $moose, $sms, false))
                throw new Exception("Error adding sms: '$file', line $line");
        }

        return $res;
    }

    private static function lineFromPlt($line)
    {
        $lat = floatval($line[0]);
        $lon = floatval($line[1]);

        $search = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
        $rep = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        $str = str_ireplace($search, $rep, iconv('cp1251', 'utf8', $line[5] . $line[6]));
        if (trim($str) != '')
        {
            $tz = date_default_timezone_get();
            date_default_timezone_set('UTC');
            $time = strtotime($str);
            date_default_timezone_set($tz);
        }
        else
            $time = self::oziToUnixTime(floatval($line[4]));

        return [$lat, $lon, $time];
    }

    // see https://www.oziexplorer4.com/eng/help/fileformats.html
    static function oziToUnixTime($val)
    {
        return round(($val - 25569) * 86400); // 25569 -- days between Unix Epoch 1/1/1970 and 12/30/1899 -- Delphi Epoch
    }
}
