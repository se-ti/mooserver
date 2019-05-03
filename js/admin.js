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

        this._menu = $('<li class="dropdown hidden"><a href="#" class="dropdown-toggle" data-toggle="dropdown">' + this._text + '<span class="caret"></span></a> <ul class="dropdown-menu" role="menu"></ul></li>')
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

        this._gates = new CEditableTableControl(je, {
                    title : 'Гейты',
                    accusative: 'гейт',
                    cols: [new CEditLogin('Гейт', 'Логин', 'Комментарий'), new CEditOrgs()],
                    onAdd: 'addGate',
                    onEdit: 'addGate',
                    onToggle: 'toggleGate',
                    proxy: new CGateProxy()
                })
            .on_dataChanged(this._cbReread);
        this._orgs  = new CEditableTableControl(je, {
                    title : 'Организации',
                    accusative: 'организацию',
                    cols: [new CEditLogin('Название', 'Название', 'Комментарий')],
                    onAdd: 'addGroup',
                    onEdit: 'addGroup',
                    onToggle: 'toggleGroup',
                    proxy: new COrgProxy()
                })
            .on_dataChanged(this._cbReread);
        this._users = new CEditableTableControl(je, {
                    title : 'Пользователи',
                    accusative: 'пользователя',
                    cols: [new CEditLogin('Логин', 'Email', 'Имя', false), new CEditRights(), new CEditOrgs()],
                    onAdd: 'addUser',
                    onEdit: 'addUser',
                    onToggle: 'toggleUser',
                    proxy: new CUserProxy()
                })
            .on_dataChanged(this._cbReread);
        this._moose = new CEditableTableControl(je, {
                    title : 'Животные',
                    accusative: 'животное',
                    cols: [new CNameEdit(), new CMoosePhoneEdit('Прибор', true), new CSingleOrg()],
                    onAdd: 'addMoose',
                    onEdit: 'addMoose',
                    onToggle: null, // 'toggleMoose'
                    proxy: new CMooseProxy(),
                    showLineNumbers: true
                })
            .on_dataChanged(this._cbReread);
        this._beacons = new CEditableTableControl(je, {
                    title : 'Приборы',
                    accusative: 'прибор',
                    cols: [new CPhoneEdit(), new CMoosePhoneEdit('Животное', false), new CSingleOrg()],
                    onAdd: 'addBeacon',
                    onEdit: 'addBeacon',
                    onToggle: 'toggleBeacon',
                    proxy: new CBeaconProxy(),
                    showLineNumbers: true
                })
            .on_dataChanged(this._cbReread);

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
            log('Ошибка Ajax: ' + result.error);
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
		//console.log(doc);
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
			var s="";
            if (result.log && result.log.length > 0)
            {
                s = result.log.join("\n");
            }
			
			if (result.status && result.status.length > 0)
				{
					if (s.length > 0 )
						s+="\n";
					s+=result.status;
				}
				
			alert(s);
			
            if (console.log)
                console.log(s);

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
    this._rowLimit = null;
    this._table = null;
    this._body = null;
    this._search = null;
    this._filter = null;
    this._filter2 = null;

    this._d_reRead = $cd(this, this.reRead);
    this._d_clearFilters = $cd(this, this._clearFilters);
    this._d_onEnter = $cd(this, this._onEnter);
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
        var je = $('<div class="col-xs-12"></div>')
            .appendTo(row);

        var ctrl = $('<div class="form-inline" style="margin-bottom: 0.5em;"><div class="form-group"><label for="log-select">Записей: </label> <select id="log-select" class="form-control"><option value="100" selected>100</option><option value="500">500</option><option value="3000">3000</option></select></div> <input type="text" class="form-control" placeholder="Поиск"> <button type="button" disabled class="btn btn-default btn-sm" style="margin-left: 1em;">Очистить фильтр</button></div>')
            .appendTo(je);

        this._rowLimit = ctrl.find('select')
            .change(this._d_reRead);
        this._clearFilters = ctrl.find('button')
            .click(this._d_clearFilters);
        
        this._search = ctrl.find('input')
            .change(this._d_reRead)
            .keydown(this._d_onEnter);

        this._table = $('<table class="hidden table table-striped table-condensed"></table>')
            .appendTo(je);
        this._table.html('<thead><tr><th>#</th><th>id</th><th>stamp</th><th>level</th><th>uid</th><th>login</th><th>duration</th><th>op</th><th>msg</th></tr></thead><tbody></tbody>');
        this._body = this._table.find('tbody');

        var items = [{caption: 'info', value: 0}, {caption: 'trace', value: 1}, {caption: 'debug', value: 2}, {caption: 'error', value: 3}, {caption: 'critical', value: 4}];
        this._filter = new CColumnFilter(this._table.find('th').get(3), 'levels', {search: false, reset: false})
            .on_dataChanged(this._d_reRead);
        this._filter.setItems(items);

        var items2 = [{caption: 'addSms', value: 'addSms'},
            {caption: 'activity_times', value: 'activity_times'},
            {caption: 'auth', value: 'auth'},
            {caption: 'gate', value: 'gate'},
            {caption: 'getBeaconData', value: 'getBeaconData'},
            {caption: 'reassignSms', value: 'reassignSms'},
            {caption: 'request restore', value: 'request restore'},
            {caption: 'togglePoint', value: 'togglePoint'},
            {caption: 'webClient', value: 'webClient'}];
        
        this._filter2 = new CColumnFilter(this._table.find('th').get(7), 'ops', {search: true, reset: false, selectAll: true})
            .on_dataChanged(this._d_reRead);
        this._filter2.setItems(items2);
    },

    activate: function(s)
    {
        CPage.callBaseMethod(this, 'activate', [s]);
        this.reRead();
    },

    _clearFilters: function()
    {
        this._filter.clear();
        this._filter2.clear();
        this._search.val('');
        this.reRead();
    },

    _onEnter: function(e)
    {
        if (e.which == 13)
        {
            e.preventDefault();
            this.reRead();
        }
    },

    reRead: function()
    {
        var param = {
            search: (this._search.val()||'').trim(),
            limit: this._rowLimit.val(),
            levels: this._filter.getValues(),
            ops: this._filter2.getValues()
        };

        this._clearFilters.get(0).disabled = !this._filter.isActive() && !this._filter2.isActive() && param.search == '';

        $ajax('getLogs', param, $cd(this, this._onReRead));
    },

    _onReRead: function(result, text, jqXHR)
    {
        if (result.error)
        {
            log('Ошибка Ajax: ' + result.error);
            return;
        }
        if (!this._table)
            return;

        this._render(result.logs);
        
        if (!this._filter2.isActive() && result.ops != null && result.ops.length > 0)
        {
            var items = [];
            for (var i =0; i < result.ops.length; i++)
                items.push({caption: result.ops[i], value: result.ops[i]});
            
            this._filter2.setItems(items);
        }
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
        var tpl = '<tr><td>{0}</td><td>{1}</td><td style="white-space: nowrap;">{2}</td><td>{3}</td><td>{4}</td><td>{5}</td><td>{6}</td><td>{7}</td><td>{8}</td></tr>';
        for (var i = 0; i < len; i++)
        {
            it = result[i];

            var d = new Date();
            d.setTime(Date.parse(it.stamp));
            body += String.format(tpl, i+1, it.id, d.toLocaleString()/*, d.toLocaleDateString()*/, String.toHTML(it.level), String.toHTML(it.uid), String.toHTML(it.login), String.toHTML(it.duration), String.toHTML(it.op), String.toHTML(it.message));
        }

        this._body.html(body);
        this._table.removeClass('hidden');
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