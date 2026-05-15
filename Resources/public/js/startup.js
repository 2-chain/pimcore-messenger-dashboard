/* global pimcore, Ext */

pimcore.registerNS('pimcore.bundle.twochain_messenger_dashboard.startup');

pimcore.bundle.twochain_messenger_dashboard.startup = Class.create({
    initialize: function () {
        document.addEventListener(pimcore.events.preMenuBuild, this.preMenuBuild.bind(this));
    },

    preMenuBuild: function (event) {
        var menu = event.detail.menu;
        var user = pimcore.globalmanager.get('user');

        var canView = user.admin || (user.permissions && (
            user.permissions.indexOf('messenger_dashboard_view') !== -1 ||
            user.permissions.indexOf('messenger_dashboard_edit') !== -1
        ));
        if (!canView) {
            return;
        }

        // Add an entry to the Tools (= "extras") menu. Pimcore's preMenuBuild
        // hands us the full menu structure; we just append our item.
        if (!menu.extras || !Array.isArray(menu.extras.items)) {
            return;
        }

        // twochainT is defined in dashboard.js (loaded before startup.js per
        // the bundle's getJsPaths()). Falls back to the key string if the
        // translation isn't seeded yet.
        menu.extras.items.push({
            text: twochainT('messenger_dashboard'),
            iconCls: 'pimcore_nav_icon_notifications',
            itemId: 'twochain_menu_messenger_dashboard',
            priority: 100,
            handler: this.openDashboard.bind(this),
        });
    },

    openDashboard: function () {
        var existing = Ext.getCmp('twochain_messenger_dashboard_tab');
        if (existing) {
            pimcore.helpers.openMainTab(existing);
            return;
        }
        var tab = new twochain.messengerDashboard.Dashboard({
            id: 'twochain_messenger_dashboard_tab',
            title: twochainT('messenger_dashboard'),
            iconCls: 'pimcore_nav_icon_notifications',
            border: false,
            closable: true,
        });
        var tabPanel = Ext.getCmp('pimcore_panel_tabs');
        tabPanel.add(tab);
        tabPanel.setActiveTab(tab);
        tabPanel.updateLayout();
    },
});

new pimcore.bundle.twochain_messenger_dashboard.startup();
