/**
 * Created by Serge Titov for mooServer project
 * 2014 - 2015
 */

CAdmin = function(after)
{
    CPage.call(this, 'admin', 'Админ', null);
    this._rm = null;

    this._type = null; // moose / beacons etc

    this._gates = null;
    this._orgs = null;
    this._users = null;
    this._moose = null;
    this._beacons = null;

    this._cbReread = $cd(this, this.reRead);
    this._iframe = null;


    this._init(after);
}

CAdmin.prototype = {

    _menus: '<li><a href="#admin/moose" class="disabled">Животные</a></li>' +
        '<li><a href="#admin/beacons" class="disabled">Приборы</a></li>' +
        '<li><a href="#admin/users">Пользователи</a></li>' +
        '<li><a href="#admin/gates">Гейты</a></li>' +
        '<li><a href="#admin/orgs">Организации</a></li>' +
        '<li class="divider backup"></li>' +
        '<li class="backup"><a href="export.php?m=backup" target="_blank">Бекап базы</a></li>',
    
    buildIn: function(menuRoot)
    {
        if (!menuRoot || !this._text)
            return;

        this._menu = $('<li class="dropdown"><a href="#" class="dropdown-toggle" data-toggle="dropdown">' + this._text + '<span class="caret"></span></a> <ul class="dropdown-menu" role="menu"></ul></li>')
            .appendTo(menuRoot);
        this._menu.find('ul')
            .append(this._menus);
    },

    match: function(hash)
    {
        if (!this._isAvailable)
            return false;
        var match = /admin\/(.+)/.exec(hash);
        if (!match || match.length < 2)
            return false;

        this._type = match[1];  // некузяво -- это надо делать при activate
        return true;
    },

    activate: function(s)
    {
        CPage.callBaseMethod(this, 'activate', [s]);

        this._gates.toggle(false);
        this._orgs.toggle(false);
        this._users.toggle(false);
        this._moose.toggle(false);
        this._beacons.toggle(false);

        if (this._type == 'users')
            this._users.toggle(true);
        else if (this._type == 'orgs')
            this._orgs.toggle(true);
        else if (this._type == 'gates')
            this._gates.toggle(true);
        else if (this._type == 'moose')
            this._moose.toggle(true);
        else if (this._type == 'beacons')
            this._beacons.toggle(true);

        this.reRead();
    },

    _init: function(after)
    {
        this._elem = $(this._tpl).insertAfter(after).hide();
        var row = this._elem.find('.row');

        this._rm = $(this._tpl_right).appendTo(row);
        var je = $(this._tpl_main)
            .appendTo(row);

        this._gates = new CManageUsersControl(je, 'gates').on_dataChanged(this._cbReread);
        this._orgs  = new CManageUsersControl(je, 'orgs').on_dataChanged(this._cbReread);
        this._users = new CManageUsersControl(je, 'users').on_dataChanged(this._cbReread);
        this._moose = new CManageUsersControl(je, 'moose').on_dataChanged(this._cbReread);
        this._beacons = new CManageUsersControl(je, 'beacons').on_dataChanged(this._cbReread);

        $('<form method="post" enctype="multipart/form-data" action="import.php" target="fileTarget" class="row" style="margin-top: 1em;"><input type="file" name="import[]" multiple class="btn row"/><button class="btn btn-default">Import files!</button></form>')
            .appendTo(this._rm)
            .find('button')
            .click($cd(this, this._sendFile));
    },

    reRead: function()
    {
        $ajax('getUsers', {all: false}, $cd(this, this._onReRead));
    },

    _onReRead: function(result, text, jqXHR)
    {
        if (result.error)
        {
            if (console)
                console.log(result.error);
            return;
        }

        this._gates.setData(result.gates, result.org);
        this._orgs.setData(result.org, result.org);
        this._users.setData(result.users, result.org);
        this._moose.setData(result.mooses, result.org, result.phones);
        this._beacons.setData(result.phones, result.org, result.mooses);

        CApp.single().setMoose(result.mooses);
    },

    _sendFile: function()
    {
        if (!this._iframe)
            this._iframe = $('<iframe name="fileTarget" class="hidden">empty in iframe</iframe>')
                .appendTo(this._rm)
                .load($cd(this, this._load));

        var form = this._rm.find('form');
        form.get(0).submit();
    },

    _load: function()
    {
        console.log('check');

        var doc = this._iframe.get(0).contentWindow.document.body.innerHTML;
		//alert(doc);
        var result = ((doc || '') == '') ? null : JSON.parse(doc);

        if (!result)
        {
            alert(doc);
            if (console.log)
                console.log(doc);
        }
        else
        {
            if (result.log && result.log.length > 0)
            {
                var s = result.log.join("\n");
                alert(s);
                if (console.log)
                    console.log(s);
            }

            if ((result.error || '') != '')
                alert(result.error);

            if (result.status)
                CApp.single().message(result.status, 10);
        }
    },    

    setRights: function(rights)
    {
        this._isAvailable = rights.canAdmin;
        if (this._menu)
        {
            this._menu.toggleClass('hidden', !rights.canAdmin);
            this._menu.find('.backup').toggleClass('hidden', !rights.isSuper);
        }

        if (!this._isAvailable && this._isActive)
            window.location.hash = '';
    }
}
CAdmin.inheritFrom(CPage);

CLogs = function(after)
{
    CPage.call(this, 'logs', 'Логи', null);

    this._rm = null;
    this._levels = null;
    this._table = null;
    this._init(after);
}

CLogs.prototype =
{
    match: function(hash)
    {
        return this._isAvailable && CPage.callBaseMethod(this, 'match', [hash]);
    },

    buildIn: function(menuRoot)
    {
        if (!menuRoot || !this._text)
            return;

        var tpl = '<li><a href="' + this._hash + '">' + this._text + '</a></li>';

        var mRoot = $(menuRoot).find('li.dropdown')
            .find('ul.dropdown-menu')
            .find('li.divider:first');

        this._menu = $(tpl).insertBefore(mRoot);
    },

    _init: function(after)
    {
        this._elem = $(this._tpl).insertAfter(after).hide();
        var row = this._elem.find('.row');
        //this._rm = $(this._tpl_right).appendTo(row);
        var je = $('<div class="col-xs-12"></div>')
            //.append('<ul class="nav nav-pills"><li role="presentation" class="active"><a href="#beacon">Все приборы</a></li></ul>')
            //.append('<h1><small></small></h1>')
            .appendTo(row);


        var sel = '<table class="col-xs-2"><tr><td><select size="5" class="form-control" multiple><option value="0">info</option><option value="1">trace</option><option value="2">debug</option><option value="3">error</option><option value="4">critical</option></select></td></tr></table>';

        this._levels = $(sel)
            .appendTo(je)
            .find('select')
            .change($cd(this, this.reRead));

        this._table = $('<table class="hidden table table-striped table-condensed"></table>')
            .appendTo(je);
    },

    activate: function(s)
    {
        CPage.callBaseMethod(this, 'activate', [s]);
        this.reRead();
    },

    reRead: function()
    {
        var param = {limit: 200, levels:[]};

        var e = this._levels.get(0);
        for (var i = 0; i < e.options.length; i++)
            if (e.options[i].selected)
                param.levels.push(e.options[i].value);

        $ajax('getLogs', param, $cd(this, this._onReRead));
    },

    _onReRead: function(result, text, jqXHR)
    {
        if (result.error)
        {
            if (console)
                console.log(result.error);
            return;
        }
        if (!this._table)
            return;

        this._render(result);
    },

    _render: function(result)
    {
        if (!result)
        {
            this._table.html('');
            return;
        }

        var it;
        var len = result.length;
        var body = '';
        var head = '<thead><tr><th>id</th><th>stamp</th><th>level</th><th>uid</th><th>login</th><th>duration</th><th>op</th><th>msg</th></tr></thead>';
        var tpl = '<tr><td>{0}</td><td style="white-space: nowrap;">{1}</td><td>{2}</td><td>{3}</td><td>{4}</td><td>{5}</td><td>{6}</td><td>{7}</td></tr>';
        for (var i = 0; i < len; i++)
        {
            it = result[i];

            var d = new Date();
            d.setTime(Date.parse(it.stamp));
            body += String.format(tpl, it.id, d.toLocaleString()/*, d.toLocaleDateString()*/, String.toHTML(it.level), String.toHTML(it.uid), String.toHTML(it.login), String.toHTML(it.duration), String.toHTML(it.op), String.toHTML(it.message));
        }

        this._table.html(head + '<tbody>' + body + '</tbody>')
            .toggleClass('hidden', false);
    },

    setRights: function(rights)
    {
        this._isAvailable = rights.canAdmin;
        if (this._menu)
            this._menu.toggleClass('hidden', !this._isAvailable);

        if (!this._isAvailable && this._isActive)
            window.location.hash = '';
    }
}
CLogs.inheritFrom(CPage);