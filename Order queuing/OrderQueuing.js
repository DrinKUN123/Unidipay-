const processingList = document.getElementById('processingList');
const readyList = document.getElementById('readyList');
const processingCount = document.getElementById('processingCount');
const readyCount = document.getElementById('readyCount');
const lastUpdated = document.getElementById('lastUpdated');

const API_URL = '../php/api/orders.php?action=all';
const POLL_INTERVAL_MS = 2000;
const DISPLAY_STATUSES = ['processing', 'ready'];

let isPolling = false;
let lastDataSignature = '';
let previousOrderState = new Map();

function normalizeStatus(status) {
	return String(status || '').trim().toLowerCase();
}

function toTimestamp(value) {
	if (!value) {
		return 0;
	}

	const parsed = Date.parse(value);
	return Number.isNaN(parsed) ? 0 : parsed;
}

function sortByNewest(orders) {
	return orders.slice().sort((a, b) => {
		return toTimestamp(b.created_at) - toTimestamp(a.created_at);
	});
}

function orderSignature(order) {
	const normalizedStatus = normalizeStatus(order.status);

	return [
		order.id,
		normalizedStatus,
		order.student_id || '',
		order.updated_at || ''
	].join('|');
}

function listSignature(orders) {
	return sortByNewest(orders).map(orderSignature).join('||');
}

function createOrderCard(order) {
	const card = document.createElement('article');
	const normalizedStatus = normalizeStatus(order.status);

	card.className = `order-card status-${normalizedStatus}`;
	card.dataset.orderId = String(order.id);
	card.dataset.signature = orderSignature(order);

	card.innerHTML = `
		<div class="order-top">
			<h2>Order #${order.id}</h2>
		</div>
	`;

	return card;
}

function triggerCardEffect(card, effectClass) {
	if (!effectClass) {
		return;
	}

	card.classList.remove(effectClass);
	void card.offsetWidth;
	card.classList.add(effectClass);

	card.addEventListener('animationend', () => {
		card.classList.remove(effectClass);
	}, { once: true });
}

function updateCardContent(card, order) {
	const normalizedStatus = normalizeStatus(order.status);
	const updatedCard = createOrderCard(order);

	card.className = `order-card status-${normalizedStatus}`;
	card.dataset.signature = updatedCard.dataset.signature;
	card.innerHTML = updatedCard.innerHTML;
}

function clearEmptyMessage(container) {
	const empty = container.querySelector('.empty-message, .loading-message, .error-message');
	if (empty) {
		empty.remove();
	}
}

function showEmptyMessage(container, message) {
	container.innerHTML = `<p class="empty-message">${message}</p>`;
}

function syncOrderList(container, orders, emptyMessage, orderEffects) {
	const sorted = sortByNewest(orders);

	if (!sorted.length) {
		showEmptyMessage(container, emptyMessage);
		return;
	}

	clearEmptyMessage(container);

	const existingCards = new Map();
	container.querySelectorAll('.order-card').forEach((card) => {
		existingCards.set(card.dataset.orderId, card);
	});

	const incomingIds = new Set();

	sorted.forEach((order) => {
		const id = String(order.id);
		const signature = orderSignature(order);
		const existing = existingCards.get(id);
		const effectClass = orderEffects.get(id) || '';
		incomingIds.add(id);

		if (existing) {
			if (existing.dataset.signature !== signature) {
				updateCardContent(existing, order);
			}
			container.appendChild(existing);
			triggerCardEffect(existing, effectClass);
			return;
		}

		const newCard = createOrderCard(order);
		container.appendChild(newCard);
		triggerCardEffect(newCard, effectClass);
	});

	existingCards.forEach((card, id) => {
		if (!incomingIds.has(id)) {
			card.remove();
		}
	});
}

async function loadQueue() {
	if (isPolling) {
		return;
	}

	isPolling = true;

	try {
		const response = await fetch(API_URL, { cache: 'no-store' });

		if (!response.ok) {
			throw new Error('Failed to fetch orders');
		}

		const data = await response.json();
		const allOrders = Array.isArray(data.orders) ? data.orders : [];

		const filteredOrders = allOrders.filter((order) => {
			return DISPLAY_STATUSES.includes(normalizeStatus(order.status));
		});

		const nextSignature = listSignature(filteredOrders);

		if (nextSignature !== lastDataSignature) {
			const nextOrderState = new Map();
			const orderEffects = new Map();

			filteredOrders.forEach((order) => {
				const id = String(order.id);
				const status = normalizeStatus(order.status);
				const signature = orderSignature(order);
				const previousState = previousOrderState.get(id);

				nextOrderState.set(id, { status, signature });

				if (!previousState) {
					orderEffects.set(id, status === 'ready' ? 'order-enter-ready' : 'order-enter-processing');
					return;
				}

				if (previousState.status !== status) {
					orderEffects.set(id, status === 'ready' ? 'order-promoted-ready' : 'order-enter-processing');
					return;
				}

				if (previousState.signature !== signature) {
					orderEffects.set(id, 'order-soft-update');
				}
			});

			const processingOrders = filteredOrders.filter((order) => {
				return normalizeStatus(order.status) === 'processing';
			});

			const readyOrders = filteredOrders.filter((order) => {
				return normalizeStatus(order.status) === 'ready';
			});

			syncOrderList(processingList, processingOrders, 'No processing orders right now.', orderEffects);
			syncOrderList(readyList, readyOrders, 'No ready orders right now.', orderEffects);

			processingCount.textContent = String(processingOrders.length);
			readyCount.textContent = String(readyOrders.length);

			previousOrderState = nextOrderState;
			lastDataSignature = nextSignature;
		}

		lastUpdated.textContent = `Live: ${new Date().toLocaleTimeString()}`;
	} catch (error) {
		processingList.innerHTML = `<p class="error-message">${error.message}</p>`;
		readyList.innerHTML = `<p class="error-message">${error.message}</p>`;
		processingCount.textContent = '0';
		readyCount.textContent = '0';
		previousOrderState = new Map();
		lastDataSignature = '';
	} finally {
		isPolling = false;
	}
}

loadQueue();
setInterval(loadQueue, POLL_INTERVAL_MS);
