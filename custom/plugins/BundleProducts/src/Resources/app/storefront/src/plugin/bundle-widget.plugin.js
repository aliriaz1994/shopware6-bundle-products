// Updated bundle-widget.plugin.js - Following Shopware Core Standards with Cart Page Refresh Fix

import Plugin from 'src/plugin-system/plugin.class';
import DomAccess from 'src/helper/dom-access.helper';
import HttpClient from 'src/service/http-client.service';
import FormSerializeUtil from 'src/utility/form/form-serialize.util';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';
import Iterator from 'src/helper/iterator.helper';

export default class BundleWidgetPlugin extends Plugin {
    static options = {
        addToCartUrl: '/bundle/add-to-cart',
        removeFromCartUrl: '/bundle/remove-from-cart',
        cartInfoUrl: '/bundle/cart-info',
        bundleContainerSelector: '.new-bundle-container, .digipercep-bundle-container',
        bundleFormSelector: 'form[data-bundle-form="true"], form.new-bundle-form, form.bundle-main-form',
        bundleDetailsToggleSelector: '.toggle-details',

        // UPDATED: Separate selectors for different contexts
        bundleRemoveSelector: '.bundle-remove-btn', // offcanvas - minicart
        bundleRemoveStandardSelector: '.bundle-remove-standard', // Checkout cart page

        bundleToggleSelector: '.bundle-contents-toggle, [data-bundle-toggle]',
        bundleItemSelector: '.bundle-item',
        requestDelay: 300,
        bundleButtonSelectors: [
            'button[type="submit"].new-bundle-submit',
            'button[type="submit"].bundle-submit',
            'form[data-bundle-form] button[type="submit"]',
            'form.new-bundle-form button[type="submit"]',
            'form.bundle-main-form button[type="submit"]'
        ]
    };

    init() {
        this.client = new HttpClient();
        this._lastRequestTime = 0;
        this._registerEvents();
        this._initBundles();
    }

    _initBundles() {
        const bundles = DomAccess.querySelectorAll(this.el, this.options.bundleContainerSelector, false);

        this._cleanupAllButtonStates();

        if (bundles.length) {
            Iterator.iterate(bundles, (bundle, index) => this._initBundle(bundle, index));
        } else if (this._isBundleContainer(this.el)) {
            this._initBundle(this.el, 0);
        }

        this.$emitter.publish('bundlesInitialized', { count: bundles.length });
    }

    _cleanupAllButtonStates() {
        const buttons = document.querySelectorAll(this.options.bundleButtonSelectors.join(', '));
        Iterator.iterate(buttons, button => this._cleanupButtonState(button));
    }

    _isBundleContainer(element) {
        return element.classList.contains('new-bundle-container') ||
            element.classList.contains('digipercep-bundle-container');
    }

    _initBundle(bundleElement, index) {
        const bundleId = this._extractBundleId(bundleElement);
        if (!bundleId) return;

        this._disableFormActions(bundleElement);
        this._registerBundleEvents(bundleElement, bundleId);
        this.$emitter.publish('bundleInitialized', { bundleId, index });
    }

    _disableFormActions(bundleElement) {
        const forms = DomAccess.querySelectorAll(bundleElement, this.options.bundleFormSelector, false);

        if (forms) {
            Iterator.iterate(forms, form => {
                if (form.action && form.action !== 'javascript:void(0)') {
                    form.dataset.originalAction = form.action;
                    form.setAttribute('action', 'javascript:void(0)');
                }
                form.setAttribute('method', 'post');
                form.setAttribute('onsubmit', 'return false;');
            });
        }
    }

    _extractBundleId(bundleElement) {
        if (bundleElement.dataset.bundleId) return bundleElement.dataset.bundleId;

        const idMatch = bundleElement.id?.match(/bundle-container-(.+)/);
        if (idMatch) return idMatch[1];

        const bundleIdInput = bundleElement.querySelector('input[name="bundle-id"]');
        return bundleIdInput?.value || null;
    }

    _registerEvents() {
        this._registerGlobalBundleEvents();
        this.$emitter.publish('eventsRegistered');
    }

    _registerBundleEvents(bundleElement, bundleId) {
        this._registerBundleFormEvents(bundleElement, bundleId);
        this._registerBundleDetailsEvents(bundleElement, bundleId);
    }

    _registerBundleFormEvents(bundleElement, bundleId) {
        const forms = DomAccess.querySelectorAll(bundleElement, this.options.bundleFormSelector, false);

        if (forms) {
            Iterator.iterate(forms, form => {
                const submitHandler = (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();

                    if (event.target) {
                        event.target.setAttribute('action', 'javascript:void(0)');
                    }

                    this._onBundleFormSubmit(form, bundleId, event);
                };

                form.addEventListener('submit', submitHandler);

                const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
                Iterator.iterate(submitButtons, button => {
                    this._cleanupButtonState(button);
                    button.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        event.stopImmediatePropagation();

                        const formEvent = new Event('submit', { bubbles: true, cancelable: true });
                        formEvent.sourceButton = button;
                        submitHandler(formEvent);
                    });
                });
            });
        }
    }

    _cleanupButtonState(button) {
        if (!button) return;

        button.classList.remove('btn-loading');
        delete button.dataset.originalText;
        delete button.dataset.originalDisabled;

        button.textContent = this._getCleanButtonText(button);
        button.disabled = false;
    }

    _registerBundleDetailsEvents(bundleElement, bundleId) {
        const toggleButtons = DomAccess.querySelectorAll(bundleElement, this.options.bundleDetailsToggleSelector, false);

        if (toggleButtons) {
            Iterator.iterate(toggleButtons, button => {
                button.addEventListener('click', this._onToggleBundleDetails.bind(this, bundleId));
            });
        }
    }

    _registerGlobalBundleEvents() {
        document.addEventListener('click', this._onDocumentClick.bind(this));
        document.addEventListener('cart-updated', this._onCartUpdated.bind(this));
        document.addEventListener('cart-manually-refreshed', this._onCartUpdated.bind(this));
    }

    _onDocumentClick(event) {
        // ONLY handle offcanvas bundle removal (AJAX) - don't interfere with cart page
        if (event.target.closest(this.options.bundleRemoveSelector)) {
            // Check if we're in offcanvas context - only then handle with AJAX
            const offcanvasContext = event.target.closest('.offcanvas-cart') ||
                event.target.closest('.js-offcanvas-cart') ||
                document.querySelector('.offcanvas-cart.show');

            if (offcanvasContext) {
                this._onBundleRemove(event);
            }
            // If not in offcanvas, let it submit normally (cart page)
        }
        // Handle bundle toggle in cart
        else if (event.target.closest(this.options.bundleToggleSelector)) {
            this._onBundleToggleInCart(event);
        }
        // Don't handle .bundle-remove-standard at all - let Shopware handle it naturally
    }

    _onBundleFormSubmit(form, bundleId, event) {
        event?.preventDefault();
        event?.stopPropagation();
        event?.stopImmediatePropagation();

        if (form.action && form.action !== 'javascript:void(0)') {
            form.setAttribute('action', 'javascript:void(0)');
        }

        this.$emitter.publish('beforeBundleAddToCart', { bundleId, form });

        const submitButton = event?.sourceButton || form.querySelector('button[type="submit"], input[type="submit"]');
        const requestData = this._prepareBundleRequestData(form, bundleId);

        if (this._lastRequestTime && Date.now() - this._lastRequestTime < this.options.requestDelay) {
            return false;
        }

        this._lastRequestTime = Date.now();

        // Use Shopware's standard pattern - open offcanvas directly
        this._openOffCanvasCart(this.options.addToCartUrl, requestData, submitButton);

        return false;
    }

    // UPDATED: Handle offcanvas bundle removal (AJAX)
    _onBundleRemove(event) {
        event.preventDefault();
        event.stopPropagation();

        const button = event.target.closest(this.options.bundleRemoveSelector);
        const { bundleId, lineItemId } = button.dataset;

        if (!bundleId && !lineItemId) return;

        this.$emitter.publish('beforeBundleRemove', { bundleId, lineItemId });

        // Find the bundle item for loading indicator
        const bundleItem = button.closest(this.options.bundleItemSelector);
        const loadingTarget = bundleItem || button.parentElement;

        // Create a form element like Shopware's regular cart items have
        const form = this._createRemovalForm(bundleId, lineItemId);

        // Show loading indicator on the actual bundle item
        ElementLoadingIndicatorUtil.create(loadingTarget);

        // Follow Shopware's _fireRequest pattern exactly
        this._fireRemovalRequest(form, loadingTarget);
    }

    // REMOVED: _onBundleRemoveStandard method - let Shopware handle cart page removals naturally

    _fireRemovalRequest(form, loadingTarget) {
        const requestUrl = form.action;
        const data = FormSerializeUtil.serialize(form);

        this.$emitter.publish('beforeFireRequest');

        this.client.post(requestUrl, data, (response) => {
            // Remove loading indicator from the target element
            ElementLoadingIndicatorUtil.remove(loadingTarget);

            // Handle the response like Shopware does
            this._onRemovalComplete(response);
        });
    }

    _createRemovalForm(bundleId, lineItemId) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = this.options.removeFromCartUrl;

        if (bundleId) {
            const bundleInput = document.createElement('input');
            bundleInput.type = 'hidden';
            bundleInput.name = 'bundle-id';
            bundleInput.value = bundleId;
            form.appendChild(bundleInput);
        }

        if (lineItemId) {
            const lineItemInput = document.createElement('input');
            lineItemInput.type = 'hidden';
            lineItemInput.name = 'line-item-id';
            lineItemInput.value = lineItemId;
            form.appendChild(lineItemInput);
        }

        // Add redirectTo for offcanvas
        const redirectInput = document.createElement('input');
        redirectInput.type = 'hidden';
        redirectInput.name = 'redirectTo';
        redirectInput.value = 'frontend.cart.offcanvas';
        form.appendChild(redirectInput);

        return form;
    }

    _onRemovalComplete(response) {
        // This follows Shopware's exact pattern for handling cart removal responses
        try {
            const offcanvasInstances = window.PluginManager.getPluginInstances('OffCanvasCart');
            if (offcanvasInstances?.length > 0) {
                const offcanvas = offcanvasInstances[0];

                if (typeof offcanvas._updateOffCanvasContent === 'function') {
                    offcanvas._updateOffCanvasContent(response);
                    this._fetchCartWidgets();
                    window.PluginManager.initializePlugins();
                }
            }
        } catch (error) {
            console.warn('Error in removal complete:', error);
        }

        this.$emitter.publish('bundleRemoveSuccess', { response });
    }

    // Updated _updateOffCanvasContent method with callback support
    _updateOffCanvasContent(callback = null) {
        try {
            const offcanvasInstances = window.PluginManager.getPluginInstances('OffCanvasCart');
            if (offcanvasInstances?.length > 0) {
                const offcanvas = offcanvasInstances[0];

                if (typeof offcanvas._updateOffCanvasContent === 'function') {
                    // Use Shopware's standard offcanvas update method
                    this.client.get(window.router['frontend.cart.offcanvas'], response => {
                        offcanvas._updateOffCanvasContent(response);

                        // Re-initialize plugins following Shopware pattern
                        window.PluginManager.initializePlugins();

                        // Execute callback if provided
                        if (callback && typeof callback === 'function') {
                            setTimeout(callback, 100);
                        }
                    }, 'text/html');
                } else {
                    // Fallback
                    if (callback && typeof callback === 'function') {
                        callback();
                    }
                }
            } else if (callback && typeof callback === 'function') {
                callback();
            }
        } catch (error) {
            console.warn('Error updating offcanvas content:', error);
            if (callback && typeof callback === 'function') {
                callback();
            }
        }
    }

    _onToggleBundleDetails(bundleId, event) {
        event.preventDefault();

        const detailsElement = document.getElementById(`bundle-details-${bundleId}`);
        if (detailsElement) {
            this._toggleElementVisibility(detailsElement, event.target);
        }
    }

    _onBundleToggleInCart(event) {
        event.preventDefault();

        const button = event.target.closest(this.options.bundleToggleSelector);
        const targetElement = document.getElementById(button.dataset.target);

        if (targetElement) {
            this._toggleElementVisibility(targetElement, button);
        }
    }

    _onCartUpdated(event) {
        this._refreshBundleCartInfo();
        this.$emitter.publish('bundleCartUpdated', event.detail);
    }

    _prepareBundleRequestData(form, bundleId) {
        const formData = FormSerializeUtil.serialize(form);
        formData.append('bundle-id', bundleId);

        if (!formData.get('bundle-product-id')) {
            formData.append('bundle-product-id', '');
        }
        if (!formData.get('bundle-source')) {
            formData.append('bundle-source', 'storefront');
        }

        // Add Shopware's standard redirectTo parameter
        formData.append('redirectTo', 'frontend.cart.offcanvas');

        return formData;
    }

    // NEW: Follow Shopware's exact pattern for opening offcanvas cart
    _openOffCanvasCart(requestUrl, formData, loadingElement) {
        const offCanvasCartInstances = window.PluginManager.getPluginInstances('OffCanvasCart');

        if (offCanvasCartInstances.length > 0) {
            // Get the first offcanvas instance (following Shopware pattern)
            const offcanvas = offCanvasCartInstances[0];

            if (typeof offcanvas.openOffCanvas === 'function') {
                // Set loading state
                if (loadingElement) {
                    ElementLoadingIndicatorUtil.create(loadingElement);
                    this._setButtonLoadingState(loadingElement, true);
                }

                // This is Shopware's standard way - it handles everything
                offcanvas.openOffCanvas(requestUrl, formData, (response) => {
                    // Clean up loading state
                    if (loadingElement) {
                        ElementLoadingIndicatorUtil.remove(loadingElement);
                        this._setButtonLoadingState(loadingElement, false);
                    }

                    // Update cart widgets following Shopware pattern
                    this._fetchCartWidgets();

                    // Publish success event
                    this.$emitter.publish('bundleAddSuccess', { response });
                });
            }
        }
    }

    // UPDATED: Following Shopware's cart widget refresh pattern
    _fetchCartWidgets() {
        const cartWidgetInstances = window.PluginManager.getPluginInstances('CartWidget');
        Iterator.iterate(cartWidgetInstances, instance => {
            if (typeof instance.fetch === 'function') {
                instance.fetch();
            }
        });

        this.$emitter.publish('cartWidgetsFetched');
    }

    _setButtonLoadingState(button, loading) {
        if (!button) return;

        if (loading) {
            if (!button.dataset.originalText) {
                button.dataset.originalText = this._getCleanButtonText(button);
                button.dataset.originalDisabled = button.disabled.toString();
            }

            button.disabled = true;
            button.textContent = button.dataset.loadingText || 'Loading...';
            button.classList.add('btn-loading');
        } else {
            if (button.dataset.originalText) {
                button.textContent = button.dataset.originalText;
                button.disabled = button.dataset.originalDisabled === 'true';
                delete button.dataset.originalText;
                delete button.dataset.originalDisabled;
            } else {
                button.disabled = false;
                button.textContent = this._getCleanButtonText(button);
            }

            button.classList.remove('btn-loading');
        }
    }

    _getCleanButtonText(button) {
        const sources = [
            () => button.title?.trim(),
            () => button.getAttribute('aria-label'),
            () => button.dataset.originalText,
            () => button.textContent?.replace(/Loading\.\.\.?|Adding to cart\.\.\.?/gi, '').replace(/\s+/g, ' ').trim()
        ];

        for (const getSource of sources) {
            const text = getSource();
            if (text && !text.includes('Loading') && text.length > 0) {
                return text;
            }
        }

        return 'Add bundle to cart';
    }

    _toggleElementVisibility(element, button) {
        const isHidden = element.classList.contains('is--hidden') || element.style.display === 'none';

        if (isHidden) {
            element.classList.remove('is--hidden');
            element.style.display = 'block';
            button.textContent = button.dataset.hideText || 'Hide Details';
        } else {
            element.classList.add('is--hidden');
            element.style.display = 'none';
            button.textContent = button.dataset.showText || 'Show Details';
        }
    }

    _setRemoveButtonState(button, disabled) {
        if (!button) return;

        button.disabled = disabled;
        button.style.opacity = disabled ? '0.6' : '';
        button.style.pointerEvents = disabled ? 'none' : '';
    }

    _refreshBundleCartInfo() {
        this.client.get(this.options.cartInfoUrl, (response) => {
            try {
                const data = JSON.parse(response);
                if (data.success) {
                    this.$emitter.publish('bundleCartInfoRefreshed', data.data);
                }
            } catch {
                // Silently handle parsing errors
            }
        }, 'application/json');
    }
}