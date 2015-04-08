<?
/**
 * Created by Serge Titov for mooServer project
 * 2014 - 2015
 */

if (!defined('IN_MOOSE'))
	exit;

if (!defined('IN_TINY'))
	define('IN_TINY', true);

require_once "auth.php";
require_once "tiny/tinydb.php";

class CMooseDb extends CTinyDb
{
    const ErrDupMoose = "Животное с таким именем уже есть в системе";
    const ErrDupPhone = "Прибор с таким номером уже есть в системе";
    const ErrDupSms = "Дублирование смс";
    const ErrWrongPhoneId = "Недопустимый id телефона";
    const ErrWrongMooseId = "Недопустимый id животного";
    const ErrWrongSmsId = "Недопустимый id sms";
    const ErrNoOrg = "Недопустимый список организаций";
    const ErrPhoneUsed = "Данный телефон уже привязан к другому животному";

    const ErrEmptyPhone = "Номер телефона должен быть не пуст";
    const ErrEmptyMoose = "Имя животного должно быть не пусто";

	function __construct()
	{
		self::$Version = 1;
		parent::__construct();
	}

    // проверяет значения флага demo и id группы для животных и приборов, их доступность текущему пользователю
    protected function ValidateOrgs(CTinyAuth $auth, $demo, $org)
    {
         $demo = filter_var($demo, FILTER_VALIDATE_BOOLEAN);

        if ($org == null)
        {
            $group = 'null';
            if ($demo == false)
                $this->Err(self::ErrNoOrg);
        }
        else
        {
            $org = filter_var($org, FILTER_VALIDATE_INT);
            if ($org === false || $org <= 0)
                $this->Err(self::ErrNoOrg);

            $group = $org;
        }

        if (!$auth->isSuper())
        {
            $groups = $this->GetUserGroups($auth);

            $hasDemo = !$demo;
            $hasOrg = $org == null;
            foreach($groups as $gr)
            {
                $hasOrg = $hasOrg || $gr == $org;
                $hasDemo = $hasDemo || $gr == CMooseAuth::Demo;
            }

            if (($hasDemo && $hasOrg) == false)
                $this->Err(self::ErrCRights);
        }

        return array ('demo' => $demo ? 1 : 0, 'group' => $group);
    }

    // $id обязан быть int
    protected function CanModify(CTinyAuth $auth, $id, $moose)
    {
        if ($auth->isSuper() || $id == null)
            return true;

        $uid = $auth->id();
        $demo = CMooseAuth::Demo;
        $base = $moose ? 'moose' : 'phone';
        $query = "select count(*) as cn from
               $base o
               inner join usergroups ug on o.demo = 1 and ug.group_id = $demo or o.group_id = ug.group_id
               inner join users g on g.id = ug.group_id
               where o.id = $id and g.removeDate is null and ug.user_id = $uid";

        $res = $this->Query($query);
        foreach ($res as $r)
        {
            $res->closeCursor();
            return $r['cn'] > 0;
        }

        return false;
    }

    protected function CanModifySms($auth, $rawSmsId)
    {
        if ($auth->isSuper())
            return true;

        $query = "select rs.phone_id, s.moose
                    from raw_sms rs
                    left join sms s on s.raw_sms_id = rs.id
                    where rs.id = $rawSmsId";

        $res = $this->Query($query);
        foreach($res as $r)
        {
            $hasMoose = isset($r['moose']);
            $can = $this->CanModify($auth, $hasMoose ? $r['moose'] : $r['phone'], $hasMoose);
            $res->closeCursor();

            return $can;
        }
        return false;
    }

    private function CanSeeCond(CTinyAuth $auth, $name, $simple = true, $table = null)
    {
        if ($auth->isSuper())
            return array('join' => '', 'cond' => 'true');

        $id = $auth->id();
        $demo = CMooseAuth::Demo;

        $res = array(
            'join' => "inner join usergroups ug on ug.group_id = $name.group_id or ug.group_id = $demo and $name.demo = 1
		            inner join users u on u.id = ug.group_id",

            'cond' => "(ug.user_id = $id and u.removeDate is null)");

        if ($simple)
            return $res;

        $query = "select $name.id from $table $name
            {$res['join']}
            where {$res['cond']}";

        $ids = array();
        $result = $this->Query($query);
        foreach ($result as $r)
            $ids[] = $r['id'];

        $res['join'] = '';
        if (count($ids) == 0)
            $res['cond'] = "$name.id is null";
        else
            $res['cond'] = "$name.id in (" . join(', ', $ids) . ") ";

        return $res;
    }

    private function TimeCondition($param, $start, $end)
    {
        $timeCond = '';
        if ($start != null && is_int($start))
            $timeCond .= "and $param >= {$this->ToSqlTime($start)} ";
        if ($end != null && is_int($end))
            $timeCond .= "and $param < {$this->ToSqlTime($end)} ";
        return $timeCond;
    }

	function AddMoose(CMooseAuth $auth, $phoneId, $name, $demo, $org)
	{
		if (!$auth->canAdmin())
			$this->ErrRights();

        $vd = $this->ValidateOrgs($auth, $demo, $org);

        $pId = 'null';
        if ($phoneId != null)
        {
            $pId = $this->ValidateId($phoneId, self::ErrWrongPhoneId);
            if (!$this->CanModify($auth, $pId, false))
                $this->ErrRights();
        }

        $name = $this->db->quote($this->ValidateTrim($name, self::ErrEmptyMoose));

		$query = "insert into moose (phone_id, name, demo, group_id) values (null, $name, {$vd['demo']}, {$vd['group']})";

		$this->Query($query, self::ErrDupMoose);
        $res = $this->db->lastInsertId();

        if ($phoneId == null)
        {
            $this->commit();
            return $res;
        }

        $query = "update moose, phone set
                    phone_id = $phoneId, phone.demo = {$vd['demo']}, phone.group_id = {$vd['group']}
                    where phone.id = $phoneId and moose.id = $res";
        $this->Query($query, self::ErrPhoneUsed);

        $this->commit();
        return $res;
	}

    function UpdateMoose(CMooseAuth $auth, $id, $phoneId, $name, $demo, $org)
    {
        if (!$auth->canAdmin())
            $this->ErrRights();

        $vd = $this->ValidateOrgs($auth, $demo, $org);

        $id = filter_var($id, FILTER_VALIDATE_INT);
        if ($id === false)
            $this->Err(self::ErrWrongMooseId);

        if (!$this->CanModify($auth, $id, true))
            $this->ErrRights();

        $pCond = '';
        $pUpdate = '';
        if ($phoneId != null)
        {
            $pId = $this->ValidateId($phoneId, self::ErrWrongPhoneId);
            if (!$this->CanModify($auth, $pId, false))
                $this->ErrRights();

            $pUpdate = ", phone.demo = {$vd['demo']}, phone.group_id = {$vd['group']}";
            $pCond = "and phone.id = $pId";
        }

        $name = $this->db->quote($this->ValidateTrim($name, self::ErrEmptyMoose));

        $query = "update moose, phone
            set phone_id = null, name = $name, moose.demo = {$vd['demo']}, moose.group_id = {$vd['group']} $pUpdate
            where moose.id = $id $pCond";

        $this->Query($query, self::ErrDupMoose);
        $res = $this->db->lastInsertId();

        if ($phoneId != null)
        {
            $query = "update moose set phone_id = $pId where id = $id";
            $this->Query($query, self::ErrPhoneUsed);
        }

        return $res;
    }

    function AddPhone(CMooseAuth $auth, $mooseId, $phone, $demo, $org)
    {
        if (!$auth->canAdmin())
            $this->ErrRights();

        $vd = $this->ValidateOrgs($auth, $demo, $org);

        $mId = 'null';
        if ($mooseId != null)
        {
            $mId = $this->ValidateId($mooseId, self::ErrWrongMooseId);
            if (!$this->CanModify($auth, $mId, true))
                $this->ErrRights();
        }

        $phone = $this->db->quote($this->ValidateTrim($phone, self::ErrEmptyPhone));
        $canonical = $this->db->quote(self::CanonicalPhone($phone));

        $this->beginTran();

        $query = "insert into phone (phone, canonical, demo, group_id) values ($phone, $canonical, {$vd['demo']}, {$vd['group']})";

        $this->Query($query, self::ErrDupPhone . " $phone, $canonical");
        $res = $this->db->lastInsertId();

        if ($mooseId == null)
        {
            $this->commit();
            return $res;
        }

        $query = "update moose set phone_id = $res, demo = {$vd['demo']}, group_id = {$vd['group']}
                    where id = $mId";
        $this->Query($query);

        $this->commit();
        return $res;
    }

    function UpdatePhone(CMooseAuth $auth, $id, $mooseId, $phone, $demo, $org)
    {
        if (!$auth->canAdmin())
            $this->ErrRights();

        $id = $this->ValidateId($id, self::ErrWrongPhoneId);
        if (!$this->CanModify($auth, $id, false))
            $this->ErrRights();

        if ($mooseId != null)
        {
            $mId = $this->ValidateId($mooseId, self::ErrWrongMooseId);
            if (!$this->CanModify($auth, $mId, true))
                $this->ErrRights();
        }

        $vd = $this->ValidateOrgs($auth, $demo, $org);
        $phone = $this->db->quote($this->ValidateTrim($phone, self::ErrEmptyPhone));
        $canonical = $this->db->quote(self::CanonicalPhone($phone));

        $this->beginTran();

        $query = "update phone
                    set phone = $phone, canonical = $canonical, demo = {$vd['demo']}, group_id={$vd['group']}
                    where id = $id";
        $this->Query($query, self::ErrDupPhone);

        $query = "update moose set phone_id = null where phone_id = $id";
        $this->Query($query);

        if ($mooseId != null)
        {
            $query = "update moose set phone_id = $id, demo = {$vd['demo']}, group_id={$vd['group']}
                where id = $mId";
            $this->Query($query);
        }

        $this->commit();

        return $this->db->lastInsertId();
    }

    function TogglePhone(CTinyAuth $auth, $phoneId, $del)
    {
        $del = filter_var($del, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($del === null)
            $this->Err('недопустимое значение del');

        $phoneId = $this->ValidateId($phoneId, self::ErrWrongPhoneId);
        if (!$auth->canAdmin() || !$this->CanModify($auth, $phoneId, false))
            $this->ErrRights();

        $del = $del ? 0 : 1;

        $query = "update phone set active = $del where id = $phoneId";
        $this->Query($query);

        return true;
    }



	function GetMooses(CTinyAuth $auth, $showRights)
	{
        $fShowRights = filter_var($showRights, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($fShowRights === null)
            $this->Err("Недопустимое значение $showRights");

        $access = $this->CanSeeCond($auth, 'moose');

        $phoneCond = $fShowRights ? 'true' : 'active = true';

        $query = "select distinct moose.id, phone, moose.name, phone_id, moose.group_id as mgid, moose.demo as mdemo
		            from moose
		            {$access['join']}
		            left join (select phone, id from phone where $phoneCond) p on phone_id = p.id
		            where {$access['cond']}
		            order by moose.name asc";

		$result = $this->Query($query);

		$arr = array();
		foreach ($result as $row)
        {
            $line = array("id" => $row['id'], "name" =>$row['name'], "phone" => self::Obfuscate($auth, $row['phone']));
            if ($fShowRights)
            {
                $line['phoneId'] = $row['phone_id'];
                $line['demo'] = $row['mdemo'] ? true : false;
                $line['groups'] = array();

                if (isset($row['mgid']) && $row['mgid'] !== null)
                    $line['groups'][] = $row['mgid'];
            }
			$arr[] = $line;
        }

		return $arr;
	}

	function GetPhones(CTinyAuth $auth, $showRights)
	{
        $fShowRights = filter_var($showRights, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($fShowRights === null)
            $this->Err("Недопустимое значение $showRights");

        $cond = $fShowRights ? 'true' : 'active = true';
        $access = $this->CanSeeCond($auth, 'phone');

        $query = "select distinct phone.id, phone, canonical, active, phone.group_id as pgid, phone.demo as pdemo, moose.id as mid
                    from phone
                    {$access['join']}
                     left outer join moose on moose.phone_id = phone.id
                    where $cond and {$access['cond']}
                    order by phone asc";
		$result = $this->Query($query);

		$arr = array();
		foreach ($result as $row)
        {
            $line = array("id" => $row['id'], "phone" => self::Obfuscate($auth, $row['phone']), "canonical" => self::Obfuscate($auth, $row['canonical']));
            if ($fShowRights)
            {
                $line['moose'] = $row['mid'];
                $line['demo'] = $row['pdemo'] ? true : false;
                $line['active'] = $row['active'] ? true : false;
                $line['groups'] = array();
                if (isset($row['pgid']) && $row['pgid'] !== null)
                    $line['groups'][] = $row['pgid'];
            }
			$arr[] = $line;
        }

		return $arr;
	}

    // todo а проверка на закрытость групп?
    private function GetRights(CMooseAuth $auth)
    {
        $query = "select ug.user_id, ug.group_id from usergroups ug
                    inner join users u on u.id = ug.user_id
                    inner join users g on g.id = ug.group_id
                    where
                        /* g.removeDate is null and */ g.is_group = 1 and u.is_group = 0";
        $result = $this->Query($query);

        $res = array();
        foreach ($result as $row)
        {
            $uid = $row['user_id'];
            if (!isset($res[$uid]))
                $res[$uid] = array('feed' => false, 'admin' => false, 'groups' => array());

            $gid = $row['group_id'];
            switch($gid)
            {
                case CTinyAuth::Super: $res[$uid]['super'] = true; break;
                case CTinyAuth::Admins: $res[$uid]['admin'] = true; break;
                case CMooseAuth::Feeders: $res[$uid]['feed'] = true; break;
                default: $res[$uid]['groups'][] = $gid;
            }
        }

        return $res;
    }

    function GetUserOrgs(CTinyAuth $auth)
    {
        if (!$auth->isLogged())
            return $this->ErrRights();

        $uid = $auth->id();
        $min = CMooseAuth::Demo;

        $query = "select g.id as gid, login, name
            from users g
             inner join usergroups ug on ug.group_id = g.id
             where g.id >= $min and g.is_group = 1 and g.removeDate is null and ug.user_id = $uid";
        $result  = $this->Query($query);
        $res = array();

        foreach ($result as $row)
            $res[] = array('id' => $row['gid'], 'login' => $row['login'], 'name' => $row['name']);

        return $res;
    }

    function GetUsers(CMooseAuth $auth, $inactive )
    {
        if (!$auth->canAdmin())
            return $this->ErrRights();

        $rights = $this->GetRights($auth);

        $cond =  ($inactive === true) ? "true" : "removeDate is null";

        $accessCond = 'true';
        $accessJoin = '';
        $min = CMooseAuth::Demo;

        if (!$auth->isSuper())
        {
            $uId = $auth->id();
            $accessCond = " (othId is not null or grId is not null)";

            $accessJoin = " -- для организаций:
            left join (select distinct group_id as grId
                from usergroups ug
                inner join users u on ug.group_id = u.id
                where ug.user_id = $uId and u.is_group = 1 and $cond) orgs on orgs.grId = users.id ".

          // для людей-гейтов                                       // todo -- проверить невидимость через удаленные группы
            "left join (select distinct oth.user_id  as othId
                from usergroups my
                inner join usergroups oth on oth.group_id = my.group_id
                inner join users g on g.id = my.group_id
                where my.user_id = $uId and my.group_id >= $min and g.removeDate is null) gates on gates.othId = users.id ";
        }

        $query = "select id, login, name, is_group, is_gate, pwd, removeDate
            from users
            $accessJoin
            where id >= $min and $cond and $accessCond
            order by login";

        $result = $this->Query($query);

        $res = array('users' => array(), 'org' => array(), 'gates' => array());

        foreach ($result as $row)
        {
            $id = $row['id'];
            $r = array('id' => $id, 'login' => $row['login'], 'name' => $row['name'], 'active' => $row['removeDate'] == null);
            if ($row['is_group'] == 1)
                $res['org'][] = $r;
            else
            {
                if (isset($rights[$id]))
                    foreach($rights[$id] as $key => $val)
                        $r[$key] = $val;

                $selector = $row['is_gate'] == 1 ? 'gates' : 'users';
                $res[$selector][] = $r;
            }
        }

        return $res;
    }

	function GetMooseTracks(CTinyAuth $auth, $ids, $start, $end)
	{
        $t0 = microtime(true);

        $ids = filter_var($ids, FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY);
        if ($ids === false || count($ids) == 0)
            return null;

		$cond = implode($ids, ", ");

		$timeCond = $this->TimeCondition('position.stamp', $start, $end);
        $access = $this->CanSeeCond($auth, 'm', false, 'moose');
		
		$query = "select lat, lon, DATE_FORMAT(position.stamp,'%Y-%m-%dT%TZ') as stamp, valid, sms.moose as moose from position
                inner join sms on position.sms_id = sms.id
                inner join moose m on m.id = sms.moose
                {$access['join']}
				where sms.moose in ($cond) $timeCond and {$access['cond']}
				order by sms.moose asc, position.stamp asc ";

        $t1 = microtime(true);
		$result = $this->Query($query);
        $t2 = microtime(true);

        $res = array();
        foreach ($result as $row)
        {
            $moose = $row['moose'];
            if (!isset($res[$moose]))
                $res[$moose] = array();

            $res[$moose][] = array($row['lat'], $row['lon'], $row['stamp'], $row['valid'] ? 1 : 0);
        }
        $result->closeCursor();

        $retVal = array();
        foreach($res as $id => $data)
            $retVal[] = array('id' => $id, 'track' => $data);


        $t3 = microtime(true);
        //Log::d($this, $auth, "track_times", "total: '" . ($t4-$t0) ."' que: '" . ($t2-$t1) . "' retr: '" . ($t3-$t2));
		return $retVal;
	}

	function GetMooseActivity(CTinyAuth $auth, $ids, $start, $end)
	{
        $t0 = microtime(true);
        $ids = filter_var($ids, FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY);
		if ($ids === false || count($ids) == 0)
			return null;

		$cond = implode($ids, ", ");

		$timeCond = $this->TimeCondition('activity.stamp', $start, $end);
        $access = $this->CanSeeCond($auth, 'm', false, 'moose');
		
		$query = "select DATE_FORMAT(activity.stamp,'%Y-%m-%dT%TZ') as stamp, max(active) as active, valid, sms.moose as moose
				from activity
				inner join sms on activity.sms_id = sms.id
				inner join moose m on m.id = sms.moose
				{$access['join']}
				where sms.moose in ($cond) $timeCond and {$access['cond']}
				 group by activity.stamp, valid, sms.moose
				order by sms.moose asc, activity.stamp asc ";

        $t1 = microtime(true);
		$result = $this->Query($query);
        $t2 = microtime(true);

        $res = array();
        foreach ($result as $row)
        {
            $moose = $row['moose'];
            if (!isset($res[$moose]))
                $res[$moose] = array();

            $res[$moose][] = array($row['stamp'], $row['active'] ? 1 : 0, $row['valid'] ? 1 : 0);
        }
        $result->closeCursor();

        $retVal = array();
        foreach($res as $id => $data)
            $retVal[] = array('id' => $id, 'activity' => $data);

        $t3 = microtime(true);

        //Log::d($this, $auth, "activity_times", "total: '" . ($t3-$t0) . "' que: '" . ($t2-$t1) . "' retr: '" . ($t3-$t2));
		return $retVal;
	}

    function GetSmsTrack(CTinyAuth $auth, $rawSmsId)
    {
        $rawSmsId = $this->ValidateId($rawSmsId, "Недопустимый id sms", 1);
        $access = $this->CanSeeCond($auth, 'p');

        $query = "select lat, lon, DATE_FORMAT(position.stamp,'%Y-%m-%dT%TZ') as stamp, valid
                from position
                inner join sms on position.sms_id = sms.id
                inner join raw_sms rs on rs.id = sms.raw_sms_id
                inner join phone p on p.id = rs.phone_id
                {$access['join']}
				where sms.raw_sms_id = $rawSmsId and {$access['cond']}
				order by position.stamp asc ";

        $result = $this->Query($query);
        $res = array();
        foreach ($result as $row)
            $res[] = array($row['lat'], $row['lon'], $row['stamp'], $row['valid'] ? 1 : 0);

        $result->closeCursor();

        return $res;
    }

    function GetSmsActivity(CTinyAuth $auth, $rawSmsId)
    {
        $rawSmsId = $this->ValidateId($rawSmsId, "Недопустимый id sms", 1);
        $access = $this->CanSeeCond($auth, 'p');

        $query = "select DATE_FORMAT(activity.stamp,'%Y-%m-%dT%TZ') as stamp, activity.active, valid
				from activity
				inner join sms on activity.sms_id = sms.id
				inner join raw_sms rs on rs.id = sms.raw_sms_id
                inner join phone p on p.id = rs.phone_id
				{$access['join']}
				where sms.raw_sms_id = $rawSmsId and {$access['cond']}
				order by activity.stamp asc";

        $result = $this->Query($query);

        $res = array();
        foreach ($result as $row)
            $res[] = array($row['stamp'], $row['active'] ? 1 : 0, $row['valid'] ? 1 : 0);
        $result->closeCursor();

        return $res;
    }

	function GetBeaconStat(CTinyAuth $auth, $ids, $start, $end, $all, $export)
	{
        $ids = filter_var($ids, FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY);
        if ($ids === false)
            return null;

		$cond = implode($ids, ", ");
		if ($cond != '')
			$cond = "and p.id in ($cond)";

		$timeCond = $this->TimeCondition('position.stamp', $start, $end);
        $access = $this->CanSeeCond($auth, 'p');

        $active = $all === true ? '' : ' and p.active = 1';
        $expCond = $export ? "rs.phone_id is not null and pos.sms_id is not null" : "true";

		$query = "select p.id as pId, phone, canonical, active, DATE_FORMAT(rs.stamp,'%Y-%m-%dT%TZ') as tm, rs.id as rsId, int_id, volt, temp, gps_on, gsm_tries, DATE_FORMAT(pos.st,'%Y-%m-%dT%TZ') as pos_time, m.name as mName
				from phone p
                {$access['join']}
				left join raw_sms rs on rs.phone_id = p.id
				left join sms on sms.raw_sms_id = rs.id
				left join (select sms_id, max(stamp) as st from position where true $timeCond group by sms_id) pos on pos.sms_id = sms.id
				left join moose m on m.phone_id = p.id
				where $expCond $cond $active and {$access['cond']}

			 order by phone, pos.st desc";

		$result = $this->Query($query);

		$res = array();
		foreach ($result as $row)
		{
			$ph = $row['pId'];
			if (!isset($res[$ph]))
				$res[$ph] = array('id' => $ph, 'phone' => self::Obfuscate($auth, $row['phone']), 'canonical' => self::Obfuscate($auth, $row['canonical']), 'moose' => $row['mName'], 'active' => $row['active'] == 1, 'data' => array());

			$res[$ph]['data'][] = array($row['tm'], $row['pos_time'], $row['int_id'], $row['volt'], $row['temp'], $row['gps_on'], $row['gsm_tries'], $row['rsId']);
		}
		$result->closeCursor();
		
		$retVal = array();
		foreach($res as $data)
			$retVal[] = $data;

		return $retVal;
	}

    public static function CanonicalPhone($phone)
    {
        if ($phone == null || !is_string($phone))
            return '';

        $ph = preg_replace("/\D+/i", '', $phone);
        $len = strlen($ph);
        if ($len != 11)
            return $ph;

        return preg_replace("/^[78]/", "+7", $ph);
    }

    protected static function Obfuscate(CTinyAuth $auth, $phone)
    {
        return $auth->isLogged() ? $phone : preg_replace("/.{2}$/", 'xx', $phone);
    }

    function ReassignSmses(CMooseAuth $auth, $smsIds, $moose)
    {
        $moose = $moose != null ? $this->ValidateId($moose, self::ErrWrongMooseId, 1): 'null';

        if (!$auth->isSuper() || !$this->CanModify($auth, $moose, true))     // todo разрешить не только super, проверка на права
            $this->ErrRights();

        $ids = filter_var($smsIds, FILTER_VALIDATE_INT, array('flags' => FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY, 'options' => array('min_range' => 1)));
        if ($ids === false || count($ids) == 0)
            return $this->Err(self::ErrWrongSmsId);

        $ids = implode(', ', $ids);

        $query = "update sms
          set moose = $moose
          where raw_sms_id in ($ids)";

        $result = $this->Query($query);
        $result->closeCursor();

        Log::t($this, $auth, 'reassignSms', "перевешиваем на лося '$moose', rawSmsIds: '$ids'");
        return array('res' => true, 'rc' => $result->rowCount());
    }

    function TogglePoint(CMooseAuth $auth, $mooseId, $time, $valid)
    {
        $mooseId = $this->ValidateId($mooseId, self::ErrWrongMooseId, 1);
        if (!$this->CanModify($auth, $mooseId, true))
            $this->ErrRights();

        return $this->CoreTogglePoint($auth, $time, $valid, "moose = $mooseId");
    }

    function TogglePoint2(CMooseAuth $auth, $rawSmsId, $time, $valid)
    {
        $rawSmsId = $this->ValidateId($rawSmsId, self::ErrWrongMooseId, 1);
        if (!$this->CanModifySms($auth, $rawSmsId))
            $this->ErrRights();

        return $this->CoreTogglePoint($auth, $time, $valid, "raw_sms_id = $rawSmsId");
    }

    private function CoreTogglePoint(CMooseAuth $auth, $time, $valid, $condition)
    {
        if (!$auth->canAdmin())
            $this->ErrRights();

        $fValid = filter_var($valid, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($fValid === null)
            $this->Err("недопустимое значение активности '$valid'");

        $fValid = $fValid ? 1 : 0;

        $stamp = strtotime($time);
        if ($stamp === false)
            $this->Err("Некорректное время: '$time'");
        $sqlTime = $this->ToSqlTime($stamp);

        $query = "update position
            set valid = $fValid
            where sms_id in (select id from sms where $condition) and stamp = $sqlTime";

        $result = $this->Query($query);
        $result->closeCursor();

        Log::t($this, $auth, "togglePoint", "condition '$condition', time: '$time', valid: '$fValid'");

        return array('res' => true, 'rc' => $result->rowCount());
    }

    function DeleteRawSms(CMooseAuth $auth, $rawSmsId)
    {
        if (!$auth->isRoot())
            $this->ErrRights();

        $this->ValidateId($rawSmsId, "Incorrect raw sms id", 1);  // todo разрешить не только root, проверка на права

        $this->beginTran();

        $query = "delete from activity where sms_id in (select id from sms where raw_sms_id = $rawSmsId)";
        $this->Query($query);

        $query = "delete from position where sms_id in (select id from sms where raw_sms_id = $rawSmsId)";
        $this->Query($query);

        $query = "delete from sms where raw_sms_id = $rawSmsId";
        $this->Query($query);

        $query = "delete from raw_sms where id = $rawSmsId";
        $this->Query($query);

        $this->commit();

        Log::t($this, $auth, "deleteSms", "Raw sms id: $rawSmsId");
    }

	function AddData(CMooseAuth $auth, $phone, CMooseSMS $msg, $moose = null)
	{
		if (!$auth->canFeed() || !$auth->isRoot() && $moose != null) // добавлять не текущему лосю может лишь рут
			$this->ErrRights();

		$prop = $this->PhoneProp($auth, $phone);
        if ($moose != null)
            $prop['mooseId'] = $this->MooseProp($auth, $moose);
		if ($prop['mooseId'] == null)
			$prop['mooseId'] = 'null';

        $this->beginTran();
		$rawSmsId = $this->AddRawSms($prop['phoneId'], $msg, $auth->id());

        $this->ValidateId($msg->id, 'Incorrect internal number', 0);

		$res = array('rawSms' => $rawSmsId, 'moose' => $prop['mooseId']);

		if (!$msg->IsValid()) // не смогли разобрать SMS
		{
			$res['error'] = $msg->GetErrorMessage();
            $this->commit();
			return $res;
		}

		$query = "insert into sms (moose, raw_sms_id, int_id, volt, temp, gsm_tries, gps_on) 
				values ( {$prop['mooseId']}, $rawSmsId, {$msg->id}, 
						{$msg->volt}, {$msg->temp}, {$msg->gsmTries}, {$msg->gpsOn})";
		$this->Query($query);

		$smsId = $this->db->lastInsertId();
		if ($msg->points != null)
			$this->AddPoints($smsId, $msg->points);
		if ($msg->activity != null)
			$this->AddActivity($smsId, $msg->activity);

		// todo set global timestamp
        $this->commit();
		$res['sms'] = $smsId;
		$res['temp'] = $msg->temp;
		return $res;
	}

    // а не проверить ли еще и доступность лосей? -- нет, они пока синхронны
    protected function PhoneProp(CTinyAuth $auth, $phone)
    {
        $qPhone = $this->db->quote(self::CanonicalPhone($phone));

        $access = $this->CanSeeCond($auth, 'p');

        $query = "select moose.id as id, p.id as pId
				from phone p
				 {$access['join']}
				left join moose on phone_id = p.id
				where p.canonical = $qPhone and {$access['cond']}";
        $result = $this->Query($query);

        $row = $result->fetch(PDO::FETCH_ASSOC);
        if ($row == null)
            $this->Err("Нет доступных телефонов с номером '$phone'");

        $res = array('mooseId' => $row['id'], 'phoneId' => $row['pId']);
        $result->closeCursor();
        return $res;
    }

    protected function MooseProp(CTinyAuth $auth, $moose)
    {
        if ($moose == null || !is_string($moose))
            return null;

        $qMoose = $this->db->quote($moose);
        $access = $this->CanSeeCond($auth, 'm');

        $query = "select m.id as id
				from moose m
				 {$access['join']}
				where m.name = $qMoose and {$access['cond']}";
        $result = $this->Query($query);

        $row = $result->fetch(PDO::FETCH_ASSOC);
        if ($row == null)
            $this->Err("Нет доступных животных с именем '$qMoose'");

        $res = $row['id'];
        $result->closeCursor();
        return $res;
    }

	protected function AddRawSms($phoneId, CMooseSMS $msg, $userId)
	{
		$text = $this->db->quote($msg->text);
		$stamp = $this->ToSqlTime($msg->time);

		$ip = $this->db->quote(@$_SERVER['REMOTE_ADDR']);
		$xfwIp = $this->db->quote(@$_SERVER['HTTP_X_FORWARDED_FOR']);

		$query = "insert into raw_sms 
				(phone_id, stamp, text, user_id, ip, xfw_ip)
				values ($phoneId, $stamp, $text, $userId, $ip, $xfwIp)";

		$this->Query($query, self::ErrDupSms);

		return $this->db->lastInsertId();		
	}

	protected function AddPoints($smsId, $points)
	{
		$values = array();		
		$tm = time() - count($points) - 10000; //
		foreach ($points as $pt)
		{
			$stamp = $this->ToSqlTime(isset($pt[2]) ? $pt[2] : $tm);
			$values[] = "({$pt[0]}, {$pt[1]}, $stamp, $smsId)";
			$tm++;
		}
		$query = "insert into position (lat, lon, stamp, sms_id) values ". implode($values, ', ');

		$this->Query($query);
    }

	protected function AddActivity($smsId, $activity)
	{
		$values = array();		
		$tm = time() - count($activity) - 10000; //
		foreach ($activity as $pt)
		{
			$stamp = $this->ToSqlTime(isset($pt[0]) ? $pt[0] : $tm);
			$bit = $pt[1] != 0 ? 1 : 0;
			$values[] = "($stamp, $bit, $smsId)";
			$tm++;
		}
		$query = "insert into activity (stamp, active, sms_id) values ". implode($values, ', ');

		$this->Query($query);
    }

    function GetGateData(CTinyAuth $auth, $limit = null, $justErr, $phoneIds)
    {
        if (!$auth->canAdmin())
            $this->ErrRights();

        $limit = ($limit === null) ? 100 : $this->ValidateId($limit, "Недопустимое значение limit", 1);
        $justErr = $justErr === true ? ' (s.id is null or m.id is null or char_length(rs.text) < 50) ' : 'true';

        if ($phoneIds === null)
            $phones = 'true';
        else
        {
            $phones = filter_var($phoneIds, FILTER_VALIDATE_INT, array('flags' => FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY, 'options' => array('min_range' => 1)));
            if (!$phones)
                $this->Err(ErrWrongPhoneId);
            $phones = 'rs.phone_id in (' . implode(', ', $phones). ')';
        }

        $access = $this->CanSeeCond($auth, 'p');

        $query = "select rs.id, rs.text, rs.stamp, DATE_FORMAT(rs.stamp,'%Y-%m-%dT%TZ') as sstamp, rs.ip, rs.xfw_ip, p.phone, s.id as 'sid', us.login, us.name as 'uname', m.name as 'mname'
            from raw_sms rs
            inner join users us on us.id = rs.user_id
            inner join phone p on p.id = rs.phone_id

            {$access['join']}

            left join sms s on s.raw_sms_id = rs.id
            left join moose m on m.id = s.moose

            where true and {$access['cond']} and $justErr and $phones
            order by id desc limit $limit";

        $result = $this->Query($query);
        $res = array();
        foreach($result as $r)
        {
            $tmp = array('id' => $r['id'],
                'sid' => $r['sid'],
                'stamp' => $r['sstamp'],
                'text' => $r['text'],
                'ip' => $r['ip'],
                'xfwIp' => $r['xfw_ip'],
                'phone' => $r['phone'],
                'login' => $r['login'],
                'name' => $r['uname'],
                'moose' => $r['mname'],
                'error' => '');

            if ($tmp['sid'] == null)
            {
                $msg = new CMooseSMS($tmp['text'], strtotime($tmp['stamp']));
                $tmp['error'] = $msg->GetErrorMessage();
            }
            $res[] = $tmp;
        }

        return $res;
    }

	private function ToSqlTime($stamp)
	{
		return "'".gmdate('Y-m-d H:i:s', $stamp)."'";
	}
}
?>