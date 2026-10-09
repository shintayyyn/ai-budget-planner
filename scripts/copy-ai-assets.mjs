// Copies the OCR engine and English language data into public/ so receipt
// scanning works fully on-device and offline (no CDN calls).
import { cpSync, mkdirSync, readdirSync } from 'node:fs';

const out = 'public/vendor/tesseract';
mkdirSync(`${out}/core`, { recursive: true });
mkdirSync(`${out}/lang`, { recursive: true });

cpSync('node_modules/tesseract.js/dist/worker.min.js', `${out}/worker.min.js`);
for (const f of readdirSync('node_modules/tesseract.js-core')) {
    if (/^tesseract-core.*lstm\.(wasm|wasm\.js|js)$/.test(f)) cpSync(`node_modules/tesseract.js-core/${f}`, `${out}/core/${f}`);
}
cpSync('node_modules/@tesseract.js-data/eng/4.0.0/eng.traineddata.gz', `${out}/lang/eng.traineddata.gz`);
console.log('On-device OCR assets copied to', out);
