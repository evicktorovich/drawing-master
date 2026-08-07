/**
 * Per-event permalinks: /event/{slug}.
 *
 * The slug comes from the class title, and only gets the date appended when
 * two classes share a title. Same rules as app/Support/EventLinks.php and the
 * "copy link" button in public/admin/index.html — keep all three in sync.
 */

export function slugify(text) {
    return String(text == null ? '' : text)
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

export function slugsFor(list) {
    const items = Array.isArray(list) ? list : [];
    const bases = items.map(item => slugify(item && item.eventName) || 'class');
    const counts = {};
    bases.forEach(base => { counts[base] = (counts[base] || 0) + 1; });

    const seen = {};
    return bases.map((base, i) => {
        const date = String((items[i] && items[i].date) || '');
        let slug = base;
        if (counts[base] > 1 && date !== '' && date !== '%') {
            slug = `${base}-${slugify(date)}`;
        }
        const n = (seen[slug] || 0) + 1;
        seen[slug] = n;
        return n > 1 ? `${slug}-${n}` : slug;
    });
}

export function slugForEvent(list, event) {
    const items = Array.isArray(list) ? list : [];
    const i = items.indexOf(event);
    if (i !== -1) return slugsFor(items)[i];
    // Not in the list (shouldn't happen) — best-effort title slug.
    return slugify(event && event.eventName) || 'class';
}

export function findBySlug(list, slug) {
    const items = Array.isArray(list) ? list : [];
    const i = slugsFor(items).indexOf(String(slug || ''));
    return i === -1 ? null : items[i];
}
