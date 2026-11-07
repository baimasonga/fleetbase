// Photos and signatures, made small on the phone before they're stored and sent
// over 3G (DESIGN.md §6.6.5; AGRI-INPUTS.md §11: about 150 KB a photo).

import { sha256Hex } from './card.js';

const MAX_EDGE = 1280;
const QUALITY = 0.6;

/** Resize and re-encode a camera photo as JPEG. Re-encoding also drops the EXIF data. */
export async function compressPhoto(file) {
  const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' }).catch(() => null);
  if (!bitmap) throw new Error('That photo could not be read. Try again.');
  const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(bitmap.width * scale);
  canvas.height = Math.round(bitmap.height * scale);
  canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  bitmap.close();
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));
  return { blob, sha256: await sha256Hex(blob) };
}

/** A drawing pad for a signature or a mark. */
export class SignaturePad {
  constructor(canvas) {
    this.canvas = canvas;
    this.strokes = 0;
    const ratio = Math.max(1, window.devicePixelRatio || 1);
    const rect = canvas.getBoundingClientRect();
    canvas.width = Math.round((rect.width || 300) * ratio);
    canvas.height = Math.round((rect.height || 160) * ratio);
    const ctx = canvas.getContext('2d');
    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111';
    this.ctx = ctx;

    let last = null;
    const point = (e) => {
      const r = canvas.getBoundingClientRect();
      return [e.clientX - r.left, e.clientY - r.top];
    };
    canvas.addEventListener('pointerdown', (e) => {
      canvas.setPointerCapture(e.pointerId);
      last = point(e);
      this.strokes++;
      this.onchange && this.onchange();
    });
    canvas.addEventListener('pointermove', (e) => {
      if (!last) return;
      const p = point(e);
      ctx.beginPath();
      ctx.moveTo(last[0], last[1]);
      ctx.lineTo(p[0], p[1]);
      ctx.stroke();
      last = p;
    });
    const end = () => { last = null; };
    canvas.addEventListener('pointerup', end);
    canvas.addEventListener('pointercancel', end);
  }

  isEmpty() {
    return this.strokes === 0;
  }

  clear() {
    this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
    this.strokes = 0;
    this.onchange && this.onchange();
  }

  async toMedia() {
    const blob = await new Promise((resolve) => this.canvas.toBlob(resolve, 'image/png'));
    return { blob, sha256: await sha256Hex(blob) };
  }
}
