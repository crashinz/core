const DEFAULT_POLICY = Object.freeze({ maxWidth: 1500, maxHeight: 300, maxBytes: 5 * 1024 * 1024 });

function imageKind(bytes) {
  const ascii = (start, end) => String.fromCharCode(...bytes.subarray(start, end));
  if (bytes[0] === 255 && bytes[1] === 216 && bytes[2] === 255) return 'image/jpeg';
  if (ascii(0, 6) === 'GIF87a' || ascii(0, 6) === 'GIF89a') return 'image/gif';
  if ([137,80,78,71,13,10,26,10].every((value, index) => bytes[index] === value)) return 'image/png';
  if (ascii(0, 4) === 'RIFF' && ascii(8, 12) === 'WEBP') return 'image/webp';
  throw new Error('Choose a valid JPEG, PNG, GIF, or WebP nameplate image.');
}

function animated(bytes, mime) {
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const ascii = (offset, size) => String.fromCharCode(...bytes.subarray(offset, offset + size));
  if (mime === 'image/png') {
    for (let offset = 8; offset + 12 <= bytes.length;) {
      const length = view.getUint32(offset);
      if (ascii(offset + 4, 4) === 'acTL') return true;
      offset += length + 12;
    }
  }
  if (mime === 'image/webp') {
    for (let offset = 12; offset + 8 <= bytes.length;) {
      const length = view.getUint32(offset + 4, true);
      const type = ascii(offset, 4);
      if (type === 'ANIM' || type === 'ANMF' || (type === 'VP8X' && (bytes[offset + 8] & 2))) return true;
      offset += 8 + length + (length % 2);
    }
  }
  if (mime === 'image/gif') {
    let offset = 13 + ((bytes[10] & 128) ? 3 * (1 << ((bytes[10] & 7) + 1)) : 0);
    let frames = 0;
    const skipBlocks = () => { while (offset < bytes.length) { const length = bytes[offset++]; if (!length) break; offset += length; } };
    while (offset < bytes.length) {
      const type = bytes[offset++];
      if (type === 59) break;
      if (type === 33) { offset++; skipBlocks(); }
      else if (type === 44) {
        if (++frames > 1) return true;
        if (offset + 9 > bytes.length) break;
        const packed = bytes[offset + 8]; offset += 9;
        if (packed & 128) offset += 3 * (1 << ((packed & 7) + 1));
        offset++; skipBlocks();
      } else break;
    }
  }
  return false;
}

export async function prepareNameplateFile(file, configuration = DEFAULT_POLICY) {
  if (!file) throw new Error('Choose a nameplate image.');
  const maxWidth = 1500, maxHeight = 300;
  const configuredBytes = Number(configuration.maxBytes);
  const maxBytes = Number.isFinite(configuredBytes) && configuredBytes >= 0.5 * 1024 * 1024 && configuredBytes <= 50 * 1024 * 1024
    ? Math.floor(configuredBytes) : DEFAULT_POLICY.maxBytes;
  const sizeError = () => new Error(`Nameplate images must be ${maxBytes / (1024 * 1024)} MB or smaller.`);
  if (file.size > maxBytes) throw sizeError();
  const bytes = new Uint8Array(await file.arrayBuffer());
  const mime = imageKind(bytes);
  const moving = animated(bytes, mime);
  const url = URL.createObjectURL(new Blob([file], { type: mime }));
  try {
    const image = await new Promise((resolve, reject) => {
      const image = new Image(); image.onload = () => resolve(image);
      image.onerror = () => reject(new Error('Could not decode the nameplate image.'));
      image.src = url;
    });
    const width = image.naturalWidth, height = image.naturalHeight;
    if (!width || !height) throw new Error('The nameplate image has invalid dimensions.');
    // No re-encoding, enlargement, or animation loss for valid artwork.
    if (width <= maxWidth && height <= maxHeight) return file;
    if (moving) throw new Error('Animated nameplates must be 1500 x 300 pixels or smaller so their animation stays intact.');
    const scale = Math.min(maxWidth / width, maxHeight / height);
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width * scale)); canvas.height = Math.max(1, Math.round(height * scale));
    const context = canvas.getContext('2d', { alpha: true });
    if (!context) throw new Error('This browser could not resize the nameplate image.');
    context.imageSmoothingEnabled = true; context.imageSmoothingQuality = 'high';
    context.drawImage(image, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise((resolve, reject) => canvas.toBlob(value => value ? resolve(value) : reject(new Error('Could not resize the nameplate image.')), 'image/png'));
    if (blob.size > maxBytes) throw sizeError();
    return new File([blob], `${String(file.name || 'nameplate').replace(/\.[^.]+$/, '')}.png`, { type: 'image/png', lastModified: file.lastModified });
  } finally { URL.revokeObjectURL(url); }
}
