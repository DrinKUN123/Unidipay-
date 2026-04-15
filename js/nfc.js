/**
 * RFID Management Page Script
 */

let currentCard = null;
let isSearchingCard = false;

document.addEventListener('DOMContentLoaded', () => {
    initDashboard();
    protectPage();
    setupNFCHandlers();
});

/**
 * Setup event listeners for RFID Management
 */
function setupNFCHandlers() {
    const searchBtn = document.getElementById('searchBtn');
    const cardIdInput = document.getElementById('cardIdInput');
    const loadBalanceBtn = document.getElementById('loadBalanceBtn');
    const deductBalanceBtn = document.getElementById('deductBalanceBtn');

    // Search button click
    searchBtn.addEventListener('click', () => {
        const cardId = cardIdInput.value.trim();
        if (!cardId) {
            showError('Please enter a card ID');
            return;
        }
        searchCard(cardId);
    });

    // Enter key on input
    cardIdInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            searchBtn.click();
        }
    });

    // Load balance button
    loadBalanceBtn.addEventListener('click', () => {
        if (currentCard) {
            openLoadBalanceModal(currentCard.card.id);
        }
    });

    // Deduct balance button
    deductBalanceBtn.addEventListener('click', () => {
        if (currentCard) {
            openDeductBalanceModal(currentCard.card.id);
        }
    });
}

/**
 * Search for an RFID Card
 */
async function searchCard(cardId) {
    if (isSearchingCard) return;
    isSearchingCard = true;

    const searchBtn = document.getElementById('searchBtn');
    const cardIdInput = document.getElementById('cardIdInput');
    const originalBtnText = searchBtn ? searchBtn.innerHTML : '';

    if (searchBtn) {
        searchBtn.disabled = true;
        searchBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Searching...';
    }
    if (cardIdInput) {
        cardIdInput.setAttribute('aria-busy', 'true');
    }

    try {
        const response = await apiRequest(`nfc.php?action=search&card_id=${encodeURIComponent(cardId)}`);
        
        if (response.error) {
            showError(response.error);
            document.getElementById('cardDetailsContainer').style.display = 'none';
            document.getElementById('historyContainer').style.display = 'none';
            document.getElementById('emptyState').style.display = 'block';
            currentCard = null;
            return;
        }

        currentCard = response;
        displayCardDetails(response);
        loadTransactionHistory(cardId);
        
        document.getElementById('emptyState').style.display = 'none';
        document.getElementById('cardDetailsContainer').style.display = 'block';
        document.getElementById('historyContainer').style.display = 'block';
        
    } catch (error) {
        showError('Failed to search card: ' + error.message);
        document.getElementById('cardDetailsContainer').style.display = 'none';
        document.getElementById('historyContainer').style.display = 'none';
        document.getElementById('emptyState').style.display = 'block';
    } finally {
        isSearchingCard = false;
        if (searchBtn) {
            searchBtn.disabled = false;
            searchBtn.innerHTML = originalBtnText || 'Search';
        }
        if (cardIdInput) {
            cardIdInput.removeAttribute('aria-busy');
        }
    }
}

/**
 * Display card details
 */
function displayCardDetails(data) {
    const card = data.card;
    const student = data.student;

    document.getElementById('detailCardId').textContent = card.id;
    document.getElementById('detailStudentName').textContent = student?.name || 'Unknown';
    document.getElementById('detailStudentId').textContent = student?.id || 'Unknown';
    document.getElementById('detailBalance').textContent = formatCurrency(card.balance);
}

/**
 * Load and display transaction history
 */
async function loadTransactionHistory(cardId) {
    const historyList = document.getElementById('historyList');
    if (historyList) {
        historyList.innerHTML = '<p class="text-center" style="color: var(--gray-600);">Loading transaction history...</p>';
    }

    try {
        const response = await apiRequest(`nfc.php?action=history&card_id=${encodeURIComponent(cardId)}`);
        
        if (!response.history || response.history.length === 0) {
            document.getElementById('historyList').innerHTML = '<p class="text-center">No transaction history</p>';
            return;
        }

        const historyHtml = response.history.map(transaction => {
            const date = formatDateTime(transaction.created_at);
            const type = transaction.type || 'transaction';
            const amount = formatCurrency(transaction.amount);
            
            let typeLabel = '';
            let typeClass = '';
            
            if (type === 'reload') {
                typeLabel = 'Balance Reload';
                typeClass = 'history-reload';
            } else if (transaction.type === 'debit') {
                typeLabel = 'Deduction';
                typeClass = 'history-debit';
            } else {
                typeLabel = 'Transaction';
                typeClass = 'history-transaction';
            }

            return `
                <div class="history-item ${typeClass}">
                    <div class="history-header">
                        <span class="history-type">${typeLabel}</span>
                        <span class="history-date">${date}</span>
                    </div>
                    <div class="history-details">
                        <div class="history-row">
                            <span>Amount:</span>
                            <span class="amount">${amount}</span>
                        </div>
                        <div class="history-row">
                            <span>Before:</span>
                            <span>${formatCurrency(transaction.balance_before)}</span>
                        </div>
                        <div class="history-row">
                            <span>After:</span>
                            <span>${formatCurrency(transaction.balance_after)}</span>
                        </div>
                        ${transaction.reason ? `<div class="history-row"><span>Reason:</span><span>${transaction.reason}</span></div>` : ''}
                    </div>
                </div>
            `;
        }).join('');

        document.getElementById('historyList').innerHTML = historyHtml;
        
    } catch (error) {
        console.error('Failed to load history:', error);
        document.getElementById('historyList').innerHTML = '<p class="text-center error">Failed to load history</p>';
    }
}

/**
 * Open load balance modal
 */
function openLoadBalanceModal(cardId) {
    document.getElementById('loadCardId').value = cardId;
    document.getElementById('loadAmount').value = '';
    document.getElementById('loadBalanceModal').classList.add('show');
}

/**
 * Close load balance modal
 */
function closeLoadModal() {
    document.getElementById('loadBalanceModal').classList.remove('show');
}

/**
 * Handle load balance form submission
 */
async function handleLoadBalance(event) {
    event.preventDefault();

    const cardId = document.getElementById('loadCardId').value;
    const amount = parseFloat(document.getElementById('loadAmount').value);

    if (!amount || amount <= 0) {
        showError('Please enter a valid amount');
        return;
    }

    try {
        const response = await apiRequest('nfc.php?action=load', {
            method: 'POST',
            body: JSON.stringify({
                card_id: cardId,
                amount: amount
            })
        });

        if (response.error) {
            showError(response.error);
            return;
        }

        showSuccess('Balance loaded successfully!');
        closeLoadModal();
        
        // Refresh card details and history
        searchCard(cardId);
        
    } catch (error) {
        showError('Failed to load balance: ' + error.message);
    }
}

/**
 * Open deduct balance modal
 */
function openDeductBalanceModal(cardId) {
    document.getElementById('deductCardId').value = cardId;
    document.getElementById('deductAmount').value = '';
    document.getElementById('deductReason').value = '';
    document.getElementById('deductBalanceModal').classList.add('show');
}

/**
 * Close deduct balance modal
 */
function closeDeductModal() {
    document.getElementById('deductBalanceModal').classList.remove('show');
}

/**
 * Handle deduct balance form submission
 */
async function handleDeductBalance(event) {
    event.preventDefault();

    const cardId = document.getElementById('deductCardId').value;
    const amount = parseFloat(document.getElementById('deductAmount').value);
    const reason = document.getElementById('deductReason').value || 'Manual deduction';

    if (!amount || amount <= 0) {
        showError('Please enter a valid amount');
        return;
    }

    try {
        const response = await apiRequest('nfc.php?action=deduct', {
            method: 'POST',
            body: JSON.stringify({
                card_id: cardId,
                amount: amount,
                reason: reason
            })
        });

        if (response.error) {
            showError(response.error);
            return;
        }

        showSuccess('Balance deducted successfully!');
        closeDeductModal();
        
        // Refresh card details and history
        searchCard(cardId);
        
    } catch (error) {
        showError('Failed to deduct balance: ' + error.message);
    }
}

// Close modals when clicking outside
document.addEventListener('click', (e) => {
    const loadModal = document.getElementById('loadBalanceModal');
    const deductModal = document.getElementById('deductBalanceModal');
    
    if (e.target === loadModal) {
        closeLoadModal();
    }
    if (e.target === deductModal) {
        closeDeductModal();
    }
});
