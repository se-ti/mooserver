/**
 * Created by Serge Titov for mooServer project
 * 2014 - 2015
 */

CLogin = function(menu)
{
	CControl.call(this);
	this.c = {
        activate: null,
        forget: null,
        dialog: null,

		mail: null,
		pwd: null,
		login: null,
		logout: null,
		name: null, 
		err: null, 
		feedback: null,

        mailErr: null,
        mailFeedback: null,
        loginErr : null
	};

    this._forget = false;

	this.attach(menu);
}

CLogin.prototype = 	
{
	_tpl: '<form class="navbar-form navbar-right" role="form">     <!-- форма логина -->'+

        '<button class="btn btn-success" type="button" id="activate">Войти</button> ' +

		// '<button class="btn btn-success hidden" type="button" id="login">Войти</button> ' +

        '<div class="form-group has-feedback has-warning">' +
			'<button class="btn btn-warning" type="button" style="display: none;" id="logout">Выход</button>' +
		'</div> ' +
	  '</form>' +
	  '<p class="navbar-text navbar-right" id="name"><a href="#profile" class="navbar-link"></a></p>',


    _modal: //'<!-- Modal -->' +
        '<div class="modal fade" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">' +
            '<div class="modal-dialog">' +
                '<div class="modal-content">' +
                    '<div class="modal-header">' +
                        '<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>' +
                        '<h4 class="modal-title" id="myModalLabel">Авторизация</h4>' +
                    '</div>' +
                    '<div class="modal-body">' +
                        '<form class="form-signin" role="form">' +

                            '<div class="form-group -has-error has-feedback"> ' +
                                '<label class="control-label" for="mail" id="logmail"></label>' +
                                '<input class="form-control" type="text" placeholder="Email" id="mail" autofocus="" autocomplete="on"/>' +
                                '<span class="glyphicon glyphicon-remove form-control-feedback"></span>' +
                            '</div> ' +

                            '<div class="form-group has-error has-feedback">'+
                                '<label class="control-label" for="pwd" id="logpwd"></label>' +
                                '<input class="form-control" type="password" placeholder="Пароль" id="pwd"/>' +
                                '<span class="glyphicon glyphicon-remove form-control-feedback"></span>' +
                            '</div> ' +

                                //     '<div class="checkbox">' +
                            '<div class="form-group has-error has-feedback">'+
                                '<label class="control-label" id="logError"></label>' +
                                '<button class="btn btn-lg btn-primary btn-block" type="button">Войти</button>' +
                            '</div>' +

                            '<div class="form-group has-error has-feedback">'+
                                '<label class="control-label" id="logError"></label>' +
                                '<a href="#" ">Восстановление пароля</a>' +
                            '</div>' +

                        '</form>' +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>',

	attach: function(menu)
	{
		var el = $(this._tpl).appendTo(menu);
        var dlg = $(this._modal).appendTo('body');

		var c = this.c;

        c.activate = el.find('#activate')
            .click($cd(this, this._activateDlg));
        c.logout = el.find('button.btn-warning')
            .click($cd(this, this.logout));
        c.name = $(el.get(1)).find('a');

        c.dialog = dlg;

        c.login = dlg.find('form button')
            .click($cd(this, this.login));
		c.mail = dlg.find('input[type="text"]')
            .keydown($cd(this, this.onEnter));
		c.pwd = dlg.find('input[type="password"]')
            .keydown($cd(this, this.onEnter));

        c.forget = dlg.find('a')
            .click($cd(this, this._startForget));


        c.mailFeedback = c.mail.parent();
        c.mailErr = c.mailFeedback.find('label.control-label');

        c.loginErr = c.login.parent().find('label.control-label');

        c.feedback = c.pwd.parent();
        c.err = c.feedback.find('label.control-label');
	},

    _activateDlg: function()
    {
        var c = this.c;
        var dlg = c.dialog;

        this._forget = false;
        this._setupDialog();

        dlg.modal();

        window.setTimeout(function(){c.mail.focus();}, 500); // magic constant
    },

    _startForget: function(e)
    {
        e.preventDefault();
        this._forget = true;
        this._setupDialog();
    },

	setup: function(appData)
	{
        appData = appData || {};
		var rights = appData.rights || {};

		var c = this.c;

		c.name.toggle(rights.isLogged && (rights.user||'') != '').text(rights.user).append('<span class="caret"></span>');
		c.login.toggle(!rights.isLogged);
		c.logout.toggle(rights.isLogged);
        c.activate.toggle(!rights.isLogged);

        c.mail.parent().toggle(!rights.isLogged);
        c.pwd.parent().toggle(!rights.isLogged);

		this.logged = rights.isLogged;

		this._raise_onSetup(appData);
	},

    _clearErrors: function()
    {
        var c = this.c;

        c.feedback.removeClass('has-error')
            .find('span')
            .removeClass('glyphicon-remove');
        c.err.text('');

        c.loginErr.text('');
        c.mailErr.text('');
        c.mailFeedback.removeClass('has-error')
            .find('span')
            .removeClass('glyphicon-remove');
    },

    _setupDialog: function()
    {
        var c = this.c;
        c.feedback.toggleClass('hidden', this._forget);
        c.forget.parent().toggleClass('hidden', this._forget);
        this.c.loginErr.parent().addClass('has-error').removeClass('has-success');

        c.login.html(this._forget ? "Отправить письмо для смены пароля" : "Войти");
        c.dialog.find('.modal-title').text(this._forget ? "Восстановление пароля" : "Авторизация");

        this.c.login.removeClass('hidden');
        this.c.mail.parent().removeClass('hidden');

        this._clearErrors();
    },

	login: function()
	{
		var c = this.c;
		var param = {
			login: c.mail.val(),
			pass : c.pwd.val()
		};

        this._clearErrors();

        if (this._forget)
            param.forget = true;

		c.pwd.val('');
		if ((param.login || '') == '')
		{
			c.mail.focus();
			return this._loginErr('Не задан логин', c.mailErr, c.mailFeedback);
		}

		if ((param.pass || '') == '' && !this._forget)
		{
			c.pwd.focus();
			return this._loginErr('Не задан пароль', c.err, c.feedback);
		}

		$ajax('login', param, $cd(this, this._onLogin));

		return true;
	},

	logout: function()
	{
		$ajax('login', {logout: true}, $cd(this, this._onLogin));
	},

	onEnter: function(e)
	{
		if (e.which == 13)
		{
			e.preventDefault();
			this.login();
		}
	},

	_loginErr: function(msg, err, feedback)
	{
		var show = (msg || '') != '';

        err = err || this.c.loginErr;

        if (feedback)
		    feedback.toggleClass('has-error', show)
                .find('span')
                .toggleClass('glyphicon-remove', show);

		err.text(msg);
		return !show;
	},

	_onLogin: function(result, text, jqXHR)
	{
		if ((result.error || '') != '')
			return this._loginErr(result.error);

        if (result.res)
        {
            if (this._forget)
            {
                this.c.loginErr.html('<p>Вам отправлено письмо с инструкцией по смене пароля.<br/>Если письмо не будет доставлено в течение часа, убедитесь, что оно не попало в спам, и обратитесь к администратору MooServer.<p>Ваш MooServer.');
                this.c.loginErr.parent().removeClass('has-error').addClass('has-success');
                this.c.login.addClass('hidden');
                this.c.mail.parent().addClass('hidden');
            }
            else
                this.c.dialog.modal('hide');
        }

		this.setup(result.data);
		//this._raise_onSetup(result.rights);
	},

	on_Setup: function(h)
	{
		return this.on("setup", h);
	},

	remove_onSetup: function(h)
	{
		return this.remove("setup", h);
	},

	_raise_onSetup:	function(appData)
	{
		this.raise("setup", appData);
	}
}
CLogin.inheritFrom(CControl);

CPassword = function(root, changePwd)
{
    CControl.call(this);

    this._c = {
        root: null,
        save: null,
        cancel: null,
        toggle: null,
        edit: null,
        old: null,
        oldErr: null,
        new1: null,
        new2: null,
        newErr: null
    };

    this._changePwd = changePwd || false;

    this._cbOnEdit = $cd(this, this.startEdit);
    this._cbOnChange = $cd(this, this._onChange);
    this._cbSave = $cd(this, this._onSave);
    this._cbCancel = $cd(this, this._onCancel);
    this._cbTest = $cd(this, this._testPwd);
    this._cbEnter = $cd(this, this._onEnter);

    this._extra = null;

    this._buildIn(root);
}

CPassword.prototype =
{
    _tpl: '<div><button class="btn btn-default">Изменить</button></div>',
    _editTpl: '<div class="hidden form-control-static"></div>',
    _editRow: '<dl><dt>{0}</dt><dd><input type="password" class="form-control"/>{1}</dd></dl>',
    _errTpl: '<p class="hidden text-danger"></p>',

    _buildIn: function(root)
    {
        var e = $(this._tpl)
            .appendTo(root);

        var c = this._c;
        c.root = e;
        c.toggle = e.find('button')
            .click(this._cbOnEdit);

        c.edit = $(this._editTpl)
            .appendTo(e);

        var e1;
        if (! this._changePwd)
        {
            e1 = $(String.format(this._editRow, 'Старый',this._errTpl))
                .appendTo(c.edit);
            c.old = e1.find('input')
                .change(this._cbTest);
            c.oldErr = e1.find('p');
        }

        e1 = $(String.format(this._editRow, 'Новый',this._errTpl))
            .appendTo(c.edit);
        c.new1 = e1.find('input')
            .keydown(this._cbEnter);
        c.newErr = e1.find('p');

        e1 = $(String.format(this._editRow, 'Повторите ввод', ''))
            .appendTo(c.edit);
        c.new2 = e1.find('input')
            .keydown(this._cbEnter);

        var sc = $saveCancel(c.edit, this._cbSave, this._cbCancel);
        c.save = sc.save;
        c.cancel = sc.cancel;
    },

    _toggle: function(edit)
    {
        this._c.edit.toggleClass('hidden', !edit);
        this._c.toggle.toggleClass('hidden', edit);
    },

    /*toggle: function(show)
    {
        this._c.root.toggleClass('hidden', !show);
    },*/

    _clear: function()
    {
        var c = this._c;
        if (c.old)
            c.old.val('');
        c.new1.val('');
        c.new2.val('');

        this._err(c.oldErr, '');
        this._err(c.newErr, '');
    },

    startEdit: function()
    {
        this._clear();

        this._toggle(true);
        (this._changePwd ? this._c.new1 : this._c.old).get(0).focus();
    },

    _onCancel: function()
    {
        this._toggle(false);
        this._raise_endEdit(false);
    },

    _onEnter: function(e)
    {
        if (e.which == 13)
        {
            e.preventDefault();
            this._onSave();
        }
    },

    _onSave: function()
    {
        var tp = this._testPwd();
        if (!this._matchPwd() || !tp)
            return;

        var param = {
            old: this._old(),
            newpwd: this._new()
        };

        if (this._changePwd && this._extra)
        {
            param.uid = this._extra.uid;
            param.token = this._extra.token;
            param.ttype = this._extra.ttype;
        }

        $ajax('changePwd', param, this._cbOnChange);
    },

    _onChange: function(result, text, jqXHR)
    {
        if (result.error)
        {
            this._err(this._changePwd ? this._c.newErr : this._c.oldErr, result.error);
            return;
        }

        this._toggle(false);
        CApp.single().message('Пароль успешно сменен', 5000);
        this._raise_endEdit(true);
    },

    _old: function()
    {
        return this._c.old ? this._c.old.val() : '';
    },

    _new: function()
    {
        return this._c.new1.val();
    },

    _testPwd: function()
    {
        if (this._old() != '' || this._changePwd)
        {
            this._err(this._c.oldErr, '');
            return true;
        }

        this._err(this._c.oldErr, 'Не указан старый пароль');
        return false;
    },

    _matchPwd: function()
    {
        var msg = '';
        var new1 = this._new();
        var new2 = this._c.new2.val();

        if (new1 == '')
            msg = 'Не задан новый пароль';
        else if (new1 != new2)
            msg = 'Пароли не совпадают';

        this._err(this._c.newErr, msg);
        return msg == '';
    },

    _err: function(elem, html)
    {
        if (!elem)
            return;
        elem.html(html || '')
            .toggleClass('hidden', (html || '') == '');
    },

    setExtraParam: function(uid, ttype, token)
    {
        this._extra = {
            uid: uid,
            ttype: ttype,
            token: token
        };
    },

    on_endEdit: function(h)
    {
        return this.on("endEdit", h);
    },

    remove_endEdit: function(h)
    {
        return this.remove("endEdit", h);
    },

    _raise_endEdit: function(save)
    {
        this.raise("endEdit", save);
    }
}
CPassword.inheritFrom(CControl);

CProfileNameEdit = function(root)
{
    CControl.call(this);
    this._c = {
        root : null,
        text: null,
        toggle: null,
        edit: null,
        input: null,
        save: null,
        cancel: null
    };

    this._restoreStatic = false;

    this._cbOnEdit = $cd(this, this._onEdit);
    this._cbSave = $cd(this, this._onSave);
    this._cbCancel = $cd(this, this._onCancel);
    this._cbOnChange = $cd(this, this._onChange);

    this._buildIn(root);
}

CProfileNameEdit.prototype =
{
    _tpl: '<button class="btn btn-default btn-xs" style="margin-left: 2em;"><span class="glyphicon glyphicon-pencil"></span></button>',
    _editTpl: '<div class="hidden"><input type="text" class="form-control"></div>',
    _buildIn: function(root)
    {
        var c = this._c;

        c.root = $(root);

        c.text = $('<span></span>')
            .appendTo(root);
        c.toggle = $(this._tpl)
            .appendTo(root)
            .click(this._cbOnEdit);

        c.edit = $(this._editTpl)
            .appendTo(root);

        c.input = c.edit.find('input');

        var sc = $saveCancel(c.edit, this._cbSave, this._cbCancel);
        c.save = sc.save;
        c.cancel = sc.cancel;
    },

    setValue: function(val)
    {
        this._c.text.text(val || '<не задано>');
        this._c.input.val(val || '');
    },

    _toggle: function(edit)
    {

        var c = this._c;
        var fcs = 'form-control-static';
        if (edit)
        {
            this._restoreStatic = c.root.hasClass(fcs);
            if (this._restoreStatic)
            {
                c.root.removeClass(fcs);
                //c.edit.css('margin-left', '-15px');
            }
        }
        else if (this._restoreStatic)
        {
            c.root.addClass(fcs);
        }

        c.text.toggleClass('hidden', edit);
        c.toggle.toggleClass('hidden', edit);
        c.edit.toggleClass('hidden', !edit);
    },

    _onEdit: function()
    {
        this._toggle(true);
        this._c.input.get(0).focus();
    },

    _onCancel: function()
    {
        this._toggle(false);
    },

    _onSave: function()
    {
        $ajax('changeName', {name: this._c.input.val()}, this._cbOnChange);
    },

    _onChange: function(result, text, jqXHR)
    {
        if (result.error)
        {
            CApp.single().error(result.error);
            return;
        }

        this._c.text.text(this._c.input.val() || '<не задано>');
        this._toggle(false);

        CApp.single().message('Имя пользователя сохранено', 5000);
    }
}
CProfileNameEdit.inheritFrom(CControl);

CSmsControl = function(parent)
{
	CControl.call(this);

    this.activator = null;
	this.address = null;
	this.time = null;
	this.text = null;
	this.send = null;
	this.err = null;

    this._cb_onSendSMS = $cd(this, this._onSendSMS);
	this._buildIn(parent);
}

CSmsControl.prototype = {
    _tpl:   '<div class="panel panel-default hidden" style="margin-top: 3ex;"> ' +
		'<div class="panel-body"> ' +
		    '<select class="form-control"></select><br/> ' +
		    '<input  type="text" class="form-control" placeholder="2012-07-31T03:00:00Z" maxlength="26"/><br/> ' +
		    '<textarea class="form-control" style="width: 100%; max-height: 25ex; resize: vertical;"></textarea><br/> ' +
		    '<div class="alert alert-danger hidden" role="alert"></div> ' +
		    '<button class="btn btn-default" class="form-control">Добавить SMS</button> ' +
		  '</div>' +
		'</div>',

    _activator: '<div class="hidden"><button class="btn btn-default" data-type="activity" style="margin-top: 3ex;">Добавить SMS...</button></div>',


	_buildIn: function(root)
	{
        this.activator = $(this._activator).appendTo(root).find('button').click($cd(this, this._onActivator));

		var content = $(this._tpl).appendTo(root);
		this.address = content.find('select').change($cd(this, this._change));
		this.time = content.find('input');
		this.text = content.find('textarea');
		this.send = content.find('button').click($cd(this, this._sendSms));
		this.err = content.find('div.alert');
	},

    _onActivator: function()
    {
        this.collapse(false);
        this.address.focus();
    },

    collapse: function(collapse)
    {
        this.err.addClass('hidden').html('');
        var ap = this.address.parent().parent();

        if (this.activator.parent().hasClass('hidden') && ap.hasClass('hidden')) // disabled
            return;

        this.activator.parent().toggleClass('hidden', !collapse);
        ap.toggleClass('hidden', collapse);
    },

	toggle: function(show)
	{
	    this.collapse(true);
        this.activator.parent().toggleClass('hidden', !show);
	},

	selectPhone: function(phone)
	{
		var opt = this.address.get(0).options;
		for (var i = 0; i < opt.length; i++)
			if (opt[i].phone == phone)
			{
				opt[i].selected = true;
				break;
			}
	},

	setData: function(phones, mooses)
	{
		if (!phones)
			return;
		mooses = mooses || [];

		var address = this.address.get(0);
		var opt = address.options;
		while (opt.length > 0)
			opt.remove(opt.length-1);

        var i;
		var map = [];
		for (i = 0; i < mooses.length; i++)
			if ((mooses[i].phone || '') != '')
				map[mooses[i].phone] = mooses[i].name;

		var optn;
		for (i = 0; i < phones.length; i++)
		{
			var name = map[phones[i].phone] || '';
			optn = $selAdd(address, i, phones[i].id, phones[i].phone + (name != '' ? ', ': '') + name);
			optn.phone = phones[i].phone;
		}
	},
	
	_change: function()
	{
		var ph = this.address.get(0);
		this._raise_change(ph.options[ph.selectedIndex].phone);
	},

    _setErr: function(msg)
    {
        this.err.html(msg).toggleClass('hidden', (msg || '') == '');
    },

	_sendSms: function()
	{
		var time = this.time.val();
		if ((time || '' != '') && !isFinite(Date.parse(time)))
            return this._setErr('Не удалось расшифровать время');

		if ((this.text.val() || '') == '')
            return this._setErr('Нет текста');

		if ((this.address.val() || '') == '')
            return this._setErr('Нет выбран корреспондент');

		this._setErr('');
		var ph = this.address.get(0);
		$ajax('sendSMS', {'phone' : ph.options[ph.selectedIndex].phone.trim(), 'sms': this.text.val().trim(), 'time': this.time.val().trim()}, this._cb_onSendSMS);
	},

	_onSendSMS: function(result, text, jqXHR)
	{
		if (result.error)
		{
			this.err.html(result.error).removeClass('hidden');
			return;
		}
	
		this._raise_sendSuccess();
	},

	on_sendSuccess: function(h)
	{
		return this.on("sendSuccess", h);
	},

	remove_sendSuccess: function(h)
	{
		return this.remove("sendSuccess", h);
	},

	_raise_sendSuccess: function()
	{
		this.raise("sendSuccess");
	},

	on_change: function(h)
	{
		return this.on("change", h);
	},

	remove_change: function(h)
	{
		return this.remove("change", h);
	},

	_raise_change: function(phone)
	{
		this.raise("change", phone);
	}
}
CSmsControl.inheritFrom(CControl);

CMooseChooser = function(elem, multiple)
{
	CControl.call(this);
	this.select = null;
    this.filter = null;

    this._d_change = $cd(this, this.change);
    this._d_filterChange = $cd(this, this._filterChange);
	this._buildIn(elem, multiple);
}

CMooseChooser.prototype = 
{
	_buildIn: function(elem, multi)
	{
	    var tpl2 = '<div class="hidden" style="margin-top: 2.5ex;">' +
                '<div class="btn-group btn-group-sm" data-toggle="buttons">' +
                '<label class="btn btn-default"><input type="radio" name="mooseFilt" autocomplete="off">Все</label>' +
                '<label class="btn btn-default active"><input type="radio" name="mooseFilt" autocomplete="off" checked>С данными</label>' +
                '<label class="btn btn-default"><input type="radio" name="mooseFilt" autocomplete="off">Активные</label>' +
                '</div>' +
            '</div>';

        this.filter = $(tpl2).appendTo(elem).change(this._d_filterChange);

		var tpl = String.format('<select class="form-control"{0} style="margin-top:2.5ex"></select>', (multi ? ' multiple size="9"' : '') );
		this.select = $(tpl).appendTo(elem).change(this._d_change);
	},

	setData: function(mooses)
	{
		if (!mooses)
			return;

		var moose = this.select.get(0);
		var opt = moose.options;
		while (opt.length > 0)
			opt.remove(opt.length-1);

		var phone;
		var optn;
		for (var i = 0; i < mooses.length; i++)
		{
            phone = (mooses[i].phone || '');
			optn = $selAdd(moose, i, mooses[i].id, mooses[i].name + (phone != '' ? ', ' : '') + phone);
			optn.phone = mooses[i].phone;
		}
	},

	selectMoose: function(mooseId)
	{
		var opt = this.select.get(0).options;
		for (var i = 0; i < opt.length; i++)
			opt[i].selected = opt[i].value == mooseId;	
	},

	selectByPhone: function(phone)
	{
		var opt = this.select.get(0).options;
		for (var i = 0; i < opt.length; i++)
			opt[i].selected = opt[i].phone == phone;	
	},

	getMoose: function()
	{
		var res = [];
		var moose = this.select.get(0);
		var opt = moose.options;
		for (var i = 0; i < opt.length; i++)
			if (opt[i].selected)
				res.push({value: opt[i].value, phone: opt[i].phone});
		return res;
	},

    _filterChange: function()
    {
    // if sel change raise change!
    },

	change: function()
	{
		this._raise_mooseChange(this.getMoose());
	},

	on_mooseChange: function(h)
	{
		return this.on("mooseChange", h);
	},

	remove_mooseChange: function(h)
	{
		return this.remove("mooseChange", h);
	},

	_raise_mooseChange: function(ids)
	{
		this.raise("mooseChange", ids);
	}
}
CMooseChooser.inheritFrom(CControl);

CPeriodChooser = function(elem)
{
    CControl.call(this);
    this.c = {
        all: null,
        exact: null,
        st: null,
        en: null,
        holder: null,
        err: null,
        opts : null
    };

    this._d_optClick = $cd(this, this._onOptClick);
    this._d_dateCahnge = $cd(this, this._dateChange);

    this._buildIn(elem);
}

CPeriodChooser.prototype =
{
    _buildIn: function(elem)
    {
        var tpl = '<div>' +
                    '<div class="btn-group btn-group-sm" data-toggle="buttons">' +
                        '<label class="btn btn-default"><input type="radio" name="options" autocomplete="off">Вчера и сегодня</label>' +
                        '<label class="btn btn-default"><input type="radio" name="options" autocomplete="off">Неделя</label>' +
                        '<label class="btn btn-default"><input type="radio" name="options" autocomplete="off">Месяц</label>' +
                        '<label class="btn btn-default active"><input type="radio" name="options" autocomplete="off" checked>Все</label>' +
                        '<label class="btn btn-default"><input type="radio" name="options" autocomplete="off" disabled>Точно...</label>' +
                    '</div>' +
                '</div>' +
                '<div class="form-horizontal hidden date-holder" style="margin-top: 1.5ex"><div class="form-group"><label class="control-label col-xs-2 col-sm-1">с</label><div class="col-xs-7 col-sm-9 col-md-6 col-lg-5"><input type="text" class="form-control" placeholder="дд.мм.гггг"/></div></div>' +
                                 '<div class="form-group"><label class="control-label col-xs-2 col-sm-1">по</label><div class="col-xs-7 col-sm-9 col-md-6 col-lg-5"><input type="text" class="form-control" placeholder="дд.мм.гггг"/></div></div>' +

                '<div class="panel -row alert alert-danger hidden" role="alert"></div>' +
            '</div>';

        var je = $(tpl).appendTo(elem);

        var c = this.c;
        c.opts = je.find('.btn-group input').change(this._d_optClick);
        c.all = this.c.opts[3];
        c.exact = this.c.opts[4];

        c.holder = $(je[1]);
        c.st = c.holder.find('input:first').change(this._d_dateCahnge);
        c.en = c.holder.find('input:last').change(this._d_dateCahnge);

        c.err = c.holder.find('.alert');
    },

    _isExact: function()
    {
        return this.c.exact.checked;
    },

    // показывать границы времени, если не были заданы
    setTimes: function(st, en, force)
    {
        return;

        /*if (force)
            this._canSetAuto = true;
        if (!this._canSetAuto || st == null || en == null)
            return;

        var d = new Date();
        this._stMonth.get(0).selectedIndex = st.getMonth() + 1;
        this._stYear.get(0).selectedIndex = d.getFullYear() - st.getFullYear() +1;
        this._enMonth.get(0).selectedIndex = en.getMonth() + 1;
        this._enYear.get(0).selectedIndex = d.getFullYear() - en.getFullYear() +1;

        this._canSetAuto = true;*/
    },

    getTimes: function()
    {
        var en = null;
        var st = null;

        if (this.c.all.checked)
            ;
        else if (this._isExact())
        {
            st = this._parseDate(this.c.st.val().trim());
            en = this._parseDate(this.c.en.val().trim());
            if (en)     // до конца дня
                en.setHours(23, 59, 59, 999);
        }
        else
            st = new Date();

        if (this.c.opts[0].checked)
            st.setTime(st.getTime() - 24 * 60 * 60 * 1000);
        if (this.c.opts[1].checked)
            st.setTime(st.getTime() - 7 * 24 * 60 *60 * 1000);
        else if (this.c.opts[2].checked)
            st.setMonth(st.getMonth() - 1);

        if (st)
            st.setHours(0, -31, 0, 0);  // чтобы зацепить активность за пред. полчаса

        return {start: st, end: en};
    },

    _dateChange: function(e)
    {
        if (this._validate())
            this._raise_periodChange(this.getTimes());
    },

    _onOptClick: function()
    {
        var c = this.c;
        var exact = this._isExact();
        this.c.holder.toggleClass('hidden', !exact);
        if (exact)
            c.st.get(0).focus();

        if (this._validate())
            this._raise_periodChange(this.getTimes());
    },

    _validate: function()
    {
        if (!this._isExact())
            return true;

        var st = null;
        var en = null;

        var c = this.c;
        var val = c.st.val().trim();

        if (val != '' && (st = this._parseDate(val)) == null)
            return this._showErr('Неправильная дата начала');
        if (st != null)
            this._toStr(st, c.st);

        val = c.en.val().trim();
        if (val != '' && (en = this._parseDate(val)) == null)
            return this._showErr('Неправильная дата окончания');
        if (en != null)
            this._toStr(en, c.en);

        if (st != null && en != null && st > en)
            return this._showErr('Дата окончания раньше даты начала');

        return this._showErr('');
    },

    _showErr: function(mess)
    {
        var ok = (mess || '') == '';
        this.c.err
            .toggleClass('hidden', ok)
            .text(mess||'');

        return ok;
    },

    _parseDate: function(str)
    {
        var d = new Date();

        var r = /^(\d{1,2})\s*[ \.-\/]\s*(\d{1,2})(\s*[ \.-\/]\s*(\d{2}|\d{4}))?$/;
        var m = r.exec(str);
        if (!m)
            return null;

        if (console.log)
            console.log(String.format("parse '{0}': {1} {2} {3}", str, m[1], m[2], m[4]));

        var y = Number(m[4] || d.getFullYear());
        if (y < 100)
            y = ((y > 90) ? 1900 : 2000) + y;  //91 -> 1991, 16 -> 2016

        if (m[2] > 12 || m[2] == 0)
            return null;

        if (m[1] > 31 || m[1] == 0)
            return null;

        if (m[1] > 30 && (m[2] == 4 || m[2] == 6 || m[2] == 9 || m[2] == 11))
            return null;

        // leap year
        if (m[2] == 2 && (m[1] > 29 || m[1] == 29 && (y % 4 != 0 || y%100 == 0 && y %400 != 0)))
            return null;

        if (console.log)
            console.log(String.format("SFE {0}: {1} {2} {3}", str, m[1], m[2], y));
        d.setFullYear(y, m[2] - 1, m[1]);

        return d;
    },

    _toStr: function(date, control)
    {
        control.val(String.format("{0}.{1}.{2}", date.getDate(), date.getMonth()+1, date.getFullYear()));
    },

    on_periodChange: function(h) {
        return this.on("periodChange", h);
    },

    remove_periodChange: function(h) {
        return this.remove("periodChange", h);
    },

    _raise_periodChange: function(period) {
        this.raise("periodChange", period);
    }
}
CPeriodChooser.inheritFrom(CControl);

CChooser = function(elem)
{
	CControl.call(this);
	this._stMonth = null;
	this._enMonth = null;
	this._stYear = null;
	this._enYear = null;
	this._err = null;

    this._canSetAuto = true;

	this._buildIn(elem);
}

CChooser.prototype = 
{
	_buildIn: function(elem)
	{
		var tpl = '<div class="form-horizontal"><div class="row">' +
 			     '<label class="control-label col-xs-2">с</label><div class="col-xs-10"><select class="form-control"></select></div><div class="col-xs-10 col-xs-push-2"><select class="form-control"></select></div></div><div class="row">' +
			     '<label class="control-label col-xs-2">по</label><div class="col-xs-10"><select class="form-control"></select></div><div class="col-xs-10 col-xs-push-2"><select class="form-control"></select></div></div>' +
                '</div>'+
            '<div class="row alert alert-danger hidden" role="alert"></div>';

		var je = $(tpl).appendTo(elem);
		this._err = $(je[1]);

		je = je.find('select')
			.change($cd(this, this._dateChange));
		this._stMonth = $(je.get(0));
		this._stYear = $(je.get(1));
		this._enMonth = $(je.get(2));
		this._enYear = $(je.get(3));
	

		var d = new Date();

		var i;
		var month  = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
		var month2 = ['', 'январь', 'февраль', 'март',  'апрель', 'май', 'июнь', 'июль', 'август',  'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
		for (i = 0; i < month.length; i++)
		{
			$selAdd(je.get(0), i, i-1, month[i]);
			$selAdd(je.get(2), i, i-1, month2[i]);
		}

		$selAdd(je.get(1), 0, 0, '');
		$selAdd(je.get(3), 0, 0, '');
		var curYear = d.getFullYear();
		for (i = curYear; i >= 2001; i--)
		{		
			$selAdd(je.get(1), 1 + curYear - i, i, i);
			$selAdd(je.get(3), 1 + curYear - i, i, i);
		}
	},

	_dateChange: function()
	{
		var times = this.getTimes();
		if (times.start != null && times.end != null && times.end <= times.start)
		{
			this._err.html('Дата окончания раньше даты начала').removeClass('hidden');
			return;
		}
		else
			this._err.addClass('hidden');

/*		console.log('from: ' + times.start.toUTCString());
		console.log('to:   ' + times.end.toUTCString());*/

        this._canSetAuto = /*this._canSetAuto ||*/ this._isDefault();
		this._raise_periodChange(times);
	},

    _isDefault: function()
    {
        return this._stMonth.get(0).selectedIndex == 0 && this._stYear.get(0).selectedIndex == 0 &&
               this._enMonth.get(0).selectedIndex == 0 && this._enYear.get(0).selectedIndex == 0;
    },

    setTimes: function(st, en, force)
    {
        if (force)
            this._canSetAuto = true;
        if (!this._canSetAuto || st == null || en == null)
            return;

        var d = new Date();
        this._stMonth.get(0).selectedIndex = st.getMonth() + 1;
        this._stYear.get(0).selectedIndex = d.getFullYear() - st.getFullYear() +1;
        this._enMonth.get(0).selectedIndex = en.getMonth() + 1;
        this._enYear.get(0).selectedIndex = d.getFullYear() - en.getFullYear() +1;

        this._canSetAuto = true;
    },

    getTimes: function()
    {
        return {start: this._toDate(this._stMonth, this._stYear, false),
            end:   this._toDate(this._enMonth, this._enYear, true)};
    },

	_toDate: function(mon, year, to)
	{
		var y = year.val();
		var m = parseInt(mon.val());
		if (y == 0 && m == -1 || this._canSetAuto)
			return null;

		var d = new Date();
		if (y == 0)
			y = d.getFullYear();
		if (m == -1)
			m = to ? 11 : 0;

		d.setFullYear(y, (to ? 1 : 0) + m, 1);
		d.setUTCHours(0, 0, 0, 0);
		return d;
	},

	on_periodChange: function(h)
	{
		return this.on("periodChange", h);
	},

	remove_periodChange: function(h)
	{
		return this.remove("periodChange", h);
	},

	_raise_periodChange: function(period)
	{
		this.raise("periodChange", period);
	}
}
CChooser.inheritFrom(CControl);

CUserProxy = function()
{
    this._keys = ['login', 'name'];
}
CUserProxy.prototype =
{
    getCaption: function(item)
    {
        return item[this._keys[0]];
    },

    _text: function(item)
    {
        var res = [];
        var keys = this._keys;
        for (var i = 0; i < keys.length; i++)
            if ((item[keys[i]] || '') != '')
                res.push(item[keys[i]].toLocaleLowerCase());
        return res;
    },

    _appendOrgs: function(arr, orgs)
    {
        if (!orgs)
            return arr;

        for (var j = 0; j < orgs.length; j++)
            if ((orgs[j].login || '') != '')
                arr.push(orgs[j].login.toLocaleLowerCase());

        return arr;
    },

    getContext: function(item)
    {
        return this._appendOrgs(this._text(item), item.orgs);
    }
}

CBeaconProxy = function()
{
    CUserProxy.call(this);
    this._keys = ['phone'];
}
CBeaconProxy.prototype =
{
    getContext: function(item)
    {
        var res = this._appendOrgs(this._text(item), item.orgs);
        var moose = CApp.single().getMoose();
        for (var i = 0; i < moose.length; i++)
        {
            if (item.moose != moose[i].id)
                continue;
            if ((moose[i].name || '') != '')
                res.push(moose[i].name.toLocaleLowerCase());
            break;
        }

        return res;
    }
}
CBeaconProxy.inheritFrom(CUserProxy);

CMooseProxy = function()
{
    CUserProxy.call(this);
    this._keys = ['name', 'phone'];
}
CMooseProxy.inheritFrom(CUserProxy);

CGateProxy = function()
{
    CUserProxy.call(this);
}
CGateProxy.inheritFrom(CUserProxy);

COrgProxy = function()
{
    CUserProxy.call(this);
}
COrgProxy.prototype =
{
    getContext: function(item)
    {
        return this._text(item);
    }
}
COrgProxy.inheritFrom(CUserProxy);

CManageUsersControl = function(elem, options)
{
    CControl.call(this);

    this._c = {
        root: null,
        title: null,
        content: null,
        add: null,
        inactive: null,
        search: null,
        lineEditor: null
    };

    this._sett = this._types[options] || options;
    this._data = null;
    this._orgs = null;
    this._alt = null;

    this._cbRender = $cd(this, this._render);
    this._cbOnSave = $cd(this, this._onSave);
    this._cbOnToggle = $cd(this, this._onToggle);

    this._buildIn(elem);
}

CManageUsersControl.prototype = {
    _types: {
        orgs: {
            title : 'Организации',
            accusative: 'организацию',
            cols: [new CEditLogin('Название', 'Название', 'Комментарий')],
            onAdd: 'addGroup',
            onEdit: 'addGroup',
            onToggle: 'toggleGroup',
            proxy: new COrgProxy()
        },
        users: {
            title : 'Пользователи',
            accusative: 'пользователя',
            cols: [new CEditLogin('Логин', 'Email', 'Имя', false), new CEditRights(), new CEditOrgs()],
            onAdd: 'addUser',
            onEdit: 'addUser',
            onToggle: 'toggleUser',
            proxy: new CUserProxy()
        },
        gates: {
            title : 'Гейты',
            accusative: 'гейт',
            cols: [new CEditLogin('Гейт', 'Логин', 'Комментарий'), new CEditOrgs()],
            onAdd: 'addGate',
            onEdit: 'addGate',
            onToggle: 'toggleGate',
            proxy: new CGateProxy()
        },
        moose: {
            title : 'Животные',
            accusative: 'животное',
            cols: [new CNameEdit(), new CMoosePhoneEdit('Прибор', true), new CSingleOrg()],
            onAdd: 'addMoose',
            onEdit: 'addMoose',
            onToggle: null, // 'toggleMoose'
            proxy: new CMooseProxy()
        },
        beacons: {
            title : 'Приборы',
            accusative: 'прибор',
            cols: [new CPhoneEdit(), new CMoosePhoneEdit('Животное', false), new CSingleOrg()],
            onAdd: 'addBeacon',
            onEdit: 'addBeacon',
            onToggle: 'toggleBeacon',
            proxy: new CBeaconProxy()
        }
    },

    _buildIn : function(elem, options)
    {
        var c = this._c;
        c.root = $('<div class="hidden"/>').appendTo(elem);

        c.title = $('<h2>' + this._sett.title + '</h2>')
            .appendTo(c.root);

        c.add = $('<button class="btn btn-default">Добавить</button>')
            .appendTo(c.root)
            .click($cd(this, this._add));

        c.search = $('<div class="checkbox"><input type="text"/> </div>')
            .appendTo(c.root)
            .find('input')
            .change(this._cbRender)
            .keyup(this._cbRender);

        if (this._sett.onToggle)
            c.inactive = $('<label><input type="checkbox"/> Включая&nbsp;удаленных</label>')
                .appendTo(c.search.parent())
                .find('input')
                .change(this._cbRender);

        c.content = $('<table class="table table-striped"></table>')
            .appendTo(c.root);

        c.lineEditor = new CLineEditor(c.content, this._sett.cols, this._sett.onToggle == null)
            .on_queryEndEdit($cd(this, this._onEndEdit));

        c.content.click($cd(this, this._edit));
    },

    toggle: function(enable)
    {
        CControl.callBaseMethod(this, 'toggle', [enable]);
        this._toggleControls(enable);
    },

    _toggleControls: function(enable)
    {
        var c = this._c;
        c.add.get(0).disabled = !enable;
        c.search.get(0).disabled = !enable;
        if(c.inactive)
            c.inactive.get(0).disabled = !enable;
    },

    _makeLEData: function()
    {
        return { uid: 0,
            orgs: this._orgs,
            alt: this._alt};
    },

    _add: function()
    {
        this._toggleControls(false);
        this._c.lineEditor.activate(null, null, true, this._makeLEData());
    },

    _delete: function(e)
    {
        var tgt = $(e.target);
        if (!tgt.hasClass('lineDel'))
            return;

        var row = tgt.parents('tr:first').get(0);
        var idx = row.getAttribute('data-id');
        var item = this._data[idx];

        if (!item)
            throw Error("нет объекта в строке " + idx);

        if (item.active && !confirm(String.format("Вы действительно хотите удалить {0} '{1}'?", this._sett.accusative, item._caption)))
            return;

        var p = {id: item.id,
            del: item.active};
        $ajax(this._sett.onToggle, p, this._cbOnToggle);
    },

    _onToggle: function(result, text, jqXHR)
    {
        if ((result.error || '') != '')
            return CApp.single().error(result.error);

        this._raise_dataChanged();
    },

    _edit: function(e)
    {
        if (!this._c.lineEditor)
            return;

        var tgt = $(e.target);
        if (tgt.hasClass('lineDel'))
            return this._delete(e);

        if (!tgt.hasClass('lineEdit'))
            return;

        var row = tgt.parents('tr:first').get(0);
        var idx = row.getAttribute('data-id');

        this._toggleControls(false);
        this._c.lineEditor.activate(row, this._data[idx], false, this._makeLEData());
    },

    _onEndEdit: function(param)
    {
        if (!param.save)
        {
            this._c.lineEditor.deactivate(param.item);
            this._toggleControls(true);
            return;
        }

        var r = $ajax((param.item.id || 0) > 0 ? this._sett.onEdit : this._sett.onAdd, param.item, this._cbOnSave);
        r.__item = param.item;
    },

    _onSave: function(result, text, jqXHR)
    {
        if ((result.error || '') != '')
            return this._c.lineEditor.error(result.error);

        // а что принес результат?
        this._c.lineEditor.deactivate(jqXHR.__item);
        this._toggleControls(true);
        this._raise_dataChanged();
    },

    setData: function(data, orgs, alt)
    {
        var i;
        var proxy = this._sett.proxy;
        if (data && orgs)
        {
            var hash = {};
            if (orgs)
                for (i = 0; i < orgs.length; i++)
                    hash[orgs[i].id] = orgs[i];

            for (i = 0; i < data.length; i++)
            {
                this._orgIdsToNames(hash, data[i]);
                data[i]._caption = proxy.getCaption(data[i]);
            }

            this._data = data;
            this._orgs = orgs;
        }

        this._alt = alt;

        var leData = this._makeLEData();
        if (this._sett.cols)
            for (i = 0; i < this._sett.cols.length; i++)
                this._sett.cols[i].setData(leData);

        this._render();
    },

    _orgIdsToNames: function(hash, item)
    {
        item.orgs = [];
        if (!item.groups)
            return ;
        for (var j = 0; j < item.groups.length; j++)
            if (hash[item.groups[j]])
                item.orgs.push(hash[item.groups[j]]);
    },

    _render: function()
    {
        var c = this._c;
        var data = this._data;

        var noInactive = this._sett.onToggle == null;
        var showInactive = noInactive || c.inactive.get(0).checked;
        var search = c.search.val().toLocaleLowerCase();

        var i;
        var head = '';
        var empty = '';
        var cols = this._sett.cols;
        var colLen = cols.length;
        for (i = 0; i < colLen; i++)
        {
            head += '<th>' + cols[i].headText() + '</th>';
            empty += '<td></td>';
        }
        head += '<th class="col-md-4">&nbsp;</th>';
        empty = '<tr><td>Нет данных</td>' + empty + '</tr>';

        var cTpl = '<td>{0}</td>';

        var body = '';
        if (data)
        {
            var cls;
            var line;
            var len = data.length;
            for (i = 0; i < len; i++)
                if (this._show(data[i], showInactive, search))
                {
                    line = '';
                    cls = (noInactive || data[i].active) ? '' : ' class="info"';
                    for (var j = 0; j < colLen; j++)
                        line += String.format(cTpl, cols[j].cellHtml(data[i]));

                    line += '<td class="col-md-4">' + c.lineEditor.activators(data[i]) + '</td>';

                    body += String.format('<tr{0} data-id="{1}">{2}</tr>', cls, i, line);
                }
        }

        if (body == '')
            body = empty;

        c.content.html('<thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody>');
    },

    _show: function(item, showInactive, search)
    {
        var show = showInactive || item.active;
        if (search == '' || !show)
            return show;

        var ctx = this._sett.proxy.getContext(item); //this._searchContext(item);
        for (var i = 0; i < ctx.length; i++)
            if (ctx[i].indexOf(search) >= 0)
                return true;

        return false;
    },

    on_dataChanged: function(h)
    {
        return this.on('dataChanged', h);
    },

    remove_dataChanged: function(h)
    {
        return this.remove('dataChanged', h);
    },

    _raise_dataChanged: function()
    {
        this.raise('dataChanged');
    }
}
CManageUsersControl.inheritFrom(CControl);


CHeatSett = function(elem)
{
    CControl.call(this);
    this._c = {
        root: elem,
        weight: null,
        pow: null,
        level: null
    };

    this._c_onChange = $cd(this, this._raise_change);
    this._buildIn(elem);
}

CHeatSett.prototype =
{
    _buildIn: function(root)
    {
        var c = this._c;

        var tpl = '<div class="panel panel-default" style="margin-top: 3ex;"> ' +
            '<div class="panel-body form-horizontal"> ' +
            '<div class="row -form-group" style="margin-bottom: 1ex;"><div class="col-sm-offset-4 col-sm-8"><div class="checkbox "><label><input type="checkbox"/> учитывать активность</label></div></div></div>'+
            '<div class="row"><label for="hs_level" class="col-sm-4 control-label">Level</label><div class="col-sm-8"><input class="form-control" type="text" placeholder="level" value="20" id="hs_level"/></div></div>' +
            '<div class="row"><label for="hs_degree" class="col-sm-4 control-label">Degree</label><div class="col-sm-8"><input class="form-control" type="text" placeholder="degree" value="1" id="hs_degree"/></div></div>' +
            '<div class="row"><label for="hs_step" class="col-sm-4 control-label">Step</label><div class="col-sm-8"><input class="form-control" type="text" placeholder="degree" value="1" id="hs_step"/></div></div>' +
            '</div>' +
            '</div>';

        var e =  $(tpl).appendTo(root);
        c.root = e;
        c.weight = e.find('input[type="checkbox"]');
        c.level = e.find('#hs_level');
        c.degree = e.find('#hs_degree');
        c.step = e.find('#hs_step');
        e.find('input').change(this._c_onChange);
    },

    get_Value: function()
    {
        var c = this._c;
        var res = {
            level: 20,
            step: 1,    /*2.4 1 0.5*/
            degree: 1,   /*HeatCanvas.QUAD*/
            opacity: 0.8
        };
        if (c.weight)
            res.weight = c.weight.get(0).checked;
        if (c.level)
            res.level = c.level.val();
        if (c.degree)
            res.degree = c.degree.val();
        if (c.step)
            res.step= c.step.val();

        return res;
    },

    on_change: function(h)
    {
        return this.on("change", h);
    },

    remove_change: function(h)
    {
        return this.remove("change", h);
    },

    _raise_change: function(period)
    {
        this.raise("change", period);
    }
}
CHeatSett.inheritFrom(CControl);

CMooseMap = function(root, id, root2)
{
    CControl.call(this);

    this.tiles = [  // и еще поковыряться тут: http://leaflet-extras.github.io/leaflet-providers/preview/
        {name: 'OSM',
            format: "http://{s}.tile.osm.org/{z}/{x}/{y}.png",
            attr: {attribution: '&copy; <a href="http://osm.org/copyright">OpenStreetMap</a> contributors'}},
        {name: 'ESRI World topo map',
            format: 'http://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}',
            attr: {attribution: 'Tiles &copy; Esri &mdash; Esri, DeLorme, NAVTEQ, TomTom, Intermap, iPC, USGS, FAO, NPS, NRCAN, GeoBase, Kadaster NL, Ordnance Survey, Esri Japan, METI, Esri China (Hong Kong), and the GIS User Community'}},
        {name: 'ESRI World imagery',
            format: 'http://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            attr: {attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'}},

        {name: 'mapbox',
            format: 'http://{s}.tiles.mapbox.com/v3/132689/{z}/{x}/{y}.png',
            attr: {attribution: 'Map data &copy; <a href="http://openstreetmap.org">OpenStreetMap</a> contributors, <a href="http://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>, Imagery © <a href="http://mapbox.com">Mapbox</a>',
                maxZoom: 18}}
    ];
    this.colors = ['red', 'green', 'blue', 'cyan', 'brown', 'yellow', 'grey', 'magenta'];
    this.map = null;
    this.heatMap = null;
    this._marker = null;
    this._heatSett = null;
    this._contextMenu = null;

    this.data = [];	  // слои на карте
    this.source = null; // исходные точки
    this.invalidLayer = null;

    this._idx = null;
    this._blockMarker = false;

    this._showInvalid = false;
    this._canToggle = false;
    this._forPrint = false;

    this._inflate = (root2 == null) ? this._inflateSett : { threshold: 300, large: 0.002, small: 0.008}; // на большом экране надо больше увеличивтаь зону просмотра

    this._d_render = $cd(this, this._render);
    this._d_onMove = $cd(this, this._onMove);
    this._d_onKeyDown = $cd(this, this._onKeyDown);
    this._d_onShowMarker = $cd(this, this._onShowMarker);
    this._d_onToggleValid = $cd(this, this._onToggleValid);
    this._d_onContextMenu = $cd(this, this._onContextMenu);
    this._d_onToggleOverlay = $cd(this, this._onToggleOverlay);

    this._buildIn(root, id, root2);
}

CMooseMap.prototype = {

    _markerColor: "#00f",
    _activeMarker: '#0c0',
    _invalidMarker: '#f00',

    _inflateSett : { threshold: 100, large: 0.0008, small: 0.003},

    _buildIn: function(root, clss, root2)
    {
        var container = $('<div class="'+ clss + '"></div>').appendTo(root);

        this.map = L.map(container.get(0))
            .setView([57.677, 41.20], 12)
            .on('mousemove', this._d_onMove)
            .on('popupclose', $cd(this, function(){this._blockMarker = false;}))
            .on('overlayadd', this._d_onToggleOverlay)
            .on('overlayremove', this._d_onToggleOverlay);

        var t = this;
        var c = $('body').keydown(this._d_onKeyDown);

        var layers = {};
        for (var i = 0; i < 3; i++)
        {
            var tile = L.tileLayer(this.tiles[i].format, this.tiles[i].attr);
            layers[this.tiles[i].name] = tile;
            if (i == 0)
                tile.addTo(this.map);
        }

        var param = null;
        if (root2 != null)
        {
            this.heatMap = new L.TileLayer.HeatCanvas({},{'step':1 /*2.4 1 0.5*/, 'degree': 1, 'opacity':0.8});
            this._heatSett = new CHeatSett(root2)
                .on_change(this._d_render);

            param = {"heatmap" : this.heatMap};
        }

        L.control.scale({imperial:false}).addTo(this.map);
        var ctrl = L.control.layers(layers, param).addTo(this.map);
        this._extendControls(ctrl);
        this._onToggleOverlay();
    },

    _extendControls: function(ctrl)
    {
        var el = $(this.map.getContainer()).find('.leaflet-control-layers-list');
        var e = $('<div class="leaflet-control-layers-list ll-extend"></div>').insertAfter(el);
        e.append('<div class="leaflet-control-layers-separator"/>');

        var t = this;
        $(String.format('<div><label><input type="checkbox" {0}><span> показывать невалидные</span></label></div>', this._showInvalid ? 'checked' : ''))
            .appendTo(e)
            .find('input')
            .change(function(e){t._showInvalid = this.checked; t.update()});

        $(String.format('<div><label><input type="checkbox" {0}><span> для печати</span></label></div>', this._forPrint ? 'checked' : ''))
            .appendTo(e)
            .find('input')
            .change(function(e){t._forPrint = this.checked; t.update()});
    },

    _onToggleOverlay: function()
    {
        if (this._heatSett)
            this._heatSett.toggle(this.map && this.heatMap && this.map.hasLayer(this.heatMap));
    },

    invalidateSize: function()
    {
        this.map.invalidateSize();
    },

    render: function(source, fitBounds)
    {
        this._fitBounds = fitBounds || false;
        this.source = source;
        this._render();
    },

    update: function(opt)
    {
        if (opt)
        {
            //this._showInvalid = opt.showInvalid || false;
            this._canToggle = opt.canToggle || false;
        }

        if (this.map)
        {
            this.map.closePopup();
            if (this._marker)
                this.map.removeLayer(this._marker);
        }

        this._render();
    },

    _render: function()
    {
        this.clearTracks();
        this.setTracks(this.source);

        if (this.data.length > 0)
        {
            if (this._fitBounds)
            {
                var bnd = this.data[0].getBounds();
                for (var j = 1; j < this.data.length; j++)
                    bnd = bnd.extend(this.data[j].getBounds());

                this.map.fitBounds(this._inflateBounds(bnd));
            }

            this._fitBounds = false;
        }
    },

    _inflateBounds: function(bounds)
    {
        if (!bounds)
            return bounds;

        var d = bounds.getSouthEast().distanceTo(bounds.getNorthWest()) > this._inflate.threshold ? this._inflate.large : this._inflate.small;
        return bounds.extend([[bounds.getSouth() - d, bounds.getWest() - d], [bounds.getNorth() + d, bounds.getEast() + d]]);
    },

    clearTracks: function()
    {
        this._idx = null;
        if (!this.map)
            return;
        for (var i = 0; i < this.data.length; i++)
            this.map.removeLayer(this.data[i]);

        if (this.invalidLayer)
            this.invalidLayer.clearLayers();

        if (this._marker)
            this.map.removeLayer(this._marker);

        this.data = [];
    },

    setTracks: function(data)
    {
        if (!data)
            return;

        var heatSett;
        var hasHeat = this.heatMap != null;
        var showHeat = hasHeat && this.map.hasLayer(this.heatMap);
        if (hasHeat)
        {
            heatSett = this._heatSett.get_Value();
            this.map.removeLayer(this.heatMap);
            this.heatMap.clear();
            this.heatMap.setOptions(heatSett);
        }

        if (!this.invalidLayer)
            this.invalidLayer = L.layerGroup();
        else
            this.map.removeLayer(this.invalidLayer);

        for (var i = 0; i < data.length; i++)
        {
            var pt;
            var ll = [];
            var active;
            var src = data[i].data;

            var series = this._newPoly(i, data[i].id, data[i].key);
            for (var j = 0; j < src.length; j++)
            {
                var valid = src[j][3] == 1;
                if (!valid && !this._showInvalid)
                    continue;

                pt = L.latLng(src[j][0], src[j][1]);
                pt._midx = j;
                pt._time = src[j][2];
                pt._valid = valid;
                pt._cnt = src[j].cnt;
                pt._sum = src[j].sum;
                pt._str = src[j].str;
                ll.push(pt);

                if (hasHeat)
                    this.heatMap.pushData(src[j][0], src[j][1], this._heatLevel(heatSett, src[j]));

                if (!valid)
                    this.invalidLayer.addLayer(L.circleMarker(pt, {color: this._invalidMarker, radius: 6, fillColor:"#fff", fillOpacity: 0.6, opacity: 1}));
            }
            series.setLatLngs(ll);
            this.data.push(series);
        }

        if (showHeat)
            this.map.addLayer(this.heatMap);

        if (this._showInvalid)
            this.map.addLayer(this.invalidLayer);
    },

    _newPoly: function(idx, id, key)
    {
        var l = L.polyline([], {color: this.colors[idx % this.colors.length], noClip: true, weight: this._forPrint ? 3 : 2})
            .addTo(this.map);
        l.__id = id;
        l.__key = key;

        return l;
    },

    _heatLevel: function(sett, pt)
    {
        if (!sett.weight)
            return sett.level / 2;

        return pt.cnt && pt.cnt != 0 ? (sett.level * pt.sum / pt.cnt) : (sett.level / 2);
    },

    _onKeyDown: function(e)
    {
        if (!this.data || this.data.length != 1)
            return true;

        var shift;
        switch (e.keyCode)
        {
            case 65: shift = -12; break; // a
            case 68: shift = 12; break; // d
            case 83: shift = -1; break; // s
            case 87: shift = 1; break; // w
            default: return;
        }

        var jm = $(this.map.getContainer());
        var inputs = {'INPUT': true, 'TEXTAREA': true /*, 'SELECT': true */};
        if (inputs[e.target.nodeName] == true || !jm.is(':visible'))
            return true;

        var pts = this.data[0].getLatLngs();
        var len = pts.length;
        if (len == 0)
            return;

        this._idx = this._idx == null ?  0 : (this._idx + shift);
        if (this._idx >= len)
            this._idx = 0;
        else if (this._idx < 0)
            this._idx = len - 1;

        if (!this._marker)
            this._initMarker(pts[this._idx]);
        else
            this._marker.setLatLng(pts[this._idx]);

        if (!this.map.hasLayer(this._marker))
            this.map.addLayer(this._marker);

        this._onShowMarker();
    },

    _initContextMenu: function(latlng)
    {
        this._contextMenu = L.popup({offset: L.point(70, 30), className: 'ctxMenu', closeButton: false})
            .setLatLng(latlng);
    },

    // works in own context;
    _toggleValid: function()
    {
        var ll = this.latlng;
        this.ctx.map.closePopup();

        var param = {time: ll._time, valid: !ll._valid};
        param[(ll.key || 'mooseId')] = ll.mId;
        var jq = $ajax('togglePoint', param, this.ctx._d_onToggleValid);
        jq._latlng = ll;
        jq._llValid = !ll._valid;
    },

    _onToggleValid: function(result, text, jqXHR)
    {
        if (result.error)
        {
            log('Ошибка Ajax: ' + result.error);
            return;
        }

        var ll = jqXHR._latlng;
        var data = this.source;
        for (var i = 0; i < data.length; i++)
            if (data[i].id == ll.mId)
            {
                data[i].data[ll._midx][3] = jqXHR._llValid ? 1 : 0;
                break;
            }

        this.update();
    },

    _onContextMenu: function(e)
    {
        if (e.originalEvent.button != 2)
        {
            if (this._marker)
            {
                this._marker.setStyle({color: this._activeMarker});
                var ll = this._marker.getLatLng();
                if (ll.idx != null)
                    this._idx = ll.idx;
            }
            return;
        }

        if (!this._canToggle)
            return;

        var ll = this._marker.getLatLng();
        if (ll.mId == null || ll.key == null)
            return;

        if (this._marker._popup) // HACK 3 !!!
            this._marker.closePopup();

        if (!this._contextMenu)
            this._initContextMenu(ll);

        var content = $(String.format('<span style="text-decoration:underline; color: dodgerblue; cursor:pointer;">{0}</span>', ll._valid ? 'Пометить невалидной': 'Восстановить'))
            .click($cd({ctx: this, latlng:ll}, this._toggleValid));

        this._contextMenu.options.offset = L.point(ll._valid ? 93 : 67, 30);
        this._contextMenu.setLatLng(ll)
            .openOn(this.map)
            .setContent(content.get(0))
            .update();

        this._blockMarker = true;

        e.originalEvent.preventDefault();
    },

    _onShowMarker: function()
    {
        var ll = this._marker.getLatLng();
        this._marker.setStyle({color: this._markerColor});
        var p = this._marker._popup;            // HACK !!!
        if (p)
        {
            var d = new Date();
            d.setTime(Date.parse(ll._time));
            var c = String.format("{0}<br/>{1} N, {2} E", d.toLocaleString(), L.Util.formatNum(ll.lat, 7), L.Util.formatNum(ll.lng, 7));
            if (ll._cnt != null)
                c += String.format('<br/>Активность: {2} ({0} / {1})', ll._sum || 0, ll._cnt || 0, ll._str || '');
            p.setContent(c);

            if (!p._isOpen)                         // HACK 2 !!!
                this._marker.openPopup();
        }
    },

    _initMarker: function(pt)
    {
        this._marker = L.circleMarker(pt, {color: this._markerColor, radius: 6, fillColor:"#fff", fillOpacity: 0.6, opacity: 1});

        this._marker.bindPopup('', {closeButton: false, offset: L.point(0, -3)});
        this._marker.on('mouseover', this._d_onShowMarker)
            .on('contextmenu', this._d_onContextMenu)
            .on('click', this._d_onContextMenu);
    },

    _onMove: function(e)
    {
        if (this._blockMarker)
            return;

        var nearest = this._nearestPt(e.latlng); // тормозит на большом кол-ве точек
        var dist = nearest ? e.latlng.distanceTo(nearest) : 10000;

        if (!this._marker)
            this._initMarker(nearest);

        var has = this.map.hasLayer(this._marker);
        var lim = this.map.getZoom() > 13 ? 20 : 50;

        if (dist <= lim)
        {
            this._marker.setLatLng(nearest);
            if (!has)
                this.map.addLayer(this._marker);
            this._marker.bringToFront();
            this._onShowMarker();
        }
        else if (has && e.latlng.distanceTo(this._marker.getLatLng()) > lim * 2.5)
            this.map.removeLayer(this._marker);
    },

    _nearestPt: function(pt)
    {
        if (!pt || !this.data || this.data.length == 0)
            return null;

        var dist;
        var point;

        var min;
        var minDist;

        var len = this.data.length;
        for (var i = 0; i < len; i++)
        {
            min = this._nearest(pt, this.data[i].getLatLngs());
            if (!min)
                continue;

            min = this._extendMarkerPoint(min, this.data[i]);
            minDist = pt.distanceTo(min);
            break;
        }

        for (++i; i < len; i++)
        {
            point = this._nearest(pt, this.data[i].getLatLngs());
            if (!point)
                continue;
            dist = pt.distanceTo(point);
            if (dist >= minDist)
                continue;

            minDist = dist;
            min = this._extendMarkerPoint(point, this.data[i]);
        }

        return min;
    },

    _extendMarkerPoint: function(point, track)
    {
        // а idx в нее добавили внутри _nearest
        point.mId = track.__id;
        point.key = track.__key;  // признак, откуда точка -- из лося, или rawSms
        return point;
    },

    _nearest: function(pt, points)
    {
        if (!pt || !points || points.length == 0)
            return null;

        var cur;
        var len = points.length;
        var min = pt.distanceTo(points[0]);
        var idx = 0;
        for (var i = 1; i < len; i++)
        {
            cur = pt.distanceTo(points[i]);
            if (cur > min)
                continue;
            min = cur;
            idx = i;
        }

        points[idx].idx = idx;
        return points[idx];
    }
}
CMooseMap.inheritFrom(CControl);

CMooseMapHelper = function()
{
    this._d_onSuccess = $cd(this, this._onSuccess);
}

CMooseMapHelper.glueTrackData = function(result)
{
    if (!result)
        return null;

    CMooseMapHelper._strToTime(result.track, 2);
    CMooseMapHelper._strToTime(result.activity, 0);

    if (!result.track || !result.activity)
        return result.track;

    var i;
    var j;
    var tPt;
    var delta = 31 * 60 * 1000; // bit more than half an hour;

    var track = result.track;
    var act = result.activity;
    var aLen = act.length
    var len = track.length;

    for (i = 0; i < len; i++)
    {
        var t = track[i].tm;
        j = 0;
        var idx = aLen - 1;
        while (idx > j+1)				// binary search
        {
            var pos = Math.floor((idx + j) / 2);
            if (t - act[pos].tm > delta)
                j = pos;
            else
                idx = pos;
        }
        if (j < aLen && t - act[j].tm < delta)
            idx = j;

        tPt = track[i];
        tPt.sum = 0;
        tPt.cnt = 0;
        tPt.str = '';
        if (idx >= 0 && t - act[idx].tm < delta)
            for (j = idx; j < aLen && act[j].tm - t < delta; j++)
            {
                tPt.cnt++;
                tPt.sum += act[j][1];
                tPt.str += act[j][1]; // == 0 ? '.' : '!';
            }
    }

    return track;
}

CMooseMapHelper._strToTime = function(arr, idx)
{
    if (!arr || arr.length <= 0 || idx < 0)
        return;

    var d;
    var len = arr.length;
    for (var i = 0; i < len; i++)
    {
        d = new Date();
        d.setTime(Date.parse(arr[i][idx]));
        arr[i].tm = d;
    }
}

CMooseMapHelper.prototype =
{
    drawRawSms: function(mapControl, rawSmsId)
    {
        if (rawSmsId == null)
            return;

        var r = $ajax('getSms', {'rawSmsId': rawSmsId}, this._d_onSuccess);
        r.__rawId = rawSmsId;
        r.__mapControl = mapControl;
    },

    _onSuccess: function(result, text, jqXHR)
    {
        if (result.error)
        {
            log('Ошибка Ajax: ' + result.error);
            return;
        }

        if (!result || !result.track || result.track.length == 0)
            return;

        if (result.track.length == 1)
            result.track.push(result.track[0]);

        jqXHR.__mapControl.render([{data:CMooseMapHelper.glueTrackData(result), id: jqXHR.__rawId, key: 'rawSmsId'}], true);
    }
}


CColumnFilter = function(root, key, options)
{
    CControl.call(this);
    this._c = {
        root: null,
        holder: null,
        search: null,
        list: null,
        okBtn: null,
        cancelBtn: null,
        resetBtn: null
    };

    this._key = key;
    this._items = [];
    this._checked = {};
    this._empty = false;
    this._curChecked = {};
    this._curEmpty = false;

    this._all = false;

    this._options = $.extend({search: true, reset: true, empty: false, emptyMeansAll: true}, options);

    this._d_onActivate = $cd(this, this._onActivate);
    this._d_onOk = $cd(this, this._onOk);
    this._d_onCancel = $cd(this, this._onCancel);
    this._d_onReset = $cd(this, this._onReset);
    this._d_onSearch = $cd(this, this._onSearch);
    this._d_onClickOutside = $cd(this, this._onClickOutside);
    this._d_onChange = $cd(this, this._onChange);
    this._buildIn(root);
}

CColumnFilter.prototype =
{
    _tpl:  '<div class="hidden filter-holder panel panel-default">' +
            '<div class="panel-body">' +
                '<button class="btn btn-default btn-sm" style="margin-bottom: .7em;">Очистить</button>' +
                '<div><input type="text" class="form-control"/></div>' +
                '<ul></ul>' +
                '<div class="filter-buttons"><button class="btn btn-default btn-sm pull-right">Отменить</button><button class="btn btn-primary btn-sm">OK</button></div>' +
            '</div>' +
           '</div>',

    _buildIn: function(root)
    {
        var c = this._c;
        c.root = $('<span class="filter-activator glyphicon glyphicon-filter"></span>')
            .appendTo(root);
        $(root)
            .css('cursor', 'pointer')
            .click(this._d_onActivate);

        c.holder = $(this._tpl)
            .appendTo(root);
        c.okBtn = c.holder.find('.filter-buttons button:last')
            .click(this._d_onOk);
        c.cancelBtn = c.holder.find('.filter-buttons button:first')
            .click(this._d_onCancel);
        c.resetBtn = c.holder.find('button:first')
            .click(this._d_onReset)
            .toggle(this._options.reset);
        c.list = c.holder.find('ul')
            .change(this._d_onChange);
        c.search = c.holder.find('input')
            .change(this._d_onSearch)
            .keyup(this._d_onSearch)
            .toggle(this._options.search);

        $(root).css('white-space', 'nowrap');
    },

    clear: function()
    {
        this._checked = {};
        this._empty = false;
        this._curChecked = {};
        this._curEmpty = this._empty;

        this._all = this._options.emptyMeansAll;
        this._c.root.removeClass('text-danger');
    },

    _onActivate: function(e)
    {
        if ($(e.target).parents('.filter-holder').length > 0)
            return;

        document.addEventListener('click', this._d_onClickOutside, true);
        this._c.holder.removeClass('hidden');
        this._c.search.val('')
            .focus();

        this._curChecked = $.extend({}, this._checked);
        this._curEmpty = this._empty;
        this._render();
        this._renderCaption();
    },

    _deactivate: function()
    {
        this._curChecked = this._checked;
        this._curEmpty = this._empty;
        this._c.holder.addClass('hidden');
        document.removeEventListener('click', this._d_onClickOutside, true);
    },

    _onOk: function()
    {
        this._checked = this._curChecked;
        this._empty = this._curEmpty;
        this._c.root.toggleClass('text-danger', !this._all);

        this._deactivate();
        this._raise_dataChanged();
    },

    _onCancel: function()
    {
        this._deactivate();
    },

    _onReset: function()
    {
        this._curChecked = {};
        this._curEmpty = false;
        this._render();
        this._renderCaption();
    },

    _onClickOutside: function(e)
    {
        if (e.target && $(e.target).parents('.filter-holder').length > 0)
            return;

        e.preventDefault();
        e.stopPropagation();
        this._deactivate();
    },

    _onSearch: function()
    {
        this._render();
    },

    _render: function()
    {
        var res = '';
        var tpl = '<li><label class="checkbox-inline"><input type="checkbox" value="{0}" {1}> {2}</label></li>';

        var search = this._options.search ? (this._c.search.val() || '').toLocaleLowerCase() : '';
        var noSearch = search == '';

        if (this._options.empty && noSearch)
            res += String.format(tpl, '', 'data-empty="true"' + (this._empty ? 'checked' : ''), '&lt;пустое значение&gt;');

        var item;
        var len = this._items.length;
        for (var i = 0; i < len; i++)
        {
            item = this._items[i];
            if (noSearch || ((item.caption || '').toLocaleLowerCase().indexOf(search) >= 0 ))
                res += String.format(tpl, item.value, this._curChecked[item.value] ? 'checked' : '', item.caption);
        }

        this._c.list.html(res);
    },

    getKey: function()
    {
        return this._key;
    },

    setItems: function(items)
    {
        this._items = items || [];
        this._checked = {};
        this._empty = false;

        this._curChecked = $.extend({}, this._checked);
        this._curEmpty = this._empty;
    },

    getValues: function()
    {
        var res = [];
        var len = this._items.length;
        for (var i = 0; i < len; i++)
            if (this._checked[this._items[i].value])
                res.push(this._items[i].value);

        if (!this._options.empty)
            return res;

        return {
            values: res,
            empty: this._empty};
    },

    setValues: function(values) // todo !!!
    {
    },

    _onChange: function(e)
    {
        if (!e || !e.target)
            return;

        var tgt = e.target;
        if ($(tgt).attr('data-empty') == 'true')
            this._curEmpty = tgt.checked;
        else
            this._curChecked[tgt.value] = tgt.checked;
        this._renderCaption();
    },

    _renderCaption: function()
    {
        var str = [];
        var st = this._stat();

        if (this._all)
            str = ['все'];
        else
        {
            if (st.count != 0)
                str.push(String.format('{0} из {1}', st.count, st.len));
            if (st.empty)
                str.push('&lt;пусто&gt;');
        }

        this._c.okBtn.html(String.format('ОК - {0}', str.join(', ')));
        this._c.resetBtn.get(0).disabled = st.empty == false && st.count == 0;
    },

    _stat: function()
    {
        var op = this._options;

        var res = {
            len: 0,
            count: 0,
            empty: op.empty && this._curEmpty,
            all: true
        };

        if (this._items)
        {
            res.len = this._items.length;
            for (var i = 0; i < res.len; i++)
                if (this._curChecked[this._items[i].value])
                    res.count++;
        }

        this._all = res.count == res.len && (!op.empty || res.empty) ||
            op.emptyMeansAll && res.count == 0 && !res.empty;
        res.all = this._all;

        return res;
    },

    isActive: function()
    {
        this._stat();
        return !this._all;
    },

    on_dataChanged: function(h)
    {
        return this.on('dataChanged', h);
    },

    remove_dataChanged: function(h)
    {
        return this.remove('dataChanged', h);
    },

    _raise_dataChanged: function()
    {
        this.raise('dataChanged');
    }
}
CColumnFilter.inheritFrom(CControl);

CTipControl = function(root, tips)
{
    this._tips = null;
    this._root = null;
    this._text = null;

    this._buildIn(root);
    this.setTips(tips);
}

CTipControl.prototype =
{
    _buildIn: function(root)
    {
        if (!root)
            return;

        this._root = $(root);

        this._text = this._root.append('Совет: <span class="text-muted -small"></span>').find('span');
    },

    setTips: function(tips, random)
    {
        this._tips = tips || [];
        this._curTip = -1;

        if (random || false)
            return this.random();
        return this.next();
    },

    random: function()
    {
        this._curTip = this._tips.length > 0 ? Math.floor(Math.random() * this._tips.length) : 0;
        return this._drawTip();
    },

    next: function()
    {
        this._curTip = this._tips.length > 0 ? ((this._curTip + 1) % this._tips.length) : 0;
        return this._drawTip();
    },

    toggle: function(show)
    {
        if (!this._root)
            return;

        this._root.toggleClass('hidden', !show);
        return this;
    },

    _drawTip: function()
    {
        if (this._root)
            this._text.html(this._tips.length > 0 ? this._tips[this._curTip] : '');
        return this;
    }
}
