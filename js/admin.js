﻿/**
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

        $('<form method="post" enctype="multipart/form-data" action="import.php" target="fileTarget" class="row" style="margin-top: 1em;"><input type="file" name="import[]" multiple class="btn row"/><div class="checkbox"><label><input type="checkbox" name="commit" value="commit"/> Залить</label></div><button class="btn btn-default">Test files!</button></form>')
            .appendTo(this._rm)
            .find('button')
            .click($cd(this, this._sendFile));
    },

    reRead: function()
    {
        $ajaxErr('getUsers', {all: false}, $cd(this, this._onReRead));
    },

    _onReRead: function(result, text, jqXHR)
    {
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
                .on('load', $cd(this, this._load));

        var form = this._rm.find('form');
        form.get(0).submit();
        window.setTimeout(function () {form.find('input[type=checkbox]').get(0).checked = false;}, 0);
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
            if (doc != '')
                alert(doc);
            console.error(doc);
        }
        else
        {
			var s = '';
            if (result.log && result.log.length > 0)
            {
                if (result.log.length < 5)
                    s = result.log.join("\n");
                else
                    s = String.format("{0} предупреждений", result.log.length);

                console.log(result.log.join('\n'));
            }
			
			if (result.status && result.status.length > 0)
			{
                console.log('status', result.status);
                if (s.length > 0 )
                    s += "\n\n";
                s += result.status;
            }

			if (s != '')
			    alert(s);
			
            if ((result.error || '') != '')
            {
                console.error(result.error);
                CApp.single().error(String.toHTML(result.error));
            }

            if (result.status)
                CApp.single().message(String.toHTML(result.status), 10);
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
    this._filters = [];

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
        this._elem = $(this._tpl_fl).insertAfter(after).hide();
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

        this._table = $('<table class="hidden table table-striped table-condensed wide-content"></table>')
            .appendTo(je);
        this._table.html('<thead><tr><th>#</th><th>id</th><th>stamp</th><th>level</th><th>uid</th><th>login</th><th>duration</th><th>op</th><th>msg</th></tr></thead><tbody></tbody>');
        var head = this._table.find('th');
        this._body = this._table.find('tbody');

        var items = [{caption: 'info', value: 0}, {caption: 'trace', value: 1}, {caption: 'debug', value: 2}, {caption: 'error', value: 3}, {caption: 'critical', value: 4}];
        this._filter = new CColumnFilter(head.get(3), 'levels', {search: false, reset: false, body: this._body})
            .on_dataChanged(this._d_reRead)
            .setItems(items)
            .setValues([3, 4]);

        this._filters = [
            {idx: 7, key: 'ops', opts: {search: true, reset: false, selectAll: true, body: this._body}},
            {idx: 5, key: 'users', opts: {search: true, reset: false, selectAll: true, body: this._body}}
        ].map(function (opt) { return new CColumnFilter(head.get(opt.idx), opt.key, opt.opts).on_dataChanged(this._d_reRead);}, this);
    },

    activate: function(s)
    {
        CPage.callBaseMethod(this, 'activate', [s]);
        this.reRead();
    },

    _clearFilters: function()
    {
        this._filter.clear();
        this._filters.forEach(function(f) { f && f.clear();});
        this._search.val('');
        this.reRead();
    },

    _updateFilters: function(filters)
    {
        if (filters == null)
            return;

        this._filters.forEach(function (f) { this._updateFilter(filters, f); }, this);
    },

    _updateFilter: function(filters, filter)
    {
        var items = filters[filter.getKey()];
        if (!filter.isActive() && items != null && items.length > 0)
            filter.setItems(items);
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
            levels: this._filter.getValues()
        };
        var hasActive = false;
        this._filters.forEach(function(f) { if (!f) return;
            param[f.getKey()] = f.getValues();
            hasActive = hasActive || f.isActive();
        });

        this._clearFilters.get(0).disabled = !this._filter.isActive() && !hasActive && param.search == '';

        $ajaxErr('getLogs', param, $cd(this, this._onReRead));
    },

    _onReRead: function(result, text, jqXHR)
    {
        if (!this._table)
            return;

        this._render(result.logs);

        var filters = {};
        this._filters.forEach(function (f)
        {
            filters[f.getKey()] = (result[f.getKey()] || [])
                .map(function(it) { return {caption: it, value: it}; });
        });
        
        this._updateFilters(filters);
    },

    _render: function(result)
    {
        if (!result)
        {
            this._table.html('');
            return;
        }

        var self = this;
        var dtf = new Intl.DateTimeFormat(navigator.language, { dateStyle: 'short', timeStyle: 'medium'});
        var tpl = '<tr><td>{0}</td><td>{1}</td><td style="white-space: nowrap;">{2}</td><td>{3}</td><td>{4}</td><td>{5}</td><td>{6}</td><td>{7}</td><td>{8}</td></tr>';
        var body = result.map(function(it, idx) {
            return String.format(tpl, idx + 1, it.id, dtf.format(new Date(it.stamp))/*, d.toLocaleDateString()*/, String.toHTML(it.level), String.toHTML(it.uid), String.toHTML(it.login), String.toHTML(it.duration), String.toHTML(it.op), self._ip2hrefs(String.toHTML(it.message)).replace(/\r?\n/gi, '<br/>'));
        });
        this._body.html(body.join(''));
        this._table.removeClass('hidden');
    },

    _ip2hrefs: function(html)
    {
        html = html || '';

        var re0 = /(real IP|xForwardFor|ip|xfw): &apos;(\d{1,3}\.\d{1,3}.\d{1,3}.\d{1,3})&apos;/gi;
        var re1 = /(real IP|xForwardFor|ip|xfw): &apos;(\d{1,3}\.\d{1,3}.\d{1,3}.\d{1,3})&apos;/gi;

        var m = re0.exec(html);
        if (!m)
            return html;

        //var cached = CLogs._ipCache[m[2]];
        //var attr = cached && cached._title ? String.format(' title="{0}"', String.toHTML(cached._title)): '';
        var replace = String.format('$1: \'<a href="https://ipinfo.io/$2" target="_ip_info_">$2</a>\'');
        return html.replace(re1, replace);
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