/******************************************************************************
 * Build 000045 - bounded runtime issue capture and safe schematic evidence.
 ******************************************************************************/

const SENSITIVE_KEY = /authorization|cookie|csrf|password|secret|token|deviceid|groupid|sdp|candidate|message|content|private/i;
const PRIVATE_PATH = /(?:[a-z]:\\|\/(?:users|home|tmp)\/)/i;
const MAX_STRING = 512;
const MAX_REPORTS_PER_MINUTE = 5;
const DUPLICATE_BACKOFF_MS = 30000;

function safeString(value, max = MAX_STRING) {
  return String(value || '')
    .replace(/https?:\/\/\S+/gi, '[url]')
    .replace(/(?:[a-z]:\\|\/(?:users|home|tmp)\/)\S+/gi, '[private-path]')
    .replace(/((?:cookie|authorization|password|secret|token|csrf)\s*[:=]\s*)\S+/gi, '$1[redacted]')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);
}

function sanitize(value, key = '', depth = 0, seen = new WeakSet()) {
  if (SENSITIVE_KEY.test(key)) return '[redacted]';
  if (value === null || typeof value === 'boolean' || typeof value === 'number') return value;
  if (typeof value === 'string') return PRIVATE_PATH.test(value) ? '[redacted-path]' : safeString(value);
  if (value === undefined) return null;
  if (!value || typeof value !== 'object') return safeString(value, 80);
  if (depth >= 5 || seen.has(value)) return '[truncated]';
  seen.add(value);
  if (Array.isArray(value)) return value.slice(0, 32).map(item => sanitize(item, key, depth + 1, seen));
  const result = {};
  for (const childKey of Object.keys(value).slice(0, 48)) {
    const safeKey = String(childKey).replace(/[^A-Za-z0-9_.:-]/g, '-').slice(0, 80);
    if (SENSITIVE_KEY.test(safeKey)) continue;
    result[safeKey] = sanitize(value[childKey], safeKey, depth + 1, seen);
  }
  return result;
}

function errorIdentity(error, fallback = {}) {
  return {
    category: safeString(fallback.category || 'browser', 64).toLowerCase(),
    component: safeString(fallback.component || 'room-runtime', 96).toLowerCase(),
    error_code: safeString(error?.code || error?.name || fallback.code || 'ERROR', 96).toUpperCase(),
    title: safeString(fallback.title || error?.name || 'Runtime failure', 191),
    message: safeString(error?.message || fallback.message || 'Runtime failure'),
    severity: ['info', 'warning', 'error', 'critical'].includes(fallback.severity) ? fallback.severity : 'error',
  };
}

export class RuntimeIssueCaptureService {
  #endpoint;
  #csrfToken;
  #diagnostics;
  #fetch;
  #global;
  #document;
  #buildId;
  #listeners = [];
  #recent = new Map();
  #reportTimes = [];
  #destroyed = false;
  #submitting = false;

  constructor({ endpoint = '/api/runtime_issues.php', csrfToken = '', diagnostics = null, fetchImpl = globalThis.fetch?.bind(globalThis), globalObject = globalThis, documentObject = globalThis.document, buildId = '000045' } = {}) {
    if (typeof fetchImpl !== 'function') throw new TypeError('RuntimeIssueCaptureService requires fetch().');
    this.#endpoint = endpoint;
    this.#csrfToken = csrfToken;
    this.#diagnostics = diagnostics;
    this.#fetch = fetchImpl;
    this.#global = globalObject;
    this.#document = documentObject;
    this.#buildId = safeString(buildId, 96) || '000045';
  }

  start() {
    if (this.#destroyed || this.#listeners.length) return this;
    const onError = event => {
      const error = event?.error || new Error(safeString(event?.message || 'Browser error'));
      this.capture(error, { category: 'browser', component: 'window-error' });
    };
    const onRejection = event => {
      const reason = event?.reason instanceof Error ? event.reason : new Error(safeString(event?.reason || 'Unhandled promise rejection'));
      if (reason.name === 'AbortError') return;
      this.capture(reason, { category: 'browser', component: 'unhandled-rejection' });
    };
    this.#global.addEventListener?.('error', onError);
    this.#global.addEventListener?.('unhandledrejection', onRejection);
    this.#listeners.push(['error', onError], ['unhandledrejection', onRejection]);
    return this;
  }

  captureRequestFailure(error) {
    if (error?.code === 'REQUEST_ABORTED') return Promise.resolve(null);
    return this.capture(error, { category: 'request', component: error?.details?.endpointCategory || 'room-api' }, {
      operation: error?.details?.operation,
      endpointCategory: error?.details?.endpointCategory,
      method: error?.details?.method,
      status: error?.details?.status,
      redirected: error?.details?.redirected,
      contentType: error?.details?.contentType,
      recoverable: error?.details?.recoverable,
      causeSummary: error?.details?.causeSummary,
    });
  }

  async capture(error, context = {}, evidence = {}) {
    if (this.#destroyed || this.#submitting) return null;
    const identity = errorIdentity(error, context);
    const key = `${identity.category}|${identity.component}|${identity.error_code}|${identity.message}`;
    const now = Date.now();
    if ((this.#recent.get(key) || 0) > now - DUPLICATE_BACKOFF_MS) return null;
    this.#reportTimes = this.#reportTimes.filter(timestamp => timestamp > now - 60000);
    if (this.#reportTimes.length >= MAX_REPORTS_PER_MINUTE) return null;
    this.#recent.set(key, now);
    this.#reportTimes.push(now);
    return this.#submit(identity, this.#safeEvidence(error, evidence));
  }

  async report({ summary, component = 'manual-report', includeScreenshot = false } = {}) {
    const cleanSummary = safeString(summary, 500);
    if (!cleanSummary) throw new Error('Describe the problem before submitting.');
    const result = await this.#submit({
      category: 'user-report', component, error_code: 'USER_REPORT',
      title: 'User-reported problem', message: cleanSummary, severity: 'warning',
    }, this.#safeEvidence(null, { reportKind: 'manual', currentSurface: 'room' }));
    if (includeScreenshot && result?.issue_id && result?.occurrence_id) {
      const dataUrl = this.createCensoredSchematic();
      if (dataUrl) await this.#post({ action: 'screenshot', issue_id: result.issue_id, occurrence_id: result.occurrence_id, data_url: dataUrl });
    }
    return result;
  }

  createCensoredSchematic() {
    if (this.#destroyed || !this.#document?.createElement) return null;
    const width = Math.max(320, Math.min(960, Number(this.#global.innerWidth) || 960));
    const height = Math.max(240, Math.min(720, Number(this.#global.innerHeight) || 540));
    const canvas = this.#document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    if (!ctx) return null;
    ctx.fillStyle = '#11151b';
    ctx.fillRect(0, 0, width, height);
    const draw = (selector, color, label) => {
      for (const element of this.#document.querySelectorAll(selector)) {
        const rect = element.getBoundingClientRect();
        if (rect.width < 1 || rect.height < 1) continue;
        const x = Math.max(0, Math.min(width, rect.left));
        const y = Math.max(0, Math.min(height, rect.top));
        const w = Math.max(1, Math.min(width - x, rect.width));
        const h = Math.max(1, Math.min(height - y, rect.height));
        ctx.fillStyle = color;
        ctx.fillRect(x, y, w, h);
        ctx.fillStyle = '#ffffff';
        ctx.font = '12px sans-serif';
        ctx.fillText(label, x + 6, Math.min(y + 18, y + h - 4));
      }
    };
    draw('.room-stage', '#24313d', 'Room stage');
    draw('.avatar', '#226f83', 'Avatar');
    draw('video, .webcam-layer, .remote-video', '#6a356e', 'Webcam');
    draw('.chat-pane, .sidebar, .modal, #room-menu, #ctx-menu, #msg-action-menu, .notification', '#080a0d', 'Censored area');
    Object.defineProperty(canvas, '__chatspaceCensorVerified', { value: true });
    return canvas.__chatspaceCensorVerified ? canvas.toDataURL('image/png') : null;
  }

  destroy() {
    if (this.#destroyed) return;
    for (const [event, handler] of this.#listeners) this.#global.removeEventListener?.(event, handler);
    this.#listeners = [];
    this.#recent.clear();
    this.#destroyed = true;
  }

  #safeEvidence(error, evidence) {
    let diagnostics = null;
    try { diagnostics = this.#diagnostics?.snapshot?.() || null; } catch { diagnostics = null; }
    return sanitize({
      errorName: error?.name || null,
      stackSummary: error?.stack || null,
      viewport: { width: Number(this.#global.innerWidth) || null, height: Number(this.#global.innerHeight) || null },
      online: this.#global.navigator?.onLine ?? null,
      diagnostics,
      ...evidence,
    });
  }

  async #submit(identity, evidence) {
    if (this.#destroyed) return null;
    this.#submitting = true;
    try {
      return await this.#post({ action: 'submit', ...identity, build_id: this.#buildId, evidence });
    } finally {
      this.#submitting = false;
    }
  }

  async #post(body) {
    const response = await this.#fetch(this.#endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.#csrfToken },
      body: JSON.stringify({ ...body, _csrf: this.#csrfToken }),
    });
    const contentType = String(response.headers?.get?.('content-type') || '');
    if (!response.ok || !contentType.includes('application/json')) throw new Error('Diagnostic report could not be submitted.');
    const result = await response.json();
    if (result?.error) throw new Error(safeString(result.error, 240));
    return result;
  }
}

export const runtimeIssueCaptureContract = Object.freeze({
  createsOnlyGeneratedSchematicPixels: true,
  forbiddenKeyPattern: SENSITIVE_KEY,
  duplicateBackoffMs: DUPLICATE_BACKOFF_MS,
  maxReportsPerMinute: MAX_REPORTS_PER_MINUTE,
});
