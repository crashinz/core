'use strict';

(function initAvatarProcessing() {
  const MIN_AVATAR_SIZE = 42;
  let maxWidth = 250;
  let maxHeight = 250;

  let maxBytes = 5 * 1024 * 1024;

  async function detectedImageType(file) {
    const bytes = new Uint8Array(await file.slice(0, 16).arrayBuffer());
    const starts = (...values) => values.every((value, index) => bytes[index] === value);
    if (starts(0xff, 0xd8, 0xff)) return 'image/jpeg';
    if (starts(0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a)) return 'image/png';
    if (bytes.length >= 12
      && String.fromCharCode(...bytes.slice(0, 4)) === 'RIFF'
      && String.fromCharCode(...bytes.slice(8, 12)) === 'WEBP') return 'image/webp';
    if (String.fromCharCode(...bytes.slice(0, 6)) === 'GIF87a'
      || String.fromCharCode(...bytes.slice(0, 6)) === 'GIF89a') return 'image/gif';
    throw new Error('Choose a JPEG, PNG, GIF, or WebP avatar image.');
  }

  function annotatePreparedFile(file, detectedMime, width, height) {
    Object.defineProperties(file, {
      avatarDetectedMime: {value: detectedMime, enumerable: false},
      avatarWidth: {value: width, enumerable: false},
      avatarHeight: {value: height, enumerable: false},
    });
    return file;
  }

  function loadImage(file) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => {
        URL.revokeObjectURL(url);
        resolve(img);
      };
      img.onerror = () => {
        URL.revokeObjectURL(url);
        reject(new Error('Could not read avatar image.'));
      };
      img.src = url;
    });
  }

  function scaledSize(width, height) {
    const scale = Math.min(1, maxWidth / width, maxHeight / height);
    return {
      width: Math.max(1, Math.round(width * scale)),
      height: Math.max(1, Math.round(height * scale)),
    };
  }

  function canvasToBlob(canvas, type, quality) {
    return new Promise((resolve, reject) => {
      canvas.toBlob(blob => {
        if (blob) resolve(blob);
        else reject(new Error('Could not optimize avatar image.'));
      }, type, quality);
    });
  }

  function webpName(name) {
    const base = String(name || 'avatar').replace(/\.[^.]+$/, '') || 'avatar';
    return `${base}.webp`;
  }

  async function prepareAvatarFile(file) {
    if (!file) return null;
    const detectedMime = await detectedImageType(file);
    const img = await loadImage(file);
    const width = img.naturalWidth || img.width;
    const height = img.naturalHeight || img.height;
    if (width < MIN_AVATAR_SIZE || height < MIN_AVATAR_SIZE) {
      throw new Error(`Avatar images must be at least ${MIN_AVATAR_SIZE}x${MIN_AVATAR_SIZE}.`);
    }
    const size = scaledSize(width, height);

    if (detectedMime === 'image/gif') {
      if (size.width !== width || size.height !== height) {
        throw new Error(`Animated GIF avatars must be ${maxWidth}x${maxHeight} or smaller so the animation stays intact.`);
      }
      if (file.size > maxBytes) throw new Error('The prepared avatar exceeds the configured size limit.');
      return annotatePreparedFile(file, detectedMime, width, height);
    }

    const canvas = document.createElement('canvas');
    canvas.width = size.width;
    canvas.height = size.height;
    const ctx = canvas.getContext('2d', { alpha: true });
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.clearRect(0, 0, size.width, size.height);
    ctx.drawImage(img, 0, 0, size.width, size.height);
    const blob = await canvasToBlob(canvas, 'image/webp', 0.88);
    const prepared = new File([blob], webpName(file.name), { type: 'image/webp', lastModified: Date.now() });
    if (prepared.size > maxBytes) throw new Error('The prepared avatar exceeds the configured size limit.');
    return annotatePreparedFile(prepared, 'image/webp', size.width, size.height);
  }

  function replaceInputFile(input, file) {
    if (!input || !file) return;
    const transfer = new DataTransfer();
    transfer.items.add(file);
    input.files = transfer.files;
  }

  window.ChatSpaceAvatar = {
    get maxSize() {
      return Math.min(maxWidth, maxHeight);
    },
    get maxWidth() {
      return maxWidth;
    },
    get maxHeight() {
      return maxHeight;
    },
    minSize: MIN_AVATAR_SIZE,
    configure(policy = {}) {
      const width = Number.parseInt(policy.avatarUploadMaxWidthPx, 10);
      const height = Number.parseInt(policy.avatarUploadMaxHeightPx, 10);
      const bytes = Number.parseInt(policy.avatarMaxBytes, 10);
      if (Number.isFinite(width) && width >= MIN_AVATAR_SIZE) maxWidth = width;
      if (Number.isFinite(height) && height >= MIN_AVATAR_SIZE) maxHeight = height;
      if (Number.isFinite(bytes) && bytes > 0) maxBytes = bytes;
      return Object.freeze({ maxWidth, maxHeight, maxBytes });
    },
    prepareAvatarFile,
    replaceInputFile,
  };
})();
