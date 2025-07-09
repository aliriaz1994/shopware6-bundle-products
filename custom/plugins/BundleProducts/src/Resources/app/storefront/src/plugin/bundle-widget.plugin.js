// Enhanced bundle-widget.plugin.js - Complete bundle functionality with quantity controls

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
        updateQuantityUrl: '/bundle/update-quantity',
        cartInfoUrl: '/bundle/cart-info',
        bundleContainerSelector: '.new-bundle-container, .digipercep-bundle-container',
        bundleFormSelector: 'form[data-bundle-form="true"], form.new-bundle-form, form.bundle-main-form',
        bundleDetailsToggleSelector: '.toggle-details',

        // Quantity control selectors
        bundleQuantityFormSelector: '.bundle-quantity-form',
        bundleQuantityInputSelector: '.bundle-quantity-input',
        bundleQuantityButtonSelector: '.bundle-qty-btn',
        bundleQuantityIncreaseSelector: '.js-btn-plus',
        bundleQuantityDecreaseSelector: '.js-btn-minus',

        // Different contexts for removal
        bundleRemoveSelector: '.bundle-remove-btn', // offcanvas - minicart
        bundleRemoveStandardSelector: '.bundle-remove-standard', // Checkout cart page

        bundleToggleSelector: '.bundle-contents-toggle, [data-bundle-toggle]',
        bundleItemSelector: '.bundle-item, .line-item-bundle',
        requestDelay: 300,
        quantityUpdateDelay: 1000,
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
        this._quantityUpdateTimeouts = new Map();
        this._registerEvents();
        this._initBundles();
        this._initQuantityControls();
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

    _initQuantityControls() {
        // Initialize all existing quantity controls
        const quantityForms = document.querySelectorAll(this.options.bundleQuantityFormSelector);
        Iterator.iterate(quantityForms, form => this._initQuantityForm(form));

        // Initialize quantity buttons
        const quantityButtons = document.querySelectorAll(this.options.bundleQuantityButtonSelector);
        Iterator.iterate(quantityButtons, button => this._initQuantityButton(button));

        // Initialize quantity inputs
        const quantityInputs = document.querySelectorAll(this.options.bundleQuantityInputSelector);
        Iterator.iterate(quantityInputs, input => this._initQuantityInput(input));

        this.$emitter.publish('bundleQuantityControlsInitialized');
    }

    _initQuantityForm(form) {
        const input = form.querySelector(this.options.bundleQuantityInputSelector);
        if (input) {
            const quantity = parseInt(input.value) || 1;
            input.setAttribute('data-original-value', quantity);
            this._updateQuantityButtonStates(form, quantity);
        }
    }

    _initQuantityButton(button) {
        // Remove any existing event listeners to prevent duplicates
        const newButton = button.cloneNode(true);
        button.parentNode.replaceChild(newButton, button);

        newButton.addEventListener('click', this._onQuantityButtonClick.bind(this));
    }

    _initQuantityInput(input) {
        // Remove any existing event listeners to prevent duplicates
        const newInput = input.cloneNode(true);
        input.parentNode.replaceChild(newInput, input);

        newInput.addEventListener('input', this._onQuantityInputChange.bind(this));
        newInput.addEventListener('change', this._onQuantityInputChange.bind(this));
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

        // Observe DOM changes for dynamically added bundle items
        this._observeQuantityControls();
    }

    _observeQuantityControls() {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1) {
                        // Check for bundle items
                        const bundleItems = node.matches && node.matches(this.options.bundleItemSelector)
                            ? [node]
                            : node.querySelectorAll ? node.querySelectorAll(this.options.bundleItemSelector) : [];

                        if (bundleItems.length > 0) {
                            Iterator.iterate(bundleItems, item => this._initBundleItemQuantityControls(item));
                        }

                        // Check for quantity controls specifically
                        const quantityControls = node.querySelectorAll ? node.querySelectorAll(this.options.bundleQuantityButtonSelector) : [];
                        if (quantityControls.length > 0) {
                            Iterator.iterate(quantityControls, button => this._initQuantityButton(button));
                        }

                        const quantityInputs = node.querySelectorAll ? node.querySelectorAll(this.options.bundleQuantityInputSelector) : [];
                        if (quantityInputs.length > 0) {
                            Iterator.iterate(quantityInputs, input => this._initQuantityInput(input));
                        }
                    }
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    _initBundleItemQuantityControls(bundleItem) {
        const quantityForm = bundleItem.querySelector(this.options.bundleQuantityFormSelector);
        if (quantityForm) {
            this._initQuantityForm(quantityForm);
        }

        const quantityButtons = bundleItem.querySelectorAll(this.options.bundleQuantityButtonSelector);
        Iterator.iterate(quantityButtons, button => this._initQuantityButton(button));

        const quantityInputs = bundleItem.querySelectorAll(this.options.bundleQuantityInputSelector);
        Iterator.iterate(quantityInputs, input => this._initQuantityInput(input));
    }

    _onQuantityButtonClick(event) {
        event.preventDefault();
        event.stopPropagation();

        const button = event.currentTarget;
        const action = button.dataset.action;
        const lineItemId = button.dataset.lineItemId;
        const form = button.closest(this.options.bundleQuantityFormSelector);
        const input = form.querySelector(this.options.bundleQuantityInputSelector);

        if (!input || !lineItemId) {
            console.warn('Bundle quantity control: missing input or line item ID');
            return;
        }

        let currentQuantity = parseInt(input.value) || 1;
        let newQuantity = currentQuantity;

        if (action === 'increase' && currentQuantity < 999) {
            newQuantity = currentQuantity + 1;
        } else if (action === 'decrease' && currentQuantity > 1) {
            newQuantity = currentQuantity - 1;
        } else {
            return; // No change needed
        }

        // Update input value
        input.value = newQuantity;
        input.setAttribute('data-original-value', newQuantity);

        // Update button states
        this._updateQuantityButtonStates(form, newQuantity);

        // Submit quantity update
        this._submitQuantityUpdate(form, lineItemId, newQuantity);

        this.$emitter.publish('bundleQuantityChanged', { lineItemId, oldQuantity: currentQuantity, newQuantity });
    }

    _onQuantityInputChange(event) {
        const input = event.currentTarget;
        const form = input.closest(this.options.bundleQuantityFormSelector);
        const lineItemIdInput = form.querySelector('input[name="line-item-id"]');
        const lineItemId = lineItemIdInput ? lineItemIdInput.value : input.dataset.lineItemId;

        if (!lineItemId) {
            console.warn('Bundle quantity control: missing line item ID');
            return;
        }

        const newQuantity = parseInt(input.value);
        const originalQuantity = parseInt(input.getAttribute('data-original-value') || input.defaultValue);

        // Clear existing timeout for this input
        if (this._quantityUpdateTimeouts.has(lineItemId)) {
            clearTimeout(this._quantityUpdateTimeouts.get(lineItemId));
        }

        // Validate quantity
        if (newQuantity < 1 || newQuantity > 999 || newQuantity === originalQuantity) {
            return;
        }

        // Update button states immediately
        this._updateQuantityButtonStates(form, newQuantity);

        // Set timeout for submission
        const timeout = setTimeout(() => {
            this._submitQuantityUpdate(form, lineItemId, newQuantity);
            this._quantityUpdateTimeouts.delete(lineItemId);
        }, this.options.quantityUpdateDelay);

        this._quantityUpdateTimeouts.set(lineItemId, timeout);

        this.$emitter.publish('bundleQuantityInputChanged', { lineItemId, newQuantity });
    }

    _updateQuantityButtonStates(form, quantity) {
        const decreaseBtn = form.querySelector('[data-action="decrease"]');
        const increaseBtn = form.querySelector('[data-action="increase"]');

        if (decreaseBtn) {
            decreaseBtn.disabled = quantity <= 1;
        }

        if (increaseBtn) {
            increaseBtn.disabled = quantity >= 999;
        }
    }

    _submitQuantityUpdate(form, lineItemId, quantity) {
        console.log('Submitting bundle quantity update:', lineItemId, quantity);

        // Show loading state
        const loadingTarget = form.closest(this.options.bundleItemSelector) || form;
        ElementLoadingIndicatorUtil.create(loadingTarget);

        // Disable form controls during update
        this._setFormLoadingState(form, true);

        // Prepare form data
        const formData = new FormData();
        formData.append('line-item-id', lineItemId);
        formData.append('quantity', quantity);
        formData.append('redirectTo', 'frontend.cart.offcanvas');

        this.$emitter.publish('beforeBundleQuantityUpdate', { lineItemId, quantity });

        // Submit request
        this.client.post(this.options.updateQuantityUrl, formData, (response) => {
            this._onQuantityUpdateSuccess(response, form, lineItemId, quantity, loadingTarget);
        }, (error) => {
            this._onQuantityUpdateError(error, form, lineItemId, loadingTarget);
        });
    }

    _onQuantityUpdateSuccess(response, form, lineItemId, quantity, loadingTarget) {
        console.log('Bundle quantity update successful');

        // Update the original value
        const input = form.querySelector(this.options.bundleQuantityInputSelector);
        if (input) {
            input.setAttribute('data-original-value', quantity);
            input.defaultValue = quantity;
        }

        // Remove loading state
        ElementLoadingIndicatorUtil.remove(loadingTarget);
        this._setFormLoadingState(form, false);

        // Update cart display
        this._updateOffCanvasContent(() => {
            this._fetchCartWidgets();
            this.$emitter.publish('bundleQuantityUpdateSuccess', { lineItemId, quantity, response });
        });
    }

    _onQuantityUpdateError(error, form, lineItemId, loadingTarget) {
        console.error('Bundle quantity update failed:', error);

        // Revert input value
        const input = form.querySelector(this.options.bundleQuantityInputSelector);
        if (input) {
            const originalValue = input.getAttribute('data-original-value') || input.defaultValue;
            input.value = originalValue;
            this._updateQuantityButtonStates(form, parseInt(originalValue));
        }

        // Remove loading state
        ElementLoadingIndicatorUtil.remove(loadingTarget);
        this._setFormLoadingState(form, false);

        this.$emitter.publish('bundleQuantityUpdateError', { lineItemId, error });
    }

    _setFormLoadingState(form, loading) {
        const buttons = form.querySelectorAll(this.options.bundleQuantityButtonSelector);
        const input = form.querySelector(this.options.bundleQuantityInputSelector);

        Iterator.iterate(buttons, btn => btn.disabled = loading);
        if (input) input.disabled = loading;

        if (loading) {
            form.classList.add('loading');
        } else {
            form.classList.remove('loading');
        }
    }

    _onDocumentClick(event) {
        // Handle offcanvas bundle removal (AJAX)
        if (event.target.closest(this.options.bundleRemoveSelector)) {
            const offcanvasContext = event.target.closest('.offcanvas-cart') ||
                event.target.closest('.js-offcanvas-cart') ||
                document.querySelector('.offcanvas-cart.show');

            if (offcanvasContext) {
                this._onBundleRemove(event);
            }
        }
        // Handle bundle toggle in cart
        else if (event.target.closest(this.options.bundleToggleSelector)) {
            this._onBundleToggleInCart(event);
        }
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

        this._openOffCanvasCart(this.options.addToCartUrl, requestData, submitButton);

        return false;
    }

    _onBundleRemove(event) {
        event.preventDefault();
        event.stopPropagation();

        const button = event.target.closest(this.options.bundleRemoveSelector);
        const { bundleId, lineItemId } = button.dataset;

        if (!bundleId && !lineItemId) return;

        this.$emitter.publish('beforeBundleRemove', { bundleId, lineItemId });

        const bundleItem = button.closest(this.options.bundleItemSelector);
        const loadingTarget = bundleItem || button.parentElement;

        const form = this._createRemovalForm(bundleId, lineItemId);

        ElementLoadingIndicatorUtil.create(loadingTarget);

        this._fireRemovalRequest(form, loadingTarget);
    }

    _fireRemovalRequest(form, loadingTarget) {
        const requestUrl = form.action;
        const data = FormSerializeUtil.serialize(form);

        this.$emitter.publish('beforeFireRequest');

        this.client.post(requestUrl, data, (response) => {
            ElementLoadingIndicatorUtil.remove(loadingTarget);
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

        const redirectInput = document.createElement('input');
        redirectInput.type = 'hidden';
        redirectInput.name = 'redirectTo';
        redirectInput.value = 'frontend.cart.offcanvas';
        form.appendChild(redirectInput);

        return form;
    }

    _onRemovalComplete(response) {
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

    _updateOffCanvasContent(callback = null) {
        try {
            const offcanvasInstances = window.PluginManager.getPluginInstances('OffCanvasCart');
            if (offcanvasInstances?.length > 0) {
                const offcanvas = offcanvasInstances[0];

                if (typeof offcanvas._updateOffCanvasContent === 'function') {
                    this.client.get(window.router['frontend.cart.offcanvas'], response => {
                        offcanvas._updateOffCanvasContent(response);
                        window.PluginManager.initializePlugins();

                        if (callback && typeof callback === 'function') {
                            setTimeout(callback, 100);
                        }
                    }, 'text/html');
                } else {
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

        formData.append('redirectTo', 'frontend.cart.offcanvas');

        return formData;
    }

    _openOffCanvasCart(requestUrl, formData, loadingElement) {
        const offCanvasCartInstances = window.PluginManager.getPluginInstances('OffCanvasCart');

        if (offCanvasCartInstances.length > 0) {
            const offcanvas = offCanvasCartInstances[0];

            if (typeof offcanvas.openOffCanvas === 'function') {
                if (loadingElement) {
                    ElementLoadingIndicatorUtil.create(loadingElement);
                    this._setButtonLoadingState(loadingElement, true);
                }

                offcanvas.openOffCanvas(requestUrl, formData, (response) => {
                    if (loadingElement) {
                        ElementLoadingIndicatorUtil.remove(loadingElement);
                        this._setButtonLoadingState(loadingElement, false);
                    }

                    this._fetchCartWidgets();
                    this.$emitter.publish('bundleAddSuccess', { response });
                });
            }
        }
    }

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