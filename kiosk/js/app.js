// Main Application Logic
let currentCategory = '';
let rfidScanBuffer = '';
let rfidLastKeyTime = 0;
let isProcessingPayment = false;
let lastScanSubmitAt = 0;

const RFID_SCAN_RESET_MS = 1000;
const RFID_DUPLICATE_GUARD_MS = 1200;

function notify(message, type = 'info') {
    if (typeof window.showNotification === 'function') {
        window.showNotification(message, type);
    } else {
        alert(message);
    }
}

function applyKioskBranding() {
    const logoCandidates = [
        '../assets/UNIDIPAY%20LOGO.png',
        '../assets/unidipay-logo.png',
        '../assets/unidipay-logo.jpg',
        '../assets/unidipay-logo.jpeg',
        '../assets/unidipay-logo.webp'
    ];

    document.querySelectorAll('img[data-kiosk-brand]').forEach((img) => {
        let idx = 0;
        const fallback = img.getAttribute('src') || '';

        const nextLogo = () => {
            if (idx >= logoCandidates.length) {
                img.onerror = null;
                if (fallback && !logoCandidates.includes(fallback)) {
                    img.src = fallback;
                }
                return;
            }
            img.src = logoCandidates[idx++];
        };

        img.onerror = nextLogo;
        nextLogo();
    });
}

function isPaymentScreenActive() {
    const screen = document.getElementById('paymentScreen');
    return !!(screen && screen.classList.contains('active'));
}

function resetRfidScanBuffer() {
    rfidScanBuffer = '';
    rfidLastKeyTime = 0;
}

function handleRfidKeyboardInput(event) {
    if (!isPaymentScreenActive()) {
        return;
    }

    if (event.ctrlKey || event.altKey || event.metaKey) {
        return;
    }

    const now = Date.now();

    if (event.key === 'Enter' || event.key === 'Tab') {
        const scannedValue = rfidScanBuffer.trim();
        resetRfidScanBuffer();

        if (!scannedValue) {
            return;
        }

        event.preventDefault();

        // Prevent duplicate submits from scanners that send double Enter.
        if (now - lastScanSubmitAt < RFID_DUPLICATE_GUARD_MS) {
            return;
        }
        lastScanSubmitAt = now;

        const input = document.getElementById('nfcCardInput');
        if (input) {
            input.value = scannedValue;
        }

        processPayment(scannedValue);
        return;
    }

    if (event.key.length !== 1) {
        return;
    }

    if (!/[A-Za-z0-9_-]/.test(event.key)) {
        return;
    }

    if (rfidLastKeyTime && now - rfidLastKeyTime > RFID_SCAN_RESET_MS) {
        rfidScanBuffer = '';
    }

    rfidScanBuffer += event.key;
    rfidLastKeyTime = now;
}

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
    container.innerHTML = `
        <div class="menu-status loading" role="status" aria-live="polite">
            <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
            <span>Loading menu items...</span>
        </div>
    `;
    
    // Go to menu screen
    goToScreen('menuScreen');
    
    // Fetch menu items
    const items = await fetchMenuItems(category);
    
    // Render menu items
    if (items.length === 0) {
        container.innerHTML = `
            <div class="menu-status empty" role="status" aria-live="polite">
                <i class="fa-solid fa-box-open" aria-hidden="true"></i>
                <span>No items available for this category</span>
            </div>
        `;
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
                <div class="menu-item-description">${(item.description || 'Freshly prepared and served hot.').trim()}</div>
                <div class="menu-item-footer">
                    <span class="menu-item-price">₱${parseFloat(item.price).toFixed(2)}</span>
                    <button class="btn-add" onclick='addToCart(${JSON.stringify(item)})'>
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
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
        notify('Your cart is empty!', 'error');
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
        notify('Please select an order type', 'error');
        return;
    }
    
    if (cart.length === 0) {
        notify('Your cart is empty', 'error');
        return;
    }
    
    // Update payment screen
    document.getElementById('paymentOrderType').textContent = selectedOrderType === 'dine-in' ? 'Dine In' : 'Take Out';
    document.getElementById('paymentTotal').textContent = `₱${getCartTotal().toFixed(2)}`;
    
    // Reset payment UI
    document.getElementById('rfidPrompt').style.display = 'flex';
    document.getElementById('processingPrompt').style.display = 'none';
    document.getElementById('nfcCardInput').value = '';
    isProcessingPayment = false;
    resetRfidScanBuffer();
    lastScanSubmitAt = 0;
    
    goToScreen('paymentScreen');
}

// Process payment
async function processPayment(scannedCardId = null) {
    if (isProcessingPayment) {
        return;
    }

    const input = document.getElementById('nfcCardInput');
    const cardId = (scannedCardId || (input ? input.value : '') || '').trim();
    const paymentTypeSelect = document.getElementById('paymentType');
    const paymentType = paymentTypeSelect && paymentTypeSelect.value ? paymentTypeSelect.value : 'nfc';
    
    if (!cardId) {
        notify('Please enter your RFID Card ID', 'error');
        return;
    }
    
    if (paymentTypeSelect && !paymentTypeSelect.value) {
        notify('Please select a payment type', 'error');
        return;
    }

    const payBtn = document.querySelector('.btn-full');
    const payBtnOriginal = payBtn ? payBtn.innerHTML : '';
    if (payBtn) {
        payBtn.disabled = true;
        payBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Processing...';
    }

    isProcessingPayment = true;
    
    // Show processing state
    document.getElementById('rfidPrompt').style.display = 'none';
    document.getElementById('processingPrompt').style.display = 'flex';
    
    try {
        // Verify card first
        const cardVerification = await verifyNFCCard(cardId);
        
        if (!cardVerification.success) {
            notify(cardVerification.message || 'Invalid card', 'error');
            document.getElementById('rfidPrompt').style.display = 'flex';
            document.getElementById('processingPrompt').style.display = 'none';
            isProcessingPayment = false;
            if (payBtn) {
                payBtn.disabled = false;
                payBtn.innerHTML = payBtnOriginal;
            }
            return;
        }
        
        // Check if balance is sufficient (only for NFC payment)
        const total = getCartTotal();
        if (paymentType === 'nfc' && parseFloat(cardVerification.balance) < total) {
            notify(`Insufficient balance. Your balance: ₱${parseFloat(cardVerification.balance).toFixed(2)}`, 'error');
            document.getElementById('rfidPrompt').style.display = 'flex';
            document.getElementById('processingPrompt').style.display = 'none';
            isProcessingPayment = false;
            if (payBtn) {
                payBtn.disabled = false;
                payBtn.innerHTML = payBtnOriginal;
            }
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
            notify(orderResult.message || 'Payment failed. Please try again.', 'error');
            document.getElementById('rfidPrompt').style.display = 'flex';
            document.getElementById('processingPrompt').style.display = 'none';
            isProcessingPayment = false;
            if (payBtn) {
                payBtn.disabled = false;
                payBtn.innerHTML = payBtnOriginal;
            }
        }
    } catch (error) {
        console.error('Payment error:', error);
        notify('Payment failed. Please try again.', 'error');
        document.getElementById('rfidPrompt').style.display = 'flex';
        document.getElementById('processingPrompt').style.display = 'none';
        isProcessingPayment = false;
        if (payBtn) {
            payBtn.disabled = false;
            payBtn.innerHTML = payBtnOriginal;
        }
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
    isProcessingPayment = false;
    resetRfidScanBuffer();
    lastScanSubmitAt = 0;
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
    applyKioskBranding();
    updateCartBadges();
    document.addEventListener('keydown', handleRfidKeyboardInput);
});
