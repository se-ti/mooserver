<?
/**
 * Created by Serge Titov for mooServer project
 * 2014 - 2015
 */
if (!defined('IN_TINY'))
	exit;

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
            $charset = isset($tinySett['charset']) ? $tinySett['charset']: 'utf8';
			$conn = "mysql:dbname={$tinySett['base']};host={$tinySett['host']};charset=$charset";
			$this->db = new PDO($conn, $tinySett['user'], $tinySett['pwd']);
		} 
		catch (PDOException $e) 
		{
            $this->Err('Connection failed: ' . $e->getMessage(), true);
		}

		$query = "select max(version) as ver from version";
		$res = $this->Query($query);
		foreach($res as $r)
		{
			if($r['ver'] != self::$Version)
				$this->Err("Wrong db version: '{$r['ver']}', expected '" .self::$Version. "'", true);
			break;
		}
		$res->closeCursor();

        if (isset($tinySett['timezone']))
            date_default_timezone_set($tinySett['timezone']);

	}

    public function beginTran()
    {
        if ($this->db == null)
            return;
        $this->useTran = true;
        $this->db->beginTransaction();
    }
    public function commit()
    {
        if (!$this->useTran)
            return;
        $this->useTran = false;

        if($this->db != null)
            $this->db->commit();
    }

    public function rollback()
    {
        if (!$this->useTran)
            return;
        $this->useTran = false;

        if($this->db != null)
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
        return $this->Err(CTinyDb::ErrCRights);
    }

    protected function ErrDuplicate($msg)
    {
        if ($this->db->errorCode() != 23000)    // Duplicate entry
            return false;

        $this->Err($msg);
        return true;
    }

	protected function Err($msg, $die = true)
	{
        $this->rollback();

        $this->LastError = $msg;

        throw new Exception($msg);
	}

    protected function Query($query, $dupMessage = null)
    {
        $res = $this->db->query($query);
        if (!$res && ($dupMessage === null || !$this->ErrDuplicate($dupMessage)))
        {
            $err = $this->db->errorInfo();
            $this->Err("Query failed: sqe: {$err[0]} code '{$err[1]}' error: {$err[2]} at request: <br/>$query");
        }

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

		$query = "delete from session where id = $session";
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
			return array();

		if ($query == null || !is_string($query))
			$query = "select *, UNIX_TIMESTAMP(CONVERT_TZ(removeDate, '+0:00', @@session.time_zone)) as removed, UNIX_TIMESTAMP(CONVERT_TZ(block_till, '+0:00', @@session.time_zone)) as block
					from users where $where";

		$result = $this->Query($query);

		$res = array();
		foreach($result as $r)
		{
			$res = $r;
			break;
		}
		$result->closeCursor();

		if (!isset($res['id']))
			return array();

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

		$res = array();
		foreach($result as $r)
			$res[] = $r['group_id'];

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
			return array();

		$user['session'] = array(
			'start' => intval(@$user['st']),
			'last' =>  intval(@$user['cur']));
		unset($user['st']);
		unset($user['cur']);

		return $user;
	}

	function UserById($id)
	{
		if ($id == null || !is_int($id))
            return $this->Err("incorrect userId '$id'");
		return $this->UserBy("id = $id");
	}

	function UserByLogin($login)
	{
		if ($login == null || !is_string($login))
            $this->Err('incorrect login');
		
		return $this->UserBy("login = " . $this->db->quote($login));
	}

    protected function ValidateTrim($str, $err = 'строка должна быть не пустой')
    {
        if ($str == null || ! is_string($str))
            $this->Err($err);

        $str = trim($str);
        if (strlen($str) == 0)
            $this->Err($err);
        return $str;
    }

    private function ValidateNameComment($name, $comment, $errNoName = 'не задано имя')
    {
        return array('name' =>$this->db->quote($this->ValidateTrim($name, $errNoName)),
                    'comment' => ($comment !== null && is_string($comment)) ? $this->db->quote(trim($comment)) : 'null');
    }

    protected function ValidateId($id, $err, $min = 1)
    {
        filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => $min)));
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
        if ($auth->isSuper() ||  $auth->id() == $id && $id  > CTinyAuth::Anonymous)
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
        $fld = array(
            "login = {$v['name']}",
            "name = {$v['comment']}"
        );

        $res = $this->UpdateUserGroup($auth, $id, $fld, false,  CTinyDb::ErrDupUser);
        // $res->rowCount() > 0; ???

        if ($auth->canAdmin())  // а сам себе не может
            $this->SetUserGroups($auth, $id, $groups);
        return true;
    }

    function UpdateGroup(CTinyAuth $auth, $id, $name, $comment)
    {
        $v = $this->ValidateNameComment($name, $comment, 'Не задано название организации');
        $fld = array(
            "login = {$v['name']}",
            "name = {$v['comment']}"
        );

        $res = $this->UpdateUserGroup($auth, $id, $fld, true,  CTinyDb::ErrDupGroup);
        // $res->rowCount() > 0; ???
        return true;
    }

    function ChangePassword(CTinyAuth $auth, $id, $pwd)
    {
        if ($pwd === null || !is_string($pwd) || strlen($pwd) == 0)
            $this->Err('Пароль не может быть пустым');

        $fld = array("pwd = {$this->db->quote($pwd)}");
        $res = $this->UpdateUserGroup($auth, $id, $fld, false, '');
        Log::t($this, $auth, 'update', 'password changed');

        return true;
    }

    function ChangeName(CTinyAuth $auth, $name)
    {
        if ($name !== null && !is_string($name))
            $this->Err('Недопустимое имя пользователя');

        $nm = $name == null || $name == '' ? 'null' : $this->db->quote($name);

        $res = $this->UpdateUserGroup($auth, $auth->id(), array("name = $nm"), false, '');
        return true;
    }

    function ToggleUserGroup(CTinyAuth $auth, $id, $del, $isGroup = false, $errInvalidId = "Недопустимый id пользователя")
    {
        $delete = $this->InactiveCond($del);

        $id = $this->ValidateId($id, $errInvalidId, CTinyAuth::Root + 1);

        $res = $this->UpdateUserGroup($auth, $id, array($delete), $isGroup, $isGroup ? CTinyDb::ErrDupGroup : CTinyDb::ErrDupUser);
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
    function CreateUser(CTinyAuth $auth, $login, $name, $pwdHash, array $groups, $isGate, $errNoName, $errDuplicate)
    {
        if (!$auth->canAdmin())
            $this->ErrRights();

        $v = $this->ValidateNameComment($login, $name, $errNoName);
        $isGate = filter_var($isGate, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isGate === null)
            $this->Err("Недопустимое значение флага isGate");

        if ($isGate == false && $pwdHash === null)
            $this->Err('Для создаваемого пользователя не указан пароль');

        $pwdHash =  ($pwdHash !== null  && is_string($pwdHash)) ?  $this->db->quote($pwdHash)  : 'null';
        $isGateVal = $isGate ? 1 : 0;

        $query = "insert into users (login, name, is_group, is_gate, pwd) values ({$v['name']}, {$v['comment']}, 0, $isGateVal, $pwdHash)";

        $this->beginTran();
        $this->Query($query, $errDuplicate);

        $res = $this->db->lastInsertId();
        $this->SetUserGroups($auth, $res, $groups);

        $this->commit();

        $type = $isGate ? 'gate' : 'user';
        Log::t($this, $auth, 'create', "Create $type, login: '$login', name: '$name'");

        return $res;
    }

    protected function SetUserGroups(CTinyAuth $auth, $userId, array $groupIds)
    {
        if (!$auth->canAdmin())
            return $this->ErrRights();

        $groupIds = filter_var($groupIds, FILTER_VALIDATE_INT, FILTER_FORCE_ARRAY | FILTER_REQUIRE_ARRAY);
        if ($groupIds === false)
            $this->Err("недопустимый id группы");

        $canUse = $this->GetUsersGroups($auth->id());
        $canUse[] = CMooseAuth::Feeders; // даже если у самого прав нет -- его можно добавить

        $isSuper = $auth->isSuper();

        $out = array();
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
            $token .= $baseStr[rand(0, $max)];

        return $token;
    }
    function SetUserToken(CTinyAuth $auth, $userId, $type)
    {
        if ($auth == null)
            return $this->ErrRights();
        if ($userId == null || !is_numeric($userId))
            return $this->Err('некорректный $userId');

        switch ($type)
        {
            case CTinyDb::Verify:
                if (! ($auth->canAdmin() && $auth->id() != $userId ||
                        $auth->isLogged() && $auth->id() == $userId || // !auth->validated()
                        !$auth->isLogged() ))
                    return $this->Err('нельзя запрашивать подтверждение');
                break;
            case CTinyDb::ResetPwd:
                if ($auth->isLogged())
                    return $this->Err('залогиненые пользователи не бывают забывшими пароль');
                break;
            case CTinyDb::OptOut:
                if (!$auth->isLogged() || $auth->id() != $userId)
                    return $this->Err('запрос на удаление не от своего имени');
                break;
            default:
                return $this->Err('wrong token type');
        }

        $tokenValidHours = CTinyDb::TokenValidHours;

        $rawToken = $this->CreateToken();
        $token = $this->db->quote($rawToken);

        $query = "update users set token = $token, token_type = $type, token_valid_till = DATE_ADD(UTC_TIMESTAMP, INTERVAL $tokenValidHours HOUR)
          where id = $userId and is_group = 0 and removeDate is null";

        $result = $this->Query($query);
        if ($result->rowCount() != 1)
            return $this->Err('не смогли записать токен');

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

        foreach($result as $r)
        {
            $res = $r['login'];
            $result->closeCursor();

            return $res;
        }

        return false;
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

    function GetLogs(CTinyAuth $auth, $levels, $limit)
    {
        if (!$auth->canAdmin())
            $this->ErrRights();

        $cond = 'true';

        if ($levels != null)
        {
            $levels = filter_var($levels, FILTER_VALIDATE_INT, array('options' => array('min_range' => self::LogInfo, 'max_range' => self::LogCritical), 'flags' => FILTER_FORCE_ARRAY | FILTER_REQUIRE_ARRAY));
            if ($levels === false)
                $this->Err("недопустимый уровень логов");

            if (count($levels) > 0)
            {
                $levs = array();
                $set = array('info', 'trace', 'debug', 'error', 'critical');
                foreach($levels as $l)
                    $levs[] = $this->db->quote($set[$l]);

                $cond = 'level in ('. implode(', ', $levs) .')';
            }
        }

        $limit = $limit === null ? '' : " limit " . $this->ValidateId($limit, "Недопустимое значение limit", 1);

        $id = $auth->id();
        $min = CTinyAuth::MinGroup;
        // знакомы через живую группу
        $join = $auth->isSuper() ? '' : "inner join usergroups oth on oth.user_id = l.user_id
            inner join users gr on gr.id = oth.group_id
            left join usergroups my on my.group_id = oth.group_id ";
        $joinCond = $auth->isSuper() ? 'true' : "gr.removedate is null and gr.is_group = true and my.user_id = $id and my.group_id > $min";

        $query = "select l.*, DATE_FORMAT(l.stamp,'%Y-%m-%dT%TZ') as sstamp, u.login from logs l
            left join users u on u.id = l.user_id
            $join
            where true and $cond and $joinCond
            order by id desc $limit";

        $result = $this->Query($query);
        $res = array();
        foreach($result as $r)
        {
            $res[] = array('id' => $r['id'],
                'stamp' => $r['sstamp'],
                'level' => $r['level'],
                'uid' => $r['user_id'],
                'login' => $r['login'],
                'duration' => $r['duration'],
                'op' => $r['operation'],
                'message' => $r['message']);
        }

        return $res;
    }
}

class Log
{
    public static function st(CTinyAuth $auth, $operation, $message)
    {
        global  $db;
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
    public static function e(CTinyDb $db, CTinyAuth $auth, $operation, $message)
    {
        $db->AddLogRecord($auth, CTinyDb::LogError, $operation, $message);
    }
    public static function c(CTinyDb $db, CTinyAuth $auth, $operation, $message)
    {
        $db->AddLogRecord($auth, CTinyDb::LogCritical, $operation, $message);
    }
}

?>