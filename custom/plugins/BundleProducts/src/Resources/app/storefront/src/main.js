import BundleWidgetPlugin from './plugin/bundle-widget.plugin';

const PluginManager = window.PluginManager;

// Register main bundle widget for product pages
PluginManager.register('BundleWidget', BundleWidgetPlugin, '[data-bundle-widget]');

// IMMEDIATELY define the global function (before DOM loads)
window.miniCartBundleDetails = function(bundleId, button, itemCount) {
    const details = document.getElementById('bundle-details-' + bundleId);
    if (!details) {
        console.warn('Bundle details element not found for bundle ID:', bundleId);
        return;
    }
    if (details.classList.contains('show')) {
        details.classList.remove('show');
        button.textContent = 'Show ' + itemCount + ' included items';
    } else {
        details.classList.add('show');
        button.textContent = 'Hide contents';
    }
};
