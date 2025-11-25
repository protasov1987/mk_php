const sections = document.querySelectorAll('.section');
const navButtons = document.querySelectorAll('.nav-tabs button');
const subtabs = document.querySelectorAll('.subtab');
const subtabContent = document.querySelectorAll('.subtab-content');
const banner = document.getElementById('server-banner');
const cardsTableBody = document.querySelector('#cards-table tbody');
const trackerTableBody = document.querySelector('#tracker-table tbody');
const archiveTableBody = document.querySelector('#archive-table tbody');
const modal = document.getElementById('card-modal');
const modalSave = document.getElementById('card-modal-save');
const modalCancel = document.getElementById('card-modal-cancel');

function setClock() {
    const now = new Date();
    document.getElementById('clock').textContent = now.toLocaleString('ru-RU');
}
setInterval(setClock, 1000);
setClock();

navButtons.forEach(btn => btn.addEventListener('click', () => {
    navButtons.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    sections.forEach(sec => sec.classList.toggle('active', sec.id === btn.dataset.target));
}));

subtabs.forEach(btn => btn.addEventListener('click', () => {
    subtabs.forEach(b => b.classList.remove('active'));
    subtabContent.forEach(c => c.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(btn.dataset.subtab).classList.add('active');
}));

function statusBadge(status) {
    return `<span class="badge ${status}">${status}</span>`;
}

const OFFLINE_KEY = 'tszp_offline_cache';
let offlineMode = false;

function readOffline() {
    const raw = localStorage.getItem(OFFLINE_KEY);
    if (!raw) return {cards: [], centers: [], operations: []};
    try {
        return JSON.parse(raw);
    } catch (e) {
        return {cards: [], centers: [], operations: []};
    }
}

function writeOffline(data) {
    localStorage.setItem(OFFLINE_KEY, JSON.stringify(data));
}

function generateEan13() {
    const base = String(Date.now()).slice(-12).padStart(12, '0');
    const digits = base.split('').map(Number);
    let sum = 0;
    for (let i = 0; i < 12; i++) {
        sum += digits[i] * (i % 2 === 0 ? 1 : 3);
    }
    const checksum = (10 - (sum % 10)) % 10;
    return base + checksum;
}

function offlineApi(path, options = {}) {
    const data = readOffline();
    const method = (options.method || 'GET').toUpperCase();
    const url = new URL(path, 'http://dummy');
    const [resource, id, subresource] = url.pathname.replace(/^\//, '').split('/');

    if (resource === 'cards') {
        if (method === 'GET' && !id) {
            const q = url.searchParams.get('q')?.toLowerCase() || '';
            const status = url.searchParams.get('status');
            const archived = url.searchParams.get('archived') === '1';
            const items = data.cards
                .filter(c => (archived ? c.archived_at : !c.archived_at))
                .filter(c => !q || c.name.toLowerCase().includes(q) || (c.order_no || '').toLowerCase().includes(q) || (c.ean13 || '').toLowerCase().includes(q))
                .filter(c => !status || c.status === status)
                .sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
            return {items};
        }
        if (method === 'POST' && !id) {
            const body = options.body ? JSON.parse(options.body) : {};
            const card = {
                id: Date.now(),
                ean13: generateEan13(),
                name: body.name,
                qty: body.qty || null,
                order_no: body.order_no || null,
                drawing: body.drawing || null,
                material: body.material || null,
                description: body.description || null,
                status: 'NOT_STARTED',
                created_at: new Date().toISOString(),
                updated_at: new Date().toISOString(),
                archived_at: null,
            };
            data.cards.unshift(card);
            writeOffline(data);
            return {id: card.id, ean13: card.ean13};
        }
        if (id && method === 'POST' && subresource === 'archive') {
            const card = data.cards.find(c => String(c.id) === id);
            if (card) {
                card.archived_at = new Date().toISOString();
                card.status = 'DONE';
                writeOffline(data);
            }
            return {success: true};
        }
        if (id && method === 'POST' && subresource === 'repeat') {
            const original = data.cards.find(c => String(c.id) === id);
            if (original) {
                const clone = {...original, id: Date.now(), ean13: generateEan13(), status: 'NOT_STARTED', archived_at: null, created_at: new Date().toISOString(), updated_at: new Date().toISOString()};
                data.cards.unshift(clone);
                writeOffline(data);
                return {id: clone.id, ean13: clone.ean13};
            }
            return {error: 'not_found'};
        }
    }

    if (resource === 'centers') {
        if (method === 'GET') return {items: data.centers};
        if (method === 'POST') {
            const body = options.body ? JSON.parse(options.body) : {};
            data.centers.push({id: Date.now(), name: body.name, description: body.description});
            writeOffline(data);
            return {success: true};
        }
    }

    if (resource === 'operations') {
        if (method === 'GET') return {items: data.operations};
        if (method === 'POST') {
            const body = options.body ? JSON.parse(options.body) : {};
            data.operations.push({id: Date.now(), code: body.code, name: body.name, description: body.description, recommended_time_minutes: body.recommended_time_minutes});
            writeOffline(data);
            return {success: true};
        }
    }

    return {items: []};
}

async function api(path, options = {}) {
    const headers = {'Content-Type': 'application/json', ...(options.headers || {})};
    try {
        const res = await fetch(`${API_BASE}${path}`, {headers, ...options});
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        banner.hidden = true;
        offlineMode = false;
        return data;
    } catch (e) {
        banner.hidden = false;
        offlineMode = true;
        return offlineApi(path, {...options, headers});
    }
}

async function loadCards() {
    const q = document.getElementById('card-search').value;
    const status = document.getElementById('card-status').value;
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (status) params.set('status', status);
    const data = await api(`/cards?${params.toString()}`);
    cardsTableBody.innerHTML = data.items.map(c => `<tr><td>${c.ean13}</td><td>${c.name}</td><td>${c.order_no ?? ''}</td><td>${statusBadge(c.status)}</td><td><button data-id="${c.id}" class="archive-btn">В архив</button></td></tr>`).join('');
}

async function loadTracker() {
    const q = document.getElementById('tracker-search').value;
    const status = document.getElementById('tracker-status').value;
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (status) params.set('status', status);
    const data = await api(`/cards?${params.toString()}`);
    trackerTableBody.innerHTML = data.items.map(c => `<tr><td>${c.ean13}</td><td>${c.name}</td><td>${statusBadge(c.status)}</td><td>${c.description ?? ''}</td></tr>`).join('');
}

async function loadArchive() {
    const q = document.getElementById('archive-search').value;
    const status = document.getElementById('archive-status').value;
    const params = new URLSearchParams();
    params.set('archived', '1');
    if (q) params.set('q', q);
    if (status) params.set('status', status);
    const data = await api(`/cards?${params.toString()}`);
    archiveTableBody.innerHTML = data.items.map(c => `<tr><td>${c.ean13}</td><td>${c.name}</td><td>${c.order_no ?? ''}</td><td><button data-id="${c.id}" class="repeat-btn">Повторить</button></td></tr>`).join('');
}

async function loadDashboard() {
    const data = await api('/cards');
    const statusCounts = data.items.reduce((acc, card) => {
        acc[card.status] = (acc[card.status] || 0) + 1;
        return acc;
    }, {});
    const statuses = ['NOT_STARTED','IN_PROGRESS','PAUSED','MIXED','DONE'];
    document.getElementById('status-stats').innerHTML = statuses.map(s => `<div class="status-pill"><span>${s}</span><strong>${statusCounts[s] || 0}</strong></div>`).join('');
    const recent = data.items.slice(0, 5).map(c => `<div class="item"><div>${c.name}</div><div>${statusBadge(c.status)}</div></div>`).join('');
    document.getElementById('recent-cards').innerHTML = recent || '<div class="hint">Нет активных карт</div>';
}

async function loadRefs() {
    const ops = await api('/operations');
    document.getElementById('operations-list').innerHTML = ops.items.map(o => `<div class="item">${o.code} — ${o.name}</div>`).join('');
    const centers = await api('/centers');
    document.getElementById('centers-list').innerHTML = centers.items.map(c => `<div class="item">${c.name}</div>`).join('');
}

function openModal() {
    modal.hidden = false;
}
function closeModal() {
    modal.hidden = true;
    document.getElementById('modal-card-name').value = '';
    document.getElementById('modal-card-qty').value = '';
    document.getElementById('modal-card-order').value = '';
    document.getElementById('modal-card-material').value = '';
    document.getElementById('modal-card-drawing').value = '';
    document.getElementById('modal-card-description').value = '';
}

document.getElementById('card-create').addEventListener('click', openModal);
modalCancel.addEventListener('click', closeModal);
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

modalSave.addEventListener('click', async () => {
    const payload = {
        name: document.getElementById('modal-card-name').value,
        qty: document.getElementById('modal-card-qty').value,
        order_no: document.getElementById('modal-card-order').value,
        material: document.getElementById('modal-card-material').value,
        drawing: document.getElementById('modal-card-drawing').value,
        description: document.getElementById('modal-card-description').value,
    };
    await api('/cards', {method: 'POST', body: JSON.stringify(payload)});
    closeModal();
    loadCards();
    loadDashboard();
});

document.getElementById('card-reset').addEventListener('click', () => { document.getElementById('card-search').value=''; document.getElementById('card-status').value=''; loadCards(); });
document.getElementById('tracker-reset').addEventListener('click', () => { document.getElementById('tracker-search').value=''; document.getElementById('tracker-status').value=''; loadTracker(); });
document.getElementById('archive-reset').addEventListener('click', () => { document.getElementById('archive-search').value=''; document.getElementById('archive-status').value=''; loadArchive(); });

cardsTableBody.addEventListener('click', async e => {
    if (e.target.classList.contains('archive-btn')) {
        const id = e.target.dataset.id;
        await api(`/cards/${id}/archive`, {method: 'POST'});
        loadCards();
        loadArchive();
        loadDashboard();
    }
});
archiveTableBody.addEventListener('click', async e => {
    if (e.target.classList.contains('repeat-btn')) {
        const id = e.target.dataset.id;
        await api(`/cards/${id}/repeat`, {method: 'POST'});
        loadCards();
        loadDashboard();
    }
});

document.getElementById('center-save').addEventListener('click', async () => {
    await api('/centers', {method: 'POST', body: JSON.stringify({name: document.getElementById('center-name').value, description: document.getElementById('center-description').value})});
    document.getElementById('center-name').value = '';
    document.getElementById('center-description').value = '';
    loadRefs();
});

document.getElementById('op-save').addEventListener('click', async () => {
    await api('/operations', {method: 'POST', body: JSON.stringify({code: document.getElementById('op-code').value, name: document.getElementById('op-name').value, description: document.getElementById('op-description').value, recommended_time_minutes: document.getElementById('op-time').value})});
    document.getElementById('op-code').value = '';
    document.getElementById('op-name').value = '';
    document.getElementById('op-description').value = '';
    document.getElementById('op-time').value = '';
    loadRefs();
});

async function init() {
    await Promise.all([loadDashboard(), loadCards(), loadTracker(), loadArchive(), loadRefs()]).catch(() => {});
}

init();
