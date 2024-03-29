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

        return ['demo' => $demo ? 1 : 0, 'group' => $group];
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

        return $this->QueryColumn($query) > 0;
    }

    protected function CanModifySms($auth, $rawSmsId)
    {
        if ($auth->isSuper())
            return true;

        $query = "select rs.phone_id, s.moose
                    from raw_sms rs
                    left join sms s on s.raw_sms_id = rs.id
                    where rs.id = $rawSmsId";

        $r = $this->QueryRow($query);
        if ($r == null)
            return false;

        $hasMoose = isset($r['moose']);
        return $this->CanModify($auth, $hasMoose ? $r['moose'] : $r['phone'], $hasMoose);
    }

    // should be called only with verified $rawSmsIds
    protected function GetRawSmsMooses(CMooseAuth $auth, array $rawSmsIds)
    {
        $result = ['ids' => [], 'names' => []];
        if ($rawSmsIds == null || count($rawSmsIds) == 0)
            return $result;

        $ids = implode(", ", $rawSmsIds);
        $query = "select distinct m.id, m.name
                    from sms s
                    inner join moose m on m.id = s.moose
                    where s.raw_sms_id in ($ids) ";

        $res = $this->Query($query);
        foreach ($res as $r)
        {
            $result['ids'][] = $r['id'];
            $result['names'][$r['id']] = $r['name'];
        }

        return $result;
    }


    /// @returns complex condition with join or query result
    /// $alias -- table alias in query
    /// $table -- real table name. Not null means you need query results
    /// $externAlias -- field name in extern query. Used only if $table != null
    private function CanSeeCond(CTinyAuth $auth, $alias, $table = null, $extAlias = null)
    {
        if ($auth->isSuper())
            return ['join' => '', 'cond' => 'true'];

        $id = $auth->id();
        $demo = CMooseAuth::Demo;

        $res = [
            'join' => "inner join usergroups ug on ug.group_id = $alias.group_id or ug.group_id = $demo and $alias.demo = 1
		            inner join users u on u.id = ug.group_id",

            'cond' => "(ug.user_id = $id and u.removeDate is null)"];

        if ($table == null)
            return $res;

        $query = "select $alias.id from $table $alias
            {$res['join']}
            where {$res['cond']}";
        $ids = $this->QueryColumnAll($query);

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

        $qName = $this->ValidateTrimQuote($name, self::ErrEmptyMoose);

        $this->beginTran();
		$query = "insert into moose (phone_id, name, demo, group_id) values (null, $qName, {$vd['demo']}, {$vd['group']})";

		$this->Query($query, self::ErrDupMoose . ': ' .$qName);
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
        $this->LogPhoneMooseUpdate($auth, $res, $name, null, $this->PhonePropByMoose($res));
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

        $qName = $this->ValidateTrimQuote($name, self::ErrEmptyMoose);

        $oldPhone = $this->PhonePropByMoose($id);

        $this->beginTran();
        $query = "update moose, phone
            set phone_id = null, name = $qName, moose.demo = {$vd['demo']}, moose.group_id = {$vd['group']} $pUpdate
            where moose.id = $id $pCond";

        $this->Query($query, self::ErrDupMoose. ': ' .$qName);
        $res = $this->db->lastInsertId();

        if ($phoneId != null)
        {
            $query = "update moose set phone_id = $pId where id = $id";
            $this->Query($query, self::ErrPhoneUsed);
        }
        $this->commit();
        $this->LogPhoneMooseUpdate($auth, $id, $name, $oldPhone, $this->PhonePropByMoose($id));

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

        $qPhone = $this->ValidateTrimQuote($phone, self::ErrEmptyPhone);
        $canonical = $this->db->quote(self::CanonicalPhone($phone));

        $this->beginTran();

        $query = "insert into phone (phone, canonical, demo, group_id) values ($qPhone, $canonical, {$vd['demo']}, {$vd['group']})";

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
        $this->LogMoosePhoneUpdate($auth, $res, $phone, null, $this->MoosePropByPhone($res));

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
        $qPhone = $this->ValidateTrimQuote($phone, self::ErrEmptyPhone);
        $canonical = $this->db->quote(self::CanonicalPhone($phone));

        $oldMoose = $this->MoosePropByPhone($id);

        $this->beginTran();

        $query = "update phone
                    set phone = $qPhone, canonical = $canonical, demo = {$vd['demo']}, group_id={$vd['group']}
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
        $this->LogMoosePhoneUpdate($auth, $id, $phone, $oldMoose, $this->MoosePropByPhone($id));

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

    private function LogPhoneMooseUpdate(CTinyAuth $auth, $mooseId, $name, $prev, $new)
    {
        if ($prev == null && $new == null || $prev['id'] == $new['id']) // nothing changed
            return;

        $from = $prev != null ? "снимаем маяк {$prev['msg']} " : '';
        $to = $new != null ? "надеваем маяк {$new['msg']}" : '';

        Log::t($this, $auth, 'exchange', "животное '$name', id=$mooseId $from$to");
    }

    private function LogMoosePhoneUpdate(CTinyAuth $auth, $phoneId, $phone, $prev, $new)
    {
        if ($prev == null && $new == null || $prev['id'] == $new['id']) // nothing changed
            return;

        $verb = 'перевешиваем';
        if ($prev == null)
            $verb = 'вешаем';
        else if ($new == null)
            $verb = 'снимаем';

        $from = $prev != null ? "c животного {$prev['msg']} " : '';
        $to = $new != null ? "на животное {$new['msg']}" : '';

        Log::t($this, $auth, 'exchange', "$verb маяк $phoneId, '$phone' $from$to");
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

        $arr = [];
		foreach ($result as $row)
        {
            $line = ["id" => $row['id'], "name" =>$row['name'], "phone" => self::Obfuscate($auth, $row['phone']), "min" => $row['min_t'], "max" => $row['max_t']];
            if ($fShowRights)
            {
                $line['phoneId'] = $row['phone_id'];
                $line['demo'] = $row['mdemo'] ? true : false;
                $line['groups'] = [];

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

		$arr = [];
		foreach ($result as $row)
        {
            $line = ["id" => $row['id'], "phone" => self::Obfuscate($auth, $row['phone']), "canonical" => self::Obfuscate($auth, $row['canonical'])];
            if ($fShowRights)
            {
                $line['moose'] = $row['mid'];
                $line['demo'] = $row['pdemo'] ? true : false;
                $line['active'] = $row['active'] ? true : false;
                $line['groups'] = [];
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

        $res = [];
        foreach ($result as $row)
        {
            $uid = $row['user_id'];
            if (!isset($res[$uid]))
                $res[$uid] = ['feed' => false, 'admin' => false, 'groups' => []];

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
        $res = [];

        foreach ($result as $row)
            $res[] = ['id' => $row['gid'], 'login' => $row['login'], 'name' => $row['name']];

        return $res;
    }

    function GetUsers(CMooseAuth $auth, $inactive )
    {
        if (!$auth->canAdmin())
            return $this->ErrRights();

        $rights = $this->GetRights($auth);

        $cond = ($inactive === true) ? "true" : "removeDate is null";

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

        if ($auth->isSuper())
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

    protected function TestSmsIntersections(CMooseAuth $auth, $mooseId, $smsId, $rawSmsId, $throwException)
    {
        $query = "select raw_sms_id, DATE_FORMAT(lim.rMin,'%Y-%m-%dT%TZ') as mMin, DATE_FORMAT(lim.rMax,'%Y-%m-%dT%TZ') as mMax 
                        from sms s
                        inner join (select mint as rMin, maxt as rMax from sms where id = $smsId) lim
                    where id <> $smsId and moose = $mooseId and lim.rMin < maxt and lim.rMax > mint ";

        $conf = [];
        $conflict = $this->Query($query);
        foreach ($conflict as $c)
        {
            $mMin = $c['mMin'];
            $mMax = $c['mMax'];
            $conf[] = $c['raw_sms_id'];
        }
        $conflict->closeCursor();
        if (count($conf) > 0)
        {
            $msg = "Смс rawSmsId=$rawSmsId пересекается по времени $mMin - $mMax с смс " .implode(", ", $conf);

            if ($throwException)
                $this->Err($msg);
            else
                Log::st($auth, "addSms", $msg);
        }
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

    // region track data (points, activity)
	function GetMooseTracks(CTinyAuth $auth, $ids, $start, $end)
	{
        $t0 = microtime(true);

        $ids = filter_var($ids, FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY);
        if ($ids === false || count($ids) == 0)
            return null;

		$cond = implode($ids, ", ");

		$timeCond = $this->TimeCondition('position.stamp', $start, $end);
        $access = $this->CanSeeCond($auth, 'm', 'moose');
		
		$query = "select lat, lon, UNIX_TIMESTAMP(position.stamp) as stamp, valid, position.comment, author_id, DATE_FORMAT(comment_stamp,'%Y-%m-%dT%TZ') as cstamp, sms.moose as moose 
                from position
                inner join sms on position.sms_id = sms.id
                inner join moose m on m.id = sms.moose
                {$access['join']}
				where sms.moose in ($cond) $timeCond and {$access['cond']}
				order by sms.moose asc, position.stamp asc ";

        $oldTz = $this->SetSessionTimezone('+00:00');
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

            $rec = [+$row['lat'], +$row['lon'], +$row['stamp'], $row['valid'] ? 1 : 0];
            if ($row['comment'] != null)
            {
                $rec[] = $row['comment'];
                $rec[] = $row['author_id'];
                $rec[] = $row['cstamp'];
            }
            $res[$moose][] = $rec;
        }
        $result->closeCursor();
        $this->SetSessionTimezone($oldTz);

        $retVal = [];
        foreach($res as $id => $data)
            $retVal[] = ['id' => $id, 'track' => $data];


        $t3 = microtime(true);
//        Log::d($this, $auth, "times", sprintf("track total: %.4f, que: %.4f, retr: %.4f", $t3 - $t0, $t2 - $t1, $t3 - $t2));
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

		$query = "select UNIX_TIMESTAMP(activity.stamp) as stamp, max(active) as active, valid, sms.moose as moose
				from activity				
				inner join sms on activity.sms_id = sms.id
				{$access['join']}
				where  sms.moose in ($cond) $timeCond and {$access['cond']}
				 group by activity.stamp, valid, sms.moose
				order by sms.moose asc, activity.stamp asc ";

        $oldTz = $this->SetSessionTimezone('+00:00');
        $old = $this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $t1 = microtime(true);
        $result = $this->Query($query);
        $t2 = microtime(true);

        $res = [];
        foreach ($result as $row)
        {
            $moose = $row['moose'];
            if (!isset($res[$moose]))
                $res[$moose] = [];

            $res[$moose][] = [+$row['stamp'], $row['active'] ? 1 : 0, $row['valid'] ? 1 : 0];
        }
        $result->closeCursor();

        $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $old);
        $this->SetSessionTimezone($oldTz);
//        Log::d($this, $auth, "times", sprintf("activity rows: %d", $rowCount));

        $retVal = [];
        foreach($res as $id => $data)
            $retVal[] = ['id' => $id, 'activity' => $data];

        $t3 = microtime(true);

        //Log::d($this, $auth, "times", sprintf("activity total: %.4f query: %.4f, retrieve %.4f", ($t3-$t0), ($t2-$t1), ($t3-$t2)));
        return $retVal;
	}

    function GetSmsTrack(CTinyAuth $auth, $rawSmsId)
    {
        $rawSmsId = $this->ValidateId($rawSmsId, "Недопустимый id sms", 1);

        $pAccess = $this->CanSeeCond($auth, 'p', 'phone', 'rs.phone_id');
        $mAccess = $this->CanSeeCond($auth, 'm', 'moose', 'sms.moose');

        $query = "select lat, lon, UNIX_TIMESTAMP(position.stamp) as stamp, valid, comment, author_id, DATE_FORMAT(comment_stamp,'%Y-%m-%dT%TZ') as cstamp
                from position
                inner join sms on position.sms_id = sms.id
                inner join raw_sms rs on rs.id = sms.raw_sms_id
				where sms.raw_sms_id = $rawSmsId and (sms.moose is null and {$pAccess['cond']} or {$mAccess['cond']})
				order by position.stamp asc ";

        $oldTz = $this->SetSessionTimezone('+00:00');
        $result = $this->Query($query);
        $res = [];
        $rec = null;
        foreach ($result as $row)
        {
            $rec = [+$row['lat'], +$row['lon'], +$row['stamp'], $row['valid'] ? 1 : 0];
            if ($row['comment'] != null)
            {
                $rec[] = $row['comment'];
                $rec[] = $row['author_id'];
                $rec[] = $row['cstamp'];
            }
            $res[] = $rec;
        }

        $result->closeCursor();
        $this->SetSessionTimezone($oldTz);

        return $res;
    }

    function GetSmsActivity(CTinyAuth $auth, $rawSmsId)
    {
        $rawSmsId = $this->ValidateId($rawSmsId, "Недопустимый id sms", 1);
        $pAccess = $this->CanSeeCond($auth, 'p', 'phone', 'rs.phone_id');
        $mAccess = $this->CanSeeCond($auth, 'm', 'moose', 'sms.moose');

        $query = "select UNIX_TIMESTAMP(activity.stamp) as stamp, activity.active, valid
				from activity
				inner join sms on activity.sms_id = sms.id
				inner join raw_sms rs on rs.id = sms.raw_sms_id
				where sms.raw_sms_id = $rawSmsId and (sms.moose is null and {$pAccess['cond']} or {$mAccess['cond']})
				order by activity.stamp asc";

        $oldTz = $this->SetSessionTimezone('+00:00');
        $result = $this->Query($query);

        $res = [];
        foreach ($result as $row)
            $res[] = [+$row['stamp'], $row['active'] ? 1 : 0, $row['valid'] ? 1 : 0];
        $result->closeCursor();
        $this->SetSessionTimezone($oldTz);

        return $res;
    }

    function GetSms(CTinyAuth $auth, $rawSmsId)
    {
        $rawSmsId = $this->ValidateId($rawSmsId, "Недопустимый id sms", 1);
        $pAccess = $this->CanSeeCond($auth, 'p', 'phone', 'rs.phone_id');
        $mAccess = $this->CanSeeCond($auth, 'm', 'moose', 'sms.moose');

        $query = "select text, UNIX_TIMESTAMP(rs.stamp) as stamp 
                from raw_sms rs 
				inner join sms on rs.id = sms.raw_sms_id				
				where rs.id = $rawSmsId and (sms.moose is null and {$pAccess['cond']} or {$mAccess['cond']}) ";

        return $this->QueryRow($query);
    }
    // endregion

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
        if ($beacons['mAccess'] == 'true')  // просто оптимизация
            $direct = 'true';

        $timeCond = $this->TimeCondition('sms.maxt', $start, $end);

        $query = "select id as pId, rec.*
                from phone p
                left join
                    (select rs.phone_id, DATE_FORMAT(rs.stamp,'%Y-%m-%dT%TZ') as tm, rs.id as rsId, text, int_id, volt, temp, gps_on, gsm_tries, sms.maxt as st, DATE_FORMAT(sms.maxt,'%Y-%m-%dT%TZ') as pos_time, sms.moose as smsMid, m.name
                    from raw_sms rs
                    inner join sms on sms.raw_sms_id = rs.id
                    left join moose m on m.id = sms.moose
                    where true $timeCond and (sms.moose is null and $direct or {$beacons['mAccess']} )
                    ) rec on p.id = rec.phone_id
                where p.id in ($cond)
                order by rec.st desc"; // в принципе те маяки и (доступное животное или непривязанное смс с ныне доступного маяка)

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
                $hash[$ph]['data'][] = [$row['tm'], $row['pos_time'], $row['int_id'], $row['volt'], $row['temp'], $row['gps_on'], $row['gsm_tries'], $row['rsId'], $addText ? $row['text'] : null, $row['smsMid'], $export ? $row['name'] : null];
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

        if (!$auth->isSuper() || !$this->CanModify($auth, $moose == null ? null : $qmoose, true))    // и чуть ниже для животных, с которых снимают
            $this->ErrRights();

        $ids = filter_var($smsIds, FILTER_VALIDATE_INT, ['flags' => FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY, 'options' => ['min_range' => 1]]);
        if ($ids === false || count($ids) == 0)
            $this->Err(self::ErrWrongSmsId);

        $qids = implode(', ', $ids);

        $query = "update sms
          set moose = $qmoose
          where raw_sms_id in ($qids)";

        $this->beginTran();

        $old = $this->GetRawSmsMooses($auth, $ids);
        if (!$auth->isSuper())
            foreach ($old['ids'] as $oId)
                if (!$this->CanModify($auth, $oId, true))
                    $this->ErrRights();

        $result = $this->Query($query);
        $result->closeCursor();

        $mooses = $this->GetRawSmsMooses($auth, $ids);
        $this->SetMooseTimestamp($auth, array_merge($old['ids'], $mooses['ids']));

        $this->commit();

        $oldText = [];
        foreach ($old['names'] as $k => $v)
            $oldText[] = "$k ($v)";
        $newText = [];
        foreach ($mooses['names'] as $k => $v)
            $newText[] = "$k ($v)";

        Log::t($this, $auth, 'reassignSms', "перевешиваем c животных '". implode(', ', $oldText) ."' на животное '" . implode(', ', $newText). "', rawSmsIds: '".implode(", ", $ids) ."'");
        return ['res' => true, 'rc' => $result->rowCount()];
    }

    // region point operations: toggle, comment
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
        $this->SetMooseTimestamp($auth, $this->GetRawSmsMooses($auth, [$rawSmsId])['ids']);
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
        $this->SetMooseTimestamp($auth, $this->GetRawSmsMooses($auth, [$rawSmsId])['ids']);
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

        $name = $auth->name();
        if ($name == null || trim($name) == '')
            $name = trim($auth->login());

        return ['res' => true,
            'rc' => $result->rowCount(),
            'author' => $name,
            'cstamp' => gmdate('Y-m-d', $t) .'T'. gmdate('H:i:s', $t). 'Z'];
    }

    // endregion

    function DeleteRawSmses(CMooseAuth $auth, array $rawSmsIds)
    {
        if (!$auth->isSuper())          // todo разрешить не только root, проверка на права
            $this->ErrRights();

        $rawIds = filter_var($rawSmsIds, FILTER_VALIDATE_INT, ['flags' => FILTER_REQUIRE_ARRAY | FILTER_FORCE_ARRAY, 'options' => ['min_range' => 1]]);
        if ($rawIds === false || count($rawIds) == 0)
            $this->Err(self::ErrWrongSmsId);

        $this->beginTran();

        $mooses = $this->GetRawSmsMooses($auth, $rawIds);
        $qRawIds = implode(', ', $rawIds);

        $smsId = $this->QueryColumnAll("select id from sms where raw_sms_id in ($qRawIds)");
        if (count($smsId) > 0)
        {
            $smsIds = implode(', ', $smsId);
            $query = "delete from activity where sms_id  in ($smsIds)";
            $this->Query($query);

            $query = "delete from position where sms_id in ($smsIds)";
            $this->Query($query);
        }

        $query = "delete from sms where raw_sms_id in ($qRawIds)";
        $this->Query($query);

        $query = "delete from raw_sms where id in ($qRawIds)";
        $this->Query($query);

        $this->SetMooseTimestamp($auth, $mooses['ids']);

        $this->commit();

        Log::t($this, $auth, "deleteSms", "Raw sms ids: $qRawIds");
    }

	function AddData(CMooseAuth $auth, $phone, CMooseSMS $msg, $moose = null, $testTime = false)
	{
		if (!$auth->canFeed() || !$auth->isSuper() && $moose != null) // добавлять не текущему лосю может лишь супер
			$this->ErrRights();

		$prop = $this->PhoneProp($auth, $phone);
        if ($moose != null)
            $prop['mooseId'] = $this->MooseProp($auth, $moose);

        $qMooseId = $prop['mooseId'] != null ? $prop['mooseId'] : 'null';

        $ourTran = !$this->beginTran();
		$rawSmsId = $this->AddRawSms($prop['phoneId'], $msg, $auth->id());

        $this->ValidateId($msg->id, 'Incorrect internal number', 0);

		$res = ['rawSms' => $rawSmsId, 'moose' => $prop['mooseId']];

		if (!$msg->HasData()) // не смогли разобрать SMS
		{
            if (!$msg->IsValid())
                $res['error'] = $msg->GetErrorMessage();
            if ($ourTran)
                $this->commit();
			return $res;
		}

		$qDiag = ($msg->diag == null || trim($msg->diag) == '') ? 'null' : $this->ValidateTrimQuote($msg->diag);

		$query = "insert into sms (moose, raw_sms_id, int_id, volt, temp, gsm_tries, gps_on, diagnose) 
				values ($qMooseId, $rawSmsId, $msg->id, 
						$msg->volt, $msg->temp, $msg->gsmTries, $msg->gpsOn, $qDiag)";
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
        {
            $this->TestSmsIntersections($auth, $prop['mooseId'], $smsId, $rawSmsId, $testTime);
            $this->SetMooseTimestamp($auth, [$prop['mooseId']]);    // todo set global timestamp
        }

        if ($ourTran)
            $this->commit();
		$res['sms'] = $smsId;
		$res['temp'] = $msg->temp;

		if (!$msg->IsValid())
            $res['error'] = $msg->GetErrorMessage();
		return $res;
	}

    // todo а не проверить ли еще и доступность лосей? -- нет, они пока синхронны
    protected function PhoneProp(CTinyAuth $auth, $phone)
    {
        $qPhone = $this->db->quote(self::CanonicalPhone($phone));

        $access = $this->CanSeeCond($auth, 'p');

        $query = "select moose.id as mooseId, p.id as phoneId
				from phone p
				 {$access['join']}
				left join moose on phone_id = p.id
				where $qPhone <> '' and p.canonical = $qPhone and {$access['cond']}";

        $res = $this->QueryRow($query);
        if ($res == null)
            $this->Err("Нет доступных телефонов с номером '$phone'");

        return $res;
    }

    private function PhonePropByMoose($mooseId)
    {
        $query = "select p.id, phone, canonical
                    from phone p
                    inner join moose m on m.phone_id = p.id
                  where m.id = $mooseId";

        $res = $this->QueryRow($query);
        if ($res == null)
            return null;

        $res['msg'] = "'{$res['phone']}' id={$res['id']}";
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
        $res = $this->QueryColumn($query);
        if ($res == null)
            $this->Err("Нет доступных животных с именем '$qMoose'");

        return $res;
    }

    private function MoosePropByPhone($phoneId)
    {
        $query = "select id, name from moose
                  where phone_id = $phoneId";
        $res = $this->QueryRow($query);
        if ($res == null)
            return null;

        $res['msg'] = "'{$res['name']}' id={$res['id']}";
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
	    $cn = count($points);
	    if ($cn == 0)
	        return;

		$values = [];
		$tm = time() - $cn - 10000; //
		foreach ($points as $pt)
		{
			$stamp = $this->ToSqlTime(isset($pt[2]) ? $pt[2] : $tm);
			$valid = isset($pt[3]) && (!$pt[3]) ? 0 : 1;
			$values[] = "($pt[0], $pt[1], $valid, $stamp, $smsId)";
			$tm++;
		}
		$query = "insert into position (lat, lon, valid, stamp, sms_id) values ". implode($values, ', ');

		$this->Query($query);
    }

	protected function AddActivity($smsId, $activity)
	{
        $cn = count($activity);
        if ($cn == 0)
            return;

		$values = [];
		$tm = time() - $cn - 10000; //
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

    function GetGateData(CTinyAuth $auth, $limit = null, $justErr, CValidatedFilter $phonesF, CValidatedFilter $moosesF)
    {
        if (!$auth->canAdmin())
            $this->ErrRights();

        $limit = ($limit === null) ? 100 : $this->ValidateId($limit, "Недопустимое значение limit", 1);
        $justErr = $justErr === true ? ' (s.id is null or m.id is null or char_length(rs.text) < 50 or rs.stamp < s.maxt) ' : 'true';

        $phones = $phonesF->GetCondition('rs.phone_id');
        $mooses = $moosesF->GetCondition('s.moose');

        $access = $this->CanSeeCond($auth, 'p');
        $mAccess = $this->CanSeeCond($auth, 'm', 'moose');

        $query = "select rs.id, rs.text, rs.stamp, DATE_FORMAT(rs.stamp,'%Y-%m-%dT%TZ') as sstamp, UNIX_TIMESTAMP(rs.stamp) as ustamp, rs.ip, rs.xfw_ip, rs.phone_id,
                        p.phone, s.id as 'sid', UNIX_TIMESTAMP(s.maxt) as umaxt, us.login, us.name as 'uname', m.name as 'mname', s.diagnose
            from raw_sms rs
            inner join users us on us.id = rs.user_id
            inner join phone p on p.id = rs.phone_id

            {$access['join']}

            left join sms s on s.raw_sms_id = rs.id
            left join moose m on m.id = s.moose

            where true and {$access['cond']} and $justErr $phones $mooses and (s.moose is null or {$mAccess['cond']})
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

            if ($tmp['sid'] == null || $r['ustamp'] + CMooseSMS::PointGrace < $r['umaxt'])
            {
                $msg = CMooseSMS::CreateFromText($tmp['text'], strtotime($tmp['stamp']), 0);
                $tmp['error'] = $msg->GetErrorMessage();
                if ($tmp['diagnose'] == '' && $msg->diag != '')
                    $tmp['diagnose'] = $msg->diag;
            }
            $res[] = $tmp;
        }

        return $res;
    }

	private function ToSqlTime($stamp)
	{
		return "'".gmdate('Y-m-d H:i:s', $stamp)."'";
	}

    //region service functions

	/// зачищает записи об успешном логине для основного гейта
	public function SimplifyGateLogs(CTinyAuth $auth)
    {
        $gateId = 1007;
        $query = "delete l1 from logs l1 
                    inner join logs l2 on l1.id + 1 = l2.id and l1.user_id = l2.user_id and l1.level = l2.level 
                    where l1.user_id = $gateId and l1.operation = 'auth' and l2.operation = 'addSms' and l2.message not like '%error%'";

        $this->Query($query);
    }

    public function UpdateSmsDiagnose(CTinyAuth $auth, $min = 0)
    {
        if (!$auth->isSuper())
            $this->ErrRights();

        $min = $this->ValidateId($min, "недопустимый минимальный raw_sms_id", 0);

        $query = "select rs.id, rs.text, stamp, diagnose
            from raw_sms rs
            inner join sms s on s.raw_sms_id = rs.id
            where rs.id >= $min and diagnose like '%Reload%'
            limit 15000";

        $i = 0;
        $cn = 0;
        $log = [];
        $upd = [];
        $result = $this->Query($query);

        $t0 = microtime(true);

        foreach($result as $r)
        {
            $i++;
            $msg = CMooseSMS::CreateFromText ($r['text'], strtotime($r['stamp']));
            if ($msg->diag != $r['diagnose'])
            {
                $upd[$r['id']] = $msg->diag;
                $cn++;
            }

            if ($i % 1000 == 0)
                $log[] = sprintf("%d tested, %d found in %.1f sec<br/>", $i, $cn, microtime(true) - $t0);
        }
        $result->closeCursor();

        foreach ($upd as $id => $diag)
        {
            $qd = $this->db->quote($diag);
            $query = "update sms set diagnose = $qd where raw_sms_id = $id";
            $this->Query($query);
        }

        $log[] = sprintf("end in %.1f sec<br/>", microtime(true) - $t0);
        $log[] = "$i, res: <br/>" . print_r($upd, 1);
        return $log;
    }

    public function RecomputeSmsDates(CTinyAuth $auth, $rawSmsId)
    {
        if (!$auth->isSuper())
            $this->ErrRights();

        $rawSmsId = $this->ValidateId($rawSmsId, "недопустимый минимальный raw_sms_id", 0);

        $t0 = microtime(true);

        $this->beginTran();

        $mooses = $this->GetRawSmsMooses($auth, [$rawSmsId]);

        $sms = null;
        $query = "select text, UNIX_TIMESTAMP(CONVERT_TZ(stamp, '+0:00', @@session.time_zone)) as ustamp, stamp, DATE_FORMAT(stamp,'%Y-%m-%dT%TZ') as sstamp from raw_sms where id = $rawSmsId";
        $row = $this->QueryRow($query);
        if ($row != null)
        {
            $sms = $row['text'];
            $time = $row['ustamp'];
            $st = $row['stamp'];
            $sst = $row['sstamp'];
        }

        if ($sms == '')
            $this->Err("empty message for raswSms '$rawSmsId'");

        $smsId = $this->QueryColumnAll("select id from sms where raw_sms_id = $rawSmsId");
        if (count($smsId) > 1)
            $this->Err('too many sms ids (' . implode(', ', $smsId) . ") for raw sms $rawSmsId");

        $msg = CMooseSMS::CreateFromText($sms, $time);
        if (!$msg->IsValid())
            $this->Err($msg->GetErrorMessage());

        //$this->Err("raw sms $rawSmsId, sms: $smsId[0], ut: $time, time: ". $this->ToSqlTime($time) ." sqlString: $sst, sqlTime: $st, $msg->diag");

        if (count($smsId) > 0)
        {
            $smsIds = implode(', ', $smsId);
            $query = "delete from activity where sms_id  in ($smsIds)";
            $this->Query($query);

            $query = "delete from position where sms_id in ($smsIds)";
            $this->Query($query);
        }

        if ($msg->points != null)
            $this->AddPoints($smsId[0], $msg->points);
        if ($msg->activity != null)
            $this->AddActivity($smsId[0], $msg->activity);

        $qDiag = $this->TrimQuote($msg->diag);
        // set sms mint-maxt  todo а если точек нет?  и вообще сделать на триггерах
        $query = "update sms s set
                    mint = (select min(p.stamp) from position p where p.sms_id = $smsId[0]),
                    maxt = (select max(p.stamp) from position p where p.sms_id = $smsId[0]),
                    diagnose = $qDiag
                    WHERE s.id = $smsId[0]";
        $this->Query($query);

        $this->SetMooseTimestamp($auth, $mooses['ids']);

        $this->commit();

        return sprintf("end in %.1f sec<br/>", microtime(true) - $t0);
    }

    // endregion
}
