// Cart Management
let cart = [];
let selectedOrderType = null;
let lastScreen = 'categoryScreen';

// Add item to cart
function addToCart(item) {
    const existingItem = cart.find(cartItem => cartItem.id === item.id);
    
    if (existingItem) {
        existingItem.quantity++;
    } else {
        cart.push({
            ...item,
            quantity: 1
        });
    }
    
    updateCartBadges();
    showNotification(`${item.name} added to cart!`);
}

// Update item quantity
function updateQuantity(itemId, change) {
    const item = cart.find(cartItem => cartItem.id === itemId);
    
    if (item) {
        item.quantity += change;
        
        if (item.quantity <= 0) {
            cart = cart.filter(cartItem => cartItem.id !== itemId);
        }
        
        updateCartBadges();
        renderCart();
    }
}

// Get cart totals
function getCartTotal() {
    return cart.reduce((total, item) => total + (item.price * item.quantity), 0);
}

function getCartItemCount() {
    return cart.reduce((total, item) => total + item.quantity, 0);
}

// Update cart badges
function updateCartBadges() {
    const count = getCartItemCount();
    const badges = document.querySelectorAll('.cart-badge');
    
    badges.forEach(badge => {
        badge.textContent = count;
        if (count > 0) {
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    });
}

// Select order type
function selectOrderType(type) {
    selectedOrderType = type;
    
    // Update UI
    document.querySelectorAll('.order-type-option').forEach(option => {
        option.classList.remove('selected');
    });
    
    const selectedOption = document.querySelector(`[data-type="${type}"]`);
    if (selectedOption) {
        selectedOption.classList.add('selected');
    }
    
    // Enable checkout button
    updateCheckoutButton();
}

// Update checkout button state
function updateCheckoutButton() {
    const checkoutBtn = document.getElementById('checkoutBtn');
    if (checkoutBtn) {
        if (selectedOrderType && cart.length > 0) {
            checkoutBtn.disabled = false;
            checkoutBtn.textContent = 'Proceed to Payment';
        } else {
            checkoutBtn.disabled = true;
            checkoutBtn.textContent = 'Select Order Type';
        }
    }
}

// Render cart
function renderCart() {
    const cartContent = document.getElementById('cartContent');
    
    if (cart.length === 0) {
        cartContent.innerHTML = `
            <div class="cart-empty">
                <i class="fa-solid fa-cart-shopping" aria-hidden="true"></i>
                <p>Your cart is empty</p>
            </div>
        `;
        return;
    }
    
    const cartItemsHTML = cart.map(item => `
        <div class="cart-item">
            <img src="${item.image_url || 'placeholder.jpg'}" alt="${item.name}" class="cart-item-image" onerror="this.style.display='none'">
            <div class="cart-item-info">
                <div class="cart-item-name">${item.name}</div>
                <div class="cart-item-price">₱${parseFloat(item.price).toFixed(2)}</div>
            </div>
            <div class="cart-item-controls">
                <button class="btn-quantity" onclick="updateQuantity(${item.id}, -1)">
                    <i class="fa-solid fa-minus" aria-hidden="true"></i>
                </button>
                <span class="cart-item-quantity">${item.quantity}</span>
                <button class="btn-quantity plus" onclick="updateQuantity(${item.id}, 1)">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                </button>
            </div>
            <div class="cart-item-subtotal">₱${(parseFloat(item.price) * item.quantity).toFixed(2)}</div>
        </div>
    `).join('');
    
    const orderTypeHTML = `
        <div class="order-type-section">
            <h2>Select Order Type</h2>
            <div class="order-type-grid">
                <div class="order-type-option ${selectedOrderType === 'dine-in' ? 'selected' : ''}" data-type="dine-in" onclick="selectOrderType('dine-in')">
                    <div class="order-type-content">
                        <div class="order-type-icon">
                            <i class="fa-solid fa-utensils" aria-hidden="true"></i>
                        </div>
                        <span class="order-type-label">Dine In</span>
                    </div>
                </div>
                <div class="order-type-option ${selectedOrderType === 'take-out' ? 'selected' : ''}" data-type="take-out" onclick="selectOrderType('take-out')">
                    <div class="order-type-content">
                        <div class="order-type-icon">
                            <i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>
                        </div>
                        <span class="order-type-label">Take Out</span>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    const totalHTML = `
        <div class="cart-total">
            <div class="total-row">
                <span>Total Amount</span>
                <span class="total-amount">₱${getCartTotal().toFixed(2)}</span>
            </div>
            <button class="btn-primary" id="checkoutBtn" onclick="goToPayment()" ${!selectedOrderType ? 'disabled' : ''}>
                ${selectedOrderType ? 'Proceed to Payment' : 'Select Order Type'}
            </button>
        </div>
    `;
    
    cartContent.innerHTML = `
        <div class="cart-items">
            ${cartItemsHTML}
            ${orderTypeHTML}
            ${totalHTML}
        </div>
    `;
}

// Show notification toast for kiosk UX feedback
function showNotification(message, type = 'info') {
    const existingToast = document.querySelector('.kiosk-toast');
    if (existingToast) {
        existingToast.remove();
    }

    const toast = document.createElement('div');
    toast.className = `kiosk-toast ${type}`;

    let iconClass = 'fa-solid fa-circle-info';
    if (type === 'success') iconClass = 'fa-solid fa-circle-check';
    if (type === 'error') iconClass = 'fa-solid fa-circle-exclamation';

    toast.innerHTML = `
        <i class="${iconClass}" aria-hidden="true"></i>
        <span>${message}</span>
    `;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('hide');
        setTimeout(() => toast.remove(), 220);
    }, 2200);
}

window.showNotification = showNotification;
