// Main Application Logic
let currentCategory = '';

// Screen Navigation
function goToScreen(screenId) {
    // Hide all screens
    document.querySelectorAll('.screen').forEach(screen => {
        screen.classList.remove('active');
    });
    
    // Show selected screen
    const screen = document.getElementById(screenId);
    if (screen) {
        screen.classList.add('active');
    }
}

// Load menu items for selected category
async function loadMenuItems(category) {
    currentCategory = category;
    lastScreen = 'menuScreen';
    
    // Update title
    document.getElementById('menuTitle').textContent = category;
    
    // Show loading state
    const container = document.getElementById('menuItemsContainer');
    container.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 48px; font-size: 1.5rem; color: var(--gray-500);">Loading...</div>';
    
    // Go to menu screen
    goToScreen('menuScreen');
    
    // Fetch menu items
    const items = await fetchMenuItems(category);
    
    // Render menu items
    if (items.length === 0) {
        container.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 48px; font-size: 1.5rem; color: var(--gray-500);">No items available</div>';
        return;
    }
    
    container.innerHTML = items.map(item => `
        <div class="menu-item-card">
            ${item.image_url ? 
                `<img src="${item.image_url}" alt="${item.name}" class="menu-item-image" onerror="this.outerHTML='<div class=\\'menu-item-image placeholder\\'>No Image</div>'">` :
                '<div class="menu-item-image placeholder">No Image</div>'
            }
            <div class="menu-item-content">
                <div class="menu-item-name">${item.name}</div>
                <div class="menu-item-footer">
                    <span class="menu-item-price">₱${parseFloat(item.price).toFixed(2)}</span>
                    <button class="btn-add" onclick='addToCart(${JSON.stringify(item)})'>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>Add</span>
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

// Go to cart
function goToCart() {
    if (cart.length === 0) {
        alert('Your cart is empty!');
        return;
    }
    
    renderCart();
    goToScreen('cartScreen');
}

// Go back from cart
function goBackFromCart() {
    if (currentCategory) {
        goToScreen('menuScreen');
    } else {
        goToScreen('categoryScreen');
    }
}

// Go to payment
function goToPayment() {
    if (!selectedOrderType) {
        alert('Please select an order type');
        return;
    }
    
    if (cart.length === 0) {
        alert('Your cart is empty');
        return;
    }
    
    // Update payment screen
    document.getElementById('paymentOrderType').textContent = selectedOrderType === 'dine-in' ? 'Dine In' : 'Take Out';
    document.getElementById('paymentTotal').textContent = `₱${getCartTotal().toFixed(2)}`;
    
    // Reset payment UI
    document.getElementById('rfidPrompt').style.display = 'flex';
    document.getElementById('processingPrompt').style.display = 'none';
    document.getElementById('nfcCardInput').value = '';
    
    goToScreen('paymentScreen');
}

// Process payment
async function processPayment() {
    const cardId = document.getElementById('nfcCardInput').value.trim();
    const paymentTypeSelect = document.getElementById('paymentType');
    const paymentType = paymentTypeSelect && paymentTypeSelect.value ? paymentTypeSelect.value : 'nfc';
    
    if (!cardId) {
        alert('Please enter your NFC Card ID');
        return;
    }
    
    if (paymentTypeSelect && !paymentTypeSelect.value) {
        alert('Please select a payment type');
        return;
    }
    
    // Show processing state
    document.getElementById('rfidPrompt').style.display = 'none';
    document.getElementById('processingPrompt').style.display = 'flex';
    
    try {
        // Verify card first
        const cardVerification = await verifyNFCCard(cardId);
        
        if (!cardVerification.success) {
            alert(cardVerification.message || 'Invalid card');
            document.getElementById('rfidPrompt').style.display = 'flex';
            document.getElementById('processingPrompt').style.display = 'none';
            return;
        }
        
        // Check if balance is sufficient (only for NFC payment)
        const total = getCartTotal();
        if (paymentType === 'nfc' && parseFloat(cardVerification.balance) < total) {
            alert(`Insufficient balance. Your balance: ₱${parseFloat(cardVerification.balance).toFixed(2)}`);
            document.getElementById('rfidPrompt').style.display = 'flex';
            document.getElementById('processingPrompt').style.display = 'none';
            return;
        }
        
        // Prepare order items
        const orderItems = cart.map(item => ({
            menu_item_id: item.id,
            name: item.name,
            price: item.price,
            quantity: item.quantity,
            subtotal: item.price * item.quantity
        }));
        
        // Create order with payment type
        const orderResult = await createOrderWithPayment(cardId, orderItems, total, selectedOrderType, paymentType);
        
        if (orderResult.success) {
            // Show success screen
            showSuccessScreen(orderResult.order_id);
        } else {
            alert(orderResult.message || 'Payment failed. Please try again.');
            document.getElementById('rfidPrompt').style.display = 'flex';
            document.getElementById('processingPrompt').style.display = 'none';
        }
    } catch (error) {
        console.error('Payment error:', error);
        alert('Payment failed. Please try again.');
        document.getElementById('rfidPrompt').style.display = 'flex';
        document.getElementById('processingPrompt').style.display = 'none';
    }
}

// Show success screen
function showSuccessScreen(orderId) {
    document.getElementById('orderNumber').textContent = `ORD-${String(orderId).padStart(6, '0')}`;
    goToScreen('successScreen');
    
    // Start countdown
    let countdown = 10;
    const countdownElement = document.getElementById('countdown');
    
    const countdownInterval = setInterval(() => {
        countdown--;
        countdownElement.textContent = countdown;
        
        if (countdown <= 0) {
            clearInterval(countdownInterval);
            resetKiosk();
        }
    }, 1000);
}

// Reset kiosk to initial state
function resetKiosk() {
    cart = [];
    selectedOrderType = null;
    currentCategory = '';
    lastScreen = 'categoryScreen';
    updateCartBadges();
    goToScreen('startScreen');
}

// FAQ modal controls (non-intrusive to main flow)
function showOrderingFAQ() {
    const modal = document.getElementById('orderingFaqModal');
    if (!modal) return;
    modal.classList.add('visible');
    modal.setAttribute('aria-hidden', 'false');
}

function hideOrderingFAQ() {
    const modal = document.getElementById('orderingFaqModal');
    if (!modal) return;
    modal.classList.remove('visible');
    modal.setAttribute('aria-hidden', 'true');
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    console.log('UniDiPay Kiosk System Initialized');
    updateCartBadges();
});
