/**
 * Where a class picture lives, from whatever the CMS stored in `img`.
 *
 * The path is always root-relative on purpose: the same page is also served
 * from /event/{slug}, where a bare "assets/..." would resolve to
 * /event/assets/... and 404. Mirrors imgUrl() in public/admin/index.html and
 * EventLinks::imageUrl() in PHP — keep the three in sync.
 */
export function imageUrl(img) {
    const path = String(img == null ? '' : img).trim();
    if (path === '') return '';
    if (/^(https?:)?\/\//i.test(path)) return path;
    if (path.startsWith('/')) return path;

    return path.startsWith('assets/') ? '/' + path : '/assets/img/' + path;
}
