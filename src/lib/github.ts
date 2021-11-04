export function dashify(str: string, options?: any): string {
    if (typeof str !== 'string') return str;
    return str
        .trim()
        .replace(/\W/g, (m) => (/[À-ž]/.test(m) ? m : '-'))
        .replace(/^-+|-+$/g, '')
        .replace(/-{2,}/g, (m) => (options && options.condense ? '-' : m))
        .toLowerCase();
}