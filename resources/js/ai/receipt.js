// Receipt scanning, fully on-device: Tesseract OCR (self-hosted WASM + language
// data) followed by rule-based extraction, optionally refined by the local LLM.
import { toISO } from '../format';
import { guessCategory } from './parse';
import { llmReady, completeJSON } from './engine';

let workerPromise = null;

async function getWorker(onProgress) {
    if (!workerPromise) {
        workerPromise = (async () => {
            const { createWorker } = await import('tesseract.js');
            return createWorker('eng', 1, {
                workerPath: '/vendor/tesseract/worker.min.js',
                corePath: '/vendor/tesseract/core',
                langPath: '/vendor/tesseract/lang',
                gzip: true,
                logger: (m) => onProgressRef?.(m),
            });
        })();
    }
    onProgressRef = onProgress;
    return workerPromise;
}
let onProgressRef = null;

/** Downscale big phone photos: faster OCR and better accuracy. */
async function prepareImage(file) {
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, 1800 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    const g = canvas.getContext('2d');
    g.filter = 'grayscale(1) contrast(1.35)';
    g.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    return canvas;
}

const NUM = /(\d{1,3}(?:[,\s]\d{3})*|\d+)[.,](\d{2})\b/g;

function amountsIn(line) {
    return [...line.matchAll(NUM)].map((m) => parseFloat(`${m[1].replace(/[,\s]/g, '')}.${m[2]}`));
}

export function extractReceipt(text) {
    const lines = text.split('\n').map((l) => l.trim()).filter(Boolean);

    // Total: prefer explicit total lines (not subtotal / tax / change).
    let total = null;
    const totalLines = lines.filter((l) => /\b(grand\s*total|total\s*(due|amount)?|amount\s*due|balance\s*due|to\s*pay|net\s*amount)\b/i.test(l) && !/sub\s*-?\s*total|tax|vat|change|tip|savings|discount/i.test(l));
    for (const l of totalLines.reverse()) {
        const a = amountsIn(l);
        if (a.length) { total = Math.max(...a); break; }
    }
    if (total === null) {
        const all = lines.flatMap(amountsIn).filter((n) => n < 100000);
        total = all.length ? Math.max(...all) : null;
    }

    // Date: several common formats.
    let date = null;
    const iso = text.match(/\b(20\d{2})[-/.](\d{1,2})[-/.](\d{1,2})\b/);
    const dmy = text.match(/\b(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})\b/);
    const named = text.match(/\b(\d{1,2})?\s*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s*(\d{1,2})?,?\s*(20\d{2})\b/i);
    const valid = (d) => d && !isNaN(d) && d <= new Date() && d > new Date(Date.now() - 400 * 86400000);
    if (iso) {
        const d = new Date(+iso[1], +iso[2] - 1, +iso[3]);
        if (valid(d)) date = toISO(d);
    }
    if (!date && dmy) {
        const y = dmy[3].length === 2 ? 2000 + +dmy[3] : +dmy[3];
        // Try month-first (US) then day-first.
        const us = new Date(y, +dmy[1] - 1, +dmy[2]);
        const eu = new Date(y, +dmy[2] - 1, +dmy[1]);
        if (+dmy[1] <= 12 && valid(us)) date = toISO(us);
        else if (+dmy[2] <= 12 && valid(eu)) date = toISO(eu);
    }
    if (!date && named) {
        const d = new Date(`${named[2]} ${named[1] || named[3] || 1} ${named[4]}`);
        if (valid(d)) date = toISO(d);
    }

    // Merchant: first meaningful line near the top.
    const merchant = lines.slice(0, 6).find((l) => /[a-z]{3,}/i.test(l) && !/receipt|invoice|tax|tel|phone|www|http|date|time|order|cashier|#\d/i.test(l) && l.length <= 40) || null;

    return { total, date, merchant: merchant ? merchant.replace(/[^\w&'.\- ]/g, '').trim() : null, text };
}

/**
 * Scan a receipt image and return a transaction draft.
 * @param {File} file
 * @param {(p: {status: string, progress: number}) => void} onProgress
 */
export async function scanReceipt(file, categories, onProgress) {
    const worker = await getWorker(onProgress);
    const image = await prepareImage(file);
    const { data } = await worker.recognize(image);
    const r = extractReceipt(data.text || '');

    let draft = {
        type: 'expense',
        amount: r.total,
        merchant: r.merchant,
        description: r.merchant || 'Receipt',
        occurred_on: r.date || toISO(new Date()),
        category_id: guessCategory(`${r.merchant || ''} ${r.text}`, categories)?.id || null,
        source: 'receipt',
    };

    if (llmReady() && r.text.trim()) {
        onProgress?.({ status: 'AI is reading the receipt', progress: 1 });
        const names = categories.filter((c) => c.kind !== 'income').map((c) => c.name).join(', ');
        const out = await completeJSON([
            { role: 'system', content: `You read OCR text from a shopping receipt. Reply with JSON only: {"merchant":string,"total":number,"date":"YYYY-MM-DD"|null,"category":string}. category must be one of: ${names}. The total is the final amount paid.` },
            { role: 'user', content: r.text.slice(0, 2500) },
        ]).catch(() => null);
        if (out) {
            const cat = categories.find((c) => c.name.toLowerCase() === String(out.category || '').toLowerCase());
            const total = Number(out.total);
            draft = {
                ...draft,
                merchant: out.merchant || draft.merchant,
                description: out.merchant || draft.description,
                // Trust the rule-based total when the model's number does not appear on the receipt.
                amount: total > 0 && r.text.includes(total.toFixed(2)) ? total : draft.amount,
                occurred_on: /^\d{4}-\d{2}-\d{2}$/.test(out.date || '') && new Date(out.date) <= new Date() ? out.date : draft.occurred_on,
                category_id: cat?.id || draft.category_id,
            };
        }
    }

    return { draft, text: r.text };
}
