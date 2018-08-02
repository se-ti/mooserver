<?php
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

    // should be called only with verified $rawSmsIds
    protected function GetRawSmsMooses(CMooseAuth $auth, $rawSmsIds)
    {
        if ($rawSmsIds == null || count($rawSmsIds) == 0)
            return [];

        $ids = implode(", ", $rawSmsIds);
        $query = "select distinct s.moose
                    from raw_sms rs
                    left join sms s on s.raw_sms_id = rs.id
                    where rs.id = ($ids) and s.moose is not null";

        $res = $this->Query($query);
        $result = [];
        foreach ($res as $r)
            $result[] = $r['moose'];

        return $result;
    }


    /// @returns complex condition with join or query result
    /// $alias -- table alias in query
    /// $table -- real table name. Not null means you need query results
    /// $externAlias -- field name in extern query. Used only if $table != null
    private function CanSeeCond(CTinyAuth $auth, $alias, $table = null, $extAlias = null)
    {
        if ($auth->isSuper())
            return array('join' => '', 'cond' => 'true');

        $id = $auth->id();
        $demo = CMooseAuth::Demo;

        $res = array(
            'join' => "inner join usergroups ug on ug.group_id = $alias.group_id or ug.group_id = $demo and $alias.demo = 1
		            inner join users u on u.id = ug.group_id",

            'cond' => "(ug.user_id = $id and u.removeDate is null)");

        if ($table == null)
            return $res;

        $query = "select $alias.id from $table $alias
            {$res['join']}
            where {$res['cond']}";

        $ids = array();
        $result = $this->Query($query);
        foreach ($result as $r)
            $ids[] = $r['id'];

        $res['join'] = '';
        if ($extAlias == null)
            $extAlias = "$alias.id";

        if (count($ids) == 0)
            $res['cond'] = "$extAlias is null";
        else
            $res['cond'] = "$extAlias in (" . join(', ', $ids) . ") ";

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

    protected function addGroupsCanAdmin(&$groups)
    {
        $groups = parent::addGroupsCanAdmin($groups);
        $groups[] = CMooseAuth::Feeders;                       // даже если у самого прав нет -- его можно добавить
        return $groups;
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

        $name = $this->ValidateTrimQuote($name, self::ErrEmptyMoose);

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

        $name = $this->ValidateTrimQuote($name, self::ErrEmptyMoose);

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

        $phone = $this->ValidateTrimQuote($phone, self::ErrEmptyPhone);
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
        $phone = $this->ValidateTrimQuote($phone, self::ErrEmptyPhone);
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

        $query = "select distinct moose.id, phone, moose.name, phone_id, moose.group_id as mgid, moose.demo as mdemo, DATE_FORMAT(mintt,'%Y-%m-%dT%TZ') as min_t, DATE_FORMAT(maxtt,'%Y-%m-%dT%TZ') as max_t
		            from moose
		            {$access['join']}
		            left join (select phone, id from phone where $phoneCond) p on phone_id = p.id
		            /* left join (select s.moose, min(ps.stamp) as mint, max(ps.stamp) as maxt from sms s inner join position ps on ps.sms_id = s.id group by moose) mm on moose.id = mm.moose */
		             left join (select s.moose, min(s.mint) as mintt, max(s.maxt) as maxtt from sms s group by moose) mm on moose.id = mm.moose 
		            where {$access['cond']}
		            order by moose.name asc";

		$result = $this->Query($query);

        $arr = array();
		foreach ($result as $row)
        {
            $line = array("id" => $row['id'], "name" =>$row['name'], "phone" => self::Obfuscate($auth, $row['phone']), "min" => $row['min_t'], "max" => $row['max_t']);
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

        $res = ['users' => [], 'org' => [], 'gates' => []];

        foreach ($result as $row)
        {
            $id = $row['id'];
            $r = ['id' => $id, 'login' => $row['login'], 'name' => $row['name'], 'active' => $row['removeDate'] == null];
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

    function GetVisibleUsers(CMooseAuth $auth)
    {
        $min = CMooseAuth::Demo;
        $demo = CMooseAuth::Demo;

        if ($auth-> isSuper())
        {
            $join = '';
            $cond = 'true';
        }
        else
        {
            $me = $auth->id();
            $join = "inner join usergroups ug on ug.user_id = u.id
                     inner join users gr on ug.group_id = gr.id
                     inner join usergroups my on my.group_id = gr.id";
            $cond = "ug.group_id <> $demo and gr.removeDate is null and gr.is_group = 1 and my.user_id = $me";
        }

        $query = "select u.id, u.login, u.name 
            from users u
            $join
            where u.is_group = 0 and u.is_gate = 0 and u.id >= $min and  $cond";

        $result = $this->Query($query);

        $res = [];
        foreach ($result as $row)
            $res[] = ['id' => $row['id'],
                'name' => $row['name'] != null || trim($row['name']) != ''? trim($row['name']) : $row['login']];
        return $res;
    }

    // call on update: +new sms, + reassign sms, + toggle is point valid, comment point , import === new sms, + DeleteRawSms
    protected function SetMooseTimestamp(CTinyAuth $auth, $ids)
    {
        $ids = filter_var($ids, FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY);
        if ($ids === false || count($ids) == 0)
            return;
        $cond = implode($ids, ", ");
        $query = "update moose 
                    set upd_stamp = UTC_TIMESTAMP 
                    where id in ($cond)";

        $this->Query($query);
    }

    function GetMooseTimestamps(CTinyAuth $auth, $ids)
    {
        $ids = filter_var($ids, FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY);
        if ($ids === false || count($ids) == 0)
            return null;

        $cond = implode($ids, ", ");
        $access = $this->CanSeeCond($auth, 'm', 'moose');

        $query = "select id, DATE_FORMAT(upd_stamp, '%Y-%m-%dT%TZ') as stamp  
                from moose m
                {$access['join']}
                where id in ($cond) and {$access['cond']}";

        $result = $this->Query($query);
        $res = [];
        foreach ($result as $row)
            $res[$row['id']] = strtotime($row['stamp']);
        $result->closeCursor();

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
        $access = $this->CanSeeCond($auth, 'm', 'moose');
		
		$query = "select lat, lon, DATE_FORMAT(position.stamp,'%Y-%m-%dT%TZ') as stamp, valid, position.comment, author_id, DATE_FORMAT(comment_stamp,'%Y-%m-%dT%TZ') as cstamp, sms.moose as moose 
                from position
                inner join sms on position.sms_id = sms.id
                inner join moose m on m.id = sms.moose
                {$access['join']}
				where sms.moose in ($cond) $timeCond and {$access['cond']}
				order by sms.moose asc, position.stamp asc ";

        $t1 = microtime(true);
		$result = $this->Query($query);
        $t2 = microtime(true);

        $res = [];
        $rec = null;
        foreach ($result as $row)
        {
            $moose = $row['moose'];
            if (!isset($res[$moose]))
                $res[$moose] = [];

            $rec = [$row['lat'], $row['lon'], $row['stamp'], $row['valid'] ? 1 : 0];
            if ($row['comment'] != null)
            {
                $rec[] = $row['comment'];
                $rec[] = $row['author_id'];
                $rec[] = $row['cstamp'];
            }
            $res[$moose][] = $rec;
        }
        $result->closeCursor();

        $retVal = [];
        foreach($res as $id => $data)
            $retVal[] = ['id' => $id, 'track' => $data];


        $t3 = microtime(true);
        //Log::d($this, $auth, "times", "track total: '" . ($t4-$t0) ."' que: '" . ($t2-$t1) . "' retr: '" . ($t3-$t2));
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
        $access = $this->CanSeeCond($auth, 'm', 'moose', 'sms.moose');

		$query = "select DATE_FORMAT(activity.stamp,'%Y-%m-%dT%TZ') as stamp, max(active) as active, valid, sms.moose as moose
				from activity				
				inner join sms on activity.sms_id = sms.id
				{$access['join']}
				where  sms.moose in ($cond) $timeCond and {$access['cond']}
				 group by activity.stamp, valid, sms.moose
				order by sms.moose asc, activity.stamp asc ";

        $old = $this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $t1 = microtime(true);
        $result = $this->Query($query);
        $t2 = microtime(true);

        $res = array();
        foreach ($result as $row)
        {
            $moose = $row['moose'];
            if (!isset($res[$moose]))
                $res[$moose] = [];

            //$res[$moose][] = new CActivity($row);
            $res[$moose][] = [$row['stamp'], $row['active'] ? 1 : 0, $row['valid'] ? 1 : 0];
        }
        $result->closeCursor();

        $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $old);
//        Log::d($this, $auth, "times", sprintf("activity rows: %d", $rowCount));

        $retVal = array();
        foreach($res as $id => $data)
            $retVal[] = array('id' => $id, 'activity' => $data);

        $t3 = microtime(true);

        //Log::d($this, $auth, "times", sprintf("activity total: %.4f query: %.4f, retrieve %4f", ($t3-$t0), ($t2-$t1), ($t3-$t2)));
        return $retVal;
	}

    function GetSmsTrack(CTinyAuth $auth, $rawSmsId)
    {
        $rawSmsId = $this->ValidateId($rawSmsId, "Недопустимый id sms", 1);

        $pAccess = $this->CanSeeCond($auth, 'p', 'phone', 'rs.phone_id');
        $mAccess = $this->CanSeeCond($auth, 'm', 'moose', 'sms.moose');

        $query = "select lat, lon, DATE_FORMAT(position.stamp,'%Y-%m-%dT%TZ') as stamp, valid, comment, author_id, DATE_FORMAT(comment_stamp,'%Y-%m-%dT%TZ') as cstamp
                from position
                inner join sms on position.sms_id = sms.id
                inner join raw_sms rs on rs.id = sms.raw_sms_id
				where sms.raw_sms_id = $rawSmsId and (sms.moose is null and {$pAccess['cond']} or {$mAccess['cond']})
				order by position.stamp asc ";

        $result = $this->Query($query);
        $res = [];
        $rec = null;
        foreach ($result as $row)
        {
            $rec =  [$row['lat'], $row['lon'], $row['stamp'], $row['valid'] ? 1 : 0];
            if ($row['comment'] != null)
            {
                $rec[] = $row['comment'];
                $rec[] = $row['author_id'];
                $rec[] = $row['cstamp'];
            }
            $res[] = $rec;
        }

        $result->closeCursor();

        return $res;
    }

    function GetSmsActivity(CTinyAuth $auth, $rawSmsId)
    {
        $rawSmsId = $this->ValidateId($rawSmsId, "Недопустимый id sms", 1);
        $pAccess = $this->CanSeeCond($auth, 'p', 'phone', 'rs.phone_id');
        $mAccess = $this->CanSeeCond($auth, 'm', 'moose', 'sms.moose');

        $query = "select DATE_FORMAT(activity.stamp,'%Y-%m-%dT%TZ') as stamp, activity.active, valid
				from activity
				inner join sms on activity.sms_id = sms.id
				inner join raw_sms rs on rs.id = sms.raw_sms_id
				where sms.raw_sms_id = $rawSmsId and (sms.moose is null and {$pAccess['cond']} or {$mAccess['cond']})
				order by activity.stamp asc";

        $result = $this->Query($query);

        $res = array();
        foreach ($result as $row)
            $res[] = array($row['stamp'], $row['active'] ? 1 : 0, $row['valid'] ? 1 : 0);
        $result->closeCursor();

        return $res;
    }

    private function GetBeacons(CTinyAuth $auth, $ids, $all)
    {
        $ids = filter_var($ids, FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY);
        if ($ids === false)
            return null;

        $ids = implode($ids, ", ");
        $cond = ($ids != '')? "p.id in ($ids)" : 'true';
        $mcond = ($ids != '')? "rs.phone_id in ($ids)" : 'true';

        $pAccess = $this->CanSeeCond($auth, 'p', 'phone');
        $mAccess = $this->CanSeeCond($auth, 'm', 'moose');
        $mooseAccess = str_replace('m.id', 'sms.moose', $mAccess['cond']);  // hack c проверкой прав.

        $query = "select id, 1 as 'flag' 
              from phone p
            where $cond and {$pAccess['cond']}
            
            union 
            
            select distinct rs.phone_id as 'id', 0 as 'flag'
              from raw_sms rs 
              inner join sms on raw_sms_id = rs.id
            where $mooseAccess and $mcond";

        $res = $this->Query($query);

        $outIds = [];
        $filtIds = [];
        foreach($res as $data) {
            $id = $data['id'];
            $outIds[] = $id;
            if ($data['flag'] == 1)
                $filtIds[] = $id;
        }
        $res->closeCursor();

        if (count($outIds) == 0)
            return null;

        $active = $all === true ? 'true' : 'p.active = 1';
        $cond = implode($outIds, ', ');
        $query = "select p.id as 'pId', phone, canonical, active, m.name as 'mName'
                    from phone p
                    left join moose m on m.phone_id = p.id and {$mAccess['cond']}
                    where p.id in ($cond) and $active
                    order by phone";

        $res = $this->Query($query);

        $bIds = [];
        $beacons = [];
        foreach($res as $row)
        {
            $id = $row['pId'];
            $bIds[] = $id;
            $beacons[$id] = ['id' => $id, 'phone' => self::Obfuscate($auth, $row['phone']), 'canonical' => self::Obfuscate($auth, $row['canonical']), 'moose' => $row['mName'], 'active' => $row['active'] == 1, 'data' => []];
        }
        $res->closeCursor();

        return ['beacons' => $beacons,
                'ids' => $bIds,
                'directIds' => $filtIds,
                'mAccess' => $mooseAccess];
    }

    /* returns:
    -- список приборов, содержащих доступные пользователю смс ($all - все или только активные)
    -- список доступных смс, которые привязаны к этому прибору из диапазона $start - $end
    -- на экспорт -- только приборы с данными*/
    function GetBeaconStat(CTinyAuth $auth, $ids, $start, $end, $all, $export)
    {
        $t1 = microtime(true);

        $beacons = $this->GetBeacons($auth, $ids, $all);
        if ($beacons == null || count($beacons['ids']) == 0)   // нет приборов
            return null;

        $t2 = microtime(true);

        $addText = $auth->isSuper() && $export != true;

        $cond = implode($beacons['ids'], ", ");
        $direct = 'false';
        if (count($beacons['directIds']) > 0)
            $direct = 'rs.phone_id in (' . implode($beacons['directIds'], ', ') . ')';
        $timeCond = $this->TimeCondition('position.stamp', $start, $end);

        $query = "select rs.phone_id as pId, DATE_FORMAT(rs.stamp,'%Y-%m-%dT%TZ') as tm, rs.id as rsId, text, int_id, volt, temp, gps_on, gsm_tries, DATE_FORMAT(pos.st,'%Y-%m-%dT%TZ') as pos_time, sms.moose as smsMid
				from raw_sms rs
				inner join sms on sms.raw_sms_id = rs.id
				left join (select sms_id, max(stamp) as st from position where true $timeCond group by sms_id) pos on pos.sms_id = sms.id
				
				where rs.phone_id in ($cond) and (sms.moose is null and $direct or {$beacons['mAccess']})
				 order by pos.st desc
				  ";// в принципе те маяки и (доступное животное или непривязанное смс с ныне доступного маяка)


        //Log::t($this, $auth, 'beaconStat', $query);
        $result = $this->Query($query);

        $t3 = microtime(true);

        $hash = $beacons['beacons'];
        foreach ($result as $row)
        {
            $ph = $row['pId'];
            if (!isset($hash[$ph]))
                Log::e($this, $auth, 'beaconStat', "no phone with id '$ph'");

            if ($row['pos_time'] != null)
                $hash[$ph]['data'][] = [$row['tm'], $row['pos_time'], $row['int_id'], $row['volt'], $row['temp'], $row['gps_on'], $row['gsm_tries'], $row['rsId'], $addText ? $row['text'] : null, $row['smsMid']];
        }
        $result->closeCursor();

        $retVal = [];
        foreach($hash as $data)
            if ($export != true || count($data['data']) > 0)       // на экспорт только маяки с данными
                $retVal[] = $data;

        $t4 = microtime(true);
        //Log::t($this, $auth, "times",  sprintf("beacon stat: %4.0f,  query: %4.0f, proc: %4.0f", ($t4 - $t1) * 1000,  ($t3 - $t2) * 1000, ($t4 - $t3) * 1000));

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
        $qmoose = $moose != null ? $this->ValidateId($moose, self::ErrWrongMooseId, 1): 'null';

        if (!$auth->isSuper() || !$this->CanModify($auth, $qmoose, true))     // todo разрешить не только super, проверка на права
            $this->ErrRights();

        $ids = filter_var($smsIds, FILTER_VALIDATE_INT, array('flags' => FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY, 'options' => array('min_range' => 1)));
        if ($ids === false || count($ids) == 0)
            return $this->Err(self::ErrWrongSmsId);

        $qids = implode(', ', $ids);

        $query = "update sms
          set moose = $qmoose
          where raw_sms_id in ($qids)";

        $this->beginTran();
        $result = $this->Query($query);
        $result->closeCursor();

        $mooses = $this->GetRawSmsMooses($auth, $ids);
        if ($moose != null)
            $mooses[] = $moose;
        $this->SetMooseTimestamp($auth, $mooses);

        $this->commit();

        Log::t($this, $auth, 'reassignSms', "перевешиваем на животное '$moose', rawSmsIds: '".implode(", ", $ids) ."'");
        return array('res' => true, 'rc' => $result->rowCount());
    }

    function ToggleMoosePoint(CMooseAuth $auth, $mooseId, $time, $valid)
    {
        $mooseId = $this->ValidateId($mooseId, self::ErrWrongMooseId, 1);
        if (!$this->CanModify($auth, $mooseId, true))
            $this->ErrRights();

        $this->beginTran();
        $res = $this->CoreTogglePoint($auth, $time, $valid, "moose = $mooseId");
        $this->SetMooseTimestamp($auth, [$mooseId]);
        $this->commit();

        return $res;
    }

    function ToggleSmsPoint(CMooseAuth $auth, $rawSmsId, $time, $valid)
    {
        $rawSmsId = $this->ValidateId($rawSmsId, self::ErrWrongMooseId, 1);
        if (!$this->CanModifySms($auth, $rawSmsId))
            $this->ErrRights();

        $this->beginTran();
        $res = $this->CoreTogglePoint($auth, $time, $valid, "raw_sms_id = $rawSmsId");
        $this->SetMooseTimestamp($auth, $this->GetRawSmsMooses($auth, [$rawSmsId]));
        $this->commit();

        return $res;
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

        return ['res' => true, 'rc' => $result->rowCount()];
    }

    function CommentMoosePoint(CMooseAuth $auth, $mooseId, $time, $comment)
    {
        $mooseId = $this->ValidateId($mooseId, self::ErrWrongMooseId, 1);
        if (!$this->CanModify($auth, $mooseId, true))
            $this->ErrRights();

        $this->beginTran();
        $res = $this->CoreCommentPoint($auth, $time, $comment, "moose = $mooseId");
        $this->SetMooseTimestamp($auth, [$mooseId]);
        $this->commit();

        return $res;
    }

    function CommentSmsPoint(CMooseAuth $auth, $rawSmsId, $time, $comment)
    {
        $rawSmsId = $this->ValidateId($rawSmsId, self::ErrWrongMooseId, 1);
        if (!$this->CanModifySms($auth, $rawSmsId))
            $this->ErrRights();

        $this->beginTran();
        $res = $this->CoreCommentPoint($auth, $time, $comment, "raw_sms_id = $rawSmsId");
        $this->SetMooseTimestamp($auth, $this->GetRawSmsMooses($auth, [$rawSmsId]));
        $this->commit();

        return $res;
    }

    private function CoreCommentPoint(CMooseAuth $auth, $time, $comment, $condition)
    {
        if ($comment === null || !is_string($comment) || trim($comment) == '')
        {
            $qComment = 'null';
            $author = 'null';
            $cstamp = 'null';
            $log = 'clear comment';
        }
        else
        {
            $qComment = $this->ValidateTrimQuote($comment);
            $author = $auth->id();
            $cstamp = 'UTC_TIMESTAMP';
            $log = "comment: $qComment";
        }

        $stamp = strtotime($time);
        if ($stamp === false)
            $this->Err("Некорректное время: '$time'");
        $sqlTime = $this->ToSqlTime($stamp);

        $query = "update position
            set comment = $qComment, author_id = $author, comment_stamp = $cstamp
            where sms_id in (select id from sms where $condition) and stamp = $sqlTime";

        $result = $this->Query($query);
        $t = time();
        $result->closeCursor();

        Log::t($this, $auth, "comment", "condition '$condition', time: '$time', $log");

        return ['res' => true, 'rc' => $result->rowCount(), 'authorId' => $auth->id(), 'cstamp' => gmdate('Y-m-d', $t) .'T'. gmdate('h:i:s', $t). 'Z'];
    }

    function DeleteRawSms(CMooseAuth $auth, $rawSmsId)
    {
        if (!$auth->isSuper())
            $this->ErrRights();

        $this->ValidateId($rawSmsId, "Incorrect raw sms id", 1);  // todo разрешить не только root, проверка на права

        $this->beginTran();

        $mooses = $this->GetRawSmsMooses($auth, [$rawSmsId]);

        $query = "delete from activity where sms_id in (select id from sms where raw_sms_id = $rawSmsId)";
        $this->Query($query);

        $query = "delete from position where sms_id in (select id from sms where raw_sms_id = $rawSmsId)";
        $this->Query($query);

        $query = "delete from sms where raw_sms_id = $rawSmsId";
        $this->Query($query);

        $query = "delete from raw_sms where id = $rawSmsId";
        $this->Query($query);

        $this->SetMooseTimestamp($auth, $mooses);

        $this->commit();

        Log::t($this, $auth, "deleteSms", "Raw sms id: $rawSmsId");
    }

	function AddData(CMooseAuth $auth, $phone, CMooseSMS $msg, $moose = null)
	{
		if (!$auth->canFeed() || !$auth->isSuper() && $moose != null) // добавлять не текущему лосю может лишь супер
			$this->ErrRights();

		$prop = $this->PhoneProp($auth, $phone);
        if ($moose != null)
            $prop['mooseId'] = $this->MooseProp($auth, $moose);

        $qMooseId = $prop['mooseId'] != null ? $prop['mooseId'] : 'null';

        $this->beginTran();
		$rawSmsId = $this->AddRawSms($prop['phoneId'], $msg, $auth->id());

        $this->ValidateId($msg->id, 'Incorrect internal number', 0);

		$res = ['rawSms' => $rawSmsId, 'moose' => $prop['mooseId']];

		if (!$msg->IsValid()) // не смогли разобрать SMS
		{
			$res['error'] = $msg->GetErrorMessage();
            $this->commit();
			return $res;
		}

		$qDiag = ($msg->diag == null || trim($msg->diag) == '') ? 'null' : $this->ValidateTrimQuote($msg->diag);

		$query = "insert into sms (moose, raw_sms_id, int_id, volt, temp, gsm_tries, gps_on, diagnose) 
				values ( $qMooseId, $rawSmsId, {$msg->id}, 
						{$msg->volt}, {$msg->temp}, {$msg->gsmTries}, {$msg->gpsOn}, $qDiag)";
		$this->Query($query);

		$smsId = $this->db->lastInsertId();
		if ($msg->points != null)
			$this->AddPoints($smsId, $msg->points);
		if ($msg->activity != null)
			$this->AddActivity($smsId, $msg->activity);

        // set sms mint-maxt  todo а если точек нет?  и вообще сделать на триггерах
        $query = "update sms s set 
                    mint = (select min(p.stamp) from position p where p.sms_id = $smsId), 
                    maxt = (select max(p.stamp) from position p where p.sms_id = $smsId)
                    WHERE s.id = $smsId";
        $this->Query($query);

        if ($prop['mooseId'] != null)
            $this->SetMooseTimestamp($auth, [$prop['mooseId']]);    // todo set global timestamp

        $this->commit();
		$res['sms'] = $smsId;
		$res['temp'] = $msg->temp;
		return $res;
	}

    // todo а не проверить ли еще и доступность лосей? -- нет, они пока синхронны
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

		$this->Query($query, self::ErrDupSms . " phoneId: $phoneId, message: $text");

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

    function MakeUserGate(CMooseAuth $auth, $userId)
    {
        if (!$auth->canAdmin() || !$this->CanAccess($auth, $userId, false))
            $this->ErrRights();

        $query = "update users set is_gate = 1 where id = $userId";
        $this->Query($query);
    }

    function GetGateData(CTinyAuth $auth, $limit = null, $justErr, $phoneIds, $mooseIds)
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
                $this->Err(self::ErrWrongPhoneId);
            $phones = 'rs.phone_id in (' . implode(', ', $phones). ')';
        }

        if ($mooseIds === null)
            $mooses = 'true';
        else
        {
            $mooses = filter_var($mooseIds, FILTER_VALIDATE_INT, array('flags' => FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY, 'options' => array('min_range' => 1)));
            if (!$mooses)
                $this->Err(self::ErrWrongMooseId);
            $mooses = 's.moose in (' . implode(', ', $mooses). ')';
        }

        $access = $this->CanSeeCond($auth, 'p');
        $mAccess = $this->CanSeeCond($auth, 'm', 'moose');

        $query = "select rs.id, rs.text, rs.stamp, DATE_FORMAT(rs.stamp,'%Y-%m-%dT%TZ') as sstamp, rs.ip, rs.xfw_ip, rs.phone_id, p.phone, s.id as 'sid', us.login, us.name as 'uname', m.name as 'mname', s.diagnose
            from raw_sms rs
            inner join users us on us.id = rs.user_id
            inner join phone p on p.id = rs.phone_id

            {$access['join']}

            left join sms s on s.raw_sms_id = rs.id
            left join moose m on m.id = s.moose

            where true and {$access['cond']} and $justErr and $phones and $mooses and (s.moose is null or {$mAccess['cond']})
            order by id desc limit $limit";

        $result = $this->Query($query);
        $res = [];
        foreach($result as $r)
        {
            $tmp = ['id' => $r['id'],
                'sid' => $r['sid'],
                'stamp' => $r['sstamp'],
                'text' => $r['text'],
                'ip' => $r['ip'],
                'xfwIp' => $r['xfw_ip'],
                'phoneId' => $r['phone_id'],
                'phone' => $r['phone'],
                'login' => $r['login'],
                'name' => $r['uname'],
                'moose' => $r['mname'],
                'diagnose' => $r['diagnose'],
                'error' => ''];

            if ($tmp['sid'] == null)
            {
                $msg = CMooseSMS::CreateFromText ($tmp['text'], strtotime($tmp['stamp']));
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

	/// зачищает записи об успешном логине для основного гейта
	public function SimplifyGateLogs(CTinyAuth $auth)
    {
        $gateId = 1007;
        $query = "delete l1 from logs l1 
                    inner join logs l2 on l1.id + 1 = l2.id and l1.user_id = l2.user_id and l1.level = l2.level 
                    where l1.user_id = $gateId and l1.operation = 'auth' and l2.operation = 'addSms' and l2.message not like '%error%'";

        $this->Query($query);
    }
}
?>