/* Repository-owned streaming primitives for direct transfer. No third-party code. */

const SHA256_K = new Uint32Array([
  0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
  0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
  0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
  0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
  0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
  0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
  0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
  0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2,
]);

const rotateRight = (value, bits) => (value >>> bits) | (value << (32 - bits));

export class IncrementalSha256 {
  #state = new Uint32Array([0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19]);
  #buffer = new Uint8Array(64);
  #bufferLength = 0;
  #bytes = 0n;
  #finished = false;

  update(input) {
    if (this.#finished) throw new Error('The transfer digest is already complete.');
    const bytes = input instanceof Uint8Array ? input : new Uint8Array(input);
    this.#bytes += BigInt(bytes.byteLength);
    let offset = 0;
    while (offset < bytes.byteLength) {
      const take = Math.min(64 - this.#bufferLength, bytes.byteLength - offset);
      this.#buffer.set(bytes.subarray(offset, offset + take), this.#bufferLength);
      this.#bufferLength += take;
      offset += take;
      if (this.#bufferLength === 64) {
        this.#compress(this.#buffer);
        this.#bufferLength = 0;
      }
    }
    return this;
  }

  hex() {
    if (!this.#finished) {
      const bitLength = this.#bytes * 8n;
      this.#buffer[this.#bufferLength++] = 0x80;
      if (this.#bufferLength > 56) {
        this.#buffer.fill(0, this.#bufferLength);
        this.#compress(this.#buffer);
        this.#bufferLength = 0;
      }
      this.#buffer.fill(0, this.#bufferLength, 56);
      for (let index = 0; index < 8; index++) {
        this.#buffer[63 - index] = Number((bitLength >> BigInt(index * 8)) & 0xffn);
      }
      this.#compress(this.#buffer);
      this.#finished = true;
    }
    return [...this.#state].map(value => value.toString(16).padStart(8, '0')).join('').toUpperCase();
  }

  #compress(block) {
    const words = new Uint32Array(64);
    const view = new DataView(block.buffer, block.byteOffset, 64);
    for (let index = 0; index < 16; index++) words[index] = view.getUint32(index * 4, false);
    for (let index = 16; index < 64; index++) {
      const a = words[index - 15];
      const b = words[index - 2];
      const s0 = rotateRight(a, 7) ^ rotateRight(a, 18) ^ (a >>> 3);
      const s1 = rotateRight(b, 17) ^ rotateRight(b, 19) ^ (b >>> 10);
      words[index] = (words[index - 16] + s0 + words[index - 7] + s1) >>> 0;
    }
    let [a,b,c,d,e,f,g,h] = this.#state;
    for (let index = 0; index < 64; index++) {
      const s1 = rotateRight(e, 6) ^ rotateRight(e, 11) ^ rotateRight(e, 25);
      const choice = (e & f) ^ (~e & g);
      const t1 = (h + s1 + choice + SHA256_K[index] + words[index]) >>> 0;
      const s0 = rotateRight(a, 2) ^ rotateRight(a, 13) ^ rotateRight(a, 22);
      const majority = (a & b) ^ (a & c) ^ (b & c);
      const t2 = (s0 + majority) >>> 0;
      h = g; g = f; f = e; e = (d + t1) >>> 0; d = c; c = b; b = a; a = (t1 + t2) >>> 0;
    }
    this.#state[0] = (this.#state[0] + a) >>> 0;
    this.#state[1] = (this.#state[1] + b) >>> 0;
    this.#state[2] = (this.#state[2] + c) >>> 0;
    this.#state[3] = (this.#state[3] + d) >>> 0;
    this.#state[4] = (this.#state[4] + e) >>> 0;
    this.#state[5] = (this.#state[5] + f) >>> 0;
    this.#state[6] = (this.#state[6] + g) >>> 0;
    this.#state[7] = (this.#state[7] + h) >>> 0;
  }
}

const CRC_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let index = 0; index < 256; index++) {
    let value = index;
    for (let bit = 0; bit < 8; bit++) value = (value & 1) ? (0xedb88320 ^ (value >>> 1)) : (value >>> 1);
    table[index] = value >>> 0;
  }
  return table;
})();

export const crc32Start = () => 0xffffffff;
export const crc32Update = (state, input) => {
  const bytes = input instanceof Uint8Array ? input : new Uint8Array(input);
  let value = state >>> 0;
  for (const byte of bytes) value = CRC_TABLE[(value ^ byte) & 0xff] ^ (value >>> 8);
  return value >>> 0;
};
export const crc32Finish = state => (state ^ 0xffffffff) >>> 0;

const RESERVED_DEVICE = /^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i;

export function safeFlatZipNames(names) {
  return safeRelativeZipPaths(names, {flatOnly: true});
}

export function safeRelativeZipPaths(paths, {flatOnly = false} = {}) {
  const seen = new Set();
  return paths.map(value => {
    const path = String(value || '').replaceAll('\\', '/').normalize('NFC');
    const segments = path.split('/');
    if (!path || path.length > 512 || path.startsWith('/') || /^[A-Za-z]:/.test(path)
      || (flatOnly && segments.length !== 1) || segments.some(segment => !segment
        || segment === '.' || segment === '..' || segment.length > 180
        || /[:*?"<>|\u0000-\u001f\u007f]/u.test(segment)
        || /[. ]$/u.test(segment) || RESERVED_DEVICE.test(segment)
        || /[\u202A-\u202E\u2066-\u2069]/u.test(segment))) {
      throw new Error('The selected files contain an unsafe archive path.');
    }
    const key = path.toLocaleLowerCase('en-US');
    if (seen.has(key)) throw new Error('The selected files contain a duplicate or deceptive archive path.');
    seen.add(key);
    return path;
  });
}

export function storedZipSize(entries) {
  const encoder = new TextEncoder();
  let total = 22;
  for (const entry of entries) {
    const nameBytes = encoder.encode(String(entry.name || ''));
    const size = Number(entry.size);
    if (!Number.isSafeInteger(size) || size < 0 || size > 0xffffffff) throw new Error('The generated archive exceeds the supported ZIP size.');
    total += 30 + nameBytes.byteLength + size + 46 + nameBytes.byteLength;
  }
  if (!Number.isSafeInteger(total) || total > 0xffffffff) throw new Error('The generated archive exceeds ZIP32 limits.');
  return total;
}

const littleEndian = (size, writer) => {
  const bytes = new Uint8Array(size);
  const view = new DataView(bytes.buffer);
  writer(view);
  return bytes;
};

export async function buildStoredZip(entries, sink) {
  const names = safeRelativeZipPaths(entries.map(entry => entry.name));
  const encoder = new TextEncoder();
  const central = [];
  let offset = 0;
  for (let index = 0; index < entries.length; index++) {
    const entry = entries[index];
    const nameBytes = encoder.encode(names[index]);
    const size = Number(entry.size);
    if (!Number.isSafeInteger(size) || size < 0 || size > 0xffffffff || offset > 0xffffffff || nameBytes.byteLength > 0xffff) {
      throw new Error('The generated archive exceeds the supported ZIP size.');
    }
    const localOffset = offset;
    const local = littleEndian(30, view => {
      view.setUint32(0, 0x04034b50, true); view.setUint16(4, 20, true); view.setUint16(6, 0x0800, true);
      view.setUint16(8, 0, true); view.setUint16(10, 0, true); view.setUint16(12, 0x21, true);
      view.setUint32(14, entry.crc32 >>> 0, true); view.setUint32(18, size, true); view.setUint32(22, size, true);
      view.setUint16(26, nameBytes.byteLength, true); view.setUint16(28, 0, true);
    });
    await sink.write(offset, local); offset += local.byteLength;
    await sink.write(offset, nameBytes); offset += nameBytes.byteLength;
    const stream = entry.blob.stream().getReader();
    while (true) {
      const {done, value} = await stream.read();
      if (done) break;
      await sink.write(offset, value); offset += value.byteLength;
    }
    central.push({nameBytes,size,crc32: entry.crc32 >>> 0,localOffset});
  }
  const centralOffset = offset;
  for (const entry of central) {
    const header = littleEndian(46, view => {
      view.setUint32(0, 0x02014b50, true); view.setUint16(4, 20, true); view.setUint16(6, 20, true);
      view.setUint16(8, 0x0800, true); view.setUint16(10, 0, true); view.setUint16(12, 0, true); view.setUint16(14, 0x21, true);
      view.setUint32(16, entry.crc32, true); view.setUint32(20, entry.size, true); view.setUint32(24, entry.size, true);
      view.setUint16(28, entry.nameBytes.byteLength, true); view.setUint16(30, 0, true); view.setUint16(32, 0, true);
      view.setUint16(34, 0, true); view.setUint16(36, 0, true); view.setUint32(38, 0, true); view.setUint32(42, entry.localOffset, true);
    });
    await sink.write(offset, header); offset += header.byteLength;
    await sink.write(offset, entry.nameBytes); offset += entry.nameBytes.byteLength;
  }
  const centralSize = offset - centralOffset;
  if (central.length > 0xffff || centralOffset > 0xffffffff || centralSize > 0xffffffff) throw new Error('The generated archive exceeds ZIP32 limits.');
  const end = littleEndian(22, view => {
    view.setUint32(0, 0x06054b50, true); view.setUint16(4, 0, true); view.setUint16(6, 0, true);
    view.setUint16(8, central.length, true); view.setUint16(10, central.length, true);
    view.setUint32(12, centralSize, true); view.setUint32(16, centralOffset, true); view.setUint16(20, 0, true);
  });
  await sink.write(offset, end);
  return await sink.finalize(offset + end.byteLength);
}
