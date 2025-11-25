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

async function api(path, options = {}) {
    try {
        const res = await fetch(`${API_BASE}${path}`, {headers: {'Content-Type': 'application/json'}, ...options});
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        banner.hidden = true;
        return data;
    } catch (e) {
        banner.hidden = false;
        throw e;
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
