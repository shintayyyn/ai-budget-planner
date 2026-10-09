// Generates PWA icons from an inline SVG.
import sharp from 'sharp';

const svg = (pad) => Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
  <rect width="512" height="512" rx="${pad ? 0 : 112}" fill="#4f46e5"/>
  <g transform="translate(256 256) scale(${pad ? 0.72 : 0.9}) translate(-256 -256)">
    <circle cx="256" cy="268" r="150" fill="#eef2ff"/>
    <text x="256" y="330" font-family="Helvetica, Arial, sans-serif" font-size="190" font-weight="700" text-anchor="middle" fill="#4f46e5">$</text>
    <path d="M370 118 l14 34 34 14 -34 14 -14 34 -14 -34 -34 -14 34 -14z" fill="#fbbf24"/>
  </g>
</svg>`);

for (const size of [192, 512]) {
    await sharp(svg(false)).resize(size, size).png().toFile(`public/icons/icon-${size}.png`);
    await sharp(svg(true)).resize(size, size).png().toFile(`public/icons/maskable-${size}.png`);
}
await sharp(svg(false)).resize(180, 180).flatten({ background: '#4f46e5' }).png().toFile('public/icons/apple-touch-icon.png');
await sharp(svg(false)).resize(64, 64).png().toFile('public/favicon.png');
console.log('Icons written to public/icons');
