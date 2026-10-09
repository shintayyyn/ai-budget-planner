// Generates the PWA icons and favicon from Amo, the Amotan mascot.
import sharp from 'sharp';
import { mascotSvg } from '../resources/js/mascot.js';

const inner = mascotSvg('happy', 'icon').replace(/<svg[^>]*>/, '').replace('</svg>', '');
const svg = (maskable) => {
    const box = maskable ? 300 : 400;
    const at = (512 - box) / 2;
    return Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
  <defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6366f1"/><stop offset="1" stop-color="#7c3aed"/></linearGradient></defs>
  <rect width="512" height="512" rx="${maskable ? 0 : 112}" fill="url(#bg)"/>
  <circle cx="256" cy="276" r="${maskable ? 150 : 190}" fill="#ffffff" opacity=".14"/>
  <svg x="${at}" y="${at + (maskable ? 6 : 10)}" width="${box}" height="${box}" viewBox="0 0 120 120">${inner}</svg>
</svg>`);
};

for (const size of [192, 512]) {
    await sharp(svg(false)).resize(size, size).png().toFile(`public/icons/icon-${size}.png`);
    await sharp(svg(true)).resize(size, size).png().toFile(`public/icons/maskable-${size}.png`);
}
await sharp(svg(false)).resize(180, 180).flatten({ background: '#6366f1' }).png().toFile('public/icons/apple-touch-icon.png');
await sharp(svg(false)).resize(64, 64).png().toFile('public/favicon.png');
console.log('Icons written to public/icons');
