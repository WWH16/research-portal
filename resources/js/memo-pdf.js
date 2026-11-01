// Draws a PDF memo's pages for the memo fold on Announcements. app.js loads it only when a PDF memo is first unfolded,
// so pages without one never fetch pdf.js.
import * as pdfjs from 'pdfjs-dist';
import worker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import jbig2 from 'pdfjs-dist/wasm/jbig2.wasm?url';
import openjpeg from 'pdfjs-dist/wasm/openjpeg.wasm?url';

pdfjs.GlobalWorkerOptions.workerSrc = worker;

// A scanned memo's pages are often JBIG2 or JPEG 2000 images, which pdf.js decodes with these. It asks for them by file
// name from one folder, but the build names them by hash, so they are handed over from this list instead.
const decoders = { 'jbig2.wasm': jbig2, 'openjpeg.wasm': openjpeg };

class BinaryData {
    async fetch({ filename }) {
        const response = decoders[filename] && (await fetch(decoders[filename]));

        if (!response?.ok) {
            throw new Error(`${filename} could not be loaded.`);
        }

        return new Uint8Array(await response.arrayBuffer());
    }
}

// Each page is drawn as a picture at its paper size, the stack wrapped like docx-preview's so both kinds zoom alike.
// The detail allows zooming in on a sharp screen, up to 1,600 pixels across, since a phone refuses canvases much larger
// than a few pages at that size.
export async function drawPdf(data, pages, label) {
    const task = pdfjs.getDocument({ data, BinaryDataFactory: BinaryData, useWorkerFetch: false });
    const wrapper = Object.assign(document.createElement('div'), { className: 'memo-pdf' });

    try {
        const pdf = await task.promise;

        for (let number = 1; number <= pdf.numPages; number++) {
            const page = await pdf.getPage(number);
            const paper = page.getViewport({ scale: 96 / 72 });
            const viewport = page.getViewport({ scale: paper.scale * Math.min(1600 / paper.width, 1.5 * Math.min(devicePixelRatio, 2)) });
            const canvas = document.createElement('canvas');

            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            canvas.style.width = `${paper.width}px`;
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', label.replace(':number', number).replace(':total', pdf.numPages));

            await page.render({ canvas, viewport }).promise;
            wrapper.append(canvas);
        }

        pages.append(wrapper);
    } finally {
        await task.destroy();
    }
}
