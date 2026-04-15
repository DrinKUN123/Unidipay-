// API Functions

// Fetch menu items by category
async function fetchMenuItems(category) {
    try {
        const response = await fetch(`${API_ENDPOINTS.getMenuItems}?category=${encodeURIComponent(category)}`);
        const data = await response.json();
        
        if (data.success) {
            return data.items;
        } else {
            throw new Error(data.message || 'Failed to fetch menu items');
        }
    } catch (error) {
        console.error('Error fetching menu items:', error);
        if (typeof window.showNotification === 'function') {
            window.showNotification('Failed to load menu items. Please try again.', 'error');
        }
        return [];
    }
}

// Verify RFID Card
async function verifyNFCCard(cardId) {
    try {
        const response = await fetch(API_ENDPOINTS.verifyCard, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ card_id: cardId })
        });
        
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('Error verifying card:', error);
        return { success: false, message: 'Failed to verify card' };
    }
}

// Create order with payment
async function createOrderWithPayment(cardId, items, total, orderType, paymentType = 'nfc') {
    try {
        const response = await fetch(API_ENDPOINTS.createOrder, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                nfc_card_id: cardId,
                items: items,
                total: total,
                order_type: orderType,
                payment_method: paymentType
            })
        });
        
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('Error creating order:', error);
        return { success: false, message: 'Failed to create order' };
    }
}
