// Amo, the Amotan mascot: a little coin pouch with a sprout (savings that grow).
// Pure SVG string so it renders in Vue, on the QR share card and in the PWA icons.
const INK = '#1e293b';
const line = (d, w = 3, c = INK) => `<path d="${d}" stroke="${c}" stroke-width="${w}" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`;
const openEyes = (dx = 0, dy = 0, r = 1) => [46, 74].map((x) =>
    `<ellipse cx="${x}" cy="66" rx="${5 * r}" ry="${6.5 * r}" fill="${INK}"/><circle cx="${x + 1.8 + dx}" cy="${63.5 + dy}" r="${2 * r}" fill="#fff"/>`).join('');
const happyEyes = line('M41 68 Q46 61 51 68') + line('M69 68 Q74 61 79 68');
const calmEyes = line('M41 66 Q46 70 51 66') + line('M69 66 Q74 70 79 66');
const smile = line('M53 76 Q60 83 67 76');
const smirk = line('M53 78 Q62 83 68 75');
const bigSmile = `<path d="M52 74 Q60 88 68 74 Z" fill="#be123c" stroke="${INK}" stroke-width="2.5" stroke-linejoin="round"/>`;
const arm = (x, y, rot) => `<ellipse cx="${x}" cy="${y}" rx="6" ry="9.5" transform="rotate(${rot} ${x} ${y})" fill="#10b981" stroke="#047857" stroke-width="2"/>`;
const heart = (x, y, s, fill = '#e11d48') =>
    `<path d="M${x} ${y + s * 0.4} C${x - s * 0.2} ${y - s * 0.5} ${x - s * 1.2} ${y - s * 0.3} ${x - s} ${y + s * 0.3} C${x - s * 0.8} ${y + s * 0.8} ${x - s * 0.2} ${y + s * 1.1} ${x} ${y + s * 1.4} C${x + s * 0.2} ${y + s * 1.1} ${x + s * 0.8} ${y + s * 0.8} ${x + s} ${y + s * 0.3} C${x + s * 1.2} ${y - s * 0.3} ${x + s * 0.2} ${y - s * 0.5} ${x} ${y + s * 0.4}Z" fill="${fill}"/>`;
const sparkle = (x, y, r, fill = '#fbbf24') =>
    `<path d="M${x} ${y - r} Q${x + r * 0.2} ${y - r * 0.2} ${x + r} ${y} Q${x + r * 0.2} ${y + r * 0.2} ${x} ${y + r} Q${x - r * 0.2} ${y + r * 0.2} ${x - r} ${y} Q${x - r * 0.2} ${y - r * 0.2} ${x} ${y - r}Z" fill="${fill}"/>`;
const coin = (x, y, r, cls = '') => `<g${cls ? ` class="${cls}"` : ''}><circle cx="${x}" cy="${y}" r="${r}" fill="#fbbf24" stroke="#b45309" stroke-width="1.5"/><circle cx="${x}" cy="${y}" r="${r * 0.6}" fill="none" stroke="#d97706" stroke-width="1.2"/></g>`;
const spiral = (x) => `<path d="M${x} 66 m-5 0 a5 5 0 1 0 10 0 a3.6 3.6 0 1 0 -7.2 0 a2.2 2.2 0 1 0 4.4 0" stroke="${INK}" stroke-width="2.2" fill="none" stroke-linecap="round"/>`;

const MOODS = {
    happy: {
        label: 'Happy', action: 'bob',
        arms: arm(23, 84, 25) + arm(97, 84, -25),
        face: openEyes() + smile,
    },
    wave: {
        front: true,
        label: 'Hello!', action: 'bob',
        arms: arm(23, 84, 25) + `<g class="amo-wave-arm">${arm(103, 56, 25)}</g>`,
        face: openEyes() + bigSmile,
        extra: line('M111 38 Q116 45 114 52', 2, '#a5b4fc') + line('M106 32 Q112 36 113 42', 2, '#a5b4fc'),
    },
    celebrate: {
        label: 'Celebrating', action: 'jump',
        arms: arm(20, 58, -35) + arm(100, 58, 35),
        face: happyEyes + bigSmile,
        extra: '<rect x="14" y="20" width="6" height="6" rx="1" fill="#f472b6" transform="rotate(20 17 23)"/>'
            + '<circle cx="104" cy="22" r="3.5" fill="#60a5fa"/><rect x="96" y="40" width="5" height="9" rx="1" fill="#fbbf24" transform="rotate(-25 98 44)"/>'
            + `<circle cx="24" cy="42" r="3" fill="#fbbf24"/>${sparkle(8, 40, 6, '#a78bfa')}`,
    },
    excited: {
        label: 'Excited', action: 'jump',
        arms: arm(19, 62, -30) + arm(101, 62, 30),
        face: sparkle(46, 66, 8, '#f59e0b') + sparkle(74, 66, 8, '#f59e0b') + bigSmile,
        extra: sparkle(14, 30, 5) + sparkle(104, 26, 6, '#f472b6') + sparkle(108, 48, 3.5, '#60a5fa'),
    },
    love: {
        label: 'Love it', action: 'pulse',
        arms: arm(24, 78, 50) + arm(96, 78, -50),
        face: heart(46, 61, 6) + heart(74, 61, 6) + bigSmile,
        extra: heart(100, 26, 4.5, '#fb7185') + heart(18, 36, 3.5, '#f472b6') + heart(108, 44, 2.5, '#fda4af'),
    },
    wink: {
        label: 'Wink', action: 'bob',
        arms: arm(23, 84, 25) + arm(99, 70, -60),
        face: `<ellipse cx="46" cy="66" rx="5" ry="6.5" fill="${INK}"/><circle cx="47.8" cy="63.5" r="2" fill="#fff"/>` + line('M69 67 Q74 62 79 67') + line('M52 76 Q61 84 68 74'),
        extra: sparkle(96, 40, 6),
    },
    proud: {
        label: 'Proud', action: 'sway',
        arms: arm(19, 84, -40) + arm(101, 84, 40),
        face: calmEyes + smirk,
        extra: sparkle(98, 30, 7) + sparkle(18, 30, 4.5) + '<path d="M50 18 l3 -6 4 4 3 -6 3 6 4 -4 3 6z" fill="#fbbf24" stroke="#b45309" stroke-width="1.2" stroke-linejoin="round" transform="translate(0 -8)"/>',
    },
    saving: {
        front: true,
        label: 'Saving', action: 'bob',
        arms: arm(38, 86, 65) + arm(82, 86, -65),
        face: happyEyes + smile,
        extra: coin(60, 92, 11) + '<path d="M56.5 96 L60 87.5 L63.5 96 M57.8 93 H62.2" stroke="#92400e" stroke-width="1.8" fill="none" stroke-linecap="round"/>'
            + coin(98, 24, 5, 'amo-coin-drop') + '<text x="104" y="42" font-family="Arial, sans-serif" font-size="11" font-weight="800" fill="#059669">+1</text>',
    },
    cool: {
        label: 'Cool', action: 'sway',
        arms: arm(23, 84, 25) + arm(100, 70, -15) + '<ellipse cx="101" cy="60" rx="3" ry="4.5" fill="#10b981" stroke="#047857" stroke-width="1.5"/>',
        face: `<rect x="35" y="59" width="21" height="13" rx="5" fill="${INK}"/><rect x="64" y="59" width="21" height="13" rx="5" fill="${INK}"/>`
            + line('M56 63 H64', 2.5) + line('M39 62 L45 62', 1.5, '#94a3b8') + line('M68 62 L74 62', 1.5, '#94a3b8') + smirk,
        extra: sparkle(20, 34, 5, '#60a5fa'),
    },
    thinking: {
        label: 'Thinking', action: 'bob',
        arms: arm(23, 84, 25) + arm(92, 94, -60),
        face: openEyes(1.5, -1.5) + line('M55 78 L65 77'),
        extra: '<text x="94" y="40" font-family="Arial, sans-serif" font-size="20" font-weight="800" fill="#6366f1">?</text>',
    },
    surprised: {
        label: 'Surprised', action: 'jump',
        arms: arm(17, 68, -20) + arm(103, 68, 20),
        face: openEyes(0, 0, 1.2) + line('M40 53 Q46 49 52 53', 2.5) + line('M68 53 Q74 49 80 53', 2.5) + `<ellipse cx="60" cy="81" rx="4.5" ry="6" fill="${INK}"/>`,
        extra: '<text x="96" y="38" font-family="Arial, sans-serif" font-size="22" font-weight="900" fill="#f59e0b">!</text>',
    },
    shy: {
        front: true,
        label: 'Shy', action: 'sway',
        arms: arm(50, 92, 70) + arm(70, 92, -70),
        face: openEyes(-1, 2, 0.85) + line('M56 78 Q60 81 64 78', 2.5),
        extra: '<ellipse cx="36" cy="77" rx="8" ry="4.5" fill="#fb7185" opacity=".8"/><ellipse cx="84" cy="77" rx="8" ry="4.5" fill="#fb7185" opacity=".8"/>',
    },
    worried: {
        label: 'Worried', action: 'shake',
        arms: arm(26, 88, 10) + arm(94, 88, -10),
        face: openEyes(0, 1) + line('M40 59 L50 55', 2.5) + line('M80 59 L70 55', 2.5) + line('M52 80 Q56 76 60 80 Q64 84 68 80', 2.5),
        extra: '<path d="M90 50 C90 50 84 58 84 61 a6 6 0 0 0 12 0 C96 58 90 50 90 50Z" fill="#7dd3fc" stroke="#0284c7" stroke-width="1.5"/>',
    },
    sad: {
        label: 'Sad', action: 'breathe',
        arms: arm(28, 92, 5) + arm(92, 92, -5),
        face: openEyes(0, 2) + line('M40 60 L51 56', 2.5) + line('M80 60 L69 56', 2.5) + line('M52 83 Q60 76 68 83'),
        extra: '<path d="M44 72 C44 72 40 78 40 80 a4 4 0 0 0 8 0 C48 78 44 72 44 72Z" fill="#7dd3fc" stroke="#0284c7" stroke-width="1"/>',
    },
    grumpy: {
        front: true,
        label: 'Grumpy', action: 'shake',
        arms: arm(50, 88, 80) + arm(70, 88, -80),
        face: openEyes() + line('M40 56 L51 60', 2.8) + line('M80 56 L69 60', 2.8) + line('M53 80 L67 80'),
        extra: '<g fill="#cbd5e1"><circle cx="98" cy="30" r="5"/><circle cx="106" cy="23" r="4"/><circle cx="105" cy="34" r="3.5"/></g>',
    },
    dizzy: {
        label: 'Dizzy', action: 'wobble',
        arms: arm(22, 80, 40) + arm(98, 88, -10),
        face: spiral(46) + spiral(74) + line('M52 80 Q56 76 60 80 Q64 84 68 80', 2.5),
        extra: sparkle(30, 20, 4.5) + sparkle(92, 18, 4, '#a78bfa') + sparkle(62, 10, 3.5, '#60a5fa'),
    },
    sleepy: {
        label: 'Sleepy (offline)', action: 'breathe',
        arms: arm(25, 88, 15) + arm(95, 88, -15),
        face: calmEyes + `<ellipse cx="60" cy="78" rx="3" ry="3.5" fill="${INK}"/>`,
        extra: '<g fill="#6366f1" font-family="Arial, sans-serif" font-weight="700"><text x="86" y="34" font-size="12">z</text><text x="96" y="22" font-size="16">Z</text></g>',
    },
};

export const MASCOT_NAME = 'Amo';
export const MOODS_LIST = Object.entries(MOODS).map(([key, m]) => ({ key, label: m.label }));
export const REACTIONS = ['wink', 'love', 'excited', 'surprised', 'cool', 'wave', 'shy', 'proud'];
export const moodAction = (mood) => (MOODS[mood] || MOODS.happy).action;

export function mascotSvg(mood = 'happy', id = 'amo') {
    const m = MOODS[mood] || MOODS.happy;
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" width="100%" height="100%" overflow="visible">
<defs><linearGradient id="${id}-g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#6ee7b7"/><stop offset="1" stop-color="#10b981"/></linearGradient></defs>
<ellipse cx="60" cy="113" rx="30" ry="4" fill="#0f172a" opacity=".12"/>
<ellipse cx="46" cy="108" rx="9" ry="5" fill="#059669"/><ellipse cx="74" cy="108" rx="9" ry="5" fill="#059669"/>
${m.front ? '' : m.arms}
<path d="M60 34 C34 34 20 58 20 80 C20 100 38 108 60 108 C82 108 100 100 100 80 C100 58 86 34 60 34Z" fill="url(#${id}-g)" stroke="#047857" stroke-width="2"/>
<ellipse cx="60" cy="86" rx="27" ry="19" fill="#ecfdf5" opacity=".55"/>
<path d="M42 34 C36 22 46 16 52 24 C54 14 66 14 68 24 C74 16 84 22 78 34 Z" fill="#6ee7b7" stroke="#047857" stroke-width="2" stroke-linejoin="round"/>
<rect x="38" y="31" width="44" height="9" rx="4.5" fill="#f59e0b" stroke="#b45309" stroke-width="1.5"/>
<path d="M60 17 C60 12 62 8 66 6" stroke="#047857" stroke-width="2" fill="none" stroke-linecap="round"/>
<path d="M66 6 C74 2 81 6 78 12 C72 14 67 11 66 6Z" fill="#a3e635" stroke="#4d7c0f" stroke-width="1.5"/>
<circle cx="60" cy="96" r="7" fill="#fbbf24" stroke="#b45309" stroke-width="1.5"/>
<path d="M57 99.5 L60 92 L63 99.5 M58.2 97 H61.8" stroke="#92400e" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
<ellipse cx="37" cy="77" rx="6" ry="3.5" fill="#fb7185" opacity=".55"/><ellipse cx="83" cy="77" rx="6" ry="3.5" fill="#fb7185" opacity=".55"/>
${m.front ? m.arms : ''}
${m.face}
${m.extra || ''}
</svg>`;
}
