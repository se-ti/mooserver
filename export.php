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

global $auth;
global $db;

$db = new CMooseDb();
$auth = new CMooseAuth($db);

if (!isset($_GET['m']))
	die('no method name');

$mName = $_GET['m'];

try
{
    if ($mName == 'tracks')
        exportTracks();
    else if ($mName == 'beacons')
        exportBeacons();
    else if ($mName == 'activity')
        exportActivity();
    else if ($mName == 'backup')
        exportBackup();
    else
        die('unknown method');
}
catch (Exception $e)
{
    die($e->getMessage()."\n\n".$e->getTraceAsString());
}

// that's all!

function prepareIds()
{
	if (!isset($_POST['ids']))
		die('not enough params');

	$_POST['ids'] = explode(",", $_POST['ids']);
}

function csvHeaders($fName)
{
	header("Content-Type: text/csv csv; charset=UTF-8");
	header('Content-Disposition: attachment; filename="' . $fName. '"');

	return "\xEF\xBB\xBF";  // utf-8 magic bytes
}

function csvTimeHeader()
{
	$res = '';
	$start = @$_POST['start'];
	$end   = @$_POST['end'];
	if ($start != null)
		$res .= "с;" . CMooseTools::csvEscape($start) . ";". CMooseTools::csvEscape(gmdate('Y-m-d H:i:s', strtotime($start))) . "\n";

	if ($end != null)
		$res .= "по;" . CMooseTools::csvEscape($end) . "\n";

	return $res;
}

function mooseName($mooses, $id)
{
	foreach($mooses as $m)
		if ($m['id'] == $id)
			return $m['name'];
	return $id;
}

function exportBeacons()
{
	global $db, $auth;

	prepareIds();
	$data = getBeaconData(true);
	if ($data == null)
		die('no data');

	$rows = array();
    $phones = array();
	foreach($data as $phoneData)
	{
		$phone = $phoneData['phone'];
        if (array_search($phone, $phones) == null)
            $phones[] = $phone;
        
		foreach($phoneData['data'] as $row)
			$rows[] = CMooseTools::csvEscape($phone, true) .";". implode(";", array_map('CMooseTools::csvEscape', $row));
	}

	$header = "Экспорт маяков\n";
	$header .= csvTimeHeader();
	$header .="\nМаяк;Получено;последняя точка;вн. id;V;T C;GPS on мин;GSM tries;rawSms id;;id животного";

    $fname = count($phones) == 1 ? preg_replace('/[^\dx]/', '', $phones[0]) : 'beacons';
	
	echo csvHeaders("$fname.csv");
	echo "$header\n" . implode("\n", $rows);
}

function exportActivity()
{
	global $db, $auth;

	$moo = $db->GetMooses($auth, false);

	prepareIds();
	$data = getActivity();

	$rows = array(); 
	$names = array();
    $qNames = array();
	if ($data != null)
		foreach($data as $moose)
		{
			$name = mooseName($moo, $moose['id']);
			$names[] = $name;
            $name = CMooseTools::csvEscape($name);
            $qNames[] = $name;

			foreach($moose['activity'] as $mark)
				$rows[] = $name . ";" . implode(";", array_map("CMooseTools::csvEscape", $mark));
		}

	$header = "Экспорт активности для:;" . implode(";", $qNames) . "\n";
	$header .= csvTimeHeader();
	$header .= "\nживотное;время;активен;валиден";

	$fname = implode('_', $names);
	if ($fname == '')
		$fname = 'activity';

	echo csvHeaders("$fname.csv");
	echo "$header\n" . implode("\n", $rows);
}

function exportTracks()
{
	global $db, $auth;
	$moo = $db->GetMooses($auth, false);
	
	prepareIds();
	$data = getData();
	if ($data == null)
		die('no data');

	$tracks = '';
	$names = array();
	foreach ($data as $track)
	{
		$pts = '';
		foreach($track['track'] as $point)
			$pts.= "<trkpt lat=\"{$point[0]}\" lon=\"{$point[1]}\"><time>{$point[2]}</time></trkpt>\n";


		$moose = mooseName($moo, $track['id']);
		$names[] = $moose;
		$tracks .= "  <trk><name>$moose</name><trkseg>\n$pts</trkseg></trk>";
	}

	$tm = gmdate('c', time());

	$fname = implode('_', $names);
	if ($fname == '')
		$fname = 'empty';

	$start = @$_POST['start'];
	$end   = @$_POST['end'];

	$times = '';
	if ($start != null)
		$times .= ' с ' . gmdate("m M Y", strtotime($start));

	if ($end != null)
		$times .= ' по ' . gmdate("M Y", strtotime($end));

	header("Content-Type: application/gpx+xml gpx; charset=UTF-8");
	header('Content-Disposition: attachment; filename="'.$fname.'.gpx"');

	$header = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
	<gpx xmlns=\"http://www.topografix.com/GPX/1/1\" creator=\"MooServer\" version=\"1.1\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xsi:schemaLocation=\"http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd\">
	  <metadata>
	    <name>Треки " . implode(', ', $names) . $times . "</name>
	    <author>
	      <name>MooServer</name>
	    </author>
	    <time>$tm</time>
	  </metadata>";
	$footer = "</gpx>";

	echo "$header\n  $tracks\n$footer";
}

function exportBackup()
{
    global $auth, $mooSett;
    if (!$auth->isSuper())
    {
        header("Content-Type: text/html html; charset=UTF-8");
        die(CTinyDb::ErrCRights);
    }

    $fname = "mooserver-" . date('Y-m-d--H-i'). ".sql.gz";
    header("Content-Type: text/sql sql; charset=UTF-8");
    header('Content-Disposition: attachment; filename="' . $fname . '"');

    $parts = explode(':', $mooSett['host'], 2);
    $host = "-h $parts[0]" . (count($parts) > 1 ? " -P$parts[1]" : '');
    $req = sprintf("mysqldump -p%s %s -u%s  -E --triggers -R  %s version stamp users usergroups moose phone activity logs position raw_sms sms session | gzip -c", $mooSett['pwd'], $host, $mooSett['user'], $mooSett['base']);
    echo system($req);
}
?>