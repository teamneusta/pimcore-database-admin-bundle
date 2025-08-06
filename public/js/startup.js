document.addEventListener(pimcore.events.preMenuBuild, (e) => {
    if (!pimcore.globalmanager.get('user').admin) {
        return;
    }

    if (!pimcore.globalmanager.get("perspective").inToolbar("extras.systemtools.database")) {
        return;
    }

    e.detail.menu.extras.items.find(item => item.itemId === 'pimcore_menu_extras_system_info').menu.items.push({
        text: t("neusta_database_admin_database_administration"),
        iconCls: "pimcore_nav_icon_mysql",
        itemId: 'neusta_database_admin_menu_extras_system_info_database_administration',
        handler: function () {
            pimcore.helpers.openGenericIframeWindow(
                "neusta_database_admin",
                Routing.generate('neusta_database_admin_adminer_index'),
                "pimcore_icon_mysql",
                t("neusta_database_admin_database_administration"),
            );
        },
    });
});
