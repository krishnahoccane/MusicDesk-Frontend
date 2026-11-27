import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

window.Alpine = Alpine;
Alpine.plugin(collapse);

// Row "⋯" menus inside tables: the menu is teleported to <body> and placed under (or above) the button,
// so the table's overflow can't clip it. Only one row menu is open at a time: opening one closes the rest.
let rowMenuSeq = 0;
Alpine.data('rowMenu', () => ({
    m: false, x: 0, y: 0, uid: ++rowMenuSeq,
    init() {
        // When another row menu opens, close this one.
        window.addEventListener('rowmenu-open', (e) => { if (e.detail !== this.uid) this.m = false; });
    },
    toggle(e) {
        const r = e.currentTarget.getBoundingClientRect();
        this.m = !this.m;
        if (!this.m) return;
        window.dispatchEvent(new CustomEvent('rowmenu-open', { detail: this.uid }));
        this.x = Math.max(8, Math.min(r.right - 224, window.innerWidth - 232));
        this.y = r.bottom + 4;
        this.$nextTick(() => {
            const h = this.$refs.menu?.offsetHeight ?? 0;
            if (this.y + h > window.innerHeight - 8) this.y = Math.max(8, r.top - h - 4);
        });
    },
}));

// Browser time zone → cookie. The server prefers the IP-detected zone and falls back to this
// (e.g. on localhost). All dates are stored in UTC and converted for display only.
try {
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (tz) document.cookie = `tz=${encodeURIComponent(tz)}; path=/; max-age=31536000; samesite=lax`;
} catch (e) {}

// Copy-to-clipboard helper used by license keys, API tokens, etc.
Alpine.magic('copy', () => async (text) => {
    try {
        await navigator.clipboard.writeText(text);
    } catch (e) {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
    }
    window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Copied to clipboard' } }));
});

// JSON calls to this site's own endpoints (portal license actions). Shows errors as a toast and rethrows.
window.portalApi = async (url, method = 'POST', body = null) => {
    const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' },
        body: body ? JSON.stringify(body) : null,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const message = data.error || data.message || 'Something went wrong. Please try again.';
        window.toast?.(message, 'danger');
        throw new Error(message);
    }
    return data;
};

// Global toast store
Alpine.store('toasts', {
    items: [],
    push(message, tone = 'default') {
        const id = Date.now() + Math.random();
        this.items.push({ id, message, tone });
        setTimeout(() => this.remove(id), 3200);
    },
    remove(id) {
        this.items = this.items.filter((t) => t.id !== id);
    },
});

window.addEventListener('toast', (e) => Alpine.store('toasts').push(e.detail.message, e.detail.tone));
window.toast = (message, tone) => Alpine.store('toasts').push(message, tone);

// Reveal-on-scroll for marketing sections
document.addEventListener('DOMContentLoaded', () => {
    const io = new IntersectionObserver(
        (entries) => entries.forEach((e) => e.isIntersecting && (e.target.classList.add('is-visible'), io.unobserve(e.target))),
        { threshold: 0.12 },
    );
    document.querySelectorAll('.reveal').forEach((el) => io.observe(el));
});

Alpine.start();
