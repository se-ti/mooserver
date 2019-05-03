/**
 * Created by Serge Titov for mooServer project
 * 2014 - 2015
 */

CEdit = function(head, options)
{
    this._head = head || '';
    this._c = {err: null};
    this._data = null;
    this._options = options || {};
}

CEdit.prototype = {
    DEMO: 1001,
    _errTpl: '<p class="hidden text-danger"></p>',

    clear: function()
    {
        for (var i in this._c)
            if (i)
                this._c[i] = null;  // detach?;
    },

    focus: function()
    {

    },

    headText: function()
    {
        return this._head;
    },

    cellHtml: function(item)
    {
        return '';
    },

    edit: function(cell, item)
    {
        cell.innerHTML = '';
        return;
    },

    validate: function()
    {
        this._errorMessage('');
        return true;
    },

    getValue: function(item)
    {
        return item;
    },

    setData: function(data)
    {
        this._data = data;
    },

    _errorMessage: function(mess)
    {
        var hide = (mess || '') == '';
        if (this._c.err)
            this._c.err.text(mess || '')
                .toggleClass('hidden', hide);
    },

    isReadOnly: function()
    {
        return this._options.readOnly;
    },

    comparator: function()
    {
        return this._options.sort;
    }
}

$namespace('Cr');
Cr.CTextEdit = function(head, key, options)
{
    CEdit.call(this, head, options);

    this._key = key;

    var opts = options || {};
    this._caption = opts.caption || '';
    this._emptyMess = opts.emptyMess || '';
    this._required = opts.required !== false;

    this._d_validate = $cd(this, this.validate);
}

Cr.CTextEdit.prototype = {
    _tpl: '<div class="form-group"><label>{0}<input type="text" class="form-control"/></label></div>',
    _cbTpl: '<div class="form-group checkbox"><label><input type="checkbox" class="-form-control"/> {0}</label></div>',


    cellHtml: function(item)
    {
        if (item && item[this._key] == '')
            return '&nbsp;';    // чтобы строки-пустышки были нормальными

        if (!item || !item[this._key])
            return '';

        return String.toHTML(item[this._key]);
    },

    edit: function(cell, item)
    {
        CEdit.callBaseMethod(this, 'edit', [cell, item]);
        var e = $('<fieldset></fieldset>').appendTo(cell);

        var c = this._c;

        c.edit = $(String.format(this._tpl, this._caption))
            .appendTo(e)
            .find('input')
            .val(item[this._key])
            .change(this._d_validate);

        c.err = $(this._errTpl).appendTo(cell);
    },

    focus: function()
    {
        this._c.edit.focus();
    },

    validate: function()
    {
        var value = this._c.edit.val().trim();
        var valid = !this._required || this._required && value != '';

        this._errorMessage(valid ? '' : this._emptyMess);
        return valid;
    },

    getValue: function(item)
    {
        item[this._key] = this._c.edit.val();
        return item;
    }
}
Cr.CTextEdit.inheritFrom(CEdit);

Cr.CNumEdit = function(head, key, options)
{
    Cr.CTextEdit.call(this, head, key, options);

    var opt = options || {};
    this._min = opt.min;
    this._max = opt.max;
}

Cr.CNumEdit.prototype = {

    edit: function(cell, item)
    {
        Cr.CTextEdit.callBaseMethod(this, 'edit', [cell, item]);
        this._c.edit.attr('size', 3).attr('maxlength', 3);
    },

    validate: function()
    {
        var val = this._c.edit.val().trim();

        var valid = false;
        var msg = '';
        while (1)
        {
            if (val == '' && this._emptyMess != '')
            {
                msg = this._emptyMess;
                break;
            }

            if (isNaN(val))
            {
                msg = String.format("'{0}' - не число", String.toHTML(val));
                break;
            }

            if (Math.floor(val) != val)
            {
                msg = String.format("'{0}' - не целое число", String.toHTML(val));
                break;
            }

            if (this._min !== null && val < this._min)
            {
                msg = "Значение должно быть не меньше " + this._min;
                break;
            }

            if (this._max !== null && val > this._max)
            {
                msg = "Значение должно быть не больше " + this._max;
                break;
            }

            break;
        }

        this._errorMessage(msg);

        return msg == '';
    }
}
Cr.CNumEdit.inheritFrom(Cr.CTextEdit);

CEditLogin = function(head, login, name, mailLogin)
{
    CEdit.call(this, head);
    this._login = login || '';
    this._lname = name || '';

    this._eMailLogin = mailLogin || false;

    this._cb_validate = $cd(this, this.validate);
}

CEditLogin.prototype = {
    cellHtml: function(item)
    {
        if (!item)
            return '';
        var res = [];
        if ((item.login ||'' ) != '')
            res.push(String.toHTML(item.login));
        if ((item.name || '') != '')
            res.push('<small>' + String.toHTML(item.name || '')  + '</small>');
        return '<h4>' + res.join('<br/>') + '</h4>';
    },

    _tpl: '<div class="form-group"><label>{0}<input type="text" class="form-control"/></label></div>',
    edit: function(cell, item)
    {
        CEdit.callBaseMethod(this, 'edit', [cell, item]);
        var e = $('<fieldset></fieldset>').appendTo(cell);
        this._c.login = $(String.format(this._tpl, String.toHTML(this._login)))
            .appendTo(e)
            .find('input')
            .val(item.login)
            .change(this._cb_validate);

        this._c.err = $(this._errTpl)
            .appendTo(e);

        this._c.lname = $(String.format(this._tpl, String.toHTML(this._lname)))
            .appendTo(e)
            .find('input')
            .val(item.name);

        return;
    },

    focus: function()
    {
        if (this._c.login)
            this._c.login.get(0).focus();
    },

    validate: function()
    {
        var login = this._c.login.val().trim();
        var valid = login != '';

        this._errorMessage(valid ? '' : String.format('Не заполнен {0}', (this._login || '').toLowerCase()));
        if (valid && this._eMailLogin)
        {
            var re = /^\S+@\S+\.\w{2,}$/gm;
            valid = re.test(login);
            if (!valid)
                this._errorMessage('Некорректный мейл');
        }
        return valid;
    },

    getValue: function(item)
    {
        item.login = this._c.login.val().trim();
        item.name = this._c.lname.val().trim();
        return item;
    }
}
CEditLogin.inheritFrom(CEdit);

CEditRights = function()
{
    CEdit.call(this, 'Права');
}

CEditRights.prototype = {

    _isSuper: function()
    {
        var r = CApp.single().getRights();
        return r && r.isSuper;
    },

    _uid: function()
    {
        var r = CApp.single().getRights();
        return r ?  (r.id || 0) : 0;
    },

    _addCheckbox: function(root, html, check, disable)
    {
        var c = $(String.format('<label><input type="checkbox"{0}/> {1}</label>', disable? ' disabled' : '', html))
            .appendTo(root)
            .find('input')
            .get(0);
        c.checked = check;
        return c;
    },

    cellHtml: function(item)
    {
        var arr = [];
        if (item.feed)
            arr.push('отправлять смс');
        if (item.admin)
            arr.push('администрировать');
        if (item.super && this._isSuper())
            arr.push('супер админ');
        return arr.join('<br/>');
    },

    edit: function(cell, item)
    {
        CEdit.callBaseMethod(this, 'edit', [cell, item]);

        var me = item.id == this._uid();
        var e = $('<div class="checkbox"></div>').appendTo(cell);

        this._c.sms = this._addCheckbox(e, 'Отправлять&nbsp;sms', item.feed, false);
        this._c.admin = this._addCheckbox(e, 'Администрировать', item.admin, me && item.admin); // чтоб себя не лишил прав
        if (this._isSuper())
            this._c.super = this._addCheckbox(e, 'Супер админ', item.super, me && item.super); // чтоб себя не лишил прав

        return;
    },

    getValue: function(item)
    {
        var c = this._c;
        item.feed = c.sms && c.sms.checked;
        item.admin = c.admin && c.admin.checked;
        item.super = c.super && c.super.checked;
        return item;
    }
}
CEditRights.inheritFrom(CEdit);

CEditOrgs = function()
{
    CEdit.call(this, 'Организации');
}
CEditOrgs.prototype =
{
    cellHtml: function(item)
    {
        if (!item || !item.orgs || item.orgs.length <= 0)
            return '&lt;нет&gt;';

        var res = [];
        var tpl = '{0}';
        var inactive = '<del>{0}</del>';
        for (var i = 0; i < item.orgs.length; i++)
            res.push(String.format(item.orgs[i].active ? tpl : inactive, String.toHTML(item.orgs[i].login)));

        return res.join('<br/>');
    },

    edit: function(cell, item)
    {
        CEdit.callBaseMethod(this, 'edit', [cell, item]);

        var i;
        var hash = {};
        if (item.groups)
            for (i = 0; i < item.groups.length; i++)
                hash[item.groups[i]] = true;

        var d;
        var line = '';
        var dClass;
        var disabled;
        for (i = 0; i < this._data.length; i++)
        {
            d = this._data[i];
            dClass = d.active ? '' : ' class="disabled"';
            disabled = d.active ? '' : ' disabled';
            line += String.format('<div class="checkbox"><label{0}><input type="checkbox" value="{1}"{2}{3}/> {4}</label></div>', dClass, d.id, hash[d.id] ? ' checked': '', disabled, String.toHTML(this._data[i].login));
        }

        this._c.root = $('<div>' + line + '</div>').appendTo(cell);

        this._c.err = $(this._errTpl)
            .appendTo(cell);
    },

    setData: function(data)
    {
        if (data && data.orgs)
            this._data = data.orgs;
    },

    _getValues: function()
    {
        var i;
        var id;
        var ids = [];
        var orgs = [];
        var hash = {};
        var items = this._c.root.find('input');
        for (i = 0; i < items.length; i++)
            if (items.get(i).checked)
            {
                id = items.get(i).value;
                if (id == '')
                    continue;

                ids.push(id);
                hash[id] = true;
            }

        if (this._data)
            for (i = 0; i < this._data.length; i++)
                if (hash[this._data[i].id])
                    orgs.push(this._data[i]);

        return {groups: ids, orgs: orgs};
    },

    validate: function()
    {
        var res = this._getValues();
        var valid = res.groups.length > 0;
        this._errorMessage(valid ? '' : 'Не выбрано ни одной организации');
        return valid;
    },

    getValue: function(item)
    {
        var rv = this._getValues();
        item.groups = rv.groups;
        item.orgs = rv.orgs;
        return item;
    }
}
CEditOrgs.inheritFrom(CEdit);

CSingleOrg = function()
{
    CEditOrgs.call(this, true);
    this._options.sort = function (a, b) {
        var av = a.orgs && a.orgs.length > 0 ? (a.orgs[0].login || '') : '';
        var bv = b.orgs && b.orgs.length > 0 ? (b.orgs[0].login || '') : '';
        return av.localeCompare(bv);
    };
}

CSingleOrg.prototype =
{
    cellHtml: function(item)
    {
        var tmp = item.orgs;
        var arr = [];
        if (item.demo && this._data)    // временно подменяем item.orgs, добавляя в него demo
        {
            for (var i = 0;  i < this._data.length; i++)
                if (this._data[i].id == this.DEMO)
                {
                    arr.push(this._data[i]);
                    break;
                }
        }

        item.orgs = arr.concat(item.orgs);
        var res = CEditOrgs.callBaseMethod(this, 'cellHtml', [item]);

        item.orgs = tmp;
        return res;
    },

    edit: function(cell, item)
    {
        CEdit.callBaseMethod(this, 'edit', [cell, item]);

        var i;
        var hash = {};
        if (item.groups)
            for (i = 0; i < item.groups.length; i++)
                hash[item.groups[i]] = true;

        var d;
        var line = '';
        var type;
        var chckd;
        var dClass;
        var disabled;
        var demo = '';
        var none = '';
        var tpl = '<div class="{1}"><label{0}><input type="{1}" value="{2}" name="seOrgs"{3}{4}/> {5}</label></div>';
        for (i = 0; i < this._data.length; i++)
        {
            d = this._data[i];

            type = d.id != this.DEMO ? 'radio' : 'checkbox';
            chckd = (hash[d.id] || d.id == this.DEMO && item.demo) ? ' checked': '';
            dClass = d.active ? '' : ' class="disabled"';
            disabled = d.active ? '' : ' disabled';
            var l = String.format(tpl, dClass, type, d.id, chckd, disabled, String.toHTML(this._data[i].login));

            if (d.id != this.DEMO)
                line += l;
            else
                demo = l;
        }

        if (line != '')
            none = String.format(tpl, '', 'radio', '', '', '', '&lt;не&nbsp;подключать&gt;');

        this._c.root = $('<div>' + demo + none + line + '</div>').appendTo(cell);
        this._c.err = $(this._errTpl).appendTo(cell);
    },

    getValue: function(item)
    {
        item = CEditOrgs.callBaseMethod(this, 'getValue', [item]);

        item.demo = item.groups && item.groups[0] == this.DEMO;
        if (item.demo)
            item.groups.splice(0, 1);

        return item;
    }
}
CSingleOrg.inheritFrom(CEditOrgs);

CMoosePhoneEdit = function(title, phones)
{
    CEdit.call(this, title);
    this._c.phones = null;
    this._phones = phones;
}

CMoosePhoneEdit.prototype =
{
    cellHtml: function(item)
    {
        if (item && this._data)
            for (var i = 0; i < this._data.length; i++)
            {
                var d = this._normalize(this._data[i]);
                if (d.ref == item.id)
                    return this._phones? CSingleBeacon.beaconHref(d.id, d.title) : String.toHTML(d.title);
            }

        return '&lt;нет&gt;';
    },

    _normalize: function(obj)
    {
        if (this._phones)
            return {
                title: obj.phone,
                id: obj.id,
                ref: obj.moose,
                active: obj.active
            };


        return {
            title: obj.name,
            ref: obj.phoneId,
            id: obj.id,
            active: true
        };

    },

    edit: function(cell, item)
    {
        CEdit.callBaseMethod(this, 'edit', [cell, item]);

        var tpl = '<div class="radio"><label{0}><input type="radio" class="" value="{1}" name="phones"{2}{3}/>{4}</label></div>';
        var none = '';
        var line = '';

        var sel;

        if (this._data && item)
        {
            for (var i = 0; i < this._data.length; i++)
            {
                var d = this._normalize(this._data[i]);
                if (d.ref && d.ref != item.id)
                    continue;

                sel = item.id != null && d.ref == item.id ? ' checked' : '';
                var disabled = d.active? '' : ' disabled';
                var dClass = d.active? '' : ' calss="disabled"';

                line += String.format(tpl, dClass, d.id, sel, disabled, String.toHTML(d.title));
            }

            var has = this._phones ? item.phoneId : item.moose;
            sel = has != null ?  '' : ' checked';
            none = String.format(tpl, '', '', sel, '', '&lt;не&nbsp;подключать&gt;');
        }

        if (line == '')
        {
            line = this._phones ? 'нет свободных приборов' : 'нет доступных животных';
            none = '';
        }

        this._c.root = $('<div/>')
            .appendTo(cell)
            .append(none + line);
    },

    setData: function(data)
    {
        if (data && data.alt)
            this._data = data.alt;
    },

    getValue: function(item)
    {
        if (!this._c.root)
            return item;

        var f = this._c.root.find('input');
        for(var i = 0; i < f.length; i++)
            if (f.get(i).checked)
            {
                if (this._phones)
                    item.phoneId = f.get(i).value;
                else
                    item.moose = f.get(i).value;
                break;
            }

        return item;
    }
}
CMoosePhoneEdit.inheritFrom(CEdit);

CNameEdit = function()
{
    Cr.CTextEdit.call(this, 'Имя', 'name', {caption: '', emptyMess: "Не заполнено имя животного"});
}
CNameEdit.inheritFrom(Cr.CTextEdit);

CPhoneEdit = function()
{
    Cr.CTextEdit.call(this, 'Телефон', 'phone', {caption: '', emptyMess: "Не заполнен телефон"});
}
CPhoneEdit.prototype =
{
    cellHtml: function(item)
    {
        return CSingleBeacon.beaconHref(item.id, item.phone || '');
    }
}
CPhoneEdit.inheritFrom(Cr.CTextEdit);

CLineEditor = function(content, columns, noToggle)
{
    CControl.call(this);
    this._content = $(content);
    this._elem = $(content).get(0);
    this._err = null;

    this._item = null;
    this._row = null;
    this.setColumns(columns);
    this._noToggle = noToggle || false;

    this._newRow = false;

    this._d_save = $cd(this, this._save);
    this._d_cancel = $cd(this, this._cancel);
}

CLineEditor.prototype =
{
    _saveTpl: '<button class="btn btn-primary">Сохранить</button> ',
    _cancelTpl: ' <button class="btn btn-default">Отменить</button>',

    activators: function(item)
    {
        var _del = '<span class="glyphicon glyphicon-trash"></span>'; // 'Удалить'
        var del = this._noToggle ? '' :  ('<button class="btn btn-default lineDel">' + (item.active ? _del : 'Восстановить') + '</button>');
        return '<div class="note"><button class="btn btn-default lineEdit">Редактировать</button>' + del + '</div>';
    },

    setColumns: function(columns)
    {
        this._cols = columns || [];
    },

    activate: function(row, item, addNew, data)
    {
        if ((!row || item == null) && !addNew)
            return;

        this._newRow = false;
        if (addNew)
            row = this._addRow();

        item = item || {id:null};

        this._item = item;
        this._row = row;

        for (var i = 0; i < this._cols.length; i++)
        {
            this._cols[i].setData(data);
            if (!this._cols[i].isReadOnly())
                this._cols[i].edit(this._row.cells[i], item);
        }

        var last = this._row.cells[this._row.cells.length -1];
        last.innerHTML = '';
        $(this._saveTpl).appendTo(last)
            .click(this._d_save);

        $(this._cancelTpl).appendTo(last)
            .click(this._d_cancel);

        this._err = $('<div class="alert alert-danger hidden"></div>')
            .appendTo(last);

        this._content.addClass('hideControls');

        var first = this._cols.find(c => !c.isReadOnly());
        if (first)
            first.focus();
    },

    deactivate: function(item)
    {
        if (item == null)
        {
            if (this._newRow)
            {
                this._elem.tBodies[0].deleteRow(0);
                this._content.removeClass('hideControls');
                return;
            }
            item = this._item;
        }

        var cells = this._row.cells;
        for (var i = 0; i < this._cols.length; i++)
            cells[i].innerHTML = this._cols[i].cellHtml(item);

        cells[cells.length -1].innerHTML = this.activators(item);

        this._item = null;
        this._row = null;

        this._content.removeClass('hideControls');
    },

    error: function(message)
    {
        this._err.text(message)
            .toggleClass('hidden', (message || '') == '');

        //CApp.single().error(String.toHTML(message));
    },

    _addRow: function()
    {
        var r = this._elem.tBodies[0].insertRow(0);
        for (var i = 0; i <= this._cols.length; i++)
            r.insertCell(0);
        this._newRow = true;
        return r;
    },

    _cancel: function(e)
    {
        e.stopPropagation();
        this._raise_queryEndEdit(false, null);
    },

    _save: function(e)
    {
        e.stopPropagation();

        var res = {
            id: this._item.id,
            active: this._item.active || this._newRow
        };

        var valid = true;
        for (var i = 0; i < this._cols.length; i++)
        {
            if (this._cols[i].isReadOnly())
                continue;
            valid = this._cols[i].validate() && valid;
            res = this._cols[i].getValue(res);
        }
        if (!valid)
            return this.error("Ошибка валидации");
        this._raise_queryEndEdit(true, res);
    },

    _raise_queryEndEdit: function(save, item)
    {
        this.raise('queryEndEdit', {save: save, item: item});
    }
}
CLineEditor.inheritFrom(CControl).addEvent('queryEndEdit');
