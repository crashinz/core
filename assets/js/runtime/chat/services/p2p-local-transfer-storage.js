/* Durable, account-scoped recipient-local storage for authenticated P2P transfers. */

const ROOT_NAME = 'corechat-p2p-transfers-v2';
const DATABASE_NAME = 'corechat-p2p-transfers-v2';
const DATABASE_VERSION = 1;
const FALLBACK_MAX_BYTES = 128 * 1024 * 1024;
const STATE_FILE_PREFIX = '_state--';

const CRC_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let index = 0; index < 256; index++) {
    let value = index;
    for (let bit = 0; bit < 8; bit++) value = (value & 1) ? (0xedb88320 ^ (value >>> 1)) : (value >>> 1);
    table[index] = value >>> 0;
  }
  return table;
})();
const crc32Update = (state, input) => {
  const bytes = input instanceof Uint8Array ? input : new Uint8Array(input);
  let value = state >>> 0;
  for (const byte of bytes) value = CRC_TABLE[(value ^ byte) & 0xff] ^ (value >>> 8);
  return value >>> 0;
};
const zipBytes = (size, writer) => {
  const bytes = new Uint8Array(size);
  writer(new DataView(bytes.buffer));
  return bytes;
};

const requestResult = request => new Promise((resolve, reject) => {
  request.onsuccess = () => resolve(request.result);
  request.onerror = () => reject(request.error || new Error('Browser storage is unavailable.'));
});

const transactionDone = transaction => new Promise((resolve, reject) => {
  transaction.oncomplete = () => resolve();
  transaction.onabort = () => reject(transaction.error || new Error('Browser storage operation was cancelled.'));
  transaction.onerror = () => reject(transaction.error || new Error('Browser storage operation failed.'));
});

const safePart = value => String(value || '').replace(/[^A-Za-z0-9._-]+/g, '-').slice(0, 120);
const attemptPrefix = attemptId => `${safePart(attemptId)}--`;

export class P2PLocalTransferStorage {
  #opfsRoot = null;
  #database = null;
  #ready = null;
  #accountId = 0;
  #preparedDirectSinks = new Map();
  #preparedDirectBatches = new Map();

  async initialize(accountId = 0) {
    if (Number(accountId) > 0) this.#accountId = Number(accountId);
    if (this.#ready) return await this.#ready;
    this.#ready = this.#initializeOnce();
    return await this.#ready;
  }

  async #initializeOnce() {
    if (navigator.storage?.getDirectory) {
      try {
        const originRoot = await navigator.storage.getDirectory();
        this.#opfsRoot = await originRoot.getDirectoryHandle(ROOT_NAME, {create: true});
      } catch {
        this.#opfsRoot = null;
      }
    }
    if (globalThis.indexedDB) {
      try { this.#database = await this.#openDatabase(); }
      catch { this.#database = null; }
    }
    if (!this.#opfsRoot && !this.#database) {
      return {opfs: false, indexedDb: false, directToDisk: typeof globalThis.showSaveFilePicker === 'function'};
    }
    return {opfs: Boolean(this.#opfsRoot), indexedDb: Boolean(this.#database), directToDisk: typeof globalThis.showSaveFilePicker === 'function'};
  }

  async capabilities(bytes = 0) {
    const ready = await this.initialize();
    const estimate = await navigator.storage?.estimate?.().catch?.(() => null) || null;
    const available = estimate && Number.isFinite(estimate.quota) && Number.isFinite(estimate.usage)
      ? Math.max(0, estimate.quota - estimate.usage) : null;
    const required = Number(bytes) > 0 ? Math.ceil(Number(bytes) * 1.1) + 4 * 1024 * 1024 : 0;
    return {
      ...ready,
      estimatedAvailableBytes: available,
      requiredBytes: required,
      opfsCapacityAvailable: Boolean(this.#opfsRoot) && (available === null || available >= required),
      indexedDbCapacityAvailable: Boolean(this.#database) && Number(bytes) <= FALLBACK_MAX_BYTES && (available === null || available >= required),
      serverQuotaUsed: false,
    };
  }

  async assertCapacity(bytes) {
    if (!Number.isSafeInteger(bytes) || bytes <= 0) throw new Error('The requested local transfer size is invalid.');
    const capability = await this.capabilities(bytes);
    if (capability.opfsCapacityAvailable) return {...capability, mode: 'opfs'};
    if (capability.indexedDbCapacityAvailable) return {...capability, mode: 'indexeddb'};
    if (capability.directToDisk) return {...capability, mode: 'direct'};
    throw new Error('This device does not have enough supported browser storage for the transfer. This is not a CoreChat server quota.');
  }

  async saveState(state) {
    await this.initialize();
    const record = structuredClone(state || {});
    const id = safePart(record.id);
    if (!id || Number(record.accountId) !== this.#accountId || !Number.isFinite(Date.parse(String(record.expiresAt || '')))) {
      throw new Error('The durable transfer state is invalid.');
    }
    record.id = id;
    record.updatedAt = new Date().toISOString();
    if (this.#database) {
      const tx = this.#database.transaction('transfers', 'readwrite');
      tx.objectStore('transfers').put(record);
      await transactionDone(tx);
      return;
    }
    await this.#writeStateFile(record);
  }

  async loadState(attemptId) {
    await this.initialize();
    const id = safePart(attemptId);
    let record = null;
    if (this.#database) {
      const tx = this.#database.transaction('transfers', 'readonly');
      record = await requestResult(tx.objectStore('transfers').get(id));
      await transactionDone(tx);
    } else {
      record = await this.#readStateFile(id);
    }
    return record && Number(record.accountId) === this.#accountId ? record : null;
  }

  async listStates() {
    await this.initialize();
    let rows = [];
    if (this.#database) {
      const tx = this.#database.transaction('transfers', 'readonly');
      rows = await requestResult(tx.objectStore('transfers').index('account').getAll(IDBKeyRange.only(this.#accountId)));
      await transactionDone(tx);
    } else if (this.#opfsRoot) {
      for await (const name of this.#opfsRoot.keys()) {
        if (!name.startsWith(STATE_FILE_PREFIX)) continue;
        const row = await this.#readStateFile(name.slice(STATE_FILE_PREFIX.length, -5));
        if (row && Number(row.accountId) === this.#accountId) rows.push(row);
      }
    }
    return rows.sort((a, b) => String(b.updatedAt || '').localeCompare(String(a.updatedAt || '')));
  }

  async createSink(attemptId, fileIndex, expectedBytes, options = {}) {
    const capability = await this.assertCapacity(expectedBytes);
    const requestedMode = String(options.mode || 'auto');
    const mode = requestedMode === 'auto' ? capability.mode : requestedMode;
    if (mode === 'direct') {
      const preparedKey = `${safePart(attemptId)}--${Number(fileIndex)}`;
      const prepared = this.#preparedDirectSinks.get(preparedKey);
      if (!prepared || Number(prepared.expectedBytes) !== Number(expectedBytes)) {
        throw new Error('Select the direct-to-device destination again before accepting this transfer.');
      }
      this.#preparedDirectSinks.delete(preparedKey);
      return prepared;
    }
    if (mode === 'direct-batch') {
      const batch = this.#preparedDirectBatches.get(safePart(attemptId));
      if (!batch) throw new Error('Select the direct-to-device ZIP destination again before accepting this transfer.');
      return await batch.begin(fileIndex, String(options.suggestedName || 'file'), expectedBytes);
    }
    const key = `${attemptPrefix(attemptId)}${String(Number(fileIndex)).padStart(5, '0')}.part`;
    if (mode === 'opfs' && this.#opfsRoot) return await this.#createOpfsSink(key, expectedBytes, Boolean(options.resume));
    if (mode === 'indexeddb' && this.#database && expectedBytes <= FALLBACK_MAX_BYTES) return await this.#createIndexedSink(key, expectedBytes, Boolean(options.resume));
    throw new Error('The selected recipient-local storage mode is unavailable. This is not a CoreChat server quota.');
  }

  async cleanupAttempt(attemptId) {
    await this.initialize();
    const prefix = attemptPrefix(attemptId);
    const preparedPrefix = `${safePart(attemptId)}--`;
    for (const [key, sink] of this.#preparedDirectSinks) {
      if (!key.startsWith(preparedPrefix)) continue;
      await sink.cancel().catch(() => {});
      this.#preparedDirectSinks.delete(key);
    }
    const directBatch = this.#preparedDirectBatches.get(safePart(attemptId));
    if (directBatch) {
      await directBatch.cancel().catch(() => {});
      this.#preparedDirectBatches.delete(safePart(attemptId));
    }
    if (this.#opfsRoot) {
      for await (const name of this.#opfsRoot.keys()) {
        if (name.startsWith(prefix) || name === `${STATE_FILE_PREFIX}${safePart(attemptId)}.json`) {
          await this.#opfsRoot.removeEntry(name).catch(() => {});
        }
      }
    }
    if (this.#database) {
      await this.#deleteIndexedPrefix(prefix);
      const tx = this.#database.transaction('transfers', 'readwrite');
      tx.objectStore('transfers').delete(safePart(attemptId));
      await transactionDone(tx);
    }
  }

  async cleanupInvalid(validOfferIds = [], now = Date.now()) {
    const valid = new Set(validOfferIds.map(safePart));
    const states = await this.listStates();
    for (const state of states) {
      const expired = Date.parse(String(state.expiresAt || '')) <= now;
      if (expired || !valid.has(safePart(state.id)) || Number(state.accountId) !== this.#accountId) {
        await this.cleanupAttempt(state.id);
      }
    }
  }

  async explicitLogout() {
    const states = await this.listStates();
    for (const state of states) await this.cleanupAttempt(state.id);
  }

  async destroy() {
    for (const sink of this.#preparedDirectSinks.values()) await sink.cancel().catch(() => {});
    this.#preparedDirectSinks.clear();
    for (const batch of this.#preparedDirectBatches.values()) await batch.cancel().catch(() => {});
    this.#preparedDirectBatches.clear();
    this.#database?.close?.();
    this.#database = null;
    this.#ready = null;
  }

  async #createOpfsSink(key, expectedBytes, resume) {
    const handle = await this.#opfsRoot.getFileHandle(key, {create: true});
    const existing = resume ? await handle.getFile() : null;
    let written = existing?.size || 0;
    if (written > expectedBytes) throw new Error('The retained local transfer exceeded its authorized size.');
    if (!resume) {
      const reset = await handle.createWritable({keepExistingData: false});
      await reset.truncate(0);
      await reset.close();
    }
    let closed = false;
    return {
      mode: 'opfs', durable: true, expectedBytes, written,
      prefix: async () => await handle.getFile(),
      write: async (offset, input) => {
        if (closed || offset !== written) throw new Error('Local transfer chunks were not contiguous.');
        const bytes = input instanceof Uint8Array ? input : new Uint8Array(input);
        if (written + bytes.byteLength > expectedBytes) throw new Error('Local transfer exceeded its authorized size.');
        const writable = await handle.createWritable({keepExistingData: true});
        await writable.write({type: 'write', position: offset, data: bytes});
        await writable.close();
        written += bytes.byteLength;
      },
      finalize: async exactBytes => {
        if (closed || exactBytes !== written) throw new Error('Local transfer size did not match.');
        closed = true;
        const file = await handle.getFile();
        if (file.size !== exactBytes) throw new Error('Local transfer storage did not preserve the complete file.');
        return {blob: file, bytes: file.size, mode: 'opfs', cleanup: () => this.#opfsRoot.removeEntry(key).catch(() => {})};
      },
      cancel: async () => {
        closed = true;
        await this.#opfsRoot.removeEntry(key).catch(() => {});
      },
    };
  }

  async #createIndexedSink(key, expectedBytes, resume) {
    const existing = resume ? await this.#readIndexedAttempt(key) : [];
    if (!resume) await this.#deleteIndexedPrefix(`${key}--`);
    let written = existing.reduce((total, row) => total + row.bytes.byteLength, 0);
    if (written > expectedBytes) throw new Error('The retained local transfer exceeded its authorized size.');
    let closed = false;
    return {
      mode: 'indexeddb', durable: true, expectedBytes, written,
      prefix: async () => new Blob((await this.#readIndexedAttempt(key)).map(row => row.bytes)),
      write: async (offset, input) => {
        if (closed || offset !== written) throw new Error('Local transfer chunks were not contiguous.');
        const bytes = input instanceof Uint8Array ? input.slice() : new Uint8Array(input).slice();
        if (written + bytes.byteLength > expectedBytes) throw new Error('Local transfer exceeded its authorized size.');
        const tx = this.#database.transaction('chunks', 'readwrite');
        tx.objectStore('chunks').put({id: `${key}--${String(offset).padStart(16, '0')}`, attempt: key, offset, bytes});
        await transactionDone(tx);
        written += bytes.byteLength;
      },
      finalize: async exactBytes => {
        if (closed || exactBytes !== written) throw new Error('Local transfer size did not match.');
        closed = true;
        const rows = await this.#readIndexedAttempt(key);
        const blob = new Blob(rows.map(row => row.bytes), {type: 'application/octet-stream'});
        if (blob.size !== exactBytes) throw new Error('Local transfer storage did not preserve the complete file.');
        return {blob, bytes: blob.size, mode: 'indexeddb', cleanup: () => this.#deleteIndexedPrefix(`${key}--`)};
      },
      cancel: async () => { closed = true; await this.#deleteIndexedPrefix(`${key}--`); },
    };
  }

  async #createDirectSink(expectedBytes, suggestedName) {
    if (typeof globalThis.showSaveFilePicker !== 'function') throw new Error('Direct-to-device download is unavailable in this browser.');
    const handle = await globalThis.showSaveFilePicker({suggestedName: String(suggestedName || 'download.bin')});
    const writable = await handle.createWritable({keepExistingData: false});
    let written = 0;
    let closed = false;
    return {
      mode: 'direct', durable: false, expectedBytes, written: 0,
      prefix: async () => new Blob([]),
      write: async (offset, input) => {
        if (closed || offset !== written) throw new Error('Direct-to-device chunks were not contiguous.');
        const bytes = input instanceof Uint8Array ? input : new Uint8Array(input);
        if (written + bytes.byteLength > expectedBytes) throw new Error('Direct-to-device transfer exceeded its authorized size.');
        await writable.write({type: 'write', position: offset, data: bytes});
        written += bytes.byteLength;
      },
      finalize: async exactBytes => {
        if (closed || exactBytes !== written) throw new Error('Direct-to-device transfer size did not match.');
        await writable.close(); closed = true;
        const file = await handle.getFile();
        if (file.size !== exactBytes) throw new Error('Direct-to-device storage did not preserve the complete file.');
        return {blob: file, bytes: file.size, mode: 'direct', cleanup: async () => {}};
      },
      cancel: async () => {
        if (!closed) await writable.abort().catch(() => {});
        closed = true;
      },
    };
  }

  async prepareDirectSink(attemptId, fileIndex, expectedBytes, suggestedName) {
    const key = `${safePart(attemptId)}--${Number(fileIndex)}`;
    const previous = this.#preparedDirectSinks.get(key);
    if (previous) await previous.cancel().catch(() => {});
    const sink = await this.#createDirectSink(expectedBytes, suggestedName);
    this.#preparedDirectSinks.set(key, sink);
    return {mode: 'direct', prepared: true};
  }

  async prepareDirectBatch(attemptId, manifest, suggestedName) {
    if (typeof globalThis.showSaveFilePicker !== 'function') throw new Error('Direct-to-device download is unavailable in this browser.');
    const files = Array.isArray(manifest?.files) ? manifest.files : [];
    if (files.length < 2 || files.length > 20) throw new Error('The direct-to-device batch manifest is invalid.');
    const key = safePart(attemptId);
    const previous = this.#preparedDirectBatches.get(key);
    if (previous) await previous.cancel().catch(() => {});
    const handle = await globalThis.showSaveFilePicker({suggestedName: String(suggestedName || 'CoreChat-transfer.zip')});
    const writable = await handle.createWritable({keepExistingData: false});
    const encoder = new TextEncoder();
    let offset = 0;
    let closed = false;
    let current = null;
    let nextFileIndex = 0;
    const entries = [];
    const write = async bytes => {
      if (closed) throw new Error('The direct-to-device ZIP is closed.');
      const input = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
      await writable.write({type: 'write', position: offset, data: input});
      offset += input.byteLength;
    };
    const batch = {
      skip(fileIndex) {
        if (closed || current || Number(fileIndex) !== nextFileIndex) throw new Error('The direct-to-device ZIP skip order is invalid.');
        nextFileIndex += 1;
      },
      async begin(fileIndex, name, expectedBytes) {
        if (closed || current || Number(fileIndex) !== nextFileIndex || !Number.isSafeInteger(expectedBytes) || expectedBytes <= 0 || expectedBytes > 0xffffffff) {
          throw new Error('The direct-to-device ZIP entry order is invalid.');
        }
        nextFileIndex += 1;
        const nameBytes = encoder.encode(String(name));
        if (!nameBytes.byteLength || nameBytes.byteLength > 0xffff || offset > 0xffffffff) throw new Error('The generated ZIP path is invalid.');
        const localOffset = offset;
        const header = zipBytes(30, view => {
          view.setUint32(0, 0x04034b50, true); view.setUint16(4, 20, true); view.setUint16(6, 0x0808, true);
          view.setUint16(8, 0, true); view.setUint16(10, 0, true); view.setUint16(12, 0x21, true);
          view.setUint32(14, 0, true); view.setUint32(18, 0, true); view.setUint32(22, 0, true);
          view.setUint16(26, nameBytes.byteLength, true); view.setUint16(28, 0, true);
        });
        await write(header); await write(nameBytes);
        const entry = {fileIndex: Number(fileIndex), nameBytes, size: expectedBytes, localOffset, written: 0, crc: 0xffffffff, committed: false};
        current = entry;
        return {
          mode: 'direct-batch', durable: false, expectedBytes, written: 0,
          prefix: async () => new Blob([]),
          write: async (fileOffset, input) => {
            if (closed || current !== entry || entry.committed || fileOffset !== entry.written) throw new Error('Direct-to-device ZIP chunks were not contiguous.');
            const bytes = input instanceof Uint8Array ? input : new Uint8Array(input);
            if (entry.written + bytes.byteLength > expectedBytes) throw new Error('Direct-to-device ZIP entry exceeded its authorized size.');
            await write(bytes); entry.written += bytes.byteLength; entry.crc = crc32Update(entry.crc, bytes);
          },
          finalize: async exactBytes => {
            if (closed || current !== entry || entry.committed || exactBytes !== entry.written) throw new Error('Direct-to-device ZIP entry size did not match.');
            return {
              blob: null, bytes: exactBytes, mode: 'direct-batch', crc32: (entry.crc ^ 0xffffffff) >>> 0,
              commit: async () => {
                if (closed || current !== entry || entry.committed) throw new Error('The direct-to-device ZIP entry is no longer pending.');
                const crc32 = (entry.crc ^ 0xffffffff) >>> 0;
                const descriptor = zipBytes(16, view => {
                  view.setUint32(0, 0x08074b50, true); view.setUint32(4, crc32, true);
                  view.setUint32(8, entry.size, true); view.setUint32(12, entry.size, true);
                });
                await write(descriptor); entry.crc32 = crc32; entry.committed = true; entries.push(entry); current = null;
              },
              cleanup: async () => {
                if (current !== entry || entry.committed) return;
                await writable.truncate(entry.localOffset); offset = entry.localOffset; current = null;
              },
            };
          },
          cancel: async () => {
            if (current !== entry || entry.committed) return;
            await writable.truncate(entry.localOffset); offset = entry.localOffset; current = null;
          },
        };
      },
      async finalize() {
        if (closed || current || !entries.length) throw new Error('The direct-to-device ZIP is incomplete.');
        const centralOffset = offset;
        for (const entry of entries) {
          const header = zipBytes(46, view => {
            view.setUint32(0, 0x02014b50, true); view.setUint16(4, 20, true); view.setUint16(6, 20, true);
            view.setUint16(8, 0x0808, true); view.setUint16(10, 0, true); view.setUint16(12, 0, true); view.setUint16(14, 0x21, true);
            view.setUint32(16, entry.crc32, true); view.setUint32(20, entry.size, true); view.setUint32(24, entry.size, true);
            view.setUint16(28, entry.nameBytes.byteLength, true); view.setUint16(30, 0, true); view.setUint16(32, 0, true);
            view.setUint16(34, 0, true); view.setUint16(36, 0, true); view.setUint32(38, 0, true); view.setUint32(42, entry.localOffset, true);
          });
          await write(header); await write(entry.nameBytes);
        }
        const centralSize = offset - centralOffset;
        if (entries.length > 0xffff || centralOffset > 0xffffffff || centralSize > 0xffffffff) throw new Error('The generated ZIP exceeds ZIP32 limits.');
        const end = zipBytes(22, view => {
          view.setUint32(0, 0x06054b50, true); view.setUint16(4, 0, true); view.setUint16(6, 0, true);
          view.setUint16(8, entries.length, true); view.setUint16(10, entries.length, true);
          view.setUint32(12, centralSize, true); view.setUint32(16, centralOffset, true); view.setUint16(20, 0, true);
        });
        await write(end); await writable.close(); closed = true;
        const file = await handle.getFile();
        return {blob: file, bytes: file.size, mode: 'direct-batch', cleanup: async () => {}};
      },
      async cancel() {
        if (!closed) await writable.abort().catch(() => {});
        closed = true; current = null;
      },
    };
    this.#preparedDirectBatches.set(key, batch);
    return {mode: 'direct-batch', prepared: true};
  }

  async finalizeDirectBatch(attemptId) {
    const key = safePart(attemptId);
    const batch = this.#preparedDirectBatches.get(key);
    if (!batch) throw new Error('The direct-to-device ZIP destination is unavailable.');
    const result = await batch.finalize();
    this.#preparedDirectBatches.delete(key);
    return result;
  }

  skipDirectBatchEntry(attemptId, fileIndex) {
    const batch = this.#preparedDirectBatches.get(safePart(attemptId));
    if (!batch) throw new Error('The direct-to-device ZIP destination is unavailable.');
    batch.skip(fileIndex);
  }

  async #openDatabase() {
    const request = indexedDB.open(DATABASE_NAME, DATABASE_VERSION);
    request.onupgradeneeded = () => {
      const database = request.result;
      const chunks = database.createObjectStore('chunks', {keyPath: 'id'});
      chunks.createIndex('attempt', 'attempt', {unique: false});
      const transfers = database.createObjectStore('transfers', {keyPath: 'id'});
      transfers.createIndex('account', 'accountId', {unique: false});
    };
    return await requestResult(request);
  }

  async #readIndexedAttempt(key) {
    const tx = this.#database.transaction('chunks', 'readonly');
    const rows = await requestResult(tx.objectStore('chunks').index('attempt').getAll(IDBKeyRange.only(key)));
    await transactionDone(tx);
    return rows.sort((a, b) => a.offset - b.offset);
  }

  async #deleteIndexedPrefix(prefix) {
    if (!this.#database) return;
    const tx = this.#database.transaction('chunks', 'readwrite');
    const request = tx.objectStore('chunks').openKeyCursor();
    request.onsuccess = () => {
      const cursor = request.result;
      if (!cursor) return;
      if (String(cursor.primaryKey).startsWith(prefix)) tx.objectStore('chunks').delete(cursor.primaryKey);
      cursor.continue();
    };
    await transactionDone(tx);
  }

  async #writeStateFile(record) {
    if (!this.#opfsRoot) throw new Error('Durable transfer metadata is unavailable.');
    const handle = await this.#opfsRoot.getFileHandle(`${STATE_FILE_PREFIX}${safePart(record.id)}.json`, {create: true});
    const writable = await handle.createWritable({keepExistingData: false});
    await writable.write(JSON.stringify(record));
    await writable.close();
  }

  async #readStateFile(id) {
    if (!this.#opfsRoot) return null;
    try {
      const handle = await this.#opfsRoot.getFileHandle(`${STATE_FILE_PREFIX}${safePart(id)}.json`);
      return JSON.parse(await (await handle.getFile()).text());
    } catch {
      return null;
    }
  }
}

export default P2PLocalTransferStorage;
