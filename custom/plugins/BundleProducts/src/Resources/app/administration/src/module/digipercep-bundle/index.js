import './page/digipercep-bundle-list';
import './page/digipercep-bundle-detail';
import './page/digipercep-bundle-create';
import './view/digipercep-bundle-detail-base';
import './view/digipercep-bundle-detail-products';
import './component/digipercep-product-bundle-assignment';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

const { Module } = Shopware;

Module.register('digipercep-bundle', {
    type: 'plugin',
    name: 'DigiPercep Bundle Products',
    title: 'digipercep-bundle.general.mainMenuItemGeneral',
    color: '#ff3d58',
    icon: 'regular-shopping-bag',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB
    },

    routes: {
        list: {
            component: 'digipercep-bundle-list',
            path: 'list',
            meta: {
                privilege: 'bundle:read'
            }
        },

        create: {
            component: 'digipercep-bundle-create',
            path: 'create',
            meta: {
                privilege: 'bundle:create'
            }
        },

        detail: {
            component: 'digipercep-bundle-detail',
            path: 'detail/:id',
            meta: {
                privilege: 'bundle:read',
                parentPath: 'digipercep.bundle.list'
            },
            redirect: {
                name: 'digipercep.bundle.detail.base'
            },
            children: {
                base: {
                    component: 'digipercep-bundle-detail-base',
                    path: 'base',
                    meta: {
                        privilege: 'bundle:read',
                        parentPath: 'digipercep.bundle.list'
                    }
                },

                products: {
                    component: 'digipercep-bundle-detail-products',
                    path: 'products',
                    meta: {
                        privilege: 'bundle:read',
                        parentPath: 'digipercep.bundle.list'
                    }
                }
            }
        }
    },

    navigation: [{
        label: 'digipercep-bundle.general.mainMenuItemGeneral',
        color: '#ff3d58',
        path: 'digipercep.bundle.list',
        icon: 'default-package-closed',
        parent: 'sw-catalogue',
        position: 100
    }],

    settingsItem: {
        group: 'plugins',
        to: 'digipercep.bundle.list',
        icon: 'regular-shopping-bag',
        privilege: 'bundle:read'
    }
});