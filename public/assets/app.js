'use strict';

// --- helpers ---------------------------------------------------------------

const $ = (id) => document.getElementById(id);

const fmtMoney = (n) => {
    if (n === null || n === undefined) return '—';
    const v = Number(n);
    if (v >= 1_000_000) return '$' + (v / 1_000_000).toFixed(2) + 'M';
    if (v >= 1_000) return '$' + Math.round(v / 1_000) + 'K';
    return '$' + v;
};

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]
));

// Wrap fetch so an expired session (401) bounces the user to the login page.
async function apiFetch(url, opts) {
    const res = await fetch(url, opts);
    if (res.status === 401) {
        window.location = 'login.php';
        throw new Error('Session expired');
    }
    return res;
}

// --- data fetching ---------------------------------------------------------

function currentQuery() {
    const p = new URLSearchParams();
    p.set('source', $('f-source').value);
    p.set('business_type', $('f-type').value);
    p.set('contains', $('f-contains').value);
    p.set('not_contains', $('f-not-contains').value);
    p.set('min_price', $('f-min-price').value);
    p.set('max_price', $('f-max-price').value);
    p.set('min_cash_flow', $('f-min-cf').value);
    if ($('f-new-only').checked) p.set('new_only', '1');
    if ($('f-changed-only').checked) p.set('changed_only', '1');
    if ($('f-starred-only').checked) p.set('starred_only', '1');
    p.set('w_price', $('w-price').value);
    p.set('w_cash_flow', $('w-cf').value);
    p.set('w_proximity', $('w-prox').value);
    p.set('sort', $('sort').value);
    return p.toString();
}

async function loadMeta() {
    const res = await apiFetch('api.php?action=meta');
    const meta = await res.json();
    const sel = $('f-type');
    const current = sel.value;
    sel.innerHTML = '<option value="">All types</option>';
    meta.business_types.forEach((t) => {
        const o = document.createElement('option');
        o.value = t; o.textContent = t;
        sel.appendChild(o);
    });
    sel.value = current;
    const c = meta.counts;
    const stale = c.last_scraped ? new Date(c.last_scraped).toLocaleDateString() : 'never';
    $('meta').innerHTML =
        `<div>${c.total} listings <span class="pill">${c.live} live</span> <span class="pill">${c.sample} sample</span></div>` +
        `<div><span class="pill">${c.new || 0} new</span> <span class="pill">${c.price_changed || 0} price changed</span> <span class="pill">★ ${c.starred || 0}</span></div>` +
        `<div>Last imported: ${stale}</div>`;
}

async function loadResults() {
    const res = await apiFetch('api.php?action=search&' + currentQuery());
    const data = await res.json();
    renderTypeChart(data.trends.by_type);
    renderPriceChart(data.trends.price_histogram);
    renderListings(data.listings, data.anchor);
    $('count').textContent = `(${data.count})`;
}

// --- rendering -------------------------------------------------------------

function barChart(container, rows) {
    const max = Math.max(1, ...rows.map((r) => r.value));
    container.innerHTML = rows.map((r) => `
        <div class="bar-row">
            <div class="label" title="${esc(r.label)}">${esc(r.label)}</div>
            <div class="bar-track"><div class="bar-fill" style="width:${(r.value / max * 100).toFixed(1)}%"></div></div>
            <div class="value">${esc(r.display ?? r.value)}</div>
        </div>`).join('');
}

function renderTypeChart(byType) {
    const rows = byType.slice(0, 10).map((t) => ({
        label: t.type,
        value: t.count,
        display: `${t.count} · avg ${fmtMoney(t.avg_price)}`,
    }));
    barChart($('chart-type'), rows);
}

function renderPriceChart(hist) {
    const rows = Object.entries(hist).map(([label, value]) => ({ label, value, display: value }));
    barChart($('chart-price'), rows);
}

function renderListings(listings, anchor) {
    const root = $('results');
    if (!listings.length) {
        root.innerHTML = '<div class="empty">No listings match these filters.</div>';
        return;
    }
    root.innerHTML = listings.map((l) => {
        const b = l.score_breakdown || {};
        const dist = l.distance_mi !== null ? `${l.distance_mi} mi from ${esc(anchor.label.split(',')[0])}` : 'distance n/a';
        const sample = Number(l.is_sample) ? '<span class="sample-flag">SAMPLE</span>' : '';
        const newBadge = l.is_new ? '<span class="new-flag">NEW</span>' : '';
        const seen = l.first_seen ? `first seen ${new Date(l.first_seen).toLocaleDateString()}` : '';

        // Price-change indicator (down = good for a buyer).
        let priceChange = '';
        if (l.price_change) {
            const pc = l.price_change;
            const down = pc.delta < 0;
            const arrow = down ? '▼' : '▲';
            const when = pc.at ? ` on ${new Date(pc.at).toLocaleDateString()}` : '';
            priceChange = `<div class="price-change ${down ? 'down' : 'up'}">${arrow} ${fmtMoney(Math.abs(pc.delta))}` +
                `${pc.pct !== null ? ` (${pc.pct > 0 ? '+' : ''}${pc.pct}%)` : ''} from ${fmtMoney(pc.previous)}${when}</div>`;
        }

        const starred = Number(l.is_starred) === 1;
        const star = `<button class="star-btn ${starred ? 'on' : ''}" data-source="${esc(l.source)}" data-id="${esc(l.external_id)}" title="${starred ? 'Remove from interesting' : 'Mark as interesting'}">${starred ? '★' : '☆'}</button>`;

        return `
        <div class="listing">
            <div class="score-badge" title="Price ${b.price ?? '—'} / Cash flow ${b.cash_flow ?? '—'} / Proximity ${b.proximity ?? '—'}">
                ${l.score}<small>SCORE</small>
            </div>
            <div>
                <div class="title">${star}${newBadge}<a href="${esc(l.url)}" target="_blank" rel="noopener">${esc(l.title)}</a> ${sample}</div>
                <div class="facts">
                    <span class="tag">${esc(l.business_type || 'Uncategorized')}</span>
                    <span>${esc(l.location || 'DFW')}</span>
                    <span>${dist}</span>
                    <span>via ${esc(l.source)}</span>
                    ${seen ? `<span>${seen}</span>` : ''}
                    <a href="#" class="hist-link" data-source="${esc(l.source)}" data-id="${esc(l.external_id)}">price history</a>
                </div>
                <div class="breakdown">Score parts → price ${b.price ?? '—'} · cash flow ${b.cash_flow ?? '—'} · proximity ${b.proximity ?? '—'}</div>
                <div class="tags-row">
                    <input class="tags-input" type="text" placeholder="add tags (comma separated)…"
                        value="${esc(l.tags || '')}" data-source="${esc(l.source)}" data-id="${esc(l.external_id)}">
                    <span class="tags-saved" hidden>saved ✓</span>
                </div>
                <div class="hist-panel" hidden></div>
            </div>
            <div class="nums">
                <div class="price">${fmtMoney(l.price)}</div>
                ${priceChange}
                <div class="cf">cash flow ${fmtMoney(l.cash_flow)}</div>
                <a class="open-btn" href="${esc(l.url)}" target="_blank" rel="noopener">Open listing →</a>
            </div>
        </div>`;
    }).join('');
}

// Save tag/star metadata for a listing.
async function saveMeta(source, id, fields) {
    const body = new URLSearchParams({ source, external_id: id, ...fields });
    const res = await apiFetch('tag.php', { method: 'POST', body });
    return res.json();
}

// Star toggle (interesting flag).
$('results').addEventListener('click', async (e) => {
    const btn = e.target.closest('.star-btn');
    if (!btn) return;
    const turningOn = !btn.classList.contains('on');
    btn.classList.toggle('on', turningOn);
    btn.textContent = turningOn ? '★' : '☆';
    try {
        await saveMeta(btn.dataset.source, btn.dataset.id, { starred: turningOn ? '1' : '0' });
        await loadMeta(); // refresh the "interesting" count in the header
    } catch (err) {
        // revert on failure
        btn.classList.toggle('on', !turningOn);
        btn.textContent = turningOn ? '☆' : '★';
    }
});

// Tags: save on Enter or blur.
function wireTagSave(input) {
    saveMeta(input.dataset.source, input.dataset.id, { tags: input.value }).then(() => {
        const saved = input.parentElement.querySelector('.tags-saved');
        if (saved) { saved.hidden = false; setTimeout(() => { saved.hidden = true; }, 1500); }
        loadMeta();
    }).catch(() => {});
}
$('results').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.classList.contains('tags-input')) {
        e.preventDefault();
        e.target.blur();
    }
});
$('results').addEventListener('blur', (e) => {
    if (e.target.classList && e.target.classList.contains('tags-input')) {
        wireTagSave(e.target);
    }
}, true);

// Price-history expander (event-delegated since rows are re-rendered).
$('results').addEventListener('click', async (e) => {
    const link = e.target.closest('.hist-link');
    if (!link) return;
    e.preventDefault();
    const panel = link.closest('.listing').querySelector('.hist-panel');
    if (!panel.hidden) { panel.hidden = true; return; }
    panel.hidden = false;
    panel.textContent = 'Loading…';
    try {
        const res = await apiFetch(`api.php?action=history&source=${encodeURIComponent(link.dataset.source)}&external_id=${encodeURIComponent(link.dataset.id)}`);
        const data = await res.json();
        const rows = data.history || [];
        panel.innerHTML = rows.length
            ? '<ul class="hist-list">' + rows.map((h) =>
                `<li>${new Date(h.recorded_at).toLocaleDateString()} — ${fmtMoney(h.price)}</li>`).join('') + '</ul>'
            : '<span class="muted">No recorded price changes yet.</span>';
    } catch (err) {
        panel.textContent = 'Could not load history.';
    }
});

// --- wiring ----------------------------------------------------------------

function syncWeightLabels() {
    $('wv-price').textContent = $('w-price').value;
    $('wv-cf').textContent = $('w-cf').value;
    $('wv-prox').textContent = $('w-prox').value;
}

['w-price', 'w-cf', 'w-prox'].forEach((id) => $(id).addEventListener('input', syncWeightLabels));
$('apply').addEventListener('click', loadResults);
$('sort').addEventListener('change', loadResults);
['f-new-only', 'f-changed-only', 'f-starred-only'].forEach((id) => $(id).addEventListener('change', loadResults));
$('reset').addEventListener('click', () => {
    ['f-source', 'f-type', 'f-contains', 'f-not-contains', 'f-min-price', 'f-max-price', 'f-min-cf'].forEach((id) => $(id).value = '');
    $('f-new-only').checked = false; $('f-changed-only').checked = false; $('f-starred-only').checked = false;
    $('w-price').value = 30; $('w-cf').value = 40; $('w-prox').value = 30;
    syncWeightLabels();
    loadResults();
});

// --- import ----------------------------------------------------------------

$('import-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const out = $('import-result');
    const btn = $('i-submit');
    const hasFile = $('i-files').files.length > 0;
    const hasPaste = $('i-html').value.trim() !== '';
    if (!hasFile && !hasPaste) {
        out.className = 'import-result err';
        out.textContent = 'Choose at least one HTML file or paste page source.';
        return;
    }

    btn.disabled = true;
    out.className = 'import-result';
    out.textContent = 'Importing…';
    try {
        const res = await apiFetch('import.php', { method: 'POST', body: new FormData(e.target) });
        const data = await res.json();
        if (!res.ok || data.error) {
            out.className = 'import-result err';
            out.textContent = data.error || 'Import failed.';
            return;
        }
        const detail = (data.files || []).map((f) =>
            `<li>${esc(f.file)}: parsed ${f.parsed}, excluded ${f.excluded}, imported ${f.imported}</li>`).join('');
        const previewRows = (data.files || []).flatMap((f) => f.preview || []);
        const preview = previewRows.length ? `
            <table class="preview-table">
                <thead><tr><th>Title</th><th>Price</th><th>Cash flow</th><th>Gross</th><th>Location</th></tr></thead>
                <tbody>${previewRows.map((p) => `<tr>
                    <td>${esc(p.title)}</td>
                    <td>${fmtMoney(p.price)}</td>
                    <td>${fmtMoney(p.cash_flow)}</td>
                    <td>${fmtMoney(p.gross_revenue)}</td>
                    <td>${esc(p.location || '—')}</td>
                </tr>`).join('')}</tbody>
            </table>` : '';
        out.className = 'import-result ok';
        out.innerHTML = `Imported ${data.imported} listing(s) from ${esc(data.source)}.` +
            (detail ? `<ul>${detail}</ul>` : '') +
            ((data.errors && data.errors.length) ? `<ul>${data.errors.map((x) => `<li>${esc(x)}</li>`).join('')}</ul>` : '') +
            preview;
        $('i-files').value = '';
        $('i-html').value = '';
        // Refresh header counts, type list, and results to include new rows.
        await loadMeta();
        await loadResults();
    } catch (err) {
        out.className = 'import-result err';
        out.textContent = 'Import failed: ' + err.message;
    } finally {
        btn.disabled = false;
    }
});

// --- clear database --------------------------------------------------------

$('clear-btn').addEventListener('click', async () => {
    const scope = $('clear-scope').value;
    const labels = { all: 'ALL listings', live: 'all scraped/imported listings', samples: 'sample listings' };
    if (!confirm(`Delete ${labels[scope]}? This cannot be undone.`)) {
        return;
    }
    const out = $('clear-result');
    const btn = $('clear-btn');
    btn.disabled = true;
    out.className = 'import-result';
    out.textContent = 'Clearing…';
    try {
        const body = new URLSearchParams({ action: 'clear', scope });
        const res = await apiFetch('admin.php', { method: 'POST', body });
        const data = await res.json();
        if (!res.ok || data.error) {
            out.className = 'import-result err';
            out.textContent = data.error || 'Clear failed.';
            return;
        }
        out.className = 'import-result ok';
        out.textContent = `Removed ${data.removed} listing(s).`;
        await loadMeta();
        await loadResults();
    } catch (err) {
        out.className = 'import-result err';
        out.textContent = 'Clear failed: ' + err.message;
    } finally {
        btn.disabled = false;
    }
});

(async function init() {
    syncWeightLabels();
    await loadMeta();
    await loadResults();
})();
