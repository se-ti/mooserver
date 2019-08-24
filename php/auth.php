<?php
/**
 * Created by Serge Titov for mooServer project
 * 2014 - 2015
 */
if (!defined('IN_MOOSE'))
	exit;

if (!defined('IN_TINY'))
	define('IN_TINY', true);

require_once('tiny/tinyauth.php');

class CMooseAuth extends CTinyAuth
{
	const Feeders = 5;
    const Demo = 1001;

	function __construct(CMooseDb $db) 
	{
		parent::__construct($db);
	}

	function canFeed()
	{
		return $this->isRoot() || $this->inGroup(self::Feeders);
	}

	function apiLogin()
	{
		if (parent::apiLogin() == false)
			return false;

		//$this->id = self::Import;
		$this->validated = true;
		$this->groups[] = self::Feeders;
	}

    protected function canLoginDirectly($usr, $needSession)
    {
        return parent::canLoginDirectly($usr, $needSession) && $usr['is_gate'] != $needSession;
    }

    function gateLogin(CTinyDb $db, $gate, $pwd = null)
    {
        if (!defined('TINY_API_LOGIN'))
            return false;

        if ($this->isLogged())
            return 'Пользователь уже залогинен';

        if ($pwd != null)
            return $this->tryLoginInt($db, $gate, $pwd, false);

        $usr = $db->UserByLogin($gate);
        if (! parent::canLoginDirectly($usr, false))
            return 'no such user';

        if (isset($usr['block']) && $usr['block'] > time())
            return "Логин временно заблокирован";

        $this->setUser($usr);

        Log::t($db, $this, "auth", "successful gate login for user '$gate'");
        return true;
    }


    function AddUser(CTinyDb $db, $name, $comment, array $orgs, $super, $admin, $feed) // todo validate all
    {
        if ($orgs == null)
            $orgs = [];

        if ($this->isSuper() && $super == true && array_search(self::Super, $orgs) === false )
            $orgs[] = self::Super;

        if ($admin == true && array_search(self::Admins, $orgs) === false )
            $orgs[] = self::Admins;

        if ($feed == true && array_search(self::Feeders, $orgs) === false )
            $orgs[] = self::Feeders;

        $db->beginTran();

        $pwd = $db->CreateToken(6);
        $res = $db->CreateUser($this, $name, $comment, password_hash($pwd, PASSWORD_BCRYPT), true, $orgs, 'Не задан логин пользователя', "Пользователь '$name' уже есть в системе");

        $token = $db->SetUserToken($this, $res, CTinyDb::Verify);

        $out = $this->SendTokenMail($res, $name, $comment, $pwd, $token, CTinyAuth::$registerTpl);
        if ($out == false)
        {
            $db->rollback();
            return "Ошибка отправки сообщения";
        }

        $db->commit();

        return $res;
    }

    function UpdateUser(CTinyDb $db, $id, $name, $comment, $orgs, $super, $admin, $feed)
    {
        if ($this->isSuper() && $super == true && array_search(self::Super, $orgs) === false )
            $orgs[] = self::Super;

        if ($admin == true && array_search(self::Admins, $orgs) === false )
            $orgs[] = self::Admins;

        if ($feed == true && array_search(self::Feeders, $orgs) === false )
            $orgs[] = self::Feeders;

        $res = $db->UpdateUser($this, $id, $name, $comment, $orgs);
        if ($res !== true)
            throw new Exception('что-то странное 2');

        return $res;
    }

    function AddGate(CMooseDb $db, $name, $comment, array $orgs)
    {
        if (array_search(self::Feeders, $orgs) === false )
            $orgs[] = self::Feeders;

        $db->beginTran();
        $res = $db->CreateUser($this, $name, $comment, null, false, $orgs, 'Не задано название гейта', "Гейт '$name' уже есть в системе");
        $db->MakeUserGate($this, $res);
        $db->commit();

        Log::t($db, $this, 'create', "Create gate, login: '$name', name: '$comment'");

        return $res;
    }

    function UpdateGate(CTinyDb $db, $id, $name, $comment, $orgs)
    {
        if (array_search(self::Feeders, $orgs) === false )
            $orgs[] = self::Feeders;

        $res = $db->UpdateUser($this, $id, $name, $comment, $orgs);
        if ($res !== true)
            throw new Exception('что-то странное');

        return $res;
    }
}
?>