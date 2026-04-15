/**
 * UniDiPay - Common JavaScript Functions
 * Shared utilities for all pages
 */

// API Base URL
const API_BASE = 'php/api/';
const FONT_AWESOME_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css';

function ensureFontAwesome() {
    if (document.querySelector('link[data-fa-cdn="true"]')) return;

    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = FONT_AWESOME_CDN;
    link.setAttribute('data-fa-cdn', 'true');
    document.head.appendChild(link);
}

function applyBranding() {
    const logoCandidates = [
        'assets/UNIDIPAY%20LOGO.png',
        'assets/unidipay-logo.png',
        'assets/unidipay-logo.jpg',
        'assets/unidipay-logo.jpeg',
        'assets/unidipay-logo.webp'
    ];

    const assignLogoWithFallback = (imgElement, fallbackSrc = '') => {
        if (!imgElement) return;
        let attempt = 0;

        const loadNext = () => {
            if (attempt >= logoCandidates.length) {
                imgElement.onerror = null;
                if (fallbackSrc && !logoCandidates.includes(fallbackSrc)) {
                    imgElement.src = fallbackSrc;
                }
                return;
            }
            imgElement.src = logoCandidates[attempt++];
        };

        imgElement.onerror = loadNext;
        loadNext();
    };

    const sidebarLogo = document.querySelector('.sidebar-logo');
    if (sidebarLogo && !sidebarLogo.querySelector('img')) {
        const sidebarImg = document.createElement('img');
        sidebarImg.alt = 'UniDiPay Logo';
        assignLogoWithFallback(sidebarImg);

        sidebarImg.onload = () => {
            sidebarLogo.textContent = '';
            if (!sidebarLogo.querySelector('img')) {
                sidebarLogo.appendChild(sidebarImg);
            }
        };
    }

    const loginBrandImg = document.querySelector('img[data-brand="true"]');
    assignLogoWithFallback(loginBrandImg, loginBrandImg?.getAttribute('src') || '');
}

function upgradeLegacyIcons() {
    const navIconMap = {
        'dashboard.html': { icon: 'fa-chart-line', label: 'Dashboard' },
        'users.html': { icon: 'fa-users', label: 'User Management' },
        'EmloyeeRoles.html': { icon: 'fa-user-gear', label: 'Employee Roles' },
        'faq.html': { icon: 'fa-comments', label: 'Messages' },
        'nfc.html': { icon: 'fa-credit-card', label: 'RFID Management' },
        'menu.html': { icon: 'fa-utensils', label: 'Menu Management' },
        'orders.html': { icon: 'fa-box', label: 'Order Monitoring' },
        'reports.html': { icon: 'fa-chart-column', label: 'Records & Reports' },
        'Uniform.html': { icon: 'fa-shirt', label: 'Uniforms' },
        'shifts.html': { icon: 'fa-clock', label: 'Shifts' },
        'Shifts.html': { icon: 'fa-clock', label: 'Shifts' },
        'audit_logs.html': { icon: 'fa-shield-halved', label: 'Audit Logs' },
        'activity_logs.html': { icon: 'fa-clipboard-list', label: 'Activity Logs' }
    };

    document.querySelectorAll('.sidebar-nav .nav-item').forEach((item) => {
        const href = item.getAttribute('href');
        const config = navIconMap[href];
        if (!config) return;

        item.innerHTML = `<i class="fa-solid ${config.icon}" aria-hidden="true"></i><span>${config.label}</span>`;
    });

    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.innerHTML = '<i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><span>Logout</span>';
    }

    document.querySelectorAll('.btn-icon.btn-edit').forEach((btn) => {
        btn.innerHTML = '<i class="fa-solid fa-pen" aria-hidden="true"></i>';
    });

    document.querySelectorAll('.btn-icon.btn-delete, .btn-item-delete').forEach((btn) => {
        btn.innerHTML = '<i class="fa-solid fa-trash" aria-hidden="true"></i>';
    });

    document.querySelectorAll('.search-input, #searchInput, #userSearch, #cardIdInput').forEach((input) => {
        if (typeof input.placeholder === 'string') {
            input.placeholder = input.placeholder.replace(/[\u{1F300}-\u{1FAFF}]/gu, '').trim();
        }
    });
}

/**
 * Check if user is authenticated
 */
async function checkAuth() {
    try {
        const response = await fetch(`${API_BASE}auth.php?action=check`);
        const data = await response.json();
        return data.loggedIn;
    } catch (error) {
        console.error('Auth check error:', error);
        return false;
    }
}

/**
 * Get logged in admin info
 */
function getAdmin() {
    const admin = localStorage.getItem('admin');
    return admin ? JSON.parse(admin) : null;
}

/**
 * Logout user
 */
async function logout() {
    try {
        await fetch(`${API_BASE}auth.php?action=logout`);
        localStorage.removeItem('admin');
        window.location.href = 'index.html';
    } catch (error) {
        console.error('Logout error:', error);
    }
}

/**
 * Make authenticated API request
 */
async function apiRequest(endpoint, options = {}) {
    try {
        const response = await fetch(`${API_BASE}${endpoint}`, {
            ...options,
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            }
        });

        const rawBody = await response.text();
        let data = {};

        if (rawBody) {
            try {
                data = JSON.parse(rawBody);
            } catch (parseError) {
                const fallbackMessage = response.ok
                    ? 'Unexpected server response format'
                    : `Request failed (${response.status})`;
                throw new Error(fallbackMessage);
            }
        }

        if (!response.ok) {
            throw new Error(data.error || `Request failed (${response.status})`);
        }

        return data;
    } catch (error) {
        console.error('API Error:', error);
        throw error;
    }
}

/**
 * Show success message
 */
function showSuccess(message) {
    showToast(message, 'success');
}

/**
 * Show error message
 */
function showError(message) {
    showToast(message, 'error');
}

/**
 * Simple toast notification
 */
function showToast(message, type = 'info') {
    // Remove existing toast
    const existingToast = document.querySelector('.toast');
    if (existingToast) {
        existingToast.remove();
    }
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    const icon = type === 'success'
        ? 'fa-circle-check'
        : type === 'error'
            ? 'fa-circle-exclamation'
            : 'fa-circle-info';

    toast.innerHTML = `<i class="fa-solid ${icon}" aria-hidden="true"></i><span>${message}</span>`;
    
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 16px 24px;
        border-radius: 8px;
        background: ${type === 'success' ? 'linear-gradient(135deg, #059669, #10B981)' : type === 'error' ? 'linear-gradient(135deg, #DC2626, #EF4444)' : 'linear-gradient(135deg, #1D4ED8, #2563EB)'};
        color: white;
        font-size: 14px;
        font-weight: 600;
        box-shadow: 0 18px 34px -20px rgba(0, 0, 0, 0.55);
        display: inline-flex;
        align-items: center;
        gap: 10px;
        z-index: 9999;
        animation: slideIn 0.3s ease;
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Add CSS for animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

/**
 * Format currency
 */
function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2);
}

/**
 * Format date/time
 */
function formatDateTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

/**
 * Format date only
 */
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

/**
 * Confirm action
 */
function confirmAction(message) {
    return confirm(message);
}

/**
 * Show loading state
 */
function showLoading(container) {
    container.innerHTML = `
        <div class="loading">
            <div class="loading-spinner"></div>
            <p>Loading...</p>
        </div>
    `;
}

/**
 * Show empty state
 */
function showEmpty(container, message = 'No data available') {
    container.innerHTML = `
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-inbox" aria-hidden="true"></i></div>
            <p>${message}</p>
        </div>
    `;
}

/**
 * Protect page - redirect if not logged in
 */
async function protectPage() {
    const isAuthenticated = await checkAuth();
    if (!isAuthenticated) {
        window.location.href = 'index.html';
    }
    
    // Check role-based access
    checkRoleBasedAccess();
}

/**
 * Get user role
 */
function getUserRole() {
    const admin = getAdmin();
    const normalized = (admin?.role || '').toLowerCase();
    if (normalized === 'manager' || normalized === 'staff' || normalized === 'cashier') {
        return normalized;
    }
    return 'staff';
}

/**
 * Format role label for UI display
 */
function formatRoleLabel(role) {
    const normalized = (role || '').toLowerCase();
    const labels = {
        manager: 'Manager',
        staff: 'Staff',
        cashier: 'Cashier'
    };

    return labels[normalized] || 'Staff';
}

/**
 * Check if user has access to current page
 */
function checkRoleBasedAccess() {
    const currentPage = window.location.pathname.split('/').pop() || 'dashboard.html';
    const role = getUserRole();
    
    // Define role-based access matrix
    const accessMatrix = {
        'manager': [
            'dashboard.html', 
            'EmloyeeRoles.html', 
            'menu.html', 
            'nfc.html', 
            'orders.html', 
            'reports.html', 
            'users.html',
            'Uniform.html',
            'shifts.html',
            'Shifts.html'
        ],
        'staff': [
            'menu.html', 
            'orders.html'
        ],
        'cashier': [
            'orders.html', 
            'menu.html', 
            'reports.html', 
            'nfc.html'
        ]
    };
    
    const allowedPages = accessMatrix[role] || [];
    
    // Check if user has access to this page
    if (currentPage && !allowedPages.includes(currentPage)) {
        console.warn(`Access Denied: ${role} cannot access ${currentPage}`);
        
        // Redirect to allowed page based on role
        const defaultPages = {
            'manager': 'dashboard.html',
            'staff': 'menu.html',
            'cashier': 'orders.html'
        };
        
        window.location.href = defaultPages[role] || 'index.html';
    }
    
    // Hide nav items based on role - use setTimeout to ensure DOM is ready
    setTimeout(() => {
        updateNavigation(role);
    }, 100);
}

/**
 * Update navigation based on user role
 */
function updateNavigation(role) {
    const navItems = document.querySelectorAll('.nav-item');
    const comingSoonPages = ['Uniform.html', 'shifts.html', 'Shifts.html'];
    
    const accessMatrix = {
        'manager': [
            'dashboard.html', 
            'users.html',
            'EmloyeeRoles.html', 
            'nfc.html', 
            'menu.html', 
            'orders.html', 
            'reports.html',
            'Uniform.html',
            'shifts.html',
            'Shifts.html'
        ],
        'staff': [
            'menu.html', 
            'orders.html'
        ],
        'cashier': [
            'orders.html', 
            'menu.html', 
            'reports.html', 
            'nfc.html'
        ]
    };
    
    const allowedPages = accessMatrix[role] || [];
    
    // Show coming-soon lock marker only for manager on target pages.
    navItems.forEach(item => {
        const href = item.getAttribute('href');
        const isAllowed = allowedPages.includes(href);
        const showComingSoonForManager = role === 'manager' && comingSoonPages.includes(href);

        item.classList.remove('nav-item-locked', 'nav-item-coming-soon', 'hidden');
        item.style.removeProperty('display');
        item.removeAttribute('aria-disabled');
        item.removeAttribute('title');
        item.removeAttribute('data-coming-soon');
        item.onclick = null;

        if (!isAllowed) {
            // Hide all unauthorized entries for non-manager roles.
            item.classList.add('hidden');
            item.style.setProperty('display', 'none', 'important');
            return;
        }

        if (showComingSoonForManager) {
            item.classList.add('nav-item-coming-soon');
            item.setAttribute('title', 'Coming soon');
            item.setAttribute('data-coming-soon', 'true');
        }
    });
    
    console.log(`Navigation filtered for role: ${role}, showing ${allowedPages.length} pages`);
}

/**
 * Check if user can perform action (admin only)
 */
function canManageEmployees() {
    return getUserRole() === 'manager';
}

/**
 * Protect admin-only pages
 */
async function protectAdminPage() {
    const isAuthenticated = await checkAuth();
    if (!isAuthenticated) {
        window.location.href = 'index.html';
        return;
    }
    
    if (!canManageEmployees()) {
        alert('Only managers can access this page');
        window.location.href = 'index.html';
    }
    
    checkRoleBasedAccess();
}

/**
 * Initialize common elements on dashboard pages
 */
function initDashboard() {
    ensureFontAwesome();
    applyBranding();

    // Set admin name
    const admin = getAdmin();
    const adminNameEl = document.getElementById('adminName');
    if (adminNameEl && admin) {
        const roleLabel = formatRoleLabel(admin.role);
        adminNameEl.textContent = `${admin.name} (${roleLabel})`;
    }
    
    // Setup logout button
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            logout();
        });
    }
    
    // Highlight current nav item
    highlightCurrentNav();
    
    // Filter navigation based on role
    const role = getUserRole();
    updateNavigation(role);

    // Normalize old emoji/SVG icon usage into Font Awesome icons
    upgradeLegacyIcons();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        ensureFontAwesome();
        applyBranding();
    });
} else {
    ensureFontAwesome();
    applyBranding();
}

/**
 * Highlight current navigation item
 */
function highlightCurrentNav() {
    const currentPage = window.location.pathname.split('/').pop();
    const navItems = document.querySelectorAll('.nav-item');
    
    navItems.forEach(item => {
        item.classList.remove('active');
        const href = item.getAttribute('href');
        if (href === currentPage) {
            item.classList.add('active');
        }
    });
}

/**
 * Export table to CSV
 */
function exportToCSV(filename, data, headers) {
    let csv = headers.join(',') + '\n';
    
    data.forEach(row => {
        csv += row.map(cell => {
            // Escape commas and quotes
            if (typeof cell === 'string' && (cell.includes(',') || cell.includes('"'))) {
                return '"' + cell.replace(/"/g, '""') + '"';
            }
            return cell;
        }).join(',') + '\n';
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    window.URL.revokeObjectURL(url);
}

/**
 * Debounce function for search
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Load activity logs for a specific target
 * @param {string} target - The target type (e.g., "User", "Employee Role")
 */
async function loadActivityLogs(target) {
    const container = document.getElementById('activityLogList');
    if (!container) return;
    
    container.innerHTML = '<div style="padding:12px;color:var(--gray-600);text-align:center;">Loading activity...</div>';
    
    try {
        const response = await fetch(`${API_BASE}activity_logs.php?target=${encodeURIComponent(target)}`);
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to load activity logs');
        }
        
        const logs = data.logs || [];
        
        if (logs.length === 0) {
            container.innerHTML = '<div style="padding:12px;color:var(--gray-600);text-align:center;">No activity yet</div>';
            return;
        }
        
        container.innerHTML = logs.map(log => {
            const timestamp = formatDateTime(log.created_at);
            return `
                <div class="activity-log-entry">
                    <strong>${log.admin_username}</strong> 
                    ${log.action} 
                    ${log.details}
                    <span class="activity-log-time">${timestamp}</span>
                </div>
            `;
        }).join('');
        
    } catch (error) {
        console.error('Activity log error:', error);
        container.innerHTML = `<div style='color:var(--danger);padding:12px;text-align:center;font-size:13px;'>${error.message}</div>`;
    }
}

// Export functions for use in other scripts
window.UniDiPay = {
    checkAuth,
    getAdmin,
    logout,
    apiRequest,
    showSuccess,
    showError,
    showToast,
    formatCurrency,
    formatDateTime,
    formatDate,
    confirmAction,
    showLoading,
    showEmpty,
    protectPage,
    protectAdminPage,
    getUserRole,
    canManageEmployees,
    checkRoleBasedAccess,
    initDashboard,
    exportToCSV,
    debounce
};
// MENU PAGE MODULE
const Menu = {
    renderMenuGrid(items) {
        const grid = document.getElementById('menuGrid');
        if (!grid) return;

        if (!items || items.length === 0) {
            UniDiPay.showEmpty(grid, 'No menu items found');
            return;
        }

        grid.innerHTML = '';
        const fragment = document.createDocumentFragment();

        items.forEach((item) => {
            fragment.appendChild(this.buildMenuCard(item));
        });

        grid.appendChild(fragment);
    },

    buildMenuCard(item) {
        const card = document.createElement('div');
        card.className = 'menu-card';

        const img = document.createElement('img');
        img.src = item.image_url || 'https://via.placeholder.com/200';
        img.alt = item.name || 'Menu item';
        img.loading = 'lazy';

        const title = document.createElement('div');
        title.className = 'title';
        title.textContent = item.name || '';

        const price = document.createElement('div');
        price.className = 'price';
        price.textContent = UniDiPay.formatCurrency(item.price || 0);

        const desc = document.createElement('div');
        desc.className = 'description';
        desc.style.cssText = 'font-size:12px;color:#666;margin:5px 0;';
        desc.textContent = item.description || '';

        const availability = document.createElement('div');
        availability.className = 'availability';

        const label = document.createElement('label');
        label.className = 'switch';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = !!item.available;
        checkbox.addEventListener('change', (e) => {
            this.toggleAvailability(item.id, e.target.checked);
        });

        const slider = document.createElement('span');
        slider.className = 'slider';

        label.appendChild(checkbox);
        label.appendChild(slider);

        const statusText = document.createElement('span');
        statusText.className = `status-text ${item.available ? 'available' : 'unavailable'}`;
        statusText.textContent = item.available ? 'Available' : 'Unavailable';

        availability.appendChild(label);
        availability.appendChild(statusText);

        const actions = document.createElement('div');
        actions.className = 'actions';

        const editBtn = document.createElement('button');
        editBtn.className = 'btn-edit';
        editBtn.textContent = 'Edit';
        editBtn.addEventListener('click', () => this.editItem(item.id));

        const deleteBtn = document.createElement('button');
        deleteBtn.className = 'btn-delete';
        deleteBtn.textContent = 'Delete';
        deleteBtn.addEventListener('click', () => this.deleteItem(item.id));

        actions.appendChild(editBtn);
        actions.appendChild(deleteBtn);

        card.appendChild(img);
        card.appendChild(title);
        card.appendChild(price);
        card.appendChild(desc);
        card.appendChild(availability);
        card.appendChild(actions);

        return card;
    },

    loadMenuItems: async (category = 'all') => {
        const grid = document.getElementById('menuGrid');
        UniDiPay.showLoading(grid);

        let url = category === 'all'
            ? 'menu.php?action=all'
            : `menu.php?action=category&category=${category}`;

        const res = await UniDiPay.apiRequest(url);
        const items = res.menu_items || [];

        Menu.renderMenuGrid(items);
    },

    openAddModal: () => {
        document.getElementById('modalTitle').textContent = 'Add Menu Item';
        document.getElementById('menuForm').reset();
        document.getElementById('menuModal').classList.add('show');
    },

    editItem: async id => {
        const res = await UniDiPay.apiRequest(`menu.php?action=single&id=${id}`);
        const item = res.menu_item;

        document.getElementById('modalTitle').textContent = 'Edit Menu Item';
        document.getElementById('menuId').value = item.id;
        document.getElementById('menuName').value = item.name;
        document.getElementById('menuCategory').value = item.category;
        document.getElementById('menuPrice').value = item.price;
        document.getElementById('menuDescription').value = item.description;
        document.getElementById('menuImage').value = item.image_url;

        document.getElementById('menuModal').classList.add('show');
    },

    deleteItem: async id => {
        if (!confirm('Delete menu item?')) return;

        await UniDiPay.apiRequest(`menu.php?id=${id}`, { method: 'DELETE' });
        UniDiPay.showSuccess('Menu item deleted');
        Menu.loadMenuItems('all');
    },

    toggleAvailability: async (id, status) => {
        try {
            await UniDiPay.apiRequest('menu.php', {
                method: 'PUT',
                body: JSON.stringify({
                    id: id,
                    available: status ? 1 : 0
                })
            });

            UniDiPay.showSuccess(status ? 'Item set to Available' : 'Item set to Unavailable');

            const activeFilter = document.querySelector('.filter-btn.active');
            const current = activeFilter?.dataset.category || 'all';
            Menu.loadMenuItems(current);

        } catch (err) {
            UniDiPay.showError('Failed to update availability');
            console.error(err);
        }
    }
};

window.Menu = Menu;
