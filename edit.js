(function () {
    'use strict';

    // ── Only activate when ?edit=true is in the URL ──────────────────────
    const params = new URLSearchParams(window.location.search);
    if (params.get('edit') !== 'true') return;

    // ── Regions that are editable ─────────────────────────────────────────
    const SELECTORS = [
        'h1', 'h2', 'h3', 'h4',
        'p', 'li', 'td', 'th',
        'a:not(nav a):not(header a):not(footer a)',
        'span[class*="font-"]',
    ].join(', ');

    // Elements to SKIP (structural / nav / scripts)
    function isSkipped(el) {
        const skip = ['HEADER', 'NAV', 'FOOTER', 'SCRIPT', 'STYLE', 'BUTTON', 'INPUT'];
        let node = el;
        while (node) {
            if (skip.includes(node.tagName)) return true;
            node = node.parentElement;
        }
        return false;
    }

    // ── Inject toolbar ─────────────────────────────────────────────────────
    const bar = document.createElement('div');
    bar.id = 'attrivo-edit-bar';
    bar.innerHTML = `
        <style>
            #attrivo-edit-bar {
                position: fixed; bottom: 24px; right: 24px; z-index: 9999;
                display: flex; align-items: center; gap: 10px;
                background: #1e293b; color: #f8fafc;
                padding: 10px 16px; border-radius: 14px;
                box-shadow: 0 8px 30px rgba(0,0,0,0.35);
                font-family: ui-sans-serif, system-ui, sans-serif;
                font-size: 13px; font-weight: 600;
                user-select: none;
            }
            #attrivo-edit-bar .dot { width:8px; height:8px; border-radius:50%; background:#22c55e; animation: pulse 1.5s infinite; }
            @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
            #attrivo-edit-bar button {
                padding: 6px 14px; border-radius: 8px; border: none;
                cursor: pointer; font-size: 12px; font-weight: 700;
                transition: opacity .15s;
            }
            #attrivo-edit-bar button:hover { opacity: .85; }
            #btn-save   { background:#3b82f6; color:#fff; }
            #btn-reset  { background:#475569; color:#fff; }
            #btn-dl     { background:#10b981; color:#fff; }
        </style>
        <span class="dot"></span>
        <span>Edit Mode</span>
        <button id="btn-save">Save to Browser</button>
        <button id="btn-dl">Download HTML</button>
        <button id="btn-reset">Reset</button>
    `;
    document.body.appendChild(bar);

    // ── Make editable regions contenteditable ─────────────────────────────
    const page = window.location.pathname;
    const storageKey = 'attrivo_edits_' + page;

    function applyEditable() {
        document.querySelectorAll(SELECTORS).forEach(el => {
            if (isSkipped(el)) return;
            el.setAttribute('contenteditable', 'true');
            el.style.outline = '2px dashed #93c5fd';
            el.style.borderRadius = '4px';
            el.style.minWidth = '4px';
        });
    }

    // ── Save to localStorage ──────────────────────────────────────────────
    function collectEdits() {
        const map = {};
        document.querySelectorAll('[contenteditable]').forEach((el, i) => {
            map[i] = el.innerHTML;
        });
        return map;
    }

    function applyEdits(map) {
        const els = document.querySelectorAll('[contenteditable]');
        Object.entries(map).forEach(([i, html]) => {
            if (els[i]) els[i].innerHTML = html;
        });
    }

    function saveToBrowser() {
        localStorage.setItem(storageKey, JSON.stringify(collectEdits()));
        showToast('Saved to browser ✓');
    }

    function resetEdits() {
        if (!confirm('Reset all edits on this page? This cannot be undone.')) return;
        localStorage.removeItem(storageKey);
        location.reload();
    }

    // ── Download updated HTML ─────────────────────────────────────────────
    function downloadHTML() {
        // Temporarily hide the edit bar
        bar.style.display = 'none';
        // Remove contenteditable outlines from clone
        const clone = document.documentElement.cloneNode(true);
        clone.querySelectorAll('[contenteditable]').forEach(el => {
            el.removeAttribute('contenteditable');
            el.style.outline = '';
            el.style.borderRadius = '';
            el.style.minWidth = '';
        });
        clone.querySelector('#attrivo-edit-bar')?.remove();
        const html = '<!DOCTYPE html>\n' + clone.outerHTML;
        const blob = new Blob([html], { type: 'text/html' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        const filename = (page === '/' ? 'index' : page.replace(/\//g, '_').replace(/^_/, '')) + '.html';
        a.download = filename;
        a.click();
        bar.style.display = '';
        showToast('Downloaded ' + filename + ' ✓');
    }

    // ── Toast helper ──────────────────────────────────────────────────────
    function showToast(msg) {
        const t = document.createElement('div');
        Object.assign(t.style, {
            position:'fixed', bottom:'90px', right:'24px', zIndex:'10000',
            background:'#1e293b', color:'#f8fafc', padding:'8px 16px',
            borderRadius:'10px', fontSize:'13px', fontWeight:'600',
            boxShadow:'0 4px 16px rgba(0,0,0,.25)', transition:'opacity .3s',
            fontFamily:'ui-sans-serif,system-ui,sans-serif',
        });
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 350); }, 2200);
    }

    // ── Wire up buttons ───────────────────────────────────────────────────
    document.getElementById('btn-save').addEventListener('click', saveToBrowser);
    document.getElementById('btn-reset').addEventListener('click', resetEdits);
    document.getElementById('btn-dl').addEventListener('click', downloadHTML);

    // ── On load: apply editable, then restore saved edits ────────────────
    applyEditable();
    const saved = localStorage.getItem(storageKey);
    if (saved) {
        try { applyEdits(JSON.parse(saved)); } catch (e) {}
    }

    // ── Auto-save on every edit ───────────────────────────────────────────
    document.addEventListener('input', () => {
        localStorage.setItem(storageKey, JSON.stringify(collectEdits()));
    });

})();
