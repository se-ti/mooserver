/**
 * Created by Serge Titov for mooServer project
 * 2014 - 2015
 */

function $get(id)
{
	return document.getElementById(id);
}

function $cd(ctx, func)
{
	if (!func || func.constructor != Function)
		return null;
	return function()
	{
		func.apply(ctx, arguments);
	};
}

function $namespace(name)
{
    if (!name)
        return;

    if (typeof(name) !== 'string' && !(name instanceof String))
        throw Error("Namespace name should be a string");

    if (window[name] == null)
    {
        window[name] = {__isNamespace: true};
        return;
    }

    if (window[name].__isNamespace !== true)
        throw Error("Object with name '" + name +  "' already exists");
}

// ['яблоко', 'яблока', 'яблок']
function $decline(num, forms)
{
    if (isNaN(num) || (forms instanceof Array) == false)
        return "";
    if (forms.length < 3)
        return forms[0];

    var nd = num % 10;
    switch (num % 100)
    {
        case 11: case 12: case 13: case 14: return forms[2];
        default: return nd == 0 || nd > 4 ? forms[2] : (nd == 1 ? forms[0] : forms[1]);
    }
}

function $decl(num, forms)
{
    return '' + num + ' ' + $decline(num, forms);
}

function $selAdd(select, idx, value, text)
{
	if (!select)
		return null;

	var opt = document.createElement("option"); 
	opt.text = text;
	opt.value = value;
	select.add(opt, idx);

	return opt;
}

function $saveCancel(root, saveCb, cancelCb)
{
    var save = $('<button class="btn btn-primary">Сохранить</button>')
        .appendTo(root)
        .click(saveCb);

    var cancel = $('<button class="btn btn-default">Отменить</button>')
        .appendTo(root)
        .click(cancelCb);

    return {save: save, cancel: cancel};
}

Function.prototype.inheritFrom = function(parent)
{
    if (!parent || !(parent instanceof Function))
        return this;

    var child = this;
    for (var i in parent.prototype)
        if (i != null && !child.hasOwnProperty(i) && child.prototype[i] == null)
            child.prototype[i] = parent.prototype[i];

    return this;
}

Function.prototype.callBaseMethod = function(ctx, method, params)
{
	var f = this.prototype[method];
	if (f instanceof Function)
		return f.apply(ctx, params);

    return undefined;
}

Function.prototype.addEvent = function(eventName)
{
    var that = this;

    if ((eventName || '') == '')
        throw Error("Function.addEvent: event name can't be empty");

    if (typeof(eventName) !== 'string' && !(eventName instanceof String))
        throw Error("Function.addEvent: event name should be a string");

    ['on', 'remove', 'raise', '_raise_'+eventName].forEach(function(prop) {
        if (!(that.prototype[prop] instanceof Function))
            throw Error(String.format("Function.addEvent: class '{0}' does't have method '{1}'", that.name, prop));
    });

    ['on_' + eventName, 'remove' + eventName].forEach(function(prop) {
        if (that.prototype[prop] != null)
            throw Error(String.format("Function.addEvent: class '{0}' has '{1}' {2} already", that.name, prop, that.prototype[prop] instanceof Function ? 'method' : 'property'));
    });

    this.prototype['on_'+eventName] = function(h) { return this.on(eventName, h); };
    this.prototype['remove_'+eventName] = function(h) { return this.remove(eventName, h); };

    return this;
}

String.format = function()
{
    var s = arguments[0];
    for (var i = 0; i < arguments.length - 1; i++)
    {
        var reg = new RegExp("\\{" + i + "\\}", "gm");
        s = s.replace(reg, arguments[i + 1]);
    }

    return s;
}

String._htmlSubstitutes = [{r:/&/gi, t:'&amp;'},
    {r:/</gi, t:'&lt;'},
    {r:/\>/gi, t:'&gt;'},
    {r:/'/gi, t:'&apos;'},
    {r:/"/gi, t:'&quot;'}];

String.toHTML = function(str)
{
    if (!str)
        return '';

    if (typeof(str) !== 'string' && !(str instanceof String))
        throw Error("Can't toHTML not a string");

    if (!str.replace)
        throw Error("String object desn't have replace method");
    
    var res = str;
    var arr = String._htmlSubstitutes;

    for (var i = 0; i < arr.length; i++)
        res = res.replace(arr[i].r, arr[i].t);
    return res;
}

String.makeBreakable = function(str, head, chunk)
{
    head = head || 70;
    chunk = chunk || 15;

    if ((str || '') == '')
        return str;

    var len = str.length;
    var res = [String.toHTML(str.substr(0, Math.min(head, len)))];
    for (var i = head; i < len; i += chunk)
        res.push(String.toHTML(str.substr(i, Math.min(chunk, len - i))));

    return res.join('<wbr/>');
}

if (String.prototype.trim === undefined)   // ie8 and prev
    String.prototype.trim = function()
    {
        return this.replace(/^[\s\uFEFF\xA0]+|[\s\uFEFF\xA0]+$/gm, '');
    }

if (!Array.isArray)
    Array.isArray = function(arg) {
        return Object.prototype.toString.call(arg) === '[object Array]';
    };

function $ajax(method, param, success, fail)
{
	var r = $.ajax({
		dataType: "json",
		url: 'ajax.php?m='+method,
		data: param,
		success: success,
		type: 'post'
		});

    fail = fail || function(jqXHR, textStatus, errorThrown)
        {
            var rt = jqXHR.responseText || '';
            var respStat = jqXHR.status == 200 ? String.format("response len: {0}, head: {1}...", rt.length, rt.substr(0, 50)): '';

            var reqData = this.data && method != 'login' ? String.format(', req params: {0}', this.data) : '';

            log(String.format("Запрос к '{0}' завершился ошибкой: {1}, statusText: {2}, err: {3}, {4}, head: {5}{6}", this.url, jqXHR.status, jqXHR.statusText, textStatus, errorThrown, respStat, reqData));
            /*if (console && console.log)
                console.log(textStatus);*/
        };

    r.fail(fail);
	return r;
}


function log(msg)
{
    if (window.CApp)
        CApp.single().error(String.toHTML(msg));
    trace(msg, true);
}

function trace(msg, error)
{
    error = error || false;

    if (error && console)
        console.log(msg);

    $ajax('log', {level: error ? 3 : 1, message: msg}, function(){}, function(){}); // чтобы не зацикливался при глобальных проблемах с сетью, сервером и т.п.
}

CControl = function()
{
	this._events = {};
}

CControl.prototype = 
{
    toggle: function(show)
    {
        if (this._c && this._c.root)
            this._c.root.toggleClass('hidden', !show);
    },

	on: function(name, handler)
	{
        this._checkType(name, handler);
		if ((name || '') == '' || !handler)
			return this;

		if (this._events[name] == null)
			this._events[name] = [];

		this._events[name].push(handler); // what about duplicates? -- nobody cares
		return this;
	},

	remove: function(name, handler)
	{
        this._checkType(name, handler);
		if ((name || '') == '' || ! handler)
			return this;

		if (this._events[name] == null)
			return this;

		var hdrs = this._events[name];
		for(var i = hdrs.length-1; i >= 0; i--)
			if (hdrs[i] == handler)
				hdrs.splice(i, 1);

		return this;
	},

	raise: function(name, object)
	{
		name = name || '';
		if (this._events[name] == null)
			return;

		this._events[name].forEach( function(h) {
		    h(object);
		});
	},

	_checkType: function(name, handler)
	{
		if (! (handler instanceof Function))
			throw Error(String.format("CControl: Handler for '{0}' should be a function", name || ''));
	}
}

CPage = function(hash, text, elem)
{
    this._hash = '#' + hash;
    this._text = text;
    this._menu = null;
    this._elem = $(elem);

    this._isAvailable = true;
    this._isActive = false;
}

CPage.prototype =
{
    _tpl: '<div class="container"><div class="row"></div></div>',
    _tpl_fl: '<div class="container-fluid"><div class="row"></div></div>',
    _tpl_main: '<div class="col-xs-12 col-sm-9 col-sm-pull-3"></div>',
    _tpl_right: '<div class="col-xs-12 col-sm-3 col-sm-push-9"></div>',

    buildIn: function(menuRoot)
    {
        if (!menuRoot)
            return;

        if (!this._text)
            return;
        this._menu = $(String.format('<li class="hidden"><a href="{0}">{1}</a></li>', this._hash , this._text));
        this._menu.appendTo(menuRoot);
    },

    match: function(hash)
    {
        return  this._isAvailable && (hash == this._hash || (hash == '' && this._hash == '#'));
    },


    deactivate: function()
    {
        if (this._menu)
            this._menu.removeClass('active');
        if (this._elem)
            this._elem.hide();
    },

    activate: function()
    {
        if (this._menu)                         // todo а проверить, что available?
            this._menu.addClass('active');
        if (this._elem)
            this._elem.show();

        if (this._text)
            document.title = 'MooServer: ' + this._text;

        CApp.single().error('');

        this._isActive = true;
    },

    setData: function(data)
    {
        if (console)
            console.log('override CPage.setData');
    },

    setRights: function(rights)
    {
        if (console)
            console.log('override CPage.setRights');

        if (this._menu)
            this._menu.toggleClass('hidden', !this._isAvailable);
    },

    stdExport: function(action, title)
    {
        return $(String.format('<form method="post" action="export.php?m={0}"><input type="hidden" name="start"/> <input type="hidden" name="end"/> <input type="hidden" name="ids"/>' +
            '<button class="btn btn-default" class="form-control" data-type="tracks">{1}</button></form>', (action || ''), title));
    }
}