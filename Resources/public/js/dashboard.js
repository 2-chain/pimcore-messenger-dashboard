/* global Ext, pimcore */

Ext.ns('twochain.messengerDashboard');

// Local alias for Pimcore's translation function. Prevents accidental
// shadowing (e.g. using `t` as a closure variable name) from breaking
// translation calls, and gives a safe fallback if `t` isn't defined.
var twochainT = (typeof t === 'function') ? t : function (key) { return key; };

// Interpolate {placeholder} tokens in a translated string. Used for messages
// with dynamic parts like "Delete {n} messages?".
function twochainTI(key, params) {
    var text = twochainT(key);
    if (params) {
        Object.keys(params).forEach(function (k) {
            text = text.split('{' + k + '}').join(params[k]);
        });
    }
    return text;
}

twochain.messengerDashboard.API = {
    base: '/admin/messenger-dashboard',
    request: function (method, path, body, success, failure) {
        Ext.Ajax.request({
            url: this.base + path,
            method: method,
            jsonData: body || undefined,
            headers: { 'X-pimcore-csrf-token': pimcore.settings.csrfToken },
            success: function (resp) {
                var data = resp.responseText ? Ext.decode(resp.responseText, true) : null;
                success && success(data);
            },
            failure: function (resp) {
                var data = resp.responseText ? Ext.decode(resp.responseText, true) : null;
                var msg = (data && data.error && data.error.message) || ('HTTP ' + resp.status);
                pimcore.helpers.showNotification(twochainT('error'), msg, 'error');
                failure && failure(data, resp);
            },
        });
    },
    transports: function (success) { this.request('GET', '/transports', null, success); },
    messages: function (name, offset, limit, success) {
        this.request('GET', '/transports/' + encodeURIComponent(name) + '/messages?offset=' + offset + '&limit=' + limit, null, success);
    },
    deleteMessage: function (name, id, success) {
        this.request('DELETE', '/transports/' + encodeURIComponent(name) + '/messages/' + encodeURIComponent(id), null, success);
    },
    bulkDelete: function (name, payload, success) {
        this.request('POST', '/transports/' + encodeURIComponent(name) + '/messages/bulk-delete', payload, success);
    },
    failedList: function (offset, limit, success) {
        this.request('GET', '/failed/messages?offset=' + offset + '&limit=' + limit, null, success);
    },
    failedClasses: function (success) {
        this.request('GET', '/failed/message-classes', null, success);
    },
    deleteFailed: function (id, success) {
        this.request('DELETE', '/failed/messages/' + encodeURIComponent(id), null, success);
    },
    requeueFailed: function (id, success) {
        this.request('POST', '/failed/messages/' + encodeURIComponent(id) + '/requeue', null, success);
    },
    bulkDeleteFailed: function (payload, success) {
        this.request('POST', '/failed/messages/bulk-delete', payload, success);
    },
    bulkRequeueFailed: function (payload, success) {
        this.request('POST', '/failed/messages/bulk-requeue', payload, success);
    },
    stats: function (success) {
        this.request('GET', '/stats?windows=1d,3d,7d', null, success);
    },
};

twochain.messengerDashboard.Permissions = {
    canEdit: function () {
        var user = pimcore.globalmanager.get('user');
        return user.admin || (user.permissions && user.permissions.indexOf('messenger_dashboard_edit') !== -1);
    },
};

// Format an ISO-8601 timestamp using Pimcore's locale-aware datetime format.
// Mirrors what Pimcore's object listing (and most other admin lists) do:
// `pimcore.globalmanager.get('localeDateTime')` returns a Locale Format
// object, not a string — the actual pattern comes from
// `.getDateTimeFormat()` on it.
twochain.messengerDashboard.formatDateTime = function (value) {
    if (value === null || value === undefined || value === '') { return ''; }
    var date = (value instanceof Date) ? value : new Date(value);
    if (isNaN(date.getTime())) { return String(value); }

    var pattern = 'Y-m-d H:i:s';
    try {
        if (typeof pimcore !== 'undefined' && pimcore.globalmanager) {
            var localeFormat = pimcore.globalmanager.get('localeDateTime');
            if (localeFormat && typeof localeFormat.getDateTimeFormat === 'function') {
                pattern = localeFormat.getDateTimeFormat() || pattern;
            }
        }
    } catch (e) { /* keep default pattern */ }

    return Ext.Date.format(date, pattern);
};

// Cell renderer wrapper for grid columns.
twochain.messengerDashboard.dateCellRenderer = function (value) {
    return twochain.messengerDashboard.formatDateTime(value);
};

// Shared cell renderer for the message body. Collapses whitespace, truncates
// to 80 chars for the cell, and exposes the full content via the cell's
// quicktip so the user can hover to read the whole payload.
twochain.messengerDashboard.bodyCellRenderer = function (value, meta) {
    if (value === null || value === undefined || value === '') {
        return '';
    }
    var text = String(value);
    var inline = text.replace(/\s+/g, ' ').trim();
    var truncated = inline.length > 80 ? inline.substring(0, 80) + '…' : inline;
    if (meta) {
        meta.tdAttr = 'data-qtip="' + Ext.util.Format.htmlEncode(text) + '"';
        meta.tdCls = (meta.tdCls || '') + ' twochain-body-cell';
    }
    return Ext.util.Format.htmlEncode(truncated);
};

// Build a paging toolbar (bbar) bound to the given store. Mirrors Pimcore's
// object-list pager: first/prev/page-input/next/last, refresh, display info,
// and a page-size combo on the right.
twochain.messengerDashboard.buildPagingBar = function (store) {
    return {
        xtype: 'pagingtoolbar',
        store: store,
        displayInfo: true,
        items: [
            '-',
            {
                xtype: 'combobox',
                width: 110,
                editable: false,
                forceSelection: true,
                queryMode: 'local',
                store: ['25', '50', '100', '200', '500'],
                value: String(store.getPageSize()),
                listeners: {
                    select: function (combo) {
                        store.setPageSize(parseInt(combo.getValue(), 10));
                        store.loadPage(1);
                    },
                },
            },
        ],
    };
};

// Build a remote-paged JSON store pointed at one of the dashboard REST
// endpoints. The endpoint must return `{items: [...], total: int}`.
twochain.messengerDashboard.buildPagedStore = function (url) {
    return new Ext.data.Store({
        fields: [
            {
                // Keep the raw id string (Redis stream ids look like
                // "1747320142-0") for delete/find operations, but sort by
                // its leading numeric value so Doctrine bigint ids order
                // as 1, 2, 10 instead of 1, 10, 2.
                name: 'id',
                sortType: function (value) {
                    if (value === null || value === undefined || value === '') {
                        return 0;
                    }
                    var n = parseInt(value, 10);
                    return isNaN(n) ? 0 : n;
                },
            },
            'messageClass',
            'bodyPreview',
            'createdAt',
            'retryCount',
            'failureClass',
            'failureMessage',
        ],
        pageSize: 50,
        remoteSort: false,
        proxy: {
            type: 'ajax',
            url: url,
            // Map Ext's default param names to the controller's `offset` /
            // `limit` query params; suppress the unused page/sort/dir params
            // so the URL stays clean.
            startParam: 'offset',
            limitParam: 'limit',
            pageParam: '',
            sortParam: '',
            directionParam: '',
            reader: {
                type: 'json',
                rootProperty: 'items',
                totalProperty: 'total',
            },
            listeners: {
                exception: function (proxy, response) {
                    var msg = 'HTTP ' + response.status;
                    try {
                        var body = Ext.decode(response.responseText, true);
                        if (body && body.error && body.error.message) { msg = body.error.message; }
                    } catch (e) { /* noop */ }
                    pimcore.helpers.showNotification(twochainT('error'), msg, 'error');
                },
            },
        },
    });
};

// Build a debounced search-text field. Typing waits 300ms then triggers
// onChange(query); Enter applies immediately; Esc and the clear-trigger
// icon both clear the value and trigger onChange(null). The caller is
// expected to wire onChange to update its store's `extraParams.q` and
// reload the store on the first page.
twochain.messengerDashboard.buildSearchField = function (onChange) {
    var debounceMs = 300;
    var timer = null;
    var lastFired = null;

    var fire = function (raw) {
        var normalized = raw && raw.trim().length > 0 ? raw.trim() : null;
        if (normalized === lastFired) {
            return;
        }
        lastFired = normalized;
        onChange(normalized);
    };

    return new Ext.form.field.Text({
        emptyText: twochainT('messenger_dashboard_search_placeholder'),
        width: 280,
        triggers: {
            clear: {
                cls: 'x-form-clear-trigger',
                hidden: true,
                handler: function (field) {
                    field.setValue('');
                    if (timer) { clearTimeout(timer); timer = null; }
                    // The change listener will hide the trigger once the
                    // field's value is empty.
                    fire('');
                },
            },
        },
        listeners: {
            change: function (field, value) {
                var trigger = field.getTrigger('clear');
                if (trigger) {
                    if (value && value.length > 0) {
                        trigger.show();
                    } else {
                        trigger.hide();
                    }
                }
                if (timer) { clearTimeout(timer); }
                timer = setTimeout(function () {
                    timer = null;
                    fire(field.getValue());
                }, debounceMs);
            },
            specialkey: function (field, e) {
                if (e.getKey() === e.ENTER) {
                    if (timer) { clearTimeout(timer); timer = null; }
                    fire(field.getValue());
                } else if (e.getKey() === e.ESC) {
                    field.setValue('');
                    if (timer) { clearTimeout(timer); timer = null; }
                    fire('');
                }
            },
        },
    });
};

// Pretty-print the body if it looks like JSON; otherwise return as-is.
twochain.messengerDashboard.formatBody = function (body) {
    if (body === null || body === undefined || body === '') { return ''; }
    var text = String(body);
    var trimmed = text.trim();
    if (trimmed.length === 0) { return ''; }
    var first = trimmed.charAt(0);
    if (first !== '{' && first !== '[') {
        return text;
    }
    try {
        var parsed = JSON.parse(trimmed);
        if (parsed !== null && (typeof parsed === 'object')) {
            return JSON.stringify(parsed, null, 2);
        }
    } catch (e) { /* not JSON, fall through */ }
    return text;
};

// Open a modal showing all the fields of a message record, with a code-style
// body preview that JSON-pretty-prints when applicable. Used by both the
// transport panel and the failed panel.
twochain.messengerDashboard.showMessageDetail = function (data) {
    var rows = [
        ['messenger_dashboard_col_id', data.id],
        ['messenger_dashboard_col_message_class', data.messageClass],
        ['messenger_dashboard_col_created', twochain.messengerDashboard.formatDateTime(data.createdAt)],
        ['messenger_dashboard_col_retries', data.retryCount],
    ];
    if (data.failureClass) {
        rows.push(['messenger_dashboard_col_failure', data.failureClass]);
    }
    if (data.failureMessage) {
        rows.push(['messenger_dashboard_col_failure_message', data.failureMessage]);
    }

    var renderValue = function (value) {
        if (value === null || value === undefined || value === '') { return '<span class="twochain-empty-inline">—</span>'; }
        return Ext.util.Format.htmlEncode(String(value));
    };

    var fieldsHtml = '<table class="twochain-detail-table">';
    rows.forEach(function (r) {
        fieldsHtml += '<tr>' +
            '<th>' + Ext.util.Format.htmlEncode(twochainT(r[0])) + '</th>' +
            '<td>' + renderValue(r[1]) + '</td>' +
        '</tr>';
    });
    fieldsHtml += '</table>';

    var formatted = twochain.messengerDashboard.formatBody(data.bodyPreview);
    var bodyHtml = '<div class="twochain-detail-section">' +
        '<h4>' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_col_body')) + '</h4>' +
        (formatted
            ? '<pre class="twochain-body-pre">' + Ext.util.Format.htmlEncode(formatted) + '</pre>'
            : '<div class="twochain-empty-inline">—</div>') +
        '</div>';

    var win = new Ext.window.Window({
        title: twochainT('messenger_dashboard_details_title') + (data.id ? ' — ' + data.id : ''),
        width: 760,
        height: 560,
        modal: true,
        layout: 'fit',
        items: [{
            xtype: 'panel',
            border: false,
            bodyStyle: 'padding:14px',
            autoScroll: true,
            html: fieldsHtml + bodyHtml,
        }],
        buttons: [{
            text: twochainT('messenger_dashboard_details_close'),
            handler: function () { win.close(); },
        }],
    });
    win.show();
};

// =====================================================================
// Main dashboard panel — border layout with west sidebar + center detail
// =====================================================================

twochain.messengerDashboard.Dashboard = Ext.extend(Ext.Panel, {
    layout: 'border',

    initComponent: function () {
        this.sidebarStore = new Ext.data.JsonStore({ fields: ['name', 'type', 'capabilities', 'count', 'lastHandledAt'] });

        this.sidebar = new Ext.Panel({
            region: 'west',
            width: 240,
            collapsible: true,
            title: twochainT('messenger_dashboard_transports'),
            layout: 'fit',
            items: this.buildSidebarTree(),
            bbar: [{ xtype: 'tbfill' }, {
                text: twochainT('refresh'),
                iconCls: 'pimcore_icon_refresh',
                handler: this.refreshSidebar.bind(this),
            }],
        });

        this.detail = new Ext.Panel({
            region: 'center',
            layout: 'fit',
            border: false,
            items: [{
                html: '<div class="twochain-empty">' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_select_left')) + '</div>',
                border: false,
            }],
        });

        this.items = [this.sidebar, this.detail];
        twochain.messengerDashboard.Dashboard.superclass.initComponent.call(this);

        this.on('afterrender', function () {
            // Open Statistics by default so the user lands on something
            // useful instead of the empty "Select an entry…" placeholder.
            this.showStats();
            this.selectStatsNode();
            this.refreshSidebar();
        }, this);

        // Poll the sidebar every 10s while the tab is visible.
        this.pollHandle = setInterval(this.refreshSidebar.bind(this), 10000);
        this.on('destroy', function () { clearInterval(this.pollHandle); }, this);
    },

    selectStatsNode: function () {
        if (!this.tree) { return; }
        var root = this.tree.getRootNode();
        if (!root) { return; }
        root.eachChild(function (child) {
            var kind = (child.data && child.data.nodeKind) || (child.raw && child.raw.nodeKind);
            if (kind === 'stats') {
                this.tree.getSelectionModel().select(child);
                return false; // stop iteration
            }
        }, this);
    },

    buildSidebarTree: function () {
        // Flat tree: Statistics + Failed pinned at the top, then transports
        // appended live by refreshSidebar(). No grouping folder.
        this.tree = new Ext.tree.Panel({
            rootVisible: false,
            useArrows: true,
            autoScroll: true,
            border: false,
            store: Ext.create('Ext.data.TreeStore', {
                fields: ['text', 'leaf', 'iconCls', 'expanded', 'cls', 'nodeKind', 'transportName'],
                root: {
                    expanded: true,
                    children: [
                        { text: twochainT('messenger_dashboard_home'),       leaf: true, nodeKind: 'stats',  iconCls: 'pimcore_icon_home' },
                        // Non-clickable section header that visually groups
                        // the failed transport + regular transport nodes
                        // below it.
                        { text: twochainT('messenger_dashboard_transports'), leaf: true, nodeKind: 'section-header', cls: 'twochain-section-header', selectable: false, iconCls: '' },
                        { text: twochainT('messenger_dashboard_failed'),     leaf: true, nodeKind: 'failed', iconCls: 'pimcore_icon_update' },
                    ],
                },
            }),
            listeners: {
                itemclick: this.onNodeClick.bind(this),
            },
        });
        return this.tree;
    },

    refreshSidebar: function () {
        var self = this;
        twochain.messengerDashboard.API.transports(function (transports) {
            if (!transports) { return; }
            self._transports = {};
            self._failedTransport = null;
            transports.forEach(function (t) {
                self._transports[t.name] = t;
                if (t.isFailedTransport) { self._failedTransport = t; }
            });

            var root = self.tree.getRootNode();

            // Total messages in queue across every transport (failed + regular).
            // Skips transports whose count is 'unavailable' (broker offline).
            var totalQueued = 0;
            transports.forEach(function (t) {
                if (typeof t.count === 'number') { totalQueued += t.count; }
            });

            // Relabel the Failed node to the actual failed transport name +
            // count, so the sidebar consistently shows the underlying
            // transport identifier instead of a generic "Failed" label.
            // Also update the section header with the running total queued
            // count across every transport (failed + regular).
            root.eachChild(function (child) {
                var kind = (child.data && child.data.nodeKind) || (child.raw && child.raw.nodeKind);
                if (kind === 'failed') {
                    if (self._failedTransport) {
                        child.set('text', self._failedTransport.name + ' (' + self._failedTransport.count + ')');
                    } else {
                        child.set('text', twochainT('messenger_dashboard_failed'));
                    }
                    // commit() clears the "dirty" flag so the TreeView doesn't
                    // paint the red corner triangle on the cell.
                    child.commit();
                } else if (kind === 'section-header') {
                    child.set('text', twochainT('messenger_dashboard_transports') + ' (' + totalQueued + ')');
                    child.commit();
                }
            });

            // Filter the regular transports list to exclude the failed
            // transport (it already gets its own dedicated sidebar entry).
            // Group supported (listable) above non-listable, alphabetical
            // within each group.
            var sorted = transports
                .filter(function (tr) { return !tr.isFailedTransport; })
                .sort(function (a, b) {
                    var aListable = a.capabilities.canList ? 0 : 1;
                    var bListable = b.capabilities.canList ? 0 : 1;
                    if (aListable !== bListable) { return aListable - bListable; }
                    return a.name.localeCompare(b.name);
                });

            // Strip just the transport rows; keep Statistics + Failed in
            // place so their selection state and styling survive refreshes.
            var transportChildren = [];
            root.eachChild(function (child) {
                var k = (child.data && child.data.nodeKind) || (child.raw && child.raw.nodeKind);
                if (k === 'transport') { transportChildren.push(child); }
            });
            transportChildren.forEach(function (n) { root.removeChild(n, true); });

            sorted.forEach(function (tr) {
                var notSupported = !tr.capabilities.canList;
                var label;
                if (notSupported) {
                    label = twochainT('messenger_dashboard_not_supported');
                } else if (tr.count === 'unavailable') {
                    label = '⚠ ' + twochainT('messenger_dashboard_unavailable');
                } else {
                    label = tr.count;
                }
                root.appendChild({
                    text: tr.name + ' (' + label + ')',
                    iconCls: notSupported ? 'pimcore_icon_encryptedField' : 'pimcore_icon_sql',
                    cls: notSupported ? 'twochain-transport-unsupported' : '',
                    leaf: true,
                    nodeKind: 'transport',
                    transportName: tr.name,
                });
            });
        });
    },

    onNodeClick: function (view, record) {
        var raw = (record && record.raw) || {};
        var data = (record && record.data) || {};
        var kind = data.nodeKind || raw.nodeKind;
        var transportName = data.transportName || raw.transportName;

        if (kind === 'section-header') {
            // Header is decorative; deselect so it doesn't leave the row
            // highlighted after the user clicks it by accident.
            this.tree.getSelectionModel().deselectAll();
            return;
        }
        if (kind === 'failed') { this.showFailed(); return; }
        if (kind === 'stats')  { this.showStats(); return; }
        if (kind === 'transport' && transportName && this._transports && this._transports[transportName]) {
            this.showTransport(this._transports[transportName]);
            return;
        }
    },

    showTransport: function (transport) {
        this.swapDetail(new twochain.messengerDashboard.TransportPanel({
            transport: transport,
            dashboard: this,
        }));
    },
    showFailed: function () {
        this.swapDetail(new twochain.messengerDashboard.FailedPanel({ dashboard: this }));
    },
    showStats: function () {
        this.swapDetail(new twochain.messengerDashboard.StatsPanel({ dashboard: this }));
    },

    // Called by detail panels after destructive operations so the sidebar
    // count reflects the new state without waiting for the next 10s poll.
    refreshAfterMutation: function () {
        this.refreshSidebar();
    },

    swapDetail: function (panel) {
        this.detail.removeAll(true);
        this.detail.add(panel);
        // `fit` layout sizes the single child automatically — no manual
        // doLayout() (removed in ExtJS 7). updateLayout() is enough on
        // versions where the framework hasn't already re-laid out for us.
        if (this.detail.updateLayout) {
            this.detail.updateLayout();
        }
    },
});

// =====================================================================
// Transport panel
// =====================================================================

twochain.messengerDashboard.TransportPanel = Ext.extend(Ext.Panel, {
    layout: 'border',
    border: false,

    initComponent: function () {
        // `t` is the global Pimcore translation function — don't shadow it.
        var info = this.transport;
        var canEdit = twochain.messengerDashboard.Permissions.canEdit();
        this.title = info.name + ' · ' + info.type;

        if (!info.capabilities.canList) {
            // Unsupported transports are treated as fully read-only by the
            // UI — no stats strip, no action buttons, just the notice
            // explaining why. The list capability is the gate for the
            // entire interactive surface; anything that needs the user to
            // pick a row (delete, requeue, inspect) is meaningless without
            // it, and purge against an unseen queue is unsafe.
            var notice = new Ext.Panel({
                border: false,
                bodyStyle: 'padding:18px',
                html: '<div class="twochain-empty">' +
                    Ext.util.Format.htmlEncode(info.type) + ' ' +
                    Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_no_listing')) +
                    '</div>',
            });
            this.layout = 'fit';
            this.items = [notice];
            twochain.messengerDashboard.TransportPanel.superclass.initComponent.call(this);
            return;
        }

        var statsBar = new Ext.Panel({
            region: 'north',
            height: 50,
            border: false,
            bodyStyle: 'padding:8px;',
            html: this.renderStatsBar(info),
        });

        this.store = twochain.messengerDashboard.buildPagedStore(
            '/admin/messenger-dashboard/transports/' + encodeURIComponent(info.name) + '/messages'
        );

        this.selModel = new Ext.selection.CheckboxModel({ mode: 'MULTI' });

        var transportStore = this.store;
        this.searchField = twochain.messengerDashboard.buildSearchField(function (q) {
            var proxy = transportStore.getProxy();
            if (q) {
                proxy.setExtraParam('q', q);
            } else {
                var params = proxy.getExtraParams() || {};
                delete params.q;
                proxy.setExtraParams(params);
            }
            transportStore.loadPage(1);
        });

        this.grid = new Ext.grid.Panel({
            region: 'center',
            border: false,
            store: this.store,
            selModel: this.selModel,
            bbar: twochain.messengerDashboard.buildPagingBar(this.store),
            columns: [
                { text: twochainT('messenger_dashboard_col_id'), dataIndex: 'id', width: 90 },
                { text: twochainT('messenger_dashboard_col_message_class'), dataIndex: 'messageClass', flex: 2 },
                {
                    text: twochainT('messenger_dashboard_col_body'),
                    dataIndex: 'bodyPreview',
                    flex: 3,
                    renderer: twochain.messengerDashboard.bodyCellRenderer,
                },
                { text: twochainT('messenger_dashboard_col_created'), dataIndex: 'createdAt', width: 160, renderer: twochain.messengerDashboard.dateCellRenderer },
                { text: twochainT('messenger_dashboard_col_retries'), dataIndex: 'retryCount', width: 70, align: 'right' },
                {
                    xtype: 'actioncolumn',
                    width: 60,
                    items: [{
                        iconCls: 'pimcore_icon_info',
                        tooltip: twochainT('messenger_dashboard_action_details'),
                        handler: this.showDetail.bind(this),
                    }, {
                        iconCls: 'pimcore_icon_delete',
                        tooltip: twochainT('messenger_dashboard_action_delete'),
                        handler: this.deleteRow.bind(this),
                        isDisabled: function () { return !canEdit; },
                    }],
                },
            ],
            tbar: [{
                text: twochainT(info.capabilities.canBulkDelete ? 'messenger_dashboard_btn_delete_selected' : 'messenger_dashboard_btn_delete_selected_unsupported'),
                iconCls: 'pimcore_icon_delete',
                disabled: !canEdit || !info.capabilities.canBulkDelete,
                handler: this.bulkDelete.bind(this),
            }, {
                text: twochainT(info.capabilities.canPurge ? 'messenger_dashboard_btn_delete_all_transport' : 'messenger_dashboard_btn_delete_all_unsupported'),
                iconCls: 'pimcore_icon_delete',
                disabled: !canEdit || !info.capabilities.canPurge,
                handler: this.purge.bind(this),
            }, { xtype: 'tbfill' }, this.searchField, {
                iconCls: 'pimcore_icon_refresh',
                tooltip: twochainT('refresh'),
                handler: this.reload.bind(this),
            }],
        });

        // Search-aware empty state: when the store loads zero rows, show a
        // tailored message if `q` is set, otherwise fall back to the grid
        // view's existing emptyText. The view caches the rendered template,
        // so we must call refresh() after mutating emptyText.
        var transportGrid = this.grid;
        var transportDefaultEmptyText = null;
        this.store.on('load', function (loadedStore) {
            if (loadedStore.getCount() !== 0) { return; }
            var view = transportGrid.getView();
            if (!view) { return; }
            if (transportDefaultEmptyText === null) {
                transportDefaultEmptyText = view.emptyText || '';
            }
            var qParam = (loadedStore.getProxy().getExtraParams() || {}).q;
            var msg = qParam
                ? twochainT('messenger_dashboard_search_no_matches')
                : transportDefaultEmptyText;
            view.emptyText = '<div style="padding:24px;color:#999;">' +
                Ext.util.Format.htmlEncode(msg) + '</div>';
            view.refresh();
        });

        this.items = [statsBar, this.grid];
        twochain.messengerDashboard.TransportPanel.superclass.initComponent.call(this);

        this.on('afterrender', function () { this.store.loadPage(1); }, this);
    },

    renderStatsBar: function (info) {
        var lh = info.lastHandledAt
            ? Ext.util.Format.htmlEncode(twochain.messengerDashboard.formatDateTime(info.lastHandledAt))
            : '—';
        var count = info.count === 'unavailable'
            ? '<span style="color:#b8362d">⚠ ' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_unavailable')) + '</span>'
            : '<strong>' + info.count + '</strong>';
        return '<div class="twochain-statsbar">' +
            '<span>' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_pending')) + ': ' + count + '</span>' +
            '<span style="margin-left:18px">' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_last_processed')) + ': ' + lh + '</span>' +
            '</div>';
    },

    reload: function () {
        this.store.reload();
    },

    showDetail: function (gridView, rowIndex) {
        var rec = this.store.getAt(rowIndex);
        if (!rec) { return; }
        twochain.messengerDashboard.showMessageDetail(rec.data);
    },

    afterMutation: function () {
        this.reload();
        if (this.dashboard && this.dashboard.refreshAfterMutation) {
            this.dashboard.refreshAfterMutation();
        }
    },

    deleteRow: function (gridView, rowIndex) {
        var rec = this.store.getAt(rowIndex);
        if (!rec) { return; }
        var self = this;
        Ext.MessageBox.confirm(
            twochainT('messenger_dashboard_confirm_delete_message_title'),
            twochainTI('messenger_dashboard_confirm_delete_message_body', { id: rec.get('id') }),
            function (btn) {
                if (btn !== 'yes') { return; }
                twochain.messengerDashboard.API.deleteMessage(self.transport.name, rec.get('id'), function () {
                    self.afterMutation();
                    pimcore.helpers.showNotification(twochainT('success'), twochainT('messenger_dashboard_notify_message_deleted'), 'success');
                });
            }
        );
    },

    bulkDelete: function () {
        var ids = this.selModel.getSelection().map(function (r) { return r.get('id'); });
        if (ids.length === 0) {
            pimcore.helpers.showNotification(twochainT('warning'), twochainT('messenger_dashboard_notify_select_one'), 'info');
            return;
        }
        var self = this;
        Ext.MessageBox.confirm(
            twochainTI('messenger_dashboard_confirm_delete_n_title', { n: ids.length }),
            twochainT('messenger_dashboard_confirm_cannot_undo'),
            function (btn) {
                if (btn !== 'yes') { return; }
                twochain.messengerDashboard.API.bulkDelete(self.transport.name, { ids: ids }, function (res) {
                    self.afterMutation();
                    pimcore.helpers.showNotification(
                        twochainT('success'),
                        twochainTI('messenger_dashboard_notify_delete_summary', {
                            processed: res ? res.processed : 0,
                            failed: (res && res.failed) ? res.failed.length : 0,
                        }),
                        'success'
                    );
                });
            }
        );
    },

    purge: function () {
        var self = this;
        Ext.MessageBox.confirm(
            twochainTI('messenger_dashboard_confirm_delete_all_title', { transport: this.transport.name }),
            twochainT('messenger_dashboard_confirm_cannot_undo'),
            function (btn) {
                if (btn !== 'yes') { return; }
                twochain.messengerDashboard.API.bulkDelete(self.transport.name, { all: true }, function (res) {
                    self.afterMutation();
                    pimcore.helpers.showNotification(
                        twochainT('success'),
                        twochainTI('messenger_dashboard_notify_n_deleted', { n: res ? res.processed : 0 }),
                        'success'
                    );
                });
            }
        );
    },
});

// =====================================================================
// Failed panel
// =====================================================================

twochain.messengerDashboard.FailedPanel = Ext.extend(Ext.Panel, {
    layout: 'fit',
    border: false,

    initComponent: function () {
        var canEdit = twochain.messengerDashboard.Permissions.canEdit();
        var self = this;
        this.store = twochain.messengerDashboard.buildPagedStore('/admin/messenger-dashboard/failed/messages');
        this.selModel = new Ext.selection.CheckboxModel({ mode: 'MULTI' });

        // Free-text search across id / message class / body / failure
        // class / failure message. Coexists with the classFilter combobox:
        // both write into the store's extraParams independently, so a
        // reload triggered by either keeps the other's filter intact.
        var failedStore = this.store;
        this.searchField = twochain.messengerDashboard.buildSearchField(function (q) {
            var proxy = failedStore.getProxy();
            if (q) {
                proxy.setExtraParam('q', q);
            } else {
                var params = proxy.getExtraParams() || {};
                delete params.q;
                proxy.setExtraParams(params);
            }
            failedStore.loadPage(1);
        });

        // Class-filter combobox. Reloads from /failed/message-classes on
        // first render + after every mutation so the dropdown reflects what
        // is actually in the failed transport right now.
        this.classFilterStore = Ext.create('Ext.data.Store', {
            fields: ['value', 'label'],
            data: [{ value: '', label: twochainT('messenger_dashboard_filter_all_classes') }],
        });
        this.classFilter = new Ext.form.field.ComboBox({
            store: this.classFilterStore,
            displayField: 'label',
            valueField: 'value',
            value: '',
            editable: false,
            forceSelection: true,
            queryMode: 'local',
            width: 360,
            fieldLabel: twochainT('messenger_dashboard_filter_class_label'),
            labelWidth: 110,
            listeners: {
                select: function (combo) {
                    var v = combo.getValue() || '';
                    var proxy = self.store.getProxy();
                    if (v) {
                        proxy.setExtraParam('messageClass', v);
                    } else {
                        // Drop the param so the URL stays clean when "All".
                        var params = proxy.getExtraParams() || {};
                        delete params.messageClass;
                        proxy.setExtraParams(params);
                    }
                    self.store.loadPage(1);
                },
            },
        });

        this.grid = new Ext.grid.Panel({
            border: false,
            store: this.store,
            selModel: this.selModel,
            bbar: twochain.messengerDashboard.buildPagingBar(this.store),
            columns: [
                { text: twochainT('messenger_dashboard_col_id'), dataIndex: 'id', width: 90 },
                { text: twochainT('messenger_dashboard_col_class'), dataIndex: 'messageClass', flex: 2 },
                {
                    text: twochainT('messenger_dashboard_col_body'),
                    dataIndex: 'bodyPreview',
                    flex: 2,
                    renderer: twochain.messengerDashboard.bodyCellRenderer,
                },
                { text: twochainT('messenger_dashboard_col_failure'), dataIndex: 'failureClass', flex: 1 },
                {
                    text: twochainT('messenger_dashboard_col_failure_message'),
                    dataIndex: 'failureMessage',
                    flex: 2,
                    renderer: twochain.messengerDashboard.bodyCellRenderer,
                },
                { text: twochainT('messenger_dashboard_col_retries'), dataIndex: 'retryCount', width: 70, align: 'right' },
                {
                    xtype: 'actioncolumn',
                    width: 90,
                    items: [
                        { iconCls: 'pimcore_icon_info',    tooltip: twochainT('messenger_dashboard_action_details'), handler: this.showDetail.bind(this) },
                        { iconCls: 'pimcore_icon_refresh', tooltip: twochainT('messenger_dashboard_action_requeue'), handler: this.requeueRow.bind(this), isDisabled: function () { return !canEdit; } },
                        { iconCls: 'pimcore_icon_delete',  tooltip: twochainT('messenger_dashboard_action_delete'),  handler: this.deleteRow.bind(this),  isDisabled: function () { return !canEdit; } },
                    ],
                },
            ],
            dockedItems: [
                {
                    xtype: 'toolbar',
                    dock: 'top',
                    items: [
                        { text: twochainT('messenger_dashboard_btn_requeue_selected'), iconCls: 'pimcore_icon_refresh', disabled: !canEdit, handler: this.bulkRequeue.bind(this) },
                        { text: twochainT('messenger_dashboard_btn_requeue_all'),      iconCls: 'pimcore_icon_refresh', disabled: !canEdit, handler: this.requeueAll.bind(this) },
                        '-',
                        { text: twochainT('messenger_dashboard_btn_delete_selected'),  iconCls: 'pimcore_icon_delete',  disabled: !canEdit, handler: this.bulkDelete.bind(this) },
                        { text: twochainT('messenger_dashboard_btn_delete_all'),       iconCls: 'pimcore_icon_delete',  disabled: !canEdit, handler: this.deleteAll.bind(this) },
                        { xtype: 'tbfill' },
                        { iconCls: 'pimcore_icon_refresh', tooltip: twochainT('refresh'), handler: this.reload.bind(this) },
                    ],
                },
                {
                    xtype: 'toolbar',
                    dock: 'top',
                    items: [
                        this.searchField,
                        { xtype: 'tbfill' },
                        this.classFilter,
                    ],
                },
            ],
        });

        // Search-aware empty state: mirror the transport panel's behavior.
        // The message changes when `q` is set; the existing emptyText is
        // captured on the first empty load so we can restore it later.
        var failedGrid = this.grid;
        var failedDefaultEmptyText = null;
        this.store.on('load', function (loadedStore) {
            if (loadedStore.getCount() !== 0) { return; }
            var view = failedGrid.getView();
            if (!view) { return; }
            if (failedDefaultEmptyText === null) {
                failedDefaultEmptyText = view.emptyText || '';
            }
            var qParam = (loadedStore.getProxy().getExtraParams() || {}).q;
            var msg = qParam
                ? twochainT('messenger_dashboard_search_no_matches')
                : failedDefaultEmptyText;
            view.emptyText = '<div style="padding:24px;color:#999;">' +
                Ext.util.Format.htmlEncode(msg) + '</div>';
            view.refresh();
        });

        this.items = [this.grid];
        twochain.messengerDashboard.FailedPanel.superclass.initComponent.call(this);
        this.on('afterrender', function () {
            this.store.loadPage(1);
            this.refreshClassFilter();
        }, this);
    },

    reload: function () {
        this.store.reload();
    },

    refreshClassFilter: function () {
        var self = this;
        twochain.messengerDashboard.API.failedClasses(function (data) {
            if (!data || !Array.isArray(data.classes)) { return; }
            var current = self.classFilter ? self.classFilter.getValue() : '';
            var entries = [{ value: '', label: twochainT('messenger_dashboard_filter_all_classes') }];
            data.classes.forEach(function (cls) { entries.push({ value: cls, label: cls }); });
            self.classFilterStore.loadData(entries);
            // Preserve the user's current selection across reloads; fall
            // back to "All classes" if the previously-selected class is no
            // longer present (e.g. last instance got deleted).
            var stillPresent = current === '' || data.classes.indexOf(current) !== -1;
            self.classFilter.setValue(stillPresent ? current : '');
        });
    },

    afterMutation: function () {
        this.reload();
        this.refreshClassFilter();
        if (this.dashboard && this.dashboard.refreshAfterMutation) {
            this.dashboard.refreshAfterMutation();
        }
    },

    showDetail: function (gridView, rowIndex) {
        var rec = this.store.getAt(rowIndex);
        if (!rec) { return; }
        twochain.messengerDashboard.showMessageDetail(rec.data);
    },

    deleteRow: function (gridView, rowIndex) {
        var rec = this.store.getAt(rowIndex);
        if (!rec) { return; }
        var self = this;
        Ext.MessageBox.confirm(
            twochainT('messenger_dashboard_confirm_delete_failed_title'),
            twochainTI('messenger_dashboard_confirm_delete_message_body', { id: rec.get('id') }),
            function (btn) {
                if (btn !== 'yes') { return; }
                twochain.messengerDashboard.API.deleteFailed(rec.get('id'), function () {
                    self.afterMutation();
                });
            }
        );
    },
    requeueRow: function (gridView, rowIndex) {
        var rec = this.store.getAt(rowIndex);
        if (!rec) { return; }
        var self = this;
        twochain.messengerDashboard.API.requeueFailed(rec.get('id'), function () {
            self.afterMutation();
            pimcore.helpers.showNotification(twochainT('success'), twochainT('messenger_dashboard_notify_message_requeued'), 'success');
        });
    },
    bulkRequeue: function () {
        var ids = this.selModel.getSelection().map(function (r) { return r.get('id'); });
        if (ids.length === 0) {
            pimcore.helpers.showNotification(twochainT('warning'), twochainT('messenger_dashboard_notify_select_one'), 'info');
            return;
        }
        var self = this;
        twochain.messengerDashboard.API.bulkRequeueFailed({ ids: ids }, function (res) {
            self.afterMutation();
            pimcore.helpers.showNotification(
                twochainT('success'),
                twochainTI('messenger_dashboard_notify_n_requeued', { n: res ? res.processed : 0 }),
                'success'
            );
        });
    },
    requeueAll: function () {
        var self = this;
        Ext.MessageBox.confirm(
            twochainT('messenger_dashboard_confirm_requeue_all_title'),
            twochainT('messenger_dashboard_confirm_requeue_all_body'),
            function (btn) {
                if (btn !== 'yes') { return; }
                twochain.messengerDashboard.API.bulkRequeueFailed({ all: true }, function (res) {
                    self.afterMutation();
                    pimcore.helpers.showNotification(
                        twochainT('success'),
                        twochainTI('messenger_dashboard_notify_n_requeued', { n: res ? res.processed : 0 }),
                        'success'
                    );
                });
        });
    },
    bulkDelete: function () {
        var ids = this.selModel.getSelection().map(function (r) { return r.get('id'); });
        if (ids.length === 0) {
            pimcore.helpers.showNotification(twochainT('warning'), twochainT('messenger_dashboard_notify_select_one'), 'info');
            return;
        }
        var self = this;
        Ext.MessageBox.confirm(
            twochainTI('messenger_dashboard_confirm_delete_n_title', { n: ids.length }),
            twochainT('messenger_dashboard_confirm_cannot_undo'),
            function (btn) {
                if (btn !== 'yes') { return; }
                twochain.messengerDashboard.API.bulkDeleteFailed({ ids: ids }, function (res) {
                    self.afterMutation();
                    pimcore.helpers.showNotification(
                        twochainT('success'),
                        twochainTI('messenger_dashboard_notify_delete_summary', {
                            processed: res ? res.processed : 0,
                            failed: (res && res.failed) ? res.failed.length : 0,
                        }),
                        'success'
                    );
                });
            }
        );
    },
    deleteAll: function () {
        var self = this;
        Ext.MessageBox.confirm(
            twochainT('messenger_dashboard_confirm_delete_all_failed_title'),
            twochainT('messenger_dashboard_confirm_cannot_undo'),
            function (btn) {
                if (btn !== 'yes') { return; }
                twochain.messengerDashboard.API.bulkDeleteFailed({ all: true }, function () { self.afterMutation(); });
            }
        );
    },
});

// =====================================================================
// Statistics panel
// =====================================================================

twochain.messengerDashboard.StatsPanel = Ext.extend(Ext.Panel, {
    layout: 'fit',
    border: false,
    bodyStyle: 'padding:14px; overflow:auto;',

    initComponent: function () {
        this.items = [{
            html: '<div class="twochain-empty">' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_loading_stats')) + '</div>',
            border: false,
        }];
        twochain.messengerDashboard.StatsPanel.superclass.initComponent.call(this);
        this.on('afterrender', this.reload, this);
    },

    reload: function () {
        var self = this;
        // Fetch BOTH transports (for capability info) and stats so we can
        // sort unsupported rows to the bottom and label them appropriately.
        twochain.messengerDashboard.API.transports(function (transports) {
            twochain.messengerDashboard.API.stats(function (stats) {
                if (!stats || !transports) { return; }
                self.removeAll(true);
                self.add({ html: self.renderIntro() + self.renderTable(stats, transports), border: false });
                if (self.updateLayout) { self.updateLayout(); }
            });
        });
    },

    renderIntro: function () {
        var readmeHref = 'https://github.com/2-chain/pimcore-messenger-dashboard';
        return '<div class="twochain-home-intro">' +
            '<h1>' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard')) + '</h1>' +
            '<p>' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_intro_body')) + '</p>' +
            '<p><a href="' + readmeHref + '" target="_blank" rel="noopener">' +
            Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_intro_readme')) +
            '</a></p>' +
            '</div>' +
            '<h2 class="twochain-home-section">' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_statistics')) + '</h2>' +
            '<p class="twochain-home-subtitle">' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_statistics_subtitle')) + '</p>';
    },

    renderTable: function (stats, transports) {
        // Index transports by name for capability + count lookups.
        var byName = {};
        transports.forEach(function (tr) { byName[tr.name] = tr; });

        // Order rows: supported (canList) first, unsupported at the bottom;
        // alphabetical within each group.
        var names = transports.slice().sort(function (a, b) {
            var aListable = a.capabilities.canList ? 0 : 1;
            var bListable = b.capabilities.canList ? 0 : 1;
            if (aListable !== bListable) { return aListable - bListable; }
            return a.name.localeCompare(b.name);
        }).map(function (tr) { return tr.name; });

        var notSupported = Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_not_supported'));

        var renderInQueue = function (tr) {
            if (tr.count === 'unavailable') {
                return '<span style="color:#b8362d">⚠ ' +
                    Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_unavailable')) +
                    '</span>';
            }
            return '<strong>' + tr.count + '</strong>';
        };

        var rows = '';
        var totals = {
            count: 0,
            '1d': { handled: 0, failed: 0 },
            '3d': { handled: 0, failed: 0 },
            '7d': { handled: 0, failed: 0 },
        };
        names.forEach(function (name) {
            var s = stats[name] || {};
            var tr = byName[name] || { capabilities: { canList: true }, count: 0 };
            if (!tr.capabilities.canList) {
                // Unsupported transports collapse every value column (in-queue
                // count + time windows + last-processed) into a single
                // "not supported" cell. We never show partial info for them.
                rows += '<tr class="twochain-stats-unsupported">' +
                    '<td>' + Ext.util.Format.htmlEncode(name) + '</td>' +
                    '<td colspan="5" class="twochain-empty-inline">' + notSupported + '</td>' +
                    '</tr>';
                return;
            }
            // Skip 'unavailable' (broker unreachable) from the in-queue sum;
            // handled/failed counts for time windows come from the audit
            // table and are always numeric.
            if (typeof tr.count === 'number') { totals.count += tr.count; }
            ['1d', '3d', '7d'].forEach(function (w) {
                if (s[w]) {
                    totals[w].handled += s[w].handled || 0;
                    totals[w].failed += s[w].failed || 0;
                }
            });
            var last = s.lastHandledAt
                ? Ext.util.Format.htmlEncode(twochain.messengerDashboard.formatDateTime(s.lastHandledAt))
                : '—';
            var fmt = function (w) {
                if (!s[w]) { return '<td>—</td>'; }
                return '<td><strong>' + s[w].handled + '</strong> <span style="color:#888">/ ' + s[w].failed + '</span></td>';
            };
            rows += '<tr>' +
                '<td>' + Ext.util.Format.htmlEncode(name) + '</td>' +
                '<td>' + renderInQueue(tr) + '</td>' +
                fmt('1d') + fmt('3d') + fmt('7d') +
                '<td>' + last + '</td>' +
            '</tr>';
        });
        var fmtTotal = function (w) {
            return '<td><strong>' + totals[w].handled + '</strong> <span style="color:#888">/ ' + totals[w].failed + '</span></td>';
        };
        var totalRow = '<tr class="twochain-stats-total">' +
            '<td>' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_stats_total')) + '</td>' +
            '<td><strong>' + totals.count + '</strong></td>' +
            fmtTotal('1d') + fmtTotal('3d') + fmtTotal('7d') +
            '<td></td>' +
            '</tr>';
        var headers = '<thead><tr>' +
            '<th>' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_transports')) + '</th>' +
            '<th>' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_col_in_queue')) + '</th>' +
            '<th>' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_col_1d')) + '</th>' +
            '<th>' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_col_3d')) + '</th>' +
            '<th>' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_col_7d')) + '</th>' +
            '<th>' + Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_last_processed')) + '</th>' +
            '</tr></thead>';
        var empty = '<tr><td colspan="6" class="twochain-empty">' +
            Ext.util.Format.htmlEncode(twochainT('messenger_dashboard_no_stats')) + '</td></tr>';
        var foot = rows ? '<tfoot>' + totalRow + '</tfoot>' : '';
        return '<table class="twochain-stats-table">' + headers + '<tbody>' + (rows || empty) + '</tbody>' + foot + '</table>';
    },
});
