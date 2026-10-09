// Amo, the Amotan mascot: a little coin pouch with a sprout (savings that grow).
// Pure SVG string so it renders in Vue, on the QR share card and in the PWA icons.
const INK = '#1e293b';
const line = (d, w = 3) => `<path d="${d}" stroke="${INK}" stroke-width="${w}" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`;
const openEyes = (dx = 0, dy = 0) => [46, 74].map((x) =>
    `<ellipse cx="${x}" cy="66" rx="5" ry="6.5" fill="${INK}"/><circle cx="${x + 1.8 + dx}" cy="${63.5 + dy}" r="2" fill="#fff"/>`).join('');
const arm = (x, y, rot) => `<ellipse cx="${x}" cy="${y}" rx="6" ry="9.5" transform="rotate(${rot} ${x} ${y})" fill="#10b981" stroke="#047857" stroke-width="2"/>`;

const MOODS = {
    happy: {
        arms: arm(23, 84, 25) + arm(97, 84, -25),
        face: openEyes() + line('M53 76 Q60 83 67 76'),
    },
    celebrate: {
        arms: arm(20, 58, -35) + arm(100, 58, 35),
        face: line('M41 68 Q46 61 51 68') + line('M69 68 Q74 61 79 68')
            + '<path d="M52 74 Q60 88 68 74 Z" fill="#be123c" stroke="#1e293b" stroke-width="2.5" stroke-linejoin="round"/>',
        extra: '<rect x="14" y="20" width="6" height="6" rx="1" fill="#f472b6" transform="rotate(20 17 23)"/>'
            + '<circle cx="104" cy="22" r="3.5" fill="#60a5fa"/><rect x="96" y="40" width="5" height="9" rx="1" fill="#fbbf24" transform="rotate(-25 98 44)"/>'
            + '<circle cx="24" cy="42" r="3" fill="#fbbf24"/><path d="M8 34 l3 6 6 3 -6 3 -3 6 -3 -6 -6 -3 6 -3z" fill="#a78bfa"/>',
    },
    worried: {
        arms: arm(26, 88, 10) + arm(94, 88, -10),
        face: openEyes(0, 1) + line('M40 59 L50 55', 2.5) + line('M80 59 L70 55', 2.5) + line('M52 80 Q56 76 60 80 Q64 84 68 80', 2.5),
        extra: '<path d="M90 50 C90 50 84 58 84 61 a6 6 0 0 0 12 0 C96 58 90 50 90 50Z" fill="#7dd3fc" stroke="#0284c7" stroke-width="1.5"/>',
    },
    sleepy: {
        arms: arm(25, 88, 15) + arm(95, 88, -15),
        face: line('M41 66 Q46 70 51 66') + line('M69 66 Q74 70 79 66') + `<ellipse cx="60" cy="78" rx="3" ry="3.5" fill="${INK}"/>`,
        extra: '<g fill="#6366f1" font-family="Arial, sans-serif" font-weight="700"><text x="86" y="34" font-size="12">z</text><text x="96" y="22" font-size="16">Z</text></g>',
    },
    thinking: {
        arms: arm(23, 84, 25) + arm(92, 94, -60),
        face: openEyes(1.5, -1.5) + line('M55 78 L65 77'),
        extra: '<text x="94" y="40" font-family="Arial, sans-serif" font-size="20" font-weight="800" fill="#6366f1">?</text>',
    },
};

export const MASCOT_NAME = 'Amo';

export function mascotSvg(mood = 'happy', id = 'amo') {
    const m = MOODS[mood] || MOODS.happy;
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" width="100%" height="100%">
<defs><linearGradient id="${id}-g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#6ee7b7"/><stop offset="1" stop-color="#10b981"/></linearGradient></defs>
<ellipse cx="60" cy="113" rx="30" ry="4" fill="#0f172a" opacity=".12"/>
<ellipse cx="46" cy="108" rx="9" ry="5" fill="#059669"/><ellipse cx="74" cy="108" rx="9" ry="5" fill="#059669"/>
${m.arms}
<path d="M60 34 C34 34 20 58 20 80 C20 100 38 108 60 108 C82 108 100 100 100 80 C100 58 86 34 60 34Z" fill="url(#${id}-g)" stroke="#047857" stroke-width="2"/>
<ellipse cx="60" cy="86" rx="27" ry="19" fill="#ecfdf5" opacity=".55"/>
<path d="M42 34 C36 22 46 16 52 24 C54 14 66 14 68 24 C74 16 84 22 78 34 Z" fill="#6ee7b7" stroke="#047857" stroke-width="2" stroke-linejoin="round"/>
<rect x="38" y="31" width="44" height="9" rx="4.5" fill="#f59e0b" stroke="#b45309" stroke-width="1.5"/>
<path d="M60 17 C60 12 62 8 66 6" stroke="#047857" stroke-width="2" fill="none" stroke-linecap="round"/>
<path d="M66 6 C74 2 81 6 78 12 C72 14 67 11 66 6Z" fill="#a3e635" stroke="#4d7c0f" stroke-width="1.5"/>
<circle cx="60" cy="96" r="7" fill="#fbbf24" stroke="#b45309" stroke-width="1.5"/>
<path d="M57 99.5 L60 92 L63 99.5 M58.2 97 H61.8" stroke="#92400e" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
<ellipse cx="37" cy="77" rx="6" ry="3.5" fill="#fb7185" opacity=".55"/><ellipse cx="83" cy="77" rx="6" ry="3.5" fill="#fb7185" opacity=".55"/>
${m.face}
${m.extra || ''}
</svg>`;
}
