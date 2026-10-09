import { describe, it, expect } from 'vitest';
import { mascotSvg, MOODS_LIST, REACTIONS, moodAction } from '../../resources/js/mascot';

describe('Amo mascot', () => {
    it('draws a distinct pose for every mood', () => {
        const svgs = MOODS_LIST.map(({ key }) => mascotSvg(key, 'x'));
        svgs.forEach((s) => expect(s).toMatch(/^<svg[\s\S]*<\/svg>$/));
        expect(new Set(svgs).size).toBe(MOODS_LIST.length);
        expect(MOODS_LIST.length).toBeGreaterThanOrEqual(15);
    });

    it('only reacts with known moods and falls back to happy', () => {
        const keys = MOODS_LIST.map((m) => m.key);
        REACTIONS.forEach((r) => expect(keys).toContain(r));
        expect(mascotSvg('nope', 'x')).toBe(mascotSvg('happy', 'x'));
        expect(moodAction('celebrate')).toBe('jump');
    });
});
