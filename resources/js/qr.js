// Personal Amotan QR codes: links like https://host/u/AMO-7KX3PQ, or the bare code.
const CODE = /(?:\/u\/|^)\s*(AMO-?[2-9A-HJ-NP-Z]{6})\s*$/i;

export function parseBuddyCode(text) {
    const m = String(text || '').trim().match(CODE);
    if (!m) return null;
    const raw = m[1].toUpperCase().replace('-', '');
    return `AMO-${raw.slice(3)}`;
}

export const buddyLink = (code, origin = location.origin) => `${origin}/u/${code}`;
