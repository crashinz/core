// Bounded, read-only recovery for the diagnostics policy request.
const MODES = new Set(['off', 'errors-only', 'errors-and-warnings', 'verbose']);
const TRANSIENT_STATUS = new Set([408, 425, 429, 500, 502, 503, 504]);
const ERROR_NAMES = new Set(['Error', 'TypeError', 'SyntaxError', 'AbortError', 'TimeoutError']);

function abortError() {
  const error = new Error('Policy request cancelled.');
  error.name = 'AbortError';
  return error;
}

function pause(ms, signal) {
  if (signal?.aborted) return Promise.resolve(false);
  return new Promise(resolve => {
    let timer;
    const finish = value => {
      clearTimeout(timer);
      signal?.removeEventListener('abort', onAbort);
      resolve(value);
    };
    const onAbort = () => finish(false);
    signal?.addEventListener('abort', onAbort, { once: true });
    timer = setTimeout(() => finish(true), ms);
  });
}

async function attempt(fetchImpl, endpoint, signal, timeoutMs) {
  if (signal?.aborted) return { ok: false, reason: 'policy-cancelled', retryable: false };
  const controller = new AbortController();
  let response = null;
  let phase = 'fetch';
  let timedOut = false;
  let timer;
  let cancel;
  const evidence = error => {
    const requestId = response?.headers?.get?.('x-request-id');
    const type = String(response?.headers?.get?.('content-type') || '').split(';')[0].toLowerCase();
    return {
      phase,
      status: Number(response?.status) || null,
      responseType: type === 'application/json' ? 'json' : type === 'text/html' ? 'html' : 'other',
      errorName: ERROR_NAMES.has(error?.name) ? error.name : null,
      serverRequestId: /^(?:[a-f0-9]{16,64}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})$/i.test(requestId || '') ? requestId : null,
    };
  };
  try {
    const deadline = new Promise((resolve, reject) => {
      cancel = () => { reject(abortError()); controller.abort(); };
      signal?.addEventListener('abort', cancel, { once: true });
      timer = setTimeout(() => {
        timedOut = true;
        const error = new Error('Policy request timed out.');
        error.name = 'TimeoutError';
        reject(error);
        controller.abort();
      }, timeoutMs);
    });
    const request = Promise.resolve().then(async () => {
      if (signal?.aborted) throw abortError();
      const separator = endpoint.includes('?') ? '&' : '?';
      response = await fetchImpl(`${endpoint}${separator}action=config`, {
        method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal,
      });
      phase = 'response';
      if (response.redirected) return { ok: false, reason: 'policy-auth-redirect', retryable: false, failure: evidence() };
      if (!response.ok) return { ok: false, reason: 'policy-http', retryable: TRANSIENT_STATUS.has(response.status), failure: evidence() };
      if (!/^(?:application|text)\/(?:[a-z0-9.+-]*\+)?json(?:\s*;|$)/i.test(String(response.headers?.get?.('content-type') || '').trim())) {
        return { ok: false, reason: 'policy-content-type', retryable: false, failure: evidence() };
      }
      phase = 'json';
      const result = await response.json();
      if (!MODES.has(result?.collection?.effectiveMode) || result?.error) {
        return { ok: false, reason: 'policy-schema', retryable: false, failure: evidence() };
      }
      return { ok: true, mode: result.collection.effectiveMode, auditRunId: result?.activeAuditRun?.publicId || null };
    });
    return await Promise.race([request, deadline]);
  } catch (error) {
    const cancelled = signal?.aborted;
    return {
      ok: false,
      reason: cancelled ? 'policy-cancelled' : timedOut ? 'policy-timeout' : phase === 'json' ? 'policy-json' : 'policy-transport',
      retryable: !cancelled,
      failure: evidence(error),
    };
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener('abort', cancel);
  }
}

export async function fetchRuntimeDiagnosticPolicy({ fetchImpl, endpoint, signal, attemptTimeoutMs = 1500, retryDelaysMs = [250, 750] }) {
  const timeoutMs = Math.max(1, Math.min(5000, Number(attemptTimeoutMs) || 1500));
  const failures = [];
  for (let index = 0; index < 3; index += 1) {
    const result = await attempt(fetchImpl, endpoint, signal, timeoutMs);
    if (result.ok) return { ...result, attempts: index + 1 };
    failures.push({ reason: result.reason, ...result.failure });
    if (!result.retryable || index === 2) return { ...result, failure: { ...result.failure, attempts: index + 1, failures } };
    if (!await pause(Math.max(0, Math.min(1000, Number(retryDelaysMs[index]) || 0)), signal)) {
      return { ok: false, reason: 'policy-cancelled', failure: { attempts: index + 1, failures } };
    }
  }
}
