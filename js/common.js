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

// ['яблоко', 'яблока', 'яблок']
function $decline(num, forms)
{
    if (isNaN(num) ||  forms instanceof Array == false)
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
    if (!parent || !parent instanceof Function)
        return;
    var child = this;
    for (var i in parent.prototype)
        if (i != null && !child.hasOwnProperty(i) && child.prototype[i] == null)
            child.prototype[i] = parent.prototype[i];
}

Function.prototype.callBaseMethod = function(ctx, method, params)
{
	var f = this.prototype[method];
	if (f instanceof Function)
		return f.apply(ctx, params);

    return undefined;
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
    if (!str || ! str instanceof String)
        return '';
    
    var res = str;
    var arr = String._htmlSubstitutes;

    for (var i = 0; i < arr.length; i++)
        res = res.replace(arr[i].r, arr[i].t);
    return res;
}

if (String.prototype.trim === undefined)   // ie8 and prev
    String.prototype.trim = function()
    {
        return this.replace(/^\s+|\s+$/gm, '');
    }

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
            if (console && console.log)
                console.log(textStatus);
        };
    
    r.fail(fail);
	return r;
}


function log(msg)
{
    CApp.single().error(String.toHTML(msg));
    if (console)
        console.log(msg);
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
		if ((name||'') == '' || !handler)
			return this;

		if (this._events[name] == null)
			this._events[name] = [];

		this._events[name].push(handler); // what about duplicates? -- nobody cares
		return this;
	},

	remove: function(name, handler)
	{
	        this._checkType(name, handler);
		if ((name||'') == '' || ! handler)
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

		var hdrs = this._events[name];
		for(var i=0; i < hdrs.length; i++)
			hdrs[i](object);
	},

	_checkType: function(name, handler)
	{
		name = name || '';
		if (! (handler instanceof Function))
			throw "Handler should be a function";
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
        this._menu = $(String.format('<li><a href="{0}">{1}</a></li>', this._hash , this._text));
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
        if (this._menu)
            this._menu.addClass('active');
        if (this._elem)
            this._elem.show();

        if (this._text)
            document.title = 'MooServer: ' + this._text;

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
    },

    stdExport: function(action, title)
    {
        return $(String.format('<form method="post" action="export.php?m={0}"><input type="hidden" name="start"/> <input type="hidden" name="end"/> <input type="hidden" name="ids"/>' +
            '<button class="btn btn-default" class="form-control" data-type="tracks">{1}</button></form>', (action || ''), title));
    }
}