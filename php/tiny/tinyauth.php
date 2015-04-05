<?
/**
 * Created by Serge Titov for mooServer project
 * 2014 - 2015
 */
if (!defined('IN_TINY'))
	exit;

class CTinyAuth
{
	const Anonymous = 1;
	const Admins = 2;
	const Root = 3;
    const Super = 4;

    const MinGroup = 1001;  // см alter table users AUTO_INCREMENT = 1001

	const Short = 3600; // 1 hour
	const Long = 43200; // 30 days

	protected $id;
	protected $name;
	protected $login;
	protected $groups;
	protected $SID;

	protected $validated;

    static $forgetTpl = array(
                            'key' => 'forget',
                            'subj' => 'Cмена пароля на mooserver',
                            'mail' => "Здравствуйте, {{NAME}}\nКто-то, возможно вы, запросил смену пароля на mooserver\n\nЕсли это были вы -- в течение {{HOUR}} часов перейдите по ссылке {{HREF}}\nВ противном случае не делайте ничего\n\nС уважением, \nАдминистрация mooserver");

    static $registerTpl = array( 'key'=> 'verify',
                            'subj' => 'Регистрация на mooserver',
                            'mail' => "Здравствуйте, {{NAME}}!\nВы были зарегистрированы на сервере mooserver\n\nВаш логин: {{LOGIN}}\nПароль: {{PWD}}\n\nС уважением, \nАдминистрация mooserver");
    //"Здравствуйте {{NAME}}! Кто-то, возможно вы, прислал вам приглашение на mooserver. Для продолжения регистрации в течение {{HOUR}} часов перейдите по ссылке {{HREF}} .\n Если письмо было отправлено вам по ошибке -- просто проигнорируйте его.\n\nС уважением, \nАдминистрация mooserver");

	function __construct(CTinyDb $db)
	{
		$this->setAnonymous();
		if ($db == null || $this->getSession() === false)
			return;

		$res = $db->UserBySession($this->SID);
		if (!isset($res['id']))
			return;

		$tm = time();
		$sess = $res['session'];
		if (isset($res['removed']) && $res['removed'] != null || 
			$tm < $sess['start'] || $tm < $sess['last'] ||
			($tm - $sess['last']) > self::Short || 
			($tm - $sess['start']) > self::Long)
		{			
			$this->clearSession($db);

			/*$diff = $tm - $sess['start'];
			die ("$tm vs {$sess['start']} vs {$sess['last']} diff: $diff, {$this->SID}");*/
			return;
		}
		
		$this->setUser($res);
		$this->updateSession($db);
	}

	function id()
	{
		return $this->id;
	}
	function name()
	{
		return $this->name;
	}
	function login()
	{
		return $this->login;
	}
	function isLogged()
	{
		return $this->id != self::Anonymous;
	}
	public function isRoot()
	{
		return $this->id == self::Root;
	}

	protected function inGroup($grId)
	{
        //return in_array($grId, $this->groups, true);
		foreach ($this->groups as $gr =>$id)
		{
			if ($id == $grId)
				return true;
		}
		return false;
	}

	function canAdmin()
	{
		return ($this->isRoot() || $this->inGroup(self::Admins)) /*&& $this->validated*/; // todo продумать логику validated
	}

    function isSuper()
    {
        return $this->isRoot() || $this->inGroup(self::Super);
    }

	protected function setUser($user)
	{
		$this->id = $user['id'];
		$this->name = $user['name'];
		$this->login = $user['login'];
		$this->validated = $user['validated'] == 1 ? true : false;
		$this->groups = $user['groups'];
	}

	private function setAnonymous()
	{	
		$this->id = self::Anonymous;
		$this->groups = array();
		$this->name = 'Anonymous';
		$this->login = '';
		$this->SID = '';
	}

	protected function getSession()
	{				
		global $tinySett;
		$this->SID = @$_COOKIE[$tinySett['cookie']];
		
		if ($this->SID == '')
			return FALSE;
		return  $this->SID;
	}

	protected function clearSession(CTinyDb $db)
	{
		global $tinySett;

		if ($db != null)
			$db->EndSession($this->SID);

		unset($_COOKIE[$tinySett['cookie']]);
		setcookie($tinySett['cookie'], "", time()-3600);
	}

	protected function startSession(CTinyDb $db, $long = false)
	{
		global $tinySett;
		$this->SID = $db->StartSession($this);
		setcookie($tinySett['cookie'], $this->SID, time() + ($long ? self::Short : self::Long ));
	}

	protected function updateSession(CTinyDb $db)
	{
		$db->UpdateSession($this->SID);
	}

	function apiLogin()
	{
        if (!defined('TINY_API_LOGIN'))
		{
			$this->setAnonymous();
			return false;
		}

		$this->id = self::Root;
		$this->groups = array(self::Admins);
        Log::st($this, "auth", "API login successful");
		return true;
	}

    function tryLogin(CTinyDb $db, $login, &$pass)
    {
        return $this->tryLoginInt($db, $login, $pass, true);
    }

	protected function tryLoginInt(CTinyDb $db, $login, &$pass, $needSession)
	{
		if ($db == null)
			return false;

		$this->setAnonymous();
		$usr = $db->UserByLogin($login);
		if (!isset($usr['id']) || $usr['removed'] != null || $usr['is_group'] == true || $usr['is_gate'] == $needSession)       // todo вынести знания про лосиную специфику из tiny
			return false;


		if (isset($usr['block']) && $usr['block'] > time())
        {
            Log::t($db, $this, "auth", "blocked login attempt for user '$login'");
			return "Логин временно заблокирован";
        }

		if (!isset($usr['pwd']) || !password_verify($pass, $usr['pwd']))
		{
			unset($pass);
			$db->LogFailedLogin($login);
			$this->setAnonymous();
            Log::t($db, $this, "auth", "bad password for user '$login'");
			return false;
		}
		unset($pass);
		
		$this->setUser($usr);
        if ($needSession)
		    $this->startSession($db);
        Log::t($db, $this, "auth", "login successful" . ($needSession ? '' : ' as gate'));
		return true;
	}

	function tryLogout(CTinyDb $db)
	{
		$this->clearSession($db);
        Log::t($db, $this, "auth", "logout successful");
		$this->setAnonymous();
		
		return;
	}

    function changePassword(CTinyDb $db, $old, $new)
    {
        if ($new == null || !is_string($new) || $new == '')
            return 'Пароль не может быть пустым';

        $usr = $db->UserByLogin($this->login());
        if (!isset($usr['id']) || $usr['removed'] != null || $usr['is_group'] == true)
            return 'Операция невозможна, пользователь удален';

        if (!isset($usr['pwd']) || !password_verify($old, $usr['pwd']))
            return 'Неправильный пароль';

        return $db->ChangePassword($this, $usr['id'], password_hash($new, PASSWORD_BCRYPT));
    }

// TODO
// registerUser ($login, $password, $name) -> send token
// tryValidate ($token)

// optOut() -> send token;
// approveOptOut($token);

    function requestRestore(CTinyDb $db, $login)
    {
        $res = $db->UserByLogin($login);
        if (!isset($res['id']) || $res['is_group']/* || $res['is_gate']*/)  // todo выкинуть знание про гейты
            return "Пользователь '$login' не существует";

        $db->beginTran();
        $token = $db->SetUserToken($this, $res['id'],  CTinyDb::ResetPwd);

        $out = $this->SendTokenMail($res['id'], $res['login'], $res['name'], '', $token, CTinyAuth::$forgetTpl);
        if ($out == false)
        {
            $db->rollback();
            return "Ошибка отправки сообщения";
        }
        $db->commit();

        Log::t($db, $this, "request restore", "for login $login");

        return true;
    }

    function resetPassword(CTinyDb $db, $uid, $token, $ttype, $newPwd)
    {
        if ($this->isLogged() && $uid != $this->id())
            return "Залогиненый пользователь не может изменять пароль другим пользователям";

        if ($ttype != CTinyDb::ResetPwd)
            return "Неправильный тип токена";

        if ($newPwd == null || !is_string($newPwd) || $newPwd == '')
            return 'Пароль не может быть пустым';

        if ($db->VerifyToken($this, $uid, $token, $ttype, true) === false)
            return "Неправильный или просроченный токен";

        $tId = $this->id;
        $this->id = $uid;
        $res = $db->ChangePassword($this, $uid, password_hash($newPwd, PASSWORD_BCRYPT));
        $this->id = $tId;

        return true;
    }

    public static function BaseHref()
    {
        $part = pathinfo($_SERVER['SCRIPT_NAME'], PATHINFO_DIRNAME);
        $port = isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] != 80 ? ":{$_SERVER['PORT']}" : '';
        $res = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . "://{$_SERVER['SERVER_NAME']}$port$part/index.html#confirm/";
        return $res;
    }

    public function SendTokenMail($id, $login, $name, $pwd, $token, $tpl)
    {
        global $tinySett;
        $href = self::BaseHref() . "$id/{$tpl['key']}/$token";

        $pairs = array(
            '{{HREF}}' => $href,
            '{{HOUR}}' => CTinyDb::TokenValidHours,
            '{{LOGIN}}' => $login,
            '{{PWD}}' => $pwd,
            '{{NAME}}' => $name == null ? $login : $name);

        $body = $this->FillTemplate($tpl['mail'], $pairs);
        $subj = $this->FillTemplate($tpl['subj'], $pairs);

        $login = filter_var($login, FILTER_VALIDATE_EMAIL);
        if ($login === false)                                   // todo: remove
            $login = $tinySett['defaultMail'];

        if ($name == null || trim($name) == '')
            $name = $login;

        return $this->sendMail($name, $login, $subj, $body);
    }

    protected function FillTemplate($tpl, array $pairs)
    {
        foreach($pairs as $key => $val)
            $tpl = str_replace($key, $val, $tpl);
        return $tpl;
    }

// updateProfile($name, $pwd)


	function sendMail($name_to, // имя получателя
		$email_to, // email получателя
		$subject, // тема письма
		$body, // текст письма
		$html = FALSE // письмо в виде html или обычного текста
		)
	{
		global $tinySett;

		return self::send_mime_mail($tinySett['name'], $tinySett['mail'],
			$name_to, $email_to, 'UTF-8', 'UTF-8', 
			$subject, $body, $html);
	}
	
	// ----------------------------------------------------------------------------
	// функция
        // Автор: Григорий Рубцов [rgbeast]
        // Из статьи Отправка e-mail в русской кодировке средствами PHP
	/*
	Пример:
	send_mime_mail('Автор письма',
	'sender@site.ru',
	'Получатель письма',
        'recepient@site.ru',
	'CP1251', // кодировка, в которой находятся передаваемые строки
	'KOI8-R', // кодировка, в которой будет отправлено письмо
	'Письмо-уведомление',
	"Здравствуйте, я Ваша программа!");
	*/

	static function send_mime_mail($name_from, // имя отправителя
		$email_from, // email отправителя
		$name_to, // имя получателя
		$email_to, // email получателя
		$data_charset, // кодировка переданных данных
		$send_charset, // кодировка письма
		$subject, // тема письма
		$body, // текст письма
		$html = FALSE // письмо в виде html или обычного текста
		)
	{
		$to = self::mime_header_encode($name_to, $data_charset, $send_charset) .     " <$email_to>";
		$from = self::mime_header_encode($name_from, $data_charset, $send_charset) . " <$email_from>";
		$subject = self::mime_header_encode($subject, $data_charset, $send_charset);

		if($data_charset != $send_charset)
			$body = iconv($data_charset, $send_charset, $body);

		$headers = "From: $from\r\n";
		$type = ($html) ? 'html' : 'plain';
		$headers .= "Content-type: text/$type; charset=$send_charset\r\n";
		$headers .= "Mime-Version: 1.0\r\n";

        	return mail($to, $subject, $body, $headers, "-f".$email_from);
	}

	static function mime_header_encode($str, $data_charset, $send_charset)
	{
		if($data_charset != $send_charset)
			$str = iconv($data_charset, $send_charset, $str);
		return '=?' . $send_charset . '?B?' . base64_encode($str) . '?=';
	}
}
?>