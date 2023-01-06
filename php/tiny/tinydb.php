<?php
/**
 * Created by Serge Titov for mooServer project
 * 2014 - 2015
 */
if (!defined('IN_TINY'))
	exit;

if (isset($tinySett['zendPath']) && trim($tinySett['zendPath']) != '')
    require_once(dirname(__FILE__).$tinySett['zendPath']);

class CTinyDb
{
    var $db = null;
    protected $useTran = false;

    const TokenValidHours = 24;
    const TokenLen = 50;

	protected static $Version = 1;

    const Verify =   1;
    const ResetPwd = 2;
    const OptOut =   3;

    const ErrCOk = 'OK';
    const ErrCRights = 'У вас не хватает прав';
    const ErrDupUser = "Такой пользователь уже есть в системе";
    const ErrDupGroup = "Группа c таким названием уже есть в системе";
    const ErrAccessDenied = 'Access denied';

    const LogInfo = 0;
    const LogTrace = 1;
    const LogDebug = 2;
    const LogError = 3;
    const LogCritical = 4;

    private $LastError = '';

	function __construct()
	{
		global $tinySett;

        $this->ClearError();
        try
        {
            $user = @$tinySett['user'];
            $pwd = @$tinySett['pwd'];
            $conn = self::getConnectionCredentials($tinySett, $user, $pwd);
            $this->db = new PDO($conn, $user, $pwd);
        }
        catch (PDOException $e)
        {
            $this->Err('Connection failed: ' . $e->getMessage());
        }

		$query = "select max(version) as ver from version";
		$ver = $this->QueryColumn($query);
        if ($ver != self::$Version)
            $this->Err("Wrong db version: '$ver', expected '" .self::$Version. "'");

        if (isset($tinySett['timezone']))
            date_default_timezone_set($tinySett['timezone']);
	}

    private static function getConnectionCredentials($tinySett, &$user, &$pwd)
    {
        $host = @$tinySett['host'];
        $db = @$tinySett['base'];

        if (isset($tinySett['zendPath']) && trim($tinySett['zendPath']) != '')
        {
            $zdb = Zend_Registry::get("config")->db->config;
            $host = isset($tinySett['host']) ? $tinySett['host'] : $zdb->host;
            $db = isset($tinySett['base']) ? $tinySett['base'] : $zdb->dbname;
            $user = isset($tinySett['user']) ? $tinySett['user'] : $zdb->username;
            $pwd = isset($tinySett['pwd']) ? $tinySett['pwd'] : $zdb->password;
        }

        $charset = isset($tinySett['charset']) ? $tinySett['charset']: 'utf8';

        return "mysql:dbname=$db;host=$host;charset=$charset";
    }

    public function beginTran()
    {
        $old = $this->useTran;
        if ($this->db == null || $old)
            return $old;

        $this->useTran = true;
        $this->db->beginTransaction();
        return $old;
    }
    public function commit()
    {
        if (!$this->useTran)
            return;
        $this->useTran = false;

        if ($this->db != null)
            $this->db->commit();
    }

    public function rollback()
    {
        if (!$this->useTran)
            return;
        $this->useTran = false;

        if ($this->db != null)
            $this->db->rollBack();
    }

    protected function ClearError()
    {
        $this->LastError = '';
    }

    public function GetError()
    {
        return $this->LastError;
    }

    protected function ErrRights()
    {
        $this->Err(CTinyDb::ErrCRights);
    }

    protected function ErrDuplicate($msg)
    {
        if ($this->db->errorCode() != 23000)    // Duplicate entry
            return false;

        $this->Err($msg);
        return true;
    }

	protected function Err($msg)
	{
        $this->rollback();

        $this->LastError = $msg;

        throw new Exception($msg);
	}

    protected function SetSessionTimezone($tz)
    {
        $old = $this->QueryColumn("select @@session.time_zone");

        if ($tz != 'SYSTEM')
            $tz = $this->TrimQuote($tz);
        $this->Query("set time_zone = $tz");

        return $old;
    }

    protected function Query($query, $dupMessage = null)
    {
        $res = $this->db->query($query, PDO::FETCH_ASSOC);
        if (!$res && ($dupMessage === null || !$this->ErrDuplicate($dupMessage)))
        {
            $err = $this->db->errorInfo();
            $this->Err("Query failed: sqe: $err[0] code '$err[1]' error: $err[2] at request: \n$query");
        }

        return $res;
    }

    protected function QueryRow($query)
    {
        $result = $this->Query($query);
        $res = $result->fetch(PDO::FETCH_ASSOC);
        $result->closeCursor();
        return $res;
    }

    protected function QueryColumn($query)
    {
        $result = $this->Query($query);
        $res = $result->fetchColumn();
        $result->closeCursor();
        return $res;
    }

	function Version()
	{
		return $this->db->getAttribute(PDO::ATTR_CLIENT_VERSION);
	}

	function StartSession(CTinyAuth $auth)
	{
		if (!$auth->isLogged())
			return null;

		$session = uniqid();
		$qSession = $this->db->quote($session);

		$query = "insert into session (user_id, id, start, last) values ({$auth->id()}, $qSession, UTC_TIMESTAMP, UTC_TIMESTAMP)";
        $this->Query($query);

		$query = "update users set failed_logins = 0, block_till = null where id = {$auth->id()}";
        $this->Query($query);

		return $session;
	}

	function UpdateSession($session)
	{
		if ($session == null || $session == '')
			return;
		$session = $this->db->quote($session);

		$query = "update session set last = UTC_TIMESTAMP where id = $session";
        $this->Query($query);
	}

	function EndSession($session)
	{
		if ($session == null || $session == '')
			return;
		$session = $this->db->quote($session);

        $short = CTinyAuth::Short;
        $long = CTinyAuth::Long;
		$query = "delete from session where id = $session
		    or DATE_ADD(last, INTERVAL $short SECOND) < UTC_TIMESTAMP
		    or DATE_ADD(start, INTERVAL $long SECOND) < UTC_TIMESTAMP";
        $this->Query($query);
    }

	function LogFailedLogin($login)
	{
		global $tinySett;
		$login = $this->db->quote($login);
		$thresh = $tinySett['blockAfter'];
		$timeout = $tinySett['blockTimeout'];
		$query = "update users set failed_logins = failed_logins + 1,  
					block_till = if (failed_logins > $thresh,
								DATE_ADD(UTC_TIMESTAMP, INTERVAL $timeout SECOND), 
								NULL)
					where login = $login";

		$this->Query($query);
	}

	protected function UserBy($where, $query = null)
	{
		if ($where === null || !is_string($where))
			return [];

		if ($query == null || !is_string($query))
			$query = "select *, UNIX_TIMESTAMP(CONVERT_TZ(removeDate, '+0:00', @@session.time_zone)) as removed, UNIX_TIMESTAMP(CONVERT_TZ(block_till, '+0:00', @@session.time_zone)) as block
					from users where $where";

		$res = $this->QueryRow($query);
		if ($res == null || !isset($res['id']))
			return [];

		$r = $this->GetUsersGroups($res['id']);
        if ($r != null)
            $res['groups'] = $r;

		return $res;
	}

    protected function GetUserGroups(CTinyAuth $auth)
    {
        return $this->GetUsersGroups($auth->id());
    }

	private function GetUsersGroups($userId)
	{
		if (!is_numeric($userId))
			return null;

		$query = "select group_id from usergroups inner join users on group_id = users.id 
				where user_id = $userId and users.removeDate is null";

		$result = $this->Query($query);
		$res = $result->fetchAll(PDO::FETCH_COLUMN);
		$result->closeCursor();
		return $res;
	}

	function UserBySession($sessionId)
	{
		$session = $this->db->quote($sessionId);

		$query = "select users.*, UNIX_TIMESTAMP(CONVERT_TZ(removeDate, '+0:00', @@session.time_zone)) as removed, UNIX_TIMESTAMP(CONVERT_TZ(block_till, '+0:00', @@session.time_zone)) as block, 
				UNIX_TIMESTAMP(CONVERT_TZ(start, '+0:00', @@session.time_zone)) as st, UNIX_TIMESTAMP(CONVERT_TZ(last, '+0:00', @@session.time_zone)) as cur
				from session inner join users on users.id = session.user_id
				where session.id = $session";

		$user = $this->UserBy('', $query);
		if (!isset($user['id']))
			return [];

		$user['session'] = [
			'start' => intval(@$user['st']),
			'last' =>  intval(@$user['cur'])];
		unset($user['st']);
		unset($user['cur']);

		return $user;
	}

	function UserById($id)
	{
		if ($id == null || !is_int($id))
            $this->Err("incorrect userId '$id'");
		return $this->UserBy("id = $id");
	}

	function UserByLogin($login)
	{
		if ($login == null || !is_string($login))
            $this->Err('incorrect login');
		
		return $this->UserBy("login = " . $this->db->quote($login));
	}

    protected function TrimQuote($str)
    {
        return ($str !== null && is_string($str)) ? $this->db->quote(trim($str)) : 'null';
    }

    protected function ValidateTrimQuote($str, $err = 'строка должна быть не пустой')
    {
        if ($str == null || ! is_string($str))
            $this->Err($err);

        $str = trim($str);
        if (strlen($str) == 0)
            $this->Err($err);
        return $this->db->quote($str);
    }

    private function ValidateNameComment($name, $comment, $errNoName = 'не задано имя')
    {
        return ['name' => $this->ValidateTrimQuote($name, $errNoName),
                'comment' => $this->TrimQuote($comment)];
    }

    protected function escapeForLike($str)
    {
        $qStr = $this->ValidateTrimQuote($str);
        return str_replace(['_', '%', '\\\\'], ['\_', '\%', '\\\\\\\\'], substr($qStr, 1, strlen($qStr)-2));    // отрезаем кавычки с краев, т.к. добавим свои
    }

    protected function ValidateId($id, $err, $min = 1)
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]]);
        if ($id === false)
            $this->Err($err);

        return $id;
    }

    protected function InactiveCond($del)
    {
        if ($del === true)
            return 'removeDate = UTC_TIMESTAMP';
        else if ($del === false)
            return 'removeDate = null';

        $this->Err('небулево значение');
    }

    /** Может ли пользователь иметь доступ к группе или пользователю с указанным id
     * @param CTinyAuth $auth
     * @param $id
     * @param $isGroup
     * @return bool
     */
    protected function CanAccess(CTinyAuth $auth, $id, $isGroup)
    {
        if ($auth->isSuper() || $auth->id() == $id && $id > CTinyAuth::Anonymous)
            return true;

        if (!$auth->canAdmin())
            return false;

        $query = $isGroup ? "select my.user_id
                from usergroups my
                inner join users gr on gr.id = my.group_id
                where gr.removeDate is null and my.group_id = $id and my.user_id = {$auth->id()}" :
            "select oth.user_id
                from usergroups oth
                inner join usergroups my on oth.group_id = my.group_id
                inner join users gr on gr.id = my.group_id
                where gr.removeDate is null and oth.user_id = $id and my.user_id = {$auth->id()}";

        $res = $this->Query($query);
        foreach($res as $r)
        {
            $res->closeCursor();
            return true;
        }

        return false;
    }

    protected function UpdateUserGroup(CTinyAuth $auth, $id, array $updFields, $isGroup, $errDuplicate)
    {
        if (!$this->CanAccess($auth, $id, $isGroup))
            $this->ErrRights();

        $id = $this->ValidateId($id, "недопустимый id");
        $sets = implode(', ', $updFields);
        $gr = $isGroup ? 1 : 0;

        $query = "update users set $sets where is_group = $gr and id = $id";
        $res = $this->Query($query, $errDuplicate);

        $msg = ($isGroup ? 'group' : 'user') . "id: $id " . implode(',', array_filter($updFields, "CTinyDb::SkipPassword"));
        Log::t($this, $auth, 'update', $msg);
        return $res;
    }

    static function SkipPassword($elem)
    {
        return strpos($elem, 'pwd =') !== 0;
    }

    function UpdateUser(CTinyAuth $auth, $id, $name, $comment, $groups)
    {
        // todo validate email for users
        $v = $this->ValidateNameComment($name, $comment, 'Не задано имя пользователя');
        $fld = [
            "login = {$v['name']}",
            "name = {$v['comment']}"];

        $res = $this->UpdateUserGroup($auth, $id, $fld, false,  CTinyDb::ErrDupUser);
        // $res->rowCount() > 0; ???

        if ($auth->canAdmin())  // а сам себе не может
            $this->SetUserGroups($auth, $id, $groups);
        return true;
    }

    function UpdateGroup(CTinyAuth $auth, $id, $name, $comment)
    {
        $v = $this->ValidateNameComment($name, $comment, 'Не задано название организации');
        $fld = [
            "login = {$v['name']}",
            "name = {$v['comment']}"];

        $res = $this->UpdateUserGroup($auth, $id, $fld, true,  CTinyDb::ErrDupGroup);
        // $res->rowCount() > 0; ???
        return true;
    }

    function ChangePassword(CTinyAuth $auth, $id, $pwdHash)
    {
        if ($pwdHash === null || !is_string($pwdHash) || strlen($pwdHash) == 0)
            $this->Err('Пароль не может быть пустым');

        $fld = ["pwd = {$this->db->quote($pwdHash)}"];
        $res = $this->UpdateUserGroup($auth, $id, $fld, false, '');
        Log::t($this, $auth, 'update', "password changed for user $id");

        return true;
    }

    function ChangeName(CTinyAuth $auth, $name)
    {
        if ($name !== null && !is_string($name))
            $this->Err('Недопустимое имя пользователя');

        $nm = $name == null || $name == '' ? 'null' : $this->db->quote($name);

        $res = $this->UpdateUserGroup($auth, $auth->id(), ["name = $nm"], false, '');
        return true;
    }

    function ToggleUserGroup(CTinyAuth $auth, $id, $del, $isGroup = false, $errInvalidId = "Недопустимый id пользователя")
    {
        $delete = $this->InactiveCond($del);

        $id = $this->ValidateId($id, $errInvalidId, CTinyAuth::Root + 1);

        $res = $this->UpdateUserGroup($auth, $id, [$delete], $isGroup, $isGroup ? CTinyDb::ErrDupGroup : CTinyDb::ErrDupUser);
        return true;
    }

    function CreateGroup(CTinyAuth $auth, $name, $comment)
    {
        if (!$auth->canAdmin())
            return $this->ErrRights();

        $v = $this->ValidateNameComment($name, $comment, 'Не задано название группы');
        $query = "insert into users (login, name, is_group, pwd) values ({$v['name']}, {$v['comment']}, 1, null)";

        $this->beginTran();
        $this->Query($query, "Группа '$name' уже есть в системе");

        $res = $this->db->lastInsertId();
        if (!$auth->isRoot())                                   // добавить автора в группу
        {
            $query = "insert into usergroups (user_id, group_id) values ({$auth->id()}, $res)";
            $this->Query($query);
        }

        $this->commit();
        Log::t($this, $auth, 'create', "Create org, name: '$name', comment: '$comment'");

        return $res;
    }

    // todo validate email for users
    // todo требовать пароль для всех
    function CreateUser(CTinyAuth $auth, $login, $name, $pwdHash, $requirePassword, array $groups, $errNoName, $errDuplicate)
    {
        if (!$auth->canAdmin())
            $this->ErrRights();

        $v = $this->ValidateNameComment($login, $name, $errNoName);

        if ($requirePassword == true && $pwdHash === null)
            $this->Err('Для создаваемого пользователя не указан пароль');

        $pwdHash =  ($pwdHash !== null  && is_string($pwdHash)) ?  $this->db->quote($pwdHash)  : 'null';

        $query = "insert into users (login, name, is_group, pwd) values ({$v['name']}, {$v['comment']}, 0, $pwdHash)";

        $hadTran = $this->beginTran();
        $this->Query($query, $errDuplicate);

        $res = $this->db->lastInsertId();
        $this->SetUserGroups($auth, $res, $groups);

        if (!$hadTran)
        {
            $this->commit();
            Log::t($this, $auth, 'create', "Create user, login: '$login', name: '$name'");
        }

        return $res;
    }

    protected function addGroupsCanAdmin(&$groups)
    {
        return $groups;
    }

    protected function SetUserGroups(CTinyAuth $auth, $userId, array $groupIds)
    {
        if (!$auth->canAdmin())
            return $this->ErrRights();

        $groupIds = filter_var($groupIds, FILTER_VALIDATE_INT, FILTER_FORCE_ARRAY | FILTER_REQUIRE_ARRAY);
        if ($groupIds === false)
            $this->Err("недопустимый id группы");

        $canUse = $this->GetUsersGroups($auth->id());
        $this->addGroupsCanAdmin($canUse);

        $isSuper = $auth->isSuper();

        $out = [];
        foreach($groupIds as $id)
            if ($isSuper || in_array($id, $canUse))
                $out[] =  "($userId, $id)";

        if (count($groupIds) <= 0)
            $this->Err("Нет групп");

        if (count($out) == 0)
        {
            Log::e($this, $auth, "SetUserGroups", "нет доступных групп: toAdd: " . print_r($groupIds, false));
            $this->Err("Нет доступных групп");
        }

        $rightsCond = $isSuper ? 'true' : "group_id in (". join(", ", $canUse) . ")";

        $query = "delete from usergroups where user_id = $userId and $rightsCond";
        $this->Query($query);


        $query = "insert into usergroups (user_id, group_id) values " . join(", ", $out);
        $this->Query($query);
    }

    // регистрировать может админ кого-то и аноним -- сам себя
    // запрашивать пароль может только аноним
    // запрашивать удаление -- только сам себя. Админ и рут сделают без вопросов

    // а если "забыл пароль" после "удалите меня"? -- а и пусть!

    function CreateToken($len = CTinyDb::TokenLen)
    {
        $token = '';
        $baseStr = 'abcdefghijklmopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($baseStr) - 1;
        for ($i = 0; $i < $len; $i++)
            $token .= $baseStr[intval(self::devurandom_rand(0, $max))];

        return $token;
    }

    // equiv to rand, mt_rand
    // returns int in *closed* interval [$min,$max]
    public static function devurandom_rand($min = 0, $max = 0x7FFFFFFF)     // todo migrate to http://php.net/random_int on php 7
    {
        $diff = $max - $min;
        if ($diff < 0 || $diff > 0x7FFFFFFF)
            throw new RuntimeException("Bad range");

        $bytes = mcrypt_create_iv(4, MCRYPT_DEV_URANDOM);
        if ($bytes === false || strlen($bytes) != 4)
            throw new RuntimeException("Unable to get 4 bytes");

        $ary = unpack("Nint", $bytes);
        $val = $ary['int'] & 0x7FFFFFFF;   // 32-bit safe
        $fp = (float) $val / 2147483647.0; // convert to [0,1]
        return round($fp * $diff) + $min;
    }

    function SetUserToken(CTinyAuth $auth, $userId, $type)
    {
        if ($auth == null)
            $this->ErrRights();
        if ($userId == null || !is_numeric($userId))
            $this->Err('некорректный $userId');

        switch ($type)
        {
            case CTinyDb::Verify:
                if (! ($auth->canAdmin() && $auth->id() != $userId ||
                        $auth->isLogged() && $auth->id() == $userId || // !auth->validated()
                        !$auth->isLogged() ))
                    $this->Err('нельзя запрашивать подтверждение');
                break;
            case CTinyDb::ResetPwd:
                if ($auth->isLogged())
                    $this->Err('залогиненые пользователи не бывают забывшими пароль');
                break;
            case CTinyDb::OptOut:
                if (!$auth->isLogged() || $auth->id() != $userId)
                    $this->Err('запрос на удаление не от своего имени');
                break;
            default:
                $this->Err('wrong token type');
        }

        $tokenValidHours = CTinyDb::TokenValidHours;

        $rawToken = $this->CreateToken();
        $token = $this->db->quote($rawToken);

        $query = "update users set token = $token, token_type = $type, token_valid_till = DATE_ADD(UTC_TIMESTAMP, INTERVAL $tokenValidHours HOUR)
          where id = $userId and is_group = 0 and removeDate is null";

        $result = $this->Query($query);
        if ($result->rowCount() != 1)
            $this->Err('не смогли записать токен');

        return $rawToken;
    }

    function VerifyToken(CTinyAuth $auth, $userId, $token, $type, $update = false)
    {
        if ($auth == null)
            $this->ErrRights();
        if ($token == null || !is_string($token))
            $this->Err('Неправильный или пустой токен');
        if ($type == null || $type != CTinyDb::Verify && $type != CTinyDb::ResetPwd && $type != CTinyDb::OptOut)
            $this->Err('Неправильный тип токена');
        if ($userId == null || !is_numeric($userId))
            $this->Err('Не указан пользователь');

        $token = $this->db->quote($token);

        $upd = "update users set token = null, token_type = null, token_valid_till = null";
        $select = "select id, login from users";
        $condition = " where id = $userId and $token = token and token_type = $type and is_group = 0 and removeDate is null";
        $extra = " and token_valid_till > UTC_TIMESTAMP";

        $query = ($update ? $upd : $select) . $condition . $extra;
        $result = $this->Query($query);


        $this->LastError = CTinyDb::ErrCOk;

        if ($update)
            return $result->rowCount() == 1;

        $res = $result->fetch(PDO::FETCH_ASSOC);
        $result->closeCursor();
        return $res == null ? false : $res['login'];
    }

    function AddLogRecord(CTinyAuth $auth, $level, $operation, $message)
    {
        global $tinySett;
        $uid = $auth->id();

        switch($level)
        {
            case self::LogInfo: $strLevel = "info"; break;
            case self::LogTrace: $strLevel = "trace"; break;
            case self::LogDebug: $strLevel = "debug"; break;
            case self::LogError: $strLevel = "error"; break;
            case self::LogCritical: $strLevel = "critical"; break;
            default: $this->Err("Unknown log level");
        }

        if ($level < $tinySett['minLogLevel'])
            return;

        $strLevel = $this->db->quote($strLevel);
        $operation = $this->db->quote($operation);
        $message = $this->db->quote($message);

        $query = "insert into logs (level, user_id, operation, message, stamp)
                values ($strLevel, $uid, $operation, $message, UTC_TIMESTAMP)";
        $this->Query($query);
    }

    function GetLogs(CTinyAuth $auth, $levels, CValidatedFilter $ops, CValidatedFilter $users, $search, $limit)
    {
        if (!$auth->canAdmin())
            $this->ErrRights();

        $ands = [];
        if ($levels != null)
        {
            $levels = filter_var($levels, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::LogInfo, 'max_range' => self::LogCritical], 'flags' => FILTER_FORCE_ARRAY | FILTER_REQUIRE_ARRAY]);
            if ($levels === false)
                $this->Err("недопустимый уровень логов");

            if (count($levels) > 0)
            {
                $levs = [];
                $set = ['info', 'trace', 'debug', 'error', 'critical'];
                foreach($levels as $l)
                    $levs[] = $this->db->quote($set[$l]);

                $ands[] = 'level in ('. implode(', ', $levs) .')';
            }
        }

        $ands[] = $ops->GetCondition('operation', $this->db, false);
        $ands[] = $users->GetCondition('login', $this->db, false);
        if ($search !== null && trim($search) != '')
        {
            $search = $this->escapeForLike($search);
            $ands[] = "(message like '%$search%' or u.login like '%$search%')" ;
        }

        $limit = $limit === null ? '' : " limit " . $this->ValidateId($limit, "Недопустимое значение limit", 1);

        $id = $auth->id();
        $min = CTinyAuth::MinGroup;
        // знакомы через живую группу
        $join = $auth->isSuper() ? '' : "inner join usergroups oth on oth.user_id = l.user_id
            inner join users gr on gr.id = oth.group_id
            left join usergroups my on my.group_id = oth.group_id ";
        if (!$auth->isSuper())
            $ands[] = "gr.removedate is null and gr.is_group = true and my.user_id = $id and my.group_id > $min";

        $ands = count($ands) > 0 ? implode(' and ', $ands) : 'true';
        $query = "select l.*, DATE_FORMAT(l.stamp,'%Y-%m-%dT%TZ') as sstamp, u.login from logs l
            left join users u on u.id = l.user_id
            $join
            where $ands
            order by id desc $limit";

        $result = $this->Query($query);
        $res = [];
        foreach($result as $r)
        {
            $res[] = ['id' => $r['id'],
                'stamp' => $r['sstamp'],
                'level' => $r['level'],
                'uid' => $r['user_id'],
                'login' => $r['login'],
                'duration' => $r['duration'],
                'op' => $r['operation'],
                'message' => $r['message']];
        }
        
        return ['logs' => $res,
            'ops' => $this->GetFilterOptions('operation'),
            'users' => $this->GetFilterOptions('login', 'users', 'is_group = false')];
    }

    private function GetFilterOptions($column, $table = 'logs', $where = "true")
    {
        $query = "select distinct $column
                    from $table
                    where $where
                    order by $column";

        $result = $this->Query($query);
        $res = $result->fetchAll(PDO::FETCH_COLUMN);
        $result->closeCursor();
        return $res;
    }
}

class CValidatedFilter
{
    var $hasEmpty;
    var $vals;

    private $isEmpty;

    const emptyKey = 'empty';
    const valsKey = 'values';

    public static function IntFilter($val, $notAnArrErr, $err, $minValue = 1)
    {
        $opt = ['options' => ['min_range' => $minValue]];
        $func = function($v) use ($err, $opt)
        {
            $res = filter_var($v, FILTER_VALIDATE_INT, $opt);
            if ($res === false)
                throw new InvalidArgumentException("$err: '$v'");

            return $res;
        };

        return new CValidatedFilter($val, $func, $notAnArrErr);
    }

    public static function StringFilter($val, $notAnArrErr, $err)
    {
        $func = function($str) use ($err)
        {
            if (!is_string($str))
                throw new InvalidArgumentException("$err: '$str'");

            return $str;
        };

        return new CValidatedFilter($val, $func, $notAnArrErr);
    }

    function __construct($val, $validator, $notAnArrErr)
    {
        $this->isEmpty = true;
        if ($val == null)
            return;

        $valsSet = isset($val[self::valsKey]);
        if (!is_array($val) || $valsSet && $val[self::valsKey] != null && !is_array($val[self::valsKey]))
            throw new Exception($notAnArrErr);

        $this->hasEmpty = (@$val[self::emptyKey]) == 'true';

        if (isset($val[self::emptyKey]) && !$valsSet)
            $this->vals = [];
        else
            $this->vals = self::FillVals($valsSet ? $val[self::valsKey]: $val, $validator);

        $this->isEmpty = (!$this->hasEmpty) && count($this->vals) == 0;
    }

    // that could be an array_map, but it predates exceptions :(
    private function FillVals(array $vals, $validator)
    {
        $res = [];
        foreach ($vals as $v)
            $res[] = call_user_func($validator, $v);

        return $res;
    }

    public function GetCondition($field, PDO $quoter = null, $withAnd = true)
    {
        if ($this->isEmpty)
            return $withAnd ? '' : 'true';

        $cond = [];
        if ($this->hasEmpty)
            $cond[] = "$field is null";

        if (count($this->vals) > 0)
        {
            $mapped = $quoter == null ? $this->vals : array_map(function ($e) use ($quoter) { return $quoter->quote($e); }, $this->vals);
            $cond[] = "$field in (" . implode(', ', $mapped) . ')';
        }

        return ($withAnd ? 'and ' : '').'(' . implode(' or ', $cond) . ')';
    }
}

class Log
{
    protected static $logDb = null;

    private static function getDb()
    {
        if (self::$logDb == null)
            self::$logDb = new CTinyDb();

        return self::$logDb;
    }

    public static function st(CTinyAuth $auth, $operation, $message)
    {
        $db = self::getDb();
        if ($db != null)
            $db->AddLogRecord($auth, CTinyDb::LogTrace, $operation, $message);
    }
    public static function i(CTinyDb $db, CTinyAuth $auth, $operation, $message)
    {
        $db->AddLogRecord($auth, CTinyDb::LogInfo, $operation, $message);
    }
    public static function t(CTinyDb $db, CTinyAuth $auth, $operation, $message)
    {
        $db->AddLogRecord($auth, CTinyDb::LogTrace, $operation, $message);
    }
    public static function d(CTinyDb $db, CTinyAuth $auth, $operation, $message)
    {
        $db->AddLogRecord($auth, CTinyDb::LogDebug, $operation, $message);
    }

    public static function se(CTinyAuth $auth, $operation, $message)
    {
        $db = self::getDb();
        if ($db != null)
            self::e($db, $auth, $operation, $message);
    }

    public static function e(CTinyDb $db, CTinyAuth $auth, $operation, $message)
    {
        $db->AddLogRecord($auth, CTinyDb::LogError, $operation, $message);
    }
    public static function c(CTinyDb $db, CTinyAuth $auth, $operation, $message)
    {
        $db->AddLogRecord($auth, CTinyDb::LogCritical, $operation, $message);
    }
}
