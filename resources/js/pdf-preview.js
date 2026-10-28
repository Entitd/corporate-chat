import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

GlobalWorkerOptions.workerSrc = workerUrl;

export function loadPdf(data) {
    return getDocument({ data: new Uint8Array(data), isEvalSupported: false, useSystemFonts: true });
}
