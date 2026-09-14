/******************************************************************************
 * Build 000045 - bounded runtime issue capture and safe schematic evidence.
 ******************************************************************************/

import { fetchRuntimeDiagnosticPolicy } from './runtime-diagnostic-policy-client.js?v=20260913-local-failure-recovery';

const SENSITIVE_KEY = /authorization|cookie|csrf|password|secret|token|deviceid|groupid|sdp|candidate|message|content|private/i;
const PRIVATE_PATH = /(?:[a-z]:\\|\/(?:users|home|tmp)\/)/i;
const MAX_STRING = 512;
const GAME_REQUEST_SERVER_CODES = new Set(["MULTIPLAYER_GAME_ACCESS_DENIED","MULTIPLAYER_GAME_NOT_FOUND","MULTIPLAYER_GAME_STATE_STALE","MULTIPLAYER_GAME_SERVER_ERROR","MULTIPLAYER_GAME_DATABASE_BUSY","MULTIPLAYER_GAME_SESSION_CLOSED"]);
const MAX_REPORTS_PER_MINUTE = 5;
const DUPLICATE_BACKOFF_MS = 30000;
const GAME_AUDIT_DELAY_MS = 750;
const GAME_AUDIT_MIN_INTERVAL_MS = 2000;
const GAME_AUDIT_STABLE_SAMPLES = 2;
const GAME_AUDIT_FOLLOWUPS = 2;
const GAME_AUDIT_MAX_CONTROLS = 80;
const GAME_DUPLICATE_ACTION_WINDOW_MS = 1200;
const GAME_STUCK_ACTION_MS = 15000;
const GAME_SLOW_READ_MS = 2000;
const GAME_SLOW_ACTION_MS = 3000;
const GAME_SLOW_CONSECUTIVE_LIMIT = 3;
const GAME_LONG_TASK_MS = 250;
const GAME_LONG_TASK_LIMIT = 3;
const GAME_LONG_TASK_WINDOW_MS = 30000;
const AUDIT_OBSERVATION_BACKOFF_MS = 10000;
const EXCLUDED_ARCADE_GAMES = new Set([
  'space-invasion', 'space-invasion-first-party', 'tetris', 'tetris-versus',
]);

function isExpectedBrowserNoise(error) {
  return /resizeobserver loop (?:limit exceeded|completed with undelivered notifications)/i
    .test(String(error?.message || error || ''));
}

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
  #collectionMode = 'errors-only';
  #policyPromise = null;
  #policyController = null;
  #postControllers = new Set();
  #frameCleanups = new Map();
  #auditRunId = null;
  #auditRecent = new Map();

  constructor({ endpoint = '/api/runtime_issues.php', csrfToken = '', diagnostics = null, fetchImpl = globalThis.fetch?.bind(globalThis), globalObject = globalThis, documentObject = globalThis.document, buildId = '000052' } = {}) {
    if (typeof fetchImpl !== 'function') throw new TypeError('RuntimeIssueCaptureService requires fetch().');
    this.#endpoint = endpoint;
    this.#csrfToken = csrfToken;
    this.#diagnostics = diagnostics;
    this.#fetch = fetchImpl;
    this.#global = globalObject;
    this.#document = documentObject;
    this.#buildId = safeString(buildId, 96) || '000052';
  }

  start() {
    if (this.#destroyed || this.#listeners.length) return this;
    this.#policyController = new AbortController();
    this.#policyPromise = this.#refreshPolicy();
    const onError = event => {
      const error = event?.error || new Error(safeString(event?.message || 'Browser error'));
      if (isExpectedBrowserNoise(error)) return;
      void this.capture(error, { category: 'browser', component: 'window-error' }).catch(() => null);
    };
    const onRejection = event => {
      const reason = event?.reason instanceof Error ? event.reason : new Error(safeString(event?.reason || 'Unhandled promise rejection'));
      if (reason.name === 'AbortError' || isExpectedBrowserNoise(reason)) return;
      void this.capture(reason, { category: 'browser', component: 'unhandled-rejection' }).catch(() => null);
    };
    this.#global.addEventListener?.('error', onError);
    this.#global.addEventListener?.('unhandledrejection', onRejection);
    this.#listeners.push(['error', onError], ['unhandledrejection', onRejection]);
    void Promise.resolve().then(() => this.#runSelfTest());
    return this;
  }

  captureRequestFailure(error) {
    if (error?.code === 'REQUEST_ABORTED') return Promise.resolve(null);
    if (isKnownClosedGameRequestFailure(error)) return Promise.resolve(null);
    return this.capture(error, { category: 'request', component: error?.details?.endpointCategory || 'room-api' }, {
      operation: error?.details?.operation,
      endpointCategory: error?.details?.endpointCategory,
      method: error?.details?.method,
      status: error?.details?.status,
      redirected: error?.details?.redirected,
      contentType: error?.details?.contentType,
      recoverable: error?.details?.recoverable,
      causeSummary: error?.details?.causeSummary,
    }).catch(() => null);
  }

  captureGameRequestFailure(error, { gameType = 'room-game-runtime' } = {}) {
    if (error?.code === 'REQUEST_ABORTED') return Promise.resolve(null);
    if (isKnownClosedGameRequestFailure(error)) return Promise.resolve(null);
    const operation = String(error?.details?.operation || '');
    const transportCode = String(error?.code || 'GAME_REQUEST_FAILED').toUpperCase();
    const serverCode = typeof error?.responsePayload?.code === 'string'
      && GAME_REQUEST_SERVER_CODES.has(error.responsePayload.code) ? error.responsePayload.code : null;
    let code = operation === 'load-game-catalog' ? 'GAME_CATALOG_LOAD_FAILED' : 'GAME_SESSION_LOAD_FAILED';
    let title = operation === 'load-game-catalog' ? 'Game catalog could not be loaded' : 'Game session could not be loaded';
    if (transportCode === 'HTML_RESPONSE') {
      code = 'GAME_API_HTML_RESPONSE';
      title = 'Game API returned HTML instead of JSON';
    } else if (transportCode === 'INVALID_JSON' || transportCode === 'INVALID_CONTENT_TYPE') {
      code = 'GAME_API_INVALID_JSON';
      title = 'Game API returned invalid JSON';
    } else if (transportCode === 'REQUEST_TIMEOUT') {
      code = 'GAME_TIMEOUT';
      title = 'Game request timed out';
    }
    return this.captureGameDiagnostic({
      code,
      title,
      message: error?.message || title,
      gameType,
      error,
      evidence: {
        operation,
        transportCode,
        serverCode,
        requestContext: error?.details?.requestContext || null,
        endpointCategory: error?.details?.endpointCategory,
        method: error?.details?.method,
        status: error?.details?.status,
        redirected: error?.details?.redirected,
        contentType: error?.details?.contentType,
        recoverable: error?.details?.recoverable,
        causeSummary: error?.details?.causeSummary,
      },
    });
  }

  captureGameDiagnostic({ code = 'GAME_RUNTIME_FAILURE', title = 'Game runtime failure', message = 'The embedded game reported a runtime failure.', severity = 'error', gameType = 'embedded-game', evidence = {}, error = null } = {}) {
    const failure = gameDiagnosticError(error, message);
    failure.code = safeString(code, 96).toUpperCase() || 'GAME_RUNTIME_FAILURE';
    return this.capture(failure, {
      category: 'game',
      component: `game-${safeString(gameType, 72).toLowerCase() || 'embedded-game'}`,
      code: failure.code,
      title,
      message,
      severity,
    }, { diagnosticKind: 'automated-game', gameType, ...gameDiagnosticErrorEvidence(error), ...evidence }).catch(() => null);
  }

  observeGameFrame(frame, { getContext = () => ({}), lifecycleSignal = null } = {}) {
    if (this.#destroyed || !frame?.addEventListener) return () => {};
    this.#frameCleanups.get(frame)?.();
    let documentCleanup = () => {};
    const monitor = createGameRuntimeMonitor();

    const attach = () => {
      documentCleanup();
      documentCleanup = () => {};
      let frameWindow;
      let frameDocument;
      try {
        frameWindow = frame.contentWindow;
        frameDocument = frame.contentDocument;
        if (!frameWindow || !frameDocument || frameWindow.location.href === 'about:blank') return;
      } catch {
        return;
      }
      const supplied = typeof getContext === 'function' ? (getContext() || {}) : {};
      const inferredGame = String(frameWindow.location.pathname || '').split('/games/')[1]?.split('/')[0] || '';
      const gameType = safeString(supplied.gameType || inferredGame || 'embedded-game', 72).toLowerCase();
      if (EXCLUDED_ARCADE_GAMES.has(gameType)) return;
      monitor.frameLoads += 1;
      const observationFrame = {
        generation: monitor.frameLoads, buildId: this.#buildId, active: true,
        instanceId: gameTransportAttemptId(), lifecycle: 'active',
        pageHideObserved: false, pageHidePersisted: false, identityCache: new Map(),
        assets: gameTransportAssets(frameWindow, frameDocument),
      };
      try { monitor.storage = frameWindow.sessionStorage; } catch { monitor.storage = null; }

      const cleanups = [];
      cleanups.push(() => { observationFrame.active = false; observationFrame.lifecycle = 'detached'; observationFrame.identityCache.clear(); });
      const onPageHide = event => {
        if (event?.isTrusted !== true) return;
        observationFrame.pageHideObserved = true;
        observationFrame.pageHidePersisted = event.persisted === true;
        observationFrame.lifecycle = event.persisted === true ? 'bfcache' : 'pagehide';
      };
      const onPageShow = event => {
        if (event?.isTrusted === true && observationFrame.active) observationFrame.lifecycle = 'active';
      };
      frameWindow.addEventListener('pagehide', onPageHide, true);
      frameWindow.addEventListener('pageshow', onPageShow, true);
      cleanups.push(
        () => frameWindow.removeEventListener('pagehide', onPageHide, true),
        () => frameWindow.removeEventListener('pageshow', onPageShow, true),
      );
      const report = details => this.captureGameDiagnostic({ gameType, ...details });
      const onWindowError = event => {
        const error = event?.error || new Error(safeString(event?.message || 'Embedded game rendering exception'));
        if (isExpectedBrowserNoise(error)) return;
        void report({
          code: 'GAME_RENDER_EXCEPTION',
          title: 'Embedded game rendering exception',
          message: error.message || 'The embedded game raised a rendering exception.',
          error,
          evidence: { source: 'game-window-error', line: Number(event?.lineno) || null, column: Number(event?.colno) || null,
            ...gameDiagnosticAssetEvidence(event?.filename, frameWindow.location.href, frameDocument) },
        });
      };
      const onUnhandledRejection = event => {
        const error = gameDiagnosticError(event?.reason, 'Embedded game promise rejection');
        if (error.name === 'AbortError' || isExpectedBrowserNoise(error)) return;
        void report({
          code: 'GAME_UNHANDLED_REJECTION',
          title: 'Embedded game operation failed',
          message: error.message || 'The embedded game rejected an operation.',
          error,
          evidence: { source: 'game-unhandled-rejection' },
        });
      };
      const onResourceError = event => {
        const target = event?.target;
        const tag = String(target?.tagName || '').toLowerCase();
        if (!['img', 'script', 'link', 'audio', 'video', 'source'].includes(tag)) return;
        let path = '';
        try { path = new URL(target.currentSrc || target.src || target.href || '', frameWindow.location.href).pathname; } catch { path = ''; }
        void report({
          code: 'GAME_ASSET_MISSING',
          title: 'Game asset could not be loaded',
          message: `A required ${tag} asset did not load.`,
          evidence: { source: 'game-resource-error', element: tag, assetPath: path || null },
        });
      };
      frameWindow.addEventListener('error', onWindowError);
      frameWindow.addEventListener('unhandledrejection', onUnhandledRejection);
      frameDocument.addEventListener('error', onResourceError, true);
      cleanups.push(
        () => frameWindow.removeEventListener('error', onWindowError),
        () => frameWindow.removeEventListener('unhandledrejection', onUnhandledRejection),
        () => frameDocument.removeEventListener('error', onResourceError, true),
      );

      const nativeFetch = frameWindow.fetch?.bind(frameWindow);
      if (nativeFetch) {
        const observedFetch = async (transportContext, ...args) => {
          const contextualReport = details => gameTransportReport(report, details, transportContext,
            gameTransportOutcome(transportContext, frameDocument, frame, observationFrame));
          const probe = beginGameRequestMonitor(monitor, args[0], args[1], frameWindow, contextualReport);
          let response;
          try {
            response = await nativeFetch(...args);
          } catch (error) {
            const outcome = gameTransportOutcome(transportContext, frameDocument, frame, observationFrame, null, error);
            const expectedPageHideFailure = isExpectedPageHideTransportFailure(transportContext, outcome, error);
            const failedReport = details => expectedPageHideFailure
              ? Promise.resolve(null)
              : gameTransportReport(report, details, transportContext, outcome);
            completeGameRequestMonitor(monitor, probe, frameWindow, failedReport, false, null);
            const request = transportContext.request;
            if (!expectedPageHideFailure) {
              void failedReport({
                code: request.action === 'session' ? 'GAME_SESSION_LOAD_FAILED' : 'GAME_NETWORK_FAILURE',
                title: request.action === 'session' ? 'Game session could not be loaded' : 'Game request could not reach the server',
                message: safeString(error?.message || 'The embedded game request failed.'),
                error,
                evidence: { ...request, source: 'game-fetch' },
              });
            }
            throw error;
          }
          const outcome = gameTransportOutcome(transportContext, frameDocument, frame, observationFrame, response);
          const deliveredReport = (details, bodyReadError = null) => {
            const currentOutcome = bodyReadError
              ? gameTransportOutcome(transportContext, frameDocument, frame, observationFrame, response, bodyReadError)
              : outcome;
            const ownedBodyCancellation = bodyReadError
              && gameDiagnosticErrorField(bodyReadError, 'name') === 'AbortError'
              && transportContext.ownedSignal
              && currentOutcome.abortSignalState === 'aborted'
              && ['pagehide', 'page-hide', 'beforeunload', 'before-unload', 'room-exit', 'frame-dispose']
                .includes(currentOutcome.abortReason);
            if (ownedBodyCancellation) return Promise.resolve(null);
            return gameTransportReport(report, details, transportContext, {
              ...currentOutcome,
              serverRequestId: currentOutcome.serverRequestId || details.evidence?.serverRequestId || null,
            });
          };
          completeGameRequestMonitor(monitor, probe, frameWindow, deliveredReport, response.ok, response);
          return { response, deliveredReport };
        };
        const sequencedFetch = async (...args) => {
          const requestSequence = monitor.diagnosticRequestSequence = (monitor.diagnosticRequestSequence || 0) + 1;
          const transportContext = gameTransportContext(args[0], args[1], frameWindow, frameDocument, frame, observationFrame, requestSequence, lifecycleSignal);
          const { response, deliveredReport } = await observedFetch(transportContext, ...args);
          if (isGameApiResponse(response, frameWindow.location.href)) {
            void inspectGameApiResponse(response.clone(), args[0], args[1], frameWindow.location.href, monitor, gameType, deliveredReport, {
              frame: observationFrame, requestSequence, request: transportContext.request,
            });
          }
          return response;
        };
        frameWindow.fetch = sequencedFetch;
        cleanups.push(() => {
          if (frameWindow.fetch === sequencedFetch) frameWindow.fetch = nativeFetch;
        });
      }

      const PerformanceObserverOwner = frameWindow.PerformanceObserver;
      if (PerformanceObserverOwner) {
        try {
          const performanceObserver = new PerformanceObserverOwner(list => {
            const now = Date.now();
            const visibility = gameTransportVisibility(frameDocument);
            const lifecycle = observationFrame.lifecycle;
            const frameAttached = frame.isConnected !== false;
            if (visibility !== 'visible' || lifecycle !== 'active' || !frameAttached) {
              monitor.longTasks = [];
              return;
            }
            monitor.longTasks = monitor.longTasks
              .filter(item => item.at > now - GAME_LONG_TASK_WINDOW_MS)
              .concat(list.getEntries().filter(item => item.duration >= GAME_LONG_TASK_MS).map(item => ({ at: now, duration: Math.round(item.duration) })));
            if (monitor.longTasks.length >= GAME_LONG_TASK_LIMIT && now > monitor.lastLongTaskReportAt + GAME_LONG_TASK_WINDOW_MS) {
              monitor.lastLongTaskReportAt = now;
              const durations = monitor.longTasks.slice(-GAME_LONG_TASK_LIMIT).map(item => item.duration);
              const recentAction = monitor.lastAction ? {
                action: safeString(monitor.lastAction.action || monitor.lastAction.type || '', 64) || null,
                outcome: safeString(monitor.lastAction.outcome || '', 32) || null,
              } : null;
              void report({
                code: 'GAME_RENDER_PERFORMANCE_REGRESSION',
                title: 'Game rendering is repeatedly blocking input',
                message: 'The embedded game produced several long main-thread tasks in a short period.',
                evidence: {
                  source: 'game-performance-observer', thresholdMs: GAME_LONG_TASK_MS, durations,
                  visibilityAtObservation: visibility, lifecycleAtObservation: lifecycle,
                  frameAttachedAtObservation: frameAttached, frameLoads: monitor.frameLoads,
                  recentAction, stateRevision: monitor.lastProjection?.stateRevision ?? null,
                },
              });
            }
          });
          performanceObserver.observe({ type: 'longtask', buffered: false });
          cleanups.push(() => performanceObserver.disconnect());
        } catch {
          // Long-task observation is optional; request timing remains authoritative.
        }
      }

      let auditTimer = null;
      let auditRunning = false;
      let auditDisposed = false;
      let auditRequested = false;
      let followupsRemaining = 0;
      let lastAuditAt = 0;
      let previousSample = null;
      let firstGeometry = null;
      let stableSamples = 0;
      let linkedVisualIssue = null;
      const seenFindingCodes = new Set();
      const observedSource = frame.getAttribute?.('src') || '';
      const isCurrentVisual = () => {
        try {
          return !auditDisposed && !this.#destroyed && observationFrame.active
            && observationFrame.lifecycle === 'active' && frame.isConnected !== false
            && frame.contentDocument === frameDocument && frame.contentWindow === frameWindow
            && (frame.getAttribute?.('src') || '') === observedSource;
        } catch { return false; }
      };
      const cancelAudit = () => {
        if (auditTimer !== null) frameWindow.clearTimeout(auditTimer);
        auditTimer = null;
        auditRequested = false;
        followupsRemaining = 0;
        previousSample = null;
        stableSamples = 0;
      };
      const queueAudit = () => {
        if (!isCurrentVisual() || auditTimer !== null || auditRunning) return;
        const delay = Math.max(GAME_AUDIT_DELAY_MS, GAME_AUDIT_MIN_INTERVAL_MS - (Date.now() - lastAuditAt));
        auditTimer = frameWindow.setTimeout(runAudit, delay);
      };
      const scheduleAudit = () => {
        if (!isCurrentVisual()) return;
        followupsRemaining = GAME_AUDIT_FOLLOWUPS;
        auditRequested = true;
        queueAudit();
      };
      const runAudit = async () => {
        auditTimer = null;
        if (auditRunning || !isCurrentVisual()) return;
        auditRunning = true;
        auditRequested = false;
        lastAuditAt = Date.now();
        try {
          await this.#policyPromise;
          if (!isCurrentVisual()) return;
          const startedAt = frameWindow.performance?.now?.() ?? Date.now();
          const sample = sampleGameVisualReadiness(frame, frameWindow, frameDocument);
          firstGeometry ||= sample.geometry;
          stableSamples = sample.ready
            ? Math.min(GAME_AUDIT_STABLE_SAMPLES, previousSample?.ready && previousSample.signature === sample.signature ? stableSamples + 1 : 1)
            : 0;
          previousSample = sample;
          const layout = {
            generation: observationFrame.generation,
            phase: sample.ready ? (stableSamples >= GAME_AUDIT_STABLE_SAMPLES ? 'ready' : 'settling') : sample.phase,
            stableSamples,
            before: { ...firstGeometry },
            after: { ...sample.geometry },
          };
          // Transient, hidden, empty, or still-settling frames remain blocked observations.
          // Objective findings are collected only after two stable ready samples.
          const findings = sample.ready && stableSamples >= GAME_AUDIT_STABLE_SAMPLES
            ? collectGameVisualFindings(frame, frameWindow, frameDocument)
            : [];
          for (const finding of findings) seenFindingCodes.add(finding.code);
          const issueReferences = [];
          for (const finding of findings) {
            if (!isCurrentVisual()) return;
            issueReferences.push(await report({ ...finding, evidence: { ...finding.evidence, layout } }));
          }
          if (!isCurrentVisual()) return;
          linkedVisualIssue ||= issueReferences.find(item => Number(item?.issue_id) > 0)?.issue_id || null;
          await this.#recordAuditObservation({
            gameType, supplied, monitor, findings, issueReferences,
            visualObservation: { layout, seenFindingCodes: [...seenFindingCodes].slice(0, 32), issueId: linkedVisualIssue },
            durationMs: Math.max(0, Math.round((frameWindow.performance?.now?.() ?? Date.now()) - startedAt)),
          });
        } finally {
          auditRunning = false;
          if (isCurrentVisual()) {
            if (auditRequested) queueAudit();
            else if (followupsRemaining > 0) {
              followupsRemaining -= 1;
              queueAudit();
            }
          }
        }
      };
      const MutationObserverOwner = frameWindow.MutationObserver;
      if (MutationObserverOwner && frameDocument.documentElement) {
        const observer = new MutationObserverOwner(scheduleAudit);
        observer.observe(frameDocument.documentElement, {
          childList: true,
          subtree: true,
          attributes: true,
          attributeFilter: ['class', 'style', 'hidden', 'aria-hidden', 'disabled', 'src'],
        });
        cleanups.push(() => observer.disconnect());
      }
      const pauseAudit = event => { if (event?.isTrusted === true) cancelAudit(); };
      const resumeAudit = event => { if (event?.isTrusted === true) scheduleAudit(); };
      const visibilityAudit = () => { previousSample = null; stableSamples = 0; scheduleAudit(); };
      frameWindow.addEventListener('resize', scheduleAudit);
      frameWindow.addEventListener('pagehide', pauseAudit, true);
      frameWindow.addEventListener('pageshow', resumeAudit, true);
      frameDocument.addEventListener('visibilitychange', visibilityAudit);
      frameDocument.addEventListener('readystatechange', scheduleAudit);
      frameDocument.addEventListener('load', scheduleAudit, true);
      cleanups.push(
        () => frameWindow.removeEventListener('resize', scheduleAudit),
        () => frameWindow.removeEventListener('pagehide', pauseAudit, true),
        () => frameWindow.removeEventListener('pageshow', resumeAudit, true),
        () => frameDocument.removeEventListener('visibilitychange', visibilityAudit),
        () => frameDocument.removeEventListener('readystatechange', scheduleAudit),
        () => frameDocument.removeEventListener('load', scheduleAudit, true),
      );
      frameDocument.fonts?.ready?.then?.(scheduleAudit)?.catch?.(() => {});
      scheduleAudit();
      documentCleanup = () => {
        auditDisposed = true;
        cancelAudit();
        cleanups.splice(0).forEach(cleanup => {
          try { cleanup(); } catch {}
        });
      };
    };

    const cleanup = () => {
      frame.removeEventListener('load', attach);
      documentCleanup();
      this.#frameCleanups.delete(frame);
    };
    frame.addEventListener('load', attach);
    this.#frameCleanups.set(frame, cleanup);
    attach();
    return cleanup;
  }

  async capture(error, context = {}, evidence = {}) {
    if (this.#destroyed || this.#submitting || isExpectedBrowserNoise(error)) return null;
    await this.#policyPromise;
    const identity = errorIdentity(error, context);
    if (!this.#modeAllows(identity.severity)) return null;
    const key = `${identity.category}|${identity.component}|${identity.error_code}|${identity.message}`;
    const now = Date.now();
    if ((this.#recent.get(key) || 0) > now - DUPLICATE_BACKOFF_MS) return null;
    this.#reportTimes = this.#reportTimes.filter(timestamp => timestamp > now - 60000);
    if (this.#reportTimes.length >= MAX_REPORTS_PER_MINUTE) return null;
    this.#recent.set(key, now);
    this.#reportTimes.push(now);
    return this.#submit(identity, this.#safeEvidence(error, evidence)).catch(failure => {
      // A failed delivery must not reserve this issue's duplicate window.
      if (this.#recent.get(key) === now) this.#recent.delete(key);
      throw failure;
    });
  }

  async #runSelfTest() {
    if (this.#destroyed) return;
    const failures = [];
    const policy = await this.#policyPromise;
    if (this.#destroyed || policy?.reason === 'policy-cancelled') return;
    const redactionProbe = safeString('token=private-value C:\\Users\\Example\\secret.txt');
    if (redactionProbe.includes('private-value') || redactionProbe.includes('Example')) failures.push('client-redaction');
    const sanitized = sanitize({ password: 'private-value', safeLabel: 'ok' });
    if (Object.prototype.hasOwnProperty.call(sanitized, 'password') || sanitized.safeLabel !== 'ok') failures.push('evidence-sanitizer');
    if (!policy?.ok) failures.push(policy?.reason || 'policy-endpoint');
    if (!failures.length) return;
    const error = new Error('The runtime diagnostics self-test did not complete successfully.');
    error.code = 'DIAGNOSTIC_SELF_TEST_FAILED';
    await this.capture(error, {
      category: 'diagnostics',
      component: 'runtime-issue-capture',
      title: 'Runtime diagnostics self-test failed',
      severity: 'error',
    }, { diagnosticKind: 'self-test', failedChecks: failures, policyFailure: policy?.failure || null }).catch(() => null);
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
    this.#policyController?.abort();
    for (const controller of this.#postControllers) controller.abort();
    for (const [event, handler] of this.#listeners) this.#global.removeEventListener?.(event, handler);
    this.#listeners = [];
    for (const cleanup of this.#frameCleanups.values()) cleanup();
    this.#frameCleanups.clear();
    this.#recent.clear();
    this.#auditRecent.clear();
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
      const serverRequestId = typeof evidence?.serverRequestId === 'string'
        && /^(?:[a-f0-9]{16,64}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})$/i.test(evidence.serverRequestId)
        && evidence.deliveryState === 'response-received' ? evidence.serverRequestId : null;
      return await this.#post({
        action: 'submit', ...identity, build_id: this.#buildId, evidence,
        ...(serverRequestId ? { request_correlation: serverRequestId } : {}),
      });
    } finally {
      this.#submitting = false;
    }
  }

  async #recordAuditObservation({ gameType, supplied, monitor, findings, issueReferences, durationMs, visualObservation }) {
    if (!this.#auditRunId) return null;
    // Use the exact measured geometry, not a later layout after reporting awaits.
    const { layout, seenFindingCodes } = visualObservation;
    const { width, height, devicePixelRatio, zoom, iframeWidth, iframeHeight } = layout.after;
    const viewport = { width, height, devicePixelRatio, zoom, iframeWidth, iframeHeight };
    const samples = monitor.requestSamples.slice(-50);
    const durations = samples.map(sample => Number(sample.browserRoundTripMs) || 0).sort((left, right) => left - right);
    const percentile = fraction => durations.length ? durations[Math.min(durations.length - 1, Math.ceil(durations.length * fraction) - 1)] : null;
    const gameKeyHash = quickHash(String(supplied.gameKey || gameType));
    const baseId = `${safeString(gameType, 48).toLowerCase() || 'embedded-game'}.${gameKeyHash}`;
    const linkedIssue = visualObservation.issueId || issueReferences.find(item => Number(item?.issue_id) > 0)?.issue_id || null;
    const performanceWarning = samples.some(sample => sample.browserRoundTripMs >= (sample.actionRequest ? GAME_SLOW_ACTION_MS : GAME_SLOW_READ_MS))
      || monitor.longTasks.length >= GAME_LONG_TASK_LIMIT;
    const checks = [
      {
        check_id: `game.visual.${baseId}`,
        description: `Objective visual diagnostics for ${gameType}.`,
        status: seenFindingCodes.length ? 'failed' : (layout.phase === 'ready' ? 'passed' : 'blocked'),
        expected: { overlap: false, clipping: false, overflow: false, missingImages: false, hiddenControls: false, invalidFrameSize: false, unreadableControls: false },
        actual: { findingCount: findings.length, findingCodes: findings.map(item => item.code), seenFindingCodes, readiness: layout.phase, stableSamples: layout.stableSamples, generation: layout.generation },
        duration_ms: durationMs,
        visual: { ...viewport, layout, objectiveChecks: runtimeIssueCaptureContract.objectiveGameVisualChecks },
        issue_id: linkedIssue,
        privacy: { pixelsIncluded: false, privateContentIncluded: false },
      },
      {
        check_id: `game.gameplay-state.${baseId}`,
        description: `Gameplay state and action continuity for ${gameType}.`,
        status: monitor.lastProjection ? 'passed' : 'blocked',
        expected: { authoritativeProjectionObserved: true, revisionsMonotonic: true, duplicateActions: false },
        actual: monitor.lastProjection || { authoritativeProjectionObserved: false, sessionStatus: safeString(supplied.sessionStatus, 48) },
        gameplay: { ...(monitor.lastProjection || {}), lastAction: monitor.lastAction || null },
      },
      {
        check_id: `game.performance.${baseId}`,
        description: `Request and rendering performance for ${gameType}.`,
        status: performanceWarning ? 'warning' : (samples.length ? 'passed' : 'blocked'),
        expected: { repeatedSlowRequests: false, repeatedLongTasks: false },
        actual: { sampleCount: samples.length, longTaskCount: monitor.longTasks.length },
        performance: {
          browserRoundTripMedianMs: percentile(0.5),
          browserRoundTripMaxMs: durations.length ? durations[durations.length - 1] : null,
          browserRoundTripP95Ms: percentile(0.95),
          serverExecutionMs: samples.at(-1)?.serverExecutionMs ?? null,
          databaseLockWaitMs: samples.at(-1)?.databaseLockWaitMs ?? null,
          retryCount: samples.reduce((sum, item) => sum + (Number(item.retryCount) || 0), 0),
          finalOutcome: samples.at(-1)?.outcome || null,
        },
      },
      {
        check_id: `game.reload-reconnect.${baseId}`,
        description: `Reload, reconnect, and idempotency continuity for ${gameType}.`,
        status: monitor.reloadRollbackDetected || monitor.duplicateActionDetected ? 'failed' : 'passed',
        expected: { stateRollback: false, duplicateActions: false, sessionRejoined: true },
        actual: { frameLoads: monitor.frameLoads, stateRollback: monitor.reloadRollbackDetected, duplicateActions: monitor.duplicateActionDetected },
        reconnect: { frameLoads: monitor.frameLoads, priorSessionObserved: monitor.frameLoads > 1, currentStateRevision: monitor.lastProjection?.stateRevision ?? null },
      },
    ];
    const signature = JSON.stringify(checks.map(check => [check.check_id, check.status, check.actual]));
    const prior = this.#auditRecent.get(baseId);
    if (prior?.signature === signature && prior.at > Date.now() - AUDIT_OBSERVATION_BACKOFF_MS) return null;
    this.#auditRecent.set(baseId, { signature, at: Date.now() });
    return this.#post({ action: 'audit_observe', audit_run_id: this.#auditRunId, checks }).catch(() => null);
  }

  #modeAllows(severity) {
    if (this.#collectionMode === 'off') return false;
    if (this.#collectionMode === 'errors-only') return severity === 'error' || severity === 'critical';
    if (this.#collectionMode === 'errors-and-warnings') return severity !== 'info';
    return this.#collectionMode === 'verbose';
  }

  async #refreshPolicy() {
    const result = await fetchRuntimeDiagnosticPolicy({
      fetchImpl: this.#fetch, endpoint: this.#endpoint, signal: this.#policyController?.signal,
    });
    if (result.ok) {
      this.#collectionMode = result.mode;
      this.#auditRunId = safeString(result.auditRunId || '', 64) || null;
    }
    // Preserve the existing errors-only fallback without enabling verbose capture.
    return result;
  }

  async #post(body) {
    const controller = new AbortController();
    this.#postControllers.add(controller);
    let timer;
    const deadline = new Promise((resolve, reject) => {
      controller.signal.addEventListener('abort', () => {
        reject(Object.assign(new Error('Diagnostic report cancelled.'), { name: 'AbortError' }));
      }, { once: true });
      timer = setTimeout(() => {
        reject(Object.assign(new Error('Diagnostic report timed out.'), { name: 'TimeoutError' }));
        controller.abort();
      }, 5000);
    });
    try {
      const request = (async () => {
        const response = await this.#fetch(this.#endpoint, {
          method: 'POST', credentials: 'same-origin', signal: controller.signal,
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.#csrfToken },
          body: JSON.stringify({ ...body, _csrf: this.#csrfToken }),
        });
        if (controller.signal.aborted) throw new Error('Diagnostic report interrupted.');
        const contentType = String(response.headers?.get?.('content-type') || '').trim();
        if (response.redirected || !response.ok || !/^(?:application|text)\/(?:[a-z0-9.+-]*\+)?json(?:\s*;|$)/i.test(contentType)) {
          throw new Error('Diagnostic report could not be submitted.');
        }
        let result;
        try { result = await response.json(); }
        catch { throw new SyntaxError('Diagnostic response could not be parsed.'); }
        if (!result || typeof result !== 'object' || Array.isArray(result) || result.error) {
          throw new Error('Diagnostic report could not be submitted.');
        }
        return result;
      })();
      return await Promise.race([request, deadline]);
    } finally {
      clearTimeout(timer);
      this.#postControllers.delete(controller);
    }
  }
}

function gameTransportAttemptId() {
  try {
    if (typeof globalThis.crypto?.randomUUID === 'function') return globalThis.crypto.randomUUID();
    const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
    return Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
  } catch { return null; }
}

function gameTransportVisibility(document) {
  try {
    return ['visible', 'hidden', 'prerender'].includes(document?.visibilityState) ? document.visibilityState : 'unknown';
  } catch { return 'unknown'; }
}

function gameTransportServerId(response, data = null) {
  if (!response) return null;
  const valid = value => typeof value === 'string' && /^(?:[a-f0-9]{16,64}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})$/i.test(value) ? value : null;
  try {
    return valid(response.headers?.get?.('x-request-id')) || (data?.error ? valid(data.request_id) : null);
  } catch { return null; }
}

function gameTransportAssets(view, document, moduleUrl = import.meta.url) {
  let baseUrl;
  try { baseUrl = view.location.href; } catch { return {}; }
  const entry = gameDiagnosticFirstPartyAsset(baseUrl, baseUrl);
  const bundles = [];
  try {
    for (const script of Array.from(document?.querySelectorAll?.('script[src]') || []).slice(0, 16)) {
      const evidence = gameDiagnosticAssetEvidence(script.src, baseUrl, document);
      if (evidence.assetPath && !bundles.some(item => item.assetPath === evidence.assetPath)) bundles.push(evidence);
    }
  } catch { /* Missing frame assets must not block transport reporting. */ }
  const moduleAsset = gameDiagnosticFirstPartyAsset(moduleUrl, baseUrl);
  let moduleRevision = null;
  if (moduleAsset) {
    const value = moduleAsset.url.searchParams.get('v') || moduleAsset.url.searchParams.get('rev') || '';
    if (/^(?:[0-9]{1,16}|[a-f0-9]{7,64}|[0-9]{8}-[a-z0-9-]{1,48})$/.test(value)) moduleRevision = value;
  }
  return Object.freeze({
    entryAssetPath: entry?.path || null,
    bundleAssets: Object.freeze(bundles.map(value => Object.freeze(value))),
    diagnosticAssetPath: moduleAsset?.path || null,
    diagnosticAssetRevision: moduleRevision,
  });
}

function gameTransportIdentity(input, options, baseUrl, frameContext) {
  let roomId = null;
  let gameId = null;
  let origin = '';
  const valid = value => typeof value === 'string' && /^[A-Za-z0-9_-]{1,160}$/.test(value)
    ? value : Number.isSafeInteger(value) && value > 0 ? String(value) : null;
  try {
    const url = new URL(typeof input?.url === 'string' ? input.url : String(input || ''), baseUrl);
    const base = new URL(baseUrl);
    if (url.origin !== base.origin || !/\/api\/(?:game_framework|games)\.php$/i.test(url.pathname)) return Promise.resolve({ identityStatus: 'unavailable', roomIdentityHash: null, gameSessionIdentityHash: null });
    origin = base.origin;
    const method = String(options?.method || input?.method || 'GET').toUpperCase();
    let body = null;
    if (typeof options?.body === 'string' && options.body.length <= 65536) {
      try { body = JSON.parse(options.body); } catch { /* Never inspect request streams. */ }
    }
    const field = name => method === 'GET' ? url.searchParams.get(name) : body?.[name];
    roomId = valid(field('session_id'));
    gameId = valid(field('game_session_id'));
  } catch { /* Unknown identity is safer than borrowing another session. */ }
  if (!roomId || !gameId) return Promise.resolve({ identityStatus: 'unavailable', roomIdentityHash: null, gameSessionIdentityHash: null });
  const roomTuple = JSON.stringify(['corechat-diagnostic-room-v1', origin, roomId]);
  const gameTuple = JSON.stringify(['corechat-diagnostic-game-v1', origin, roomId, gameId]);
  const cache = frameContext.identityCache;
  if (cache?.has(gameTuple)) return cache.get(gameTuple);
  const digest = async value => {
    const bytes = new TextEncoder().encode(value);
    const result = await globalThis.crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(result), byte => byte.toString(16).padStart(2, '0')).join('');
  };
  const result = Promise.all([digest(roomTuple), digest(gameTuple)])
    .then(([roomIdentityHash, gameSessionIdentityHash]) => Object.freeze({ identityStatus: 'exact-request-hashed', roomIdentityHash, gameSessionIdentityHash }))
    .catch(() => ({ identityStatus: 'unavailable', roomIdentityHash: null, gameSessionIdentityHash: null }));
  if (cache) {
    if (cache.size >= 16) cache.delete(cache.keys().next().value);
    cache.set(gameTuple, result);
  }
  return result;
}

function gameTransportContext(input, options, view, document, frame, frameContext, sequence, lifecycleSignal = null) {
  const baseUrl = view.location.href;
  const request = Object.freeze(gameRequestMetadata(input, options, baseUrl));
  let signal = null;
  try { signal = options?.signal || input?.signal || null; } catch {}
  return Object.freeze({
    request,
    identity: gameTransportIdentity(input, options, baseUrl, frameContext),
    signal,
    ownedSignal: signal !== null && signal === lifecycleSignal,
    dispatch: Object.freeze({
      diagnosticContextVersion: 1,
      clientAttemptId: gameTransportAttemptId(),
      frameInstanceId: frameContext.instanceId,
      frameGeneration: frameContext.generation,
      requestSequence: sequence,
      visibilityAtDispatch: gameTransportVisibility(document),
      lifecycleAtDispatch: frameContext.lifecycle,
      frameAttachedAtDispatch: frame.isConnected !== false,
      keepalive: options?.keepalive === true || (options?.keepalive === undefined && input?.keepalive === true),
      ...frameContext.assets,
    }),
  });
}

function gameTransportOutcome(context, document, frame, frameContext, response = null, error = null) {
  let signalState = context.signal ? 'active' : 'absent';
  let abortReason = 'none';
  try {
    if (context.signal?.aborted === true) {
      signalState = 'aborted';
      abortReason = ['pagehide', 'page-hide', 'beforeunload', 'before-unload', 'room-exit', 'frame-dispose', 'timeout'].includes(context.signal.reason) ? context.signal.reason : 'other';
    }
  } catch { signalState = 'unknown'; abortReason = 'other'; }
  const abortError = gameDiagnosticErrorField(error, 'name') === 'AbortError';
  const ownedExitAbort = !response && abortError && context.ownedSignal && signalState === 'aborted'
    && ['pagehide', 'page-hide', 'beforeunload', 'before-unload', 'room-exit', 'frame-dispose'].includes(abortReason);
  return Object.freeze({
    visibilityAtOutcome: gameTransportVisibility(document),
    lifecycleAtOutcome: frameContext.lifecycle,
    frameAttachedAtOutcome: frame.isConnected !== false,
    pageHideObserved: frameContext.pageHideObserved,
    pageHidePersisted: frameContext.pageHidePersisted,
    abortSignalState: signalState,
    abortReason,
    abortClassification: ownedExitAbort ? 'owned-exit-abort' : abortError ? 'unattributed-abort' : 'not-abort',
    deliveryState: response ? 'response-received' : ownedExitAbort ? 'owned-cancellation' : 'unconfirmed',
    serverRequestId: gameTransportServerId(response),
  });
}

function isExpectedPageHideTransportFailure(context, outcome, error) {
  if (outcome.abortClassification === 'owned-exit-abort'
    && outcome.deliveryState === 'owned-cancellation') return true;
  return ['TypeError', 'AbortError'].includes(error?.name)
    && context.request.action === 'disconnect'
    && context.dispatch.keepalive === true
    && outcome.pageHideObserved === true
    && ['pagehide', 'detached'].includes(outcome.lifecycleAtOutcome)
    // Same-origin iframe navigation can dispatch a trusted, non-persisted
    // pagehide while the departing document still reports "visible".
    && outcome.pageHidePersisted === false
    && outcome.deliveryState === 'unconfirmed'
    && outcome.serverRequestId === null;
}

async function gameTransportReport(report, details, context, outcome) {
  const identity = await context.identity;
  return report({
    ...details,
    evidence: { ...details.evidence, ...context.dispatch, ...identity, ...outcome },
  });
}

function gameRequestMetadata(input, options = {}, baseUrl = globalThis.location?.href || '/') {
  let url;
  try {
    const value = typeof input?.url === 'string' ? input.url : String(input || '');
    url = new URL(value, baseUrl);
  } catch {
    url = new URL(baseUrl);
  }
  const method = String(options?.method || input?.method || 'GET').toUpperCase();
  let action = safeString(url.searchParams.get('action') || '', 64).toLowerCase();
  if (!action && typeof options?.body === 'string' && options.body.length <= 65536) {
    try { action = safeString(JSON.parse(options.body)?.action || '', 64).toLowerCase(); } catch {}
  }
  return { path: url.pathname, action: action || null, method };
}

function gameSessionReadRequestBinding(input, method, baseUrl = globalThis.location?.href) {
  try {
    if (String(method).toUpperCase() !== 'GET') return null;
    const base = new URL(baseUrl);
    const url = new URL(typeof input?.url === 'string' ? input.url : String(input || ''), base);
    if (!['http:', 'https:'].includes(base.protocol) || url.origin !== base.origin
      || !/\/api\/game_framework\.php$/i.test(url.pathname)) return null;
    for (const key of ['action', 'game_session_id', 'public_id', 'session_id', 'participant_id']) {
      if (url.searchParams.getAll(key).length > 1) return null;
    }
    if (url.searchParams.get('action') !== 'session') return null;
    const gameId = url.searchParams.get('game_session_id');
    const publicId = url.searchParams.get('public_id');
    if (gameId !== null && publicId !== null && gameId.trim() !== publicId.trim()) return null;
    const lobbyCode = (gameId ?? publicId ?? '').trim();
    if (!/^[A-Za-z0-9_-]{1,160}$/.test(lobbyCode)) return null;
    const roomId = url.searchParams.get('session_id') || '';
    if (!/^[A-Za-z0-9_-]{1,160}$/.test(roomId)) return null;
    const participantId = url.searchParams.get('participant_id');
    if (participantId !== null && !/^[1-9][0-9]*$/.test(participantId)) return null;
    return Object.freeze({ kind: 'game-session-read-v1', lobbyCode });
  } catch { return null; }
}

function isKnownClosedGameSessionPayload(data, status, expectedLobby) {
  const record = value => value !== null && typeof value === 'object' && !Array.isArray(value);
  if (Number(status) !== 410 || typeof expectedLobby !== 'string'
    || !/^[A-Za-z0-9_-]{1,160}$/.test(expectedLobby) || !record(data)) return false;
  if (Object.keys(data).sort().join(',') !== 'code,error,gameLifecycle'
    || data.error !== 'This game session has ended.' || data.code !== 'MULTIPLAYER_GAME_SESSION_CLOSED') return false;
  const lifecycle = data.gameLifecycle;
  return record(lifecycle) && Object.keys(lifecycle).sort().join(',') === 'lobbyCode,status,version'
    && lifecycle.version === 1 && lifecycle.status === 'closed' && lifecycle.lobbyCode === expectedLobby;
}

function isKnownClosedGameRequestFailure(error) {
  const binding = error?.gameSessionReadBinding;
  return error?.code === 'HTTP_ERROR' && error?.details?.method === 'GET'
    && error?.details?.redirected === false
    && /^(?:application|text)\/(?:[a-z0-9.+-]*\+)?json(?:\s*;|$)/i.test(String(error?.details?.contentType || ''))
    && binding?.kind === 'game-session-read-v1'
    && isKnownClosedGameSessionPayload(error?.responsePayload, error?.details?.status, binding.lobbyCode);
}

export function isExpectedAuthoritativeGameStateStaleResponse(response, data, request, contentType = '') {
  const currentVersion = data?.currentVersion;
  return Number(response?.status) === 409
    && response?.redirected === false
    && request?.method === 'POST'
    && request?.action === 'extension-action'
    && /\/api\/game_framework\.php$/i.test(String(request?.path || ''))
    && /^(?:application|text)\/(?:[a-z0-9.+-]*\+)?json(?:\s*;|$)/i.test(String(contentType))
    && data !== null && typeof data === 'object' && !Array.isArray(data)
    && typeof data.error === 'string' && data.error.trim() !== ''
    && data.code === 'MULTIPLAYER_GAME_STATE_STALE'
    && Number.isSafeInteger(currentVersion) && currentVersion >= 0;
}

function isGameApiResponse(response, baseUrl) {
  try {
    const url = new URL(response.url || '', baseUrl);
    return url.origin === new URL(baseUrl).origin && /\/api\/(?:game_framework|games)\.php$/i.test(url.pathname);
  } catch {
    return false;
  }
}

function gameFailureIdentity(action, status) {
  if (action === 'session') return ['GAME_SESSION_LOAD_FAILED', 'Game session could not be loaded'];
  if (action === 'catalog') return ['GAME_CATALOG_LOAD_FAILED', 'Game catalog could not be loaded'];
  if (/reconnect|resume/.test(action || '')) return ['GAME_RECONNECT_FAILED', 'Game reconnect or resume failed'];
  if (/save/.test(action || '')) return ['GAME_SAVE_FAILED', 'Game save failed'];
  if (/settle|settlement|complete|finish|result/.test(action || '')) return ['GAME_SETTLEMENT_FAILED', 'Game settlement failed'];
  if (/timeout/.test(action || '') || status === 408 || status === 504) return ['GAME_TIMEOUT', 'Game request timed out'];
  return ['GAME_ACTION_REJECTED', 'Game action was rejected'];
}

function createGameRuntimeMonitor() {
  return {
    frameLoads: 0,
    storage: null,
    stateVersions: new Map(),
    inFlightActions: new Map(),
    recentActions: new Map(),
    slowCounts: new Map(),
    longTasks: [],
    lastLongTaskReportAt: 0,
    requestSamples: [],
    lastProjection: null,
    lastAction: null,
    lastStateHash: null,
    reloadRollbackDetected: false,
    duplicateActionDetected: false,
  };
}

function quickHash(value) {
  let hash = 2166136261;
  for (let index = 0; index < value.length; index += 1) {
    hash ^= value.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }
  return (hash >>> 0).toString(16).padStart(8, '0');
}

function gameActionFingerprint(request, input, options = {}) {
  if (request.method === 'GET' || !request.action
      || /^(?:session|catalog|options|records|poll|presence|disconnect|reconnect)$/.test(request.action)) return '';
  let body = '';
  if (typeof options?.body === 'string') body = options.body;
  else if (!(input instanceof Request) && input && typeof input.body === 'string') body = input.body;
  return `${request.method}:${request.path}:${request.action}:${quickHash(body.slice(0, 65536))}`;
}

function beginGameRequestMonitor(monitor, input, options, view, report) {
  const request = gameRequestMetadata(input, options, view.location.href);
  const startedAt = view.performance?.now?.() ?? Date.now();
  if (!/\/api\/(?:game_framework|games)\.php$/i.test(request.path)) {
    return { token: '', request, startedAt, fingerprint: '', stuckTimer: null, monitored: false };
  }
  const fingerprint = gameActionFingerprint(request, input, options);
  const token = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  if (fingerprint) {
    const previousAt = monitor.recentActions.get(fingerprint) || 0;
    if (Date.now() - previousAt <= GAME_DUPLICATE_ACTION_WINDOW_MS) {
      monitor.duplicateActionDetected = true;
      void report({
        code: 'GAME_DUPLICATE_ACTION_ATTEMPT',
        title: 'Game action was submitted more than once',
        message: 'The same game action was submitted repeatedly before the first interaction settled.',
        evidence: { ...request, source: 'game-action-monitor', intervalMs: Date.now() - previousAt },
      });
    }
    monitor.recentActions.set(fingerprint, Date.now());
  }
  const stuckTimer = fingerprint ? view.setTimeout(() => {
    if (!monitor.inFlightActions.has(token)) return;
    void report({
      code: 'GAME_ACTION_STUCK',
      title: 'Game action is not completing',
      message: 'A submitted game action remained unsettled beyond the bounded response window.',
      evidence: { ...request, source: 'game-action-watchdog', elapsedMs: GAME_STUCK_ACTION_MS },
    });
  }, GAME_STUCK_ACTION_MS) : null;
  const probe = { token, request, startedAt, fingerprint, stuckTimer, monitored: true };
  monitor.inFlightActions.set(token, probe);
  return probe;
}

function completeGameRequestMonitor(monitor, probe, view, report, successful, response = null) {
  if (!probe?.monitored) return;
  monitor.inFlightActions.delete(probe.token);
  if (probe.stuckTimer !== null) view.clearTimeout(probe.stuckTimer);
  const finishedAt = view.performance?.now?.() ?? Date.now();
  const durationMs = Math.max(0, Math.round(finishedAt - probe.startedAt));
  const key = probe.request.action || probe.request.path;
  const thresholdMs = probe.fingerprint ? GAME_SLOW_ACTION_MS : GAME_SLOW_READ_MS;
  const prior = monitor.slowCounts.get(key) || 0;
  const count = durationMs >= thresholdMs ? prior + 1 : 0;
  monitor.slowCounts.set(key, count);
  const headerNumber = name => {
    const value = Number(response?.headers?.get?.(name));
    return Number.isFinite(value) && value >= 0 ? value : null;
  };
  const sample = {
    action: probe.request.action || null,
    actionRequest: Boolean(probe.fingerprint),
    browserRoundTripMs: durationMs,
    serverExecutionMs: headerNumber('x-corechat-server-ms'),
    databaseLockWaitMs: headerNumber('x-corechat-db-lock-wait-ms'),
    retryCount: headerNumber('x-corechat-retry-count') || 0,
    outcome: successful ? 'success' : 'failed',
  };
  monitor.requestSamples.push(sample);
  if (monitor.requestSamples.length > 100) monitor.requestSamples.splice(0, monitor.requestSamples.length - 100);
  monitor.lastAction = { action: sample.action, outcome: sample.outcome, rejectionCode: successful ? null : 'REQUEST_FAILED' };
  if (successful && count >= GAME_SLOW_CONSECUTIVE_LIMIT) {
    monitor.slowCounts.set(key, 0);
    void report({
      code: 'GAME_REQUEST_PERFORMANCE_REGRESSION',
      title: 'Game requests are repeatedly slow',
      message: 'The same game operation exceeded its response-time threshold several times in succession.',
      evidence: { ...probe.request, source: 'game-request-timing', durationMs, thresholdMs, consecutiveSlowRequests: count },
    });
  }
}

function gameDiagnosticErrorField(error, field) {
  try { return typeof error?.[field] === 'string' ? error[field] : ''; } catch { return ''; }
}

function gameDiagnosticFirstPartyAsset(value, baseUrl = globalThis.location?.href) {
  try {
    const base = new URL(baseUrl);
    const url = new URL(value, base);
    // This module lives at assets/js/core/ in every installation. Resolve the
    // installation root from the module, not a particular hosting folder name.
    const installation = new URL('../../../', import.meta.url);
    if (!['http:', 'https:'].includes(base.protocol) || url.origin !== base.origin
      || installation.origin !== base.origin || !url.pathname.startsWith(installation.pathname)
      || !/^\/(?:assets|games)\/[A-Za-z0-9_./-]+\.(?:m?js|html?|php)$/.test(
        url.pathname.slice(installation.pathname.length - 1))) return null;
    return { path: url.pathname, url };
  } catch { return null; }
}

function gameDiagnosticAssetEvidence(filename, baseUrl, frameDocument = null) {
  const asset = gameDiagnosticFirstPartyAsset(filename, baseUrl);
  if (!asset) return {};
  let revision = null;
  try {
    for (const script of Array.from(frameDocument?.querySelectorAll?.('script[src]') || []).slice(0, 64)) {
      const declared = gameDiagnosticFirstPartyAsset(script.src, baseUrl);
      if (!declared || declared.path !== asset.path) continue;
      const candidate = declared.url.searchParams.get('v') || declared.url.searchParams.get('rev') || '';
      if (/^(?:[0-9]{1,16}|[a-f0-9]{7,64}|[0-9]{8}-[a-z0-9-]{1,48})$/.test(candidate)) revision = candidate;
      break;
    }
  } catch { /* An unavailable document must not prevent error capture. */ }
  return { assetPath: asset.path, assetRevision: revision };
}

function gameDiagnosticErrorFrames(error, baseUrl = globalThis.location?.href) {
  const frames = [];
  for (const line of gameDiagnosticErrorField(error, 'stack').slice(0, 8192).split(/\r?\n/).slice(0, 24)) {
    const match = line.match(/((?:https?:\/\/|\/)[^\s()]+):([0-9]{1,7}):([0-9]{1,7})\)?\s*$/);
    if (!match) continue;
    const asset = gameDiagnosticFirstPartyAsset(match[1], baseUrl);
    if (!asset) continue;
    const prefix = line.slice(0, match.index).trim().replace(/^at\s+/, '').replace(/[\s(@]+$/, '');
    const functionName = /^[A-Za-z0-9_$#.<> -]{1,100}$/.test(prefix) ? prefix : '[function]';
    frames.push({ assetPath: asset.path, line: Number(match[2]), column: Number(match[3]), functionName });
    if (frames.length === 12) break;
  }
  return frames;
}

function gameDiagnosticError(error, fallbackMessage) {
  const name = gameDiagnosticErrorField(error, 'name');
  const failure = new Error(safeString(gameDiagnosticErrorField(error, 'message') || fallbackMessage || 'Game runtime failure', 600));
  failure.name = /^[A-Za-z][A-Za-z0-9_.]{0,63}$/.test(name) ? name : 'Error';
  const frames = gameDiagnosticErrorFrames(error);
  // Preserve iframe locations, never stack arguments, URL queries or external paths.
  failure.stack = [failure.name, ...frames.map(frame => '    at ' + frame.functionName + ' (' + frame.assetPath + ':' + frame.line + ':' + frame.column + ')')].join('\n').slice(0, 2400);
  return failure;
}

function gameDiagnosticErrorEvidence(error) {
  const frame = gameDiagnosticErrorFrames(error)[0];
  return frame ? { assetPath: frame.assetPath, assetLine: frame.line, assetColumn: frame.column } : {};
}

function gameSessionKey(projection, gameType, context = {}) {
  const validIdentity = value => (typeof value === 'string' && /^[A-Za-z0-9_-]{1,160}$/.test(value))
    || (Number.isSafeInteger(value) && value > 0);
  const sessionId = [
    projection.publicId, projection.public_id, projection.gameSessionId, projection.game_session_id,
    projection.sessionId, projection.session_id, projection.id,
  ].find(validIdentity);
  if (sessionId === undefined) return null;
  const roomId = [
    projection.sourceRoomSessionId, projection.source_room_session_id, projection.roomSessionId, projection.room_session_id,
  ].find(validIdentity);
  return JSON.stringify([2, safeString(gameType, 72), String(roomId ?? ''), String(sessionId), safeString(context.frame?.buildId || '', 120)]);
}

function gameObservationIsCurrent(monitor, context = {}) {
  return !context.frame || (context.frame.active !== false && context.frame.generation === monitor.frameLoads);
}

function observeGameStateVersion(projection, gameType, request, monitor, context = {}) {
  if (!gameObservationIsCurrent(monitor, context)) return null;
  const version = Number(projection.stateVersion ?? projection.state_version ?? projection.revision);
  const sessionKey = gameSessionKey(projection, gameType, context);
  if (!sessionKey || !Number.isInteger(version) || version < 0) return null;
  const key = request.path + '|' + sessionKey;
  const previous = monitor.stateVersions.get(key);
  const sequence = Number(context.requestSequence) || 0;
  monitor.diagnosticResponseSequences ||= new Map();
  const previousSequence = monitor.diagnosticResponseSequences.get(key) || 0;
  // An older request returning a lower version is late, not proof of server rollback.
  if (sequence > 0 && sequence < previousSequence && Number.isInteger(previous) && version <= previous) return null;
  monitor.diagnosticResponseSequences.set(key, Math.max(sequence, previousSequence));
  const storageKey = 'corechat.game-diagnostic.v2.' + encodeURIComponent(sessionKey);
  let stored = null;
  try { stored = JSON.parse(monitor.storage?.getItem(storageKey) || 'null'); } catch { stored = null; }
  const storedVersion = stored?.sessionKey === sessionKey && Number.isInteger(stored.stateVersion) && stored.stateVersion >= 0
    ? stored.stateVersion : null;
  const evidence = { ...request, sessionKeyHash: quickHash(sessionKey), receivedVersion: version };
  const findings = [];
  if (Number.isInteger(previous) && version < previous) {
    findings.push({
      code: 'GAME_STATE_DESYNCHRONIZED',
      title: 'Game state version moved backwards',
      message: 'The embedded game received an older state after a newer state.',
      evidence: { ...evidence, previousVersion: previous, source: 'game-state-version' },
    });
  }
  if (previous === undefined && storedVersion !== null && version < storedVersion) {
    monitor.reloadRollbackDetected = true;
    findings.push({
      code: 'GAME_RELOAD_STATE_ROLLBACK',
      title: 'Game reload restored an older state',
      message: 'After the embedded game reloaded, it restored a state version older than the last version observed in this tab.',
      evidence: { ...evidence, previousVersion: storedVersion, source: 'game-reload-integrity' },
    });
  }
  const highWatermark = Math.max(version, Number(previous) || 0, storedVersion ?? 0);
  monitor.stateVersions.set(key, highWatermark);
  try { monitor.storage?.setItem(storageKey, JSON.stringify({ sessionKey, stateVersion: highWatermark })); } catch {}
  return { sessionKey, version, findings };
}

function collectGameStateFindings(projection, gameType) {
  const findings = [];
  const state = projection.state || {};
  const members = Array.isArray(projection.members) ? projection.members : [];
  const playerMembers = members.filter(member => ['master', 'player'].includes(String(member?.role || '').toLowerCase()));
  const memberIds = playerMembers.map(member => Number(member?.userId ?? member?.user_id)).filter(Number.isInteger);
  if (new Set(memberIds).size !== memberIds.length) {
    findings.push({ code: 'GAME_STATE_MEMBER_DUPLICATE', title: 'Game state contains duplicate players', message: 'The same player identity appears more than once in the authoritative game projection.', evidence: { source: 'game-state-invariant', playerCount: memberIds.length } });
  }
  const turnUserId = Number(projection.turnUserId ?? state.currentTurnUserId ?? state.current_turn_user_id ?? state.turnUserId ?? state.turn_user_id ?? state.currentPlayerUserId ?? state.current_player_user_id);
  if (Number.isInteger(turnUserId) && turnUserId > 0 && memberIds.length && !memberIds.includes(turnUserId)) {
    findings.push({ code: 'GAME_STATE_TURN_OWNER_INVALID', title: 'Game turn points to a missing player', message: 'The authoritative turn owner is not present in the projected player list.', evidence: { source: 'game-state-invariant', playerCount: memberIds.length } });
  }
  const turnIndex = Number(state.currentTurnIndex ?? state.current_turn_index ?? state.currentPlayerIndex ?? state.current_player_index);
  if (Number.isInteger(turnIndex) && turnIndex >= 0 && playerMembers.length && turnIndex >= playerMembers.length) {
    findings.push({ code: 'GAME_STATE_TURN_INDEX_INVALID', title: 'Game turn index is outside the player list', message: 'The authoritative turn index exceeds the projected player count.', evidence: { source: 'game-state-invariant', turnIndex, playerCount: playerMembers.length } });
  }
  const actionNames = new Set();
  for (const source of [state.legalActions, state?._framework?.actions]) {
    if (Array.isArray(source)) {
      for (const entry of source) {
        const name = typeof entry === 'string' ? entry : String(entry?.action ?? entry?.type ?? entry?.name ?? '');
        if (name) actionNames.add(name);
      }
    } else if (source && typeof source === 'object') {
      for (const [name, enabled] of Object.entries(source)) {
        if (enabled === true || (enabled && typeof enabled === 'object' && enabled.enabled !== false)) actionNames.add(name);
      }
    }
  }
  const actionable = [...actionNames].filter(name => /^(?:can)?(?:play|draw|bid|move|roll|score|discard|submit|attack|place)/i.test(name));
  if (state.completed === true && actionable.length) {
    findings.push({ code: 'GAME_STATE_COMPLETED_ACTIONABLE', title: 'Completed game still exposes active moves', message: 'The game is marked complete while gameplay actions remain enabled.', evidence: { source: 'game-state-invariant', actionNames: actionable.slice(0, 12) } });
  }
  if (/spades/.test(gameType)) {
    const trick = Array.isArray(state.currentTrick) ? state.currentTrick
      : Array.isArray(state.trickCards) ? state.trickCards
        : Array.isArray(state.trick?.cards) ? state.trick.cards : [];
    if (trick.length > 4) findings.push({ code: 'SPADES_TRICK_CARD_COUNT_INVALID', title: 'Spades trick contains too many cards', message: 'A Spades trick contains more than one played card per seat.', evidence: { source: 'spades-state-invariant', trickCardCount: trick.length } });
    const hands = state.hands && typeof state.hands === 'object' ? Object.values(state.hands).filter(Array.isArray) : [];
    const oversizedHand = hands.find(hand => hand.length > 13);
    if (oversizedHand) findings.push({ code: 'SPADES_HAND_CARD_COUNT_INVALID', title: 'Spades hand contains too many cards', message: 'A projected Spades hand contains more than thirteen cards.', evidence: { source: 'spades-state-invariant', cardCount: oversizedHand.length } });
    const bidValues = state.bids && typeof state.bids === 'object' ? Object.values(state.bids) : [];
    const invalidBid = bidValues.find(value => typeof value === 'number' && (!Number.isInteger(value) || value < 0 || value > 13));
    if (invalidBid !== undefined) findings.push({ code: 'SPADES_BID_INVALID', title: 'Spades bid is outside the valid range', message: 'A projected Spades bid is not a whole number from zero through thirteen.', evidence: { source: 'spades-state-invariant' } });
  }
  if (/puppy/.test(gameType) && playerMembers.length > 5) {
    findings.push({ code: 'PUPPY_PANIC_PLAYER_COUNT_INVALID', title: 'Puppy Panic has too many players', message: 'The projected Puppy Panic game exceeds its five-player table capacity.', evidence: { source: 'puppy-panic-state-invariant', playerCount: playerMembers.length } });
  }
  return findings;
}

export function canonicalFrameworkDiagnosticSessionProblemDetails(value) {
  const record = item => item !== null && typeof item === 'object' && !Array.isArray(item);
  const actualType = (candidate, present = true) => {
    if (!present) return 'missing';
    if (candidate === null) return 'null';
    if (Array.isArray(candidate)) return 'array';
    if (typeof candidate === 'string' && candidate.length === 0) return 'empty-string';
    if (typeof candidate === 'number' && Number.isInteger(candidate) && candidate < 0) return 'negative-integer';
    if (typeof candidate === 'string' && !/^\d+$/.test(candidate)) return 'non-integer-string';
    return typeof candidate === 'number' ? 'number' : typeof candidate;
  };
  const problem = (offendingField, expectedType, actual, present = true) => ({
    offendingField,
    expectedType,
    actualType: actualType(actual, present),
  });
  const has = (object, key) => Object.prototype.hasOwnProperty.call(object, key);
  if (!record(value)) return problem('session', 'object', value);
  for (const field of ['publicId', 'status']) {
    if (!has(value, field)) return problem(`session.${field}`, 'non-empty-string', undefined, false);
    if (typeof value[field] !== 'string' || !value[field].trim()) {
      return problem(`session.${field}`, 'non-empty-string', value[field]);
    }
  }
  if (!has(value, 'settingsSha256')) return problem('session.settingsSha256', 'string', undefined, false);
  if (typeof value.settingsSha256 !== 'string') return problem('session.settingsSha256', 'string', value.settingsSha256);
  if (!has(value, 'stateVersion')) return problem('session.stateVersion', 'non-negative-integer', undefined, false);
  if ((!Number.isInteger(value.stateVersion) || value.stateVersion < 0)
      && (typeof value.stateVersion !== 'string' || !/^\d+$/.test(value.stateVersion))) {
    return problem('session.stateVersion', 'non-negative-integer', value.stateVersion);
  }
  if (!has(value, 'members')) return problem('session.members', 'array-of-objects', undefined, false);
  if (!Array.isArray(value.members)) return problem('session.members', 'array-of-objects', value.members);
  const invalidMember = value.members.find(member => !record(member));
  if (invalidMember !== undefined) return problem('session.members[]', 'object', invalidMember);
  if (!has(value, 'state')) return problem('session.state', 'object', undefined, false);
  if (!record(value.state)) return problem('session.state', 'object', value.state);
  if (has(value.state, '_framework') && value.state._framework !== null && !record(value.state._framework)) {
    return problem('session.state._framework', 'object', value.state._framework);
  }
  const players = value.state._framework?.players;
  const playerCollection = Array.isArray(players) || record(players);
  if (players !== undefined && players !== null && !playerCollection) {
    return problem('session.state._framework.players', 'array-or-object-of-objects', players);
  }
  if (playerCollection) {
    const invalidPlayer = Object.values(players).find(player => !record(player));
    if (invalidPlayer !== undefined) return problem('session.state._framework.players[]', 'object', invalidPlayer);
  }
  return null;
}

function canonicalFrameworkDiagnosticSessionProblem(value) {
  const record = item => item !== null && typeof item === "object" && !Array.isArray(item);
  if (!record(value)) return "session-object";
  if (typeof value.publicId !== "string" || value.publicId.trim() === "") return "session-id";
  if (typeof value.status !== "string" || value.status.trim() === "") return "session-status";
  if (typeof value.settingsSha256 !== "string") return "settings-hash";
  const version = value.stateVersion;
  if (!(typeof version === "number" || (typeof version === "string" && /^\d+$/.test(version)))
    || !Number.isFinite(Number(version)) || !Number.isInteger(Number(version)) || Number(version) < 0) return "session-version";
  if (!Array.isArray(value.members) || !value.members.every(record)) return "session-members";
  if (value.state === null || typeof value.state !== "object") return "session-state-container";
  const framework = value.state._framework;
  if (framework !== undefined && framework !== null) {
    if (!record(framework)) return "framework-object";
    const players = framework.players;
    if (players !== undefined && players !== null
      && (typeof players !== "object" || !Object.values(players).every(record))) return "framework-players";
  }
  return "";
}

async function inspectGameApiResponse(response, input, options, baseUrl, monitor, gameType, report, observationContext = {}) {
  const request = observationContext.request || gameRequestMetadata(input, options, baseUrl);
  const contentType = safeString(response.headers?.get?.('content-type') || '', 120).toLowerCase();
  let text = '';
  try { text = await response.text(); } catch (error) {
    await report({
      code: 'GAME_NETWORK_FAILURE',
      title: 'Game response could not be read',
      message: 'The game API response body could not be read.',
      evidence: { ...request, status: Number(response.status), contentType, source: 'game-api-response-body',
        errorName: ['Error', 'TypeError', 'AbortError', 'TimeoutError'].includes(error?.name) ? error.name : 'Error' },
    }, error);
    return;
  }
  const trimmed = text.trim();
  if (/^(?:<!doctype\s+html|<html\b|<br\s*\/?\s*>|<b\b)/i.test(trimmed) || contentType.includes('text/html')) {
    await report({
      code: 'GAME_API_HTML_RESPONSE',
      title: 'Game API returned HTML instead of JSON',
      message: 'The game API returned an HTML error document instead of JSON.',
      evidence: { ...request, status: Number(response.status), contentType, source: 'game-api-response' },
    });
    return;
  }
  if (!trimmed) {
    await report({
      code: 'GAME_API_EMPTY_RESPONSE',
      title: 'Game API returned an empty response',
      message: 'The game API response was empty.',
      evidence: { ...request, status: Number(response.status), contentType, source: 'game-api-response' },
    });
    return;
  }
  let data;
  try {
    data = JSON.parse(text);
  } catch (error) {
    await report({
      code: 'GAME_API_INVALID_JSON',
      title: 'Game API returned invalid JSON',
      message: 'The game API response could not be parsed as JSON.',
      error: new SyntaxError('The game API response could not be parsed as JSON.'),
      evidence: { ...request, status: Number(response.status), contentType, source: 'game-api-response' },
    });
    return;
  }
  const closedReadBinding = gameSessionReadRequestBinding(input, options?.method || input?.method || 'GET', baseUrl);
  if (!response.redirected
    && /^(?:application|text)\/(?:[a-z0-9.+-]*\+)?json(?:\s*;|$)/i.test(contentType)
    && isKnownClosedGameSessionPayload(data, Number(response.status), closedReadBinding?.lobbyCode)) return;
  if (isExpectedAuthoritativeGameStateStaleResponse(response, data, request, contentType)) return;
  if (!response.ok || data?.error) {
    const [code, title] = gameFailureIdentity(request.action, Number(response.status));
    await report({
      code,
      title,
      message: safeString(data?.error || `${title} (${response.status}).`),
evidence: { ...request, status: Number(response.status), serverCode: safeString(data?.code || '', 96) || null, retryable: data?.retryable === true, source: 'game-api-response', serverRequestId: gameTransportServerId(response, data) },
    });
    return;
  }
  if (!gameObservationIsCurrent(monitor, observationContext)) return;
  if (request.action !== 'session') return;
  const projection = data?.session && typeof data.session === 'object' ? data.session : data;
  const canonicalFrameworkSession = request.method === 'GET'
    && /\/api\/game_framework\.php$/i.test(request.path);
  const sessionProblem = canonicalFrameworkSession
    ? canonicalFrameworkDiagnosticSessionProblemDetails(projection) : null;
  if (sessionProblem
      || !projection || typeof projection !== 'object' || Array.isArray(projection)
      || !projection.state || typeof projection.state !== 'object'
      || !Array.isArray(projection.members)) {
    await report({
      code: 'GAME_STATE_INVALID',
      title: 'Game session state is invalid',
      message: 'The game session response contains an invalid session envelope.',
      evidence: { ...request, status: Number(response.status), source: 'game-state-contract',
        ...(sessionProblem ? {
          offendingField: sessionProblem.offendingField,
          expectedType: sessionProblem.expectedType,
          actualType: sessionProblem.actualType,
        } : {}) },
    });
    return;
  }
  for (const finding of collectGameStateFindings(projection, gameType)) await report(finding);
  const observation = observeGameStateVersion(projection, gameType, request, monitor, observationContext);
  if (!observation) return;
  const { version, sessionKey } = observation;
  const projectedPlayers = projection.members.filter(member => ['master', 'player'].includes(String(member?.role || '').toLowerCase()));
  const stateHash = quickHash(JSON.stringify(projection.state));
  monitor.lastProjection = {
    gameType,
    sessionKeyHash: quickHash(sessionKey),
    playerCount: projectedPlayers.length,
    seatPositions: projection.members.map((member, index) => ({ seat: member.seat ?? member.position ?? index, role: safeString(member.role || 'player', 24) })),
    phase: safeString(projection.state.phase || projection.state.status || '', 48) || null,
    turn: Number(projection.state.currentTurnIndex ?? projection.state.current_turn_index ?? projection.state.turnIndex ?? projection.state.turn_index),
    round: Number(projection.state.round ?? projection.state.roundNumber ?? projection.state.round_number),
    stateRevision: version,
    beforeStateHash: monitor.lastStateHash,
    afterStateHash: stateHash,
  };
  monitor.lastStateHash = stateHash;
  for (const finding of observation.findings) await report(finding);
}

function sampleGameVisualReadiness(frame, view, documentObject) {
  const rect = frame.getBoundingClientRect();
  const root = documentObject.documentElement;
  const body = documentObject.body;
  const surface = documentObject.querySelector?.('#board-host')
    || documentObject.querySelector?.('#game-root')
    || documentObject.querySelector?.('#play-surface') || body;
  const surfaceRect = surface?.getBoundingClientRect?.();
  const pixels = value => Number.isFinite(Number(value)) ? Math.round(Number(value)) : 0;
  const geometry = {
    observedAt: Date.now(),
    width: pixels(view.innerWidth || frame.clientWidth), height: pixels(view.innerHeight || frame.clientHeight),
    iframeWidth: pixels(rect.width), iframeHeight: pixels(rect.height),
    rootWidth: pixels(root?.clientWidth), rootHeight: pixels(root?.clientHeight),
    rootScrollWidth: pixels(root?.scrollWidth), rootScrollHeight: pixels(root?.scrollHeight),
    bodyWidth: pixels(body?.clientWidth), bodyHeight: pixels(body?.clientHeight),
    bodyScrollWidth: pixels(body?.scrollWidth), bodyScrollHeight: pixels(body?.scrollHeight),
    surfaceWidth: pixels(surfaceRect?.width), surfaceHeight: pixels(surfaceRect?.height),
    devicePixelRatio: Number(view.devicePixelRatio) || 1, zoom: Number(view.visualViewport?.scale) || 1,
    readyState: ['loading', 'interactive', 'complete'].includes(documentObject.readyState) ? documentObject.readyState : 'unknown',
    visibility: gameTransportVisibility(documentObject),
    fonts: documentObject.fonts?.status === 'loading' ? 'loading' : 'loaded',
  };
  const hostView = frame.ownerDocument?.defaultView;
  const visible = !frame.hidden && rect.width > 1 && rect.height > 1
    && geometry.visibility === 'visible' && (!hostView || elementVisible(frame, hostView));
  const elements = [...(surface?.querySelectorAll?.('button,a[href],input:not([type="hidden"]),select,textarea,[role="button"],img,canvas,svg') || [])]
    .slice(0, GAME_AUDIT_MAX_CONTROLS).filter(element => elementVisible(element, view));
  const boxes = elements.map(element => {
    const box = element.getBoundingClientRect();
    return [pixels(box.left), pixels(box.top), pixels(box.width), pixels(box.height)];
  });
  geometry.visibleElements = elements.length;
  const pendingImages = elements.some(element => String(element.tagName || '').toLowerCase() === 'img' && !element.complete);
  const populated = Boolean(root && body && surfaceRect?.width > 1 && surfaceRect?.height > 1 && elements.length);
  const phase = !visible ? 'hidden' : geometry.readyState !== 'complete' ? 'loading'
    : !populated ? 'empty' : geometry.fonts === 'loading' ? 'fonts-loading'
      : pendingImages ? 'assets-loading' : 'ready';
  // Numeric element geometry stays local; no labels, card values, URLs, or pixels.
  const { observedAt, ...stableGeometry } = geometry;
  return { geometry, ready: phase === 'ready', phase, signature: JSON.stringify([stableGeometry, boxes]) };
}

function elementDescription(element) {
  const rect = element.getBoundingClientRect();
  // Stable UI identifiers only; do not capture labels, player names or text.
  const settingReferences = String(element.getAttribute?.('aria-describedby') || '')
    .split(/\s+/).filter(value => /^setting-(?:help|label)-[A-Za-z0-9_-]{1,80}$/.test(value)).slice(0, 4);
  const seat = ['top', 'bottom', 'left', 'right']
    .find(position => element.closest?.('.spm-seat-' + position)) || null;
  return {
    tag: String(element.tagName || '').toLowerCase(),
    id: safeString(element.id || '', 80) || null,
    classes: [...(element.classList || [])].slice(0, 4).map(value => safeString(value, 64)),
    role: safeString(element.getAttribute?.('role') || '', 40) || null,
    settingReferences,
    seat,
    width: Math.round(rect.width),
    height: Math.round(rect.height),
  };
}

function elementVisible(element, view) {
  if (!element || element.hidden || element.closest?.('[hidden],[aria-hidden="true"]')) return false;
  const style = view.getComputedStyle(element);
  if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) return false;
  const rect = element.getBoundingClientRect();
  return rect.width > 1 && rect.height > 1;
}

function cardLikeControl(element) {
  return /(?:card|tile|piece|checker|chip|dice|die)(?:$|[\s_-])/i.test(`${element.id || ''} ${element.className || ''}`)
    || element.hasAttribute?.('data-diagnostic-allow-overlap');
}

function intentionalBoardGeometryControl(element) {
  return element.matches?.('.point-game-point,.point-game-zone')
    || element.hasAttribute?.('data-diagnostic-board-geometry');
}

function effectiveControlTargetRect(element, view) {
  const ownRect = element.getBoundingClientRect();
  if (!element.matches?.('input[type="radio"],input[type="checkbox"]')) return ownRect;
  const labels = [...(element.labels || [])];
  const label = labels.find(candidate => elementVisible(candidate, view)
    && view.getComputedStyle(candidate).pointerEvents !== 'none');
  return label?.getBoundingClientRect?.() || ownRect;
}

function controlScope(element) {
  return element.closest?.('dialog,[role="dialog"],.modal,.game-controls,.controls,nav,form')
    || element.ownerDocument.body;
}

function controlClippedByScrollBounds(element, view, viewportWidth, viewportHeight) {
  const documentObject = element.ownerDocument;
  const root = documentObject.documentElement;
  const body = documentObject.body;
  const rootStyle = view.getComputedStyle(root);
  const bodyStyle = body ? view.getComputedStyle(body) : rootStyle;
  const bodyPropagates = body?.parentElement === root && bodyStyle.display !== 'none'
    && rootStyle.overflowX === 'visible' && rootStyle.overflowY === 'visible';
  const viewportStyle = bodyPropagates ? bodyStyle : rootStyle;
  const viewportScroller = documentObject.scrollingElement;
  const viewportScrollingDisabled = view.frameElement?.getAttribute?.('scrolling') === 'no';
  const box = element.getBoundingClientRect();
  const ancestors = [];
  for (let parent = element.parentElement; parent && parent !== root; parent = parent.parentElement) {
    if (parent === body && bodyPropagates) continue;
    const style = view.getComputedStyle(parent);
    if (style.display === 'contents') continue;
    ancestors.push({ node: parent, style, box: parent.getBoundingClientRect() });
  }
  const axes = [
    { near: 'left', size: 'width', clientNear: 'clientLeft', clientSize: 'clientWidth', offsetSize: 'offsetWidth', scrollSize: 'scrollWidth', scrollAt: 'scrollLeft', windowAt: 'scrollX', overflow: 'overflowX', viewportSize: viewportWidth },
    { near: 'top', size: 'height', clientNear: 'clientTop', clientSize: 'clientHeight', offsetSize: 'offsetHeight', scrollSize: 'scrollHeight', scrollAt: 'scrollTop', windowAt: 'scrollY', overflow: 'overflowY', viewportSize: viewportHeight },
  ];
  return axes.some(axis => {
    let minimum = box[axis.near];
    let maximum = minimum;
    const size = box[axis.size];
    const constrain = (near, length, mode, range, current, scale, style) => {
      if (/^(?:auto|scroll)$/.test(mode) && range > 2) {
        const reversed = current < 0 || (current === 0 && (axis.near === 'left'
          ? style.direction === 'rtl' : style.flexDirection === 'column-reverse'));
        const low = reversed ? -range : 0;
        const high = reversed ? 0 : range;
        minimum += (current - high) * scale;
        maximum += (current - low) * scale;
      }
      minimum = Math.max(minimum, near - 4);
      maximum = Math.min(maximum, near + length - size + 4);
      return minimum > maximum;
    };
    for (const ancestor of ancestors) {
      const mode = ancestor.style[axis.overflow] || ancestor.style.overflow || 'visible';
      if (!/^(?:auto|scroll|hidden|clip)$/.test(mode)) continue;
      const node = ancestor.node;
      const ratio = ancestor.box[axis.size] / Number(node[axis.offsetSize]);
      const scale = Number.isFinite(ratio) && ratio > 0 ? ratio : 1;
      // Rects are zoomed viewport coordinates; client/scroll metrics are local CSS pixels.
      const near = ancestor.box[axis.near] + (Number(node[axis.clientNear]) || 0) * scale;
      const length = (Number(node[axis.clientSize]) || 0) * scale;
      const range = Math.max(0, (Number(node[axis.scrollSize]) || 0) - (Number(node[axis.clientSize]) || 0));
      const current = Number(node[axis.scrollAt]) || 0;
      // Reject an empty feasible interval before any outer scroller can move both boxes.
      if (constrain(near, length, mode, range, current, scale, ancestor.style)) return true;
    }
    // Only the iframe's own enabled viewport can reveal its overflow, never the room.
    let mode = viewportScrollingDisabled ? 'hidden' : (viewportStyle[axis.overflow] || viewportStyle.overflow || 'visible');
    if (mode === 'visible') mode = 'auto';
    const range = Math.max(0, (Number(viewportScroller?.[axis.scrollSize]) || 0) - axis.viewportSize);
    const current = Number(view[axis.windowAt] ?? viewportScroller?.[axis.scrollAt]) || 0;
    return constrain(0, axis.viewportSize, mode, range, current, 1, viewportStyle);
  });
}

function intersectionArea(left, right) {
  const width = Math.max(0, Math.min(left.right, right.right) - Math.max(left.left, right.left));
  const height = Math.max(0, Math.min(left.bottom, right.bottom) - Math.max(left.top, right.top));
  return width * height;
}

function gameControlCenterVisible(frame, view, x, y) {
  const width = Number(view.innerWidth) || frame.clientWidth;
  const height = Number(view.innerHeight) || frame.clientHeight;
  if (x < 0 || y < 0 || x >= width || y >= height) return false;
  const hostView = frame.ownerDocument?.defaultView;
  if (!hostView) return true;
  const frameRect = frame.getBoundingClientRect();
  const hostX = frameRect.left + x * frameRect.width / Math.max(1, width);
  const hostY = frameRect.top + y * frameRect.height / Math.max(1, height);
  if (hostX < 0 || hostY < 0 || hostX >= hostView.innerWidth || hostY >= hostView.innerHeight) return false;
  for (let parent = frame.parentElement; parent; parent = parent.parentElement) {
    const style = hostView.getComputedStyle(parent);
    const rect = parent.getBoundingClientRect();
    if (/auto|scroll|hidden|clip/.test(style.overflowX)
        && (hostX < rect.left || hostX >= rect.right)) return false;
    if (/auto|scroll|hidden|clip/.test(style.overflowY)
        && (hostY < rect.top || hostY >= rect.bottom)) return false;
  }
  return true;
}

function collectGameVisualFindings(frame, view, documentObject) {
  const findings = [];
  const frameRect = frame.getBoundingClientRect();
  if (frameRect.width < 1 || frameRect.height < 1 || frame.hidden) return findings;
  if (frameRect.width < 240 || frameRect.height < 180) {
    findings.push({
      code: 'GAME_FRAME_INVALID_SIZE',
      title: 'Game frame is too small',
      message: 'The visible game frame is below the minimum usable dimensions.',
      evidence: { source: 'game-visual-audit', frameWidth: Math.round(frameRect.width), frameHeight: Math.round(frameRect.height) },
    });
  }
  const root = documentObject.documentElement;
  const body = documentObject.body;
  if (!root || !body) return findings;
  const rootStyle = view.getComputedStyle(root);
  const bodyStyle = view.getComputedStyle(body);
  const clippedX = root.scrollWidth > root.clientWidth + 8
    && /hidden|clip/.test(`${rootStyle.overflowX} ${bodyStyle.overflowX}`);
  const clippedY = root.scrollHeight > root.clientHeight + 8
    && /hidden|clip/.test(`${rootStyle.overflowY} ${bodyStyle.overflowY}`);
  if (clippedX || clippedY) {
    findings.push({
      code: 'GAME_DOCUMENT_CLIPPED',
      title: 'Game content is clipped',
      message: 'Game content exceeds a dimension that is configured to hide overflow.',
      evidence: { source: 'game-visual-audit', horizontal: clippedX, vertical: clippedY, clientWidth: root.clientWidth, scrollWidth: root.scrollWidth, clientHeight: root.clientHeight, scrollHeight: root.scrollHeight },
    });
  }
  const missingImage = [...documentObject.images].find(image => elementVisible(image, view)
    && image.complete && image.naturalWidth === 0 && String(image.currentSrc || image.src || '').trim());
  if (missingImage) {
    let assetPath = '';
    try { assetPath = new URL(missingImage.currentSrc || missingImage.src, view.location.href).pathname; } catch {}
    findings.push({
      code: 'GAME_ASSET_MISSING',
      title: 'Game image is missing',
      message: 'A visible game image completed without usable pixels.',
      evidence: { source: 'game-visual-audit', element: elementDescription(missingImage), assetPath: assetPath || null },
    });
  }
  const controls = [...documentObject.querySelectorAll('button,a[href],input:not([type="hidden"]),select,textarea,[role="button"]')]
    .filter(element => elementVisible(element, view) && !element.disabled && view.getComputedStyle(element).pointerEvents !== 'none')
    .slice(0, GAME_AUDIT_MAX_CONTROLS);
  const viewportWidth = Math.max(1, Number(view.innerWidth) || root.clientWidth);
  const viewportHeight = Math.max(1, Number(view.innerHeight) || root.clientHeight);
  const viewportBand = viewportWidth < 480 ? 'ultra-narrow' : viewportWidth < 760 ? 'narrow' : viewportWidth < 1100 ? 'compact' : 'wide';
  const responsiveOverflow = root.scrollWidth > root.clientWidth + 8 || body.scrollWidth > body.clientWidth + 8;
  if (responsiveOverflow) {
    findings.push({
      code: 'GAME_RESPONSIVE_OVERFLOW',
      title: 'Game layout creates horizontal page overflow',
      message: 'The current responsive layout is wider than the embedded game viewport.',
      evidence: { source: 'game-responsive-sweep', viewportBand, viewportWidth, documentWidth: Math.max(root.scrollWidth, body.scrollWidth) },
    });
  }
  const clippedControl = controls.find(element => !cardLikeControl(element) && !intentionalBoardGeometryControl(element)
    && controlClippedByScrollBounds(element, view, viewportWidth, viewportHeight));
  if (clippedControl) {
    findings.push({
      code: 'GAME_CONTROL_CLIPPED',
      title: 'Game control is clipped',
      message: 'An enabled game control extends outside the usable game frame.',
      evidence: { source: 'game-visual-audit', element: elementDescription(clippedControl), viewportWidth, viewportHeight },
    });
  }
  const requiredControls = [...documentObject.querySelectorAll('[data-diagnostic-required-visible="true"]')];
  if (documentObject.activeElement?.matches?.('button,a[href],input,select,textarea,[role="button"]')) requiredControls.push(documentObject.activeElement);
  const hiddenRequired = requiredControls.find(element => !elementVisible(element, view));
  if (hiddenRequired) {
    findings.push({
      code: 'GAME_REQUIRED_CONTROL_HIDDEN',
      title: 'Required game control is hidden',
      message: 'A required or focused game control is not visibly rendered.',
      evidence: { source: 'game-visual-audit', element: elementDescription(hiddenRequired) },
    });
  }
  const avatarFrames = [...documentObject.querySelectorAll('[data-diagnostic-avatar-frame="true"]')]
    .filter(element => elementVisible(element, view));
  const playAreas = [...documentObject.querySelectorAll('[data-diagnostic-play-area="true"]')]
    .filter(element => elementVisible(element, view));
  let avatarPlayAreaOverlap = null;
  for (const avatarFrame of avatarFrames) {
    const avatarRect = avatarFrame.getBoundingClientRect();
    for (const playArea of playAreas) {
      if (avatarFrame.contains(playArea) || playArea.contains(avatarFrame)) continue;
      const playRect = playArea.getBoundingClientRect();
      const area = intersectionArea(avatarRect, playRect);
      const minimumArea = Math.min(avatarRect.width * avatarRect.height, playRect.width * playRect.height);
      if (area >= 64 && minimumArea > 0 && area / minimumArea >= 0.04) {
        avatarPlayAreaOverlap = { avatarFrame, playArea, area, ratio: area / minimumArea };
        break;
      }
    }
    if (avatarPlayAreaOverlap) break;
  }
  if (avatarPlayAreaOverlap) {
    findings.push({
      code: 'GAME_AVATAR_OVERLAPS_PLAY_AREA',
      title: 'Player avatar overlaps the active play area',
      message: 'A player avatar frame substantially covers the area reserved for played cards.',
      evidence: {
        source: 'game-visual-audit',
        avatar: elementDescription(avatarPlayAreaOverlap.avatarFrame),
        playArea: elementDescription(avatarPlayAreaOverlap.playArea),
        overlapArea: Math.round(avatarPlayAreaOverlap.area),
        overlapRatio: Number(avatarPlayAreaOverlap.ratio.toFixed(3)),
        viewportBand,
      },
    });
  }
  const intentionalControlLayer = (left, right) => {
    const pair = [left, right];
    return pair.some(element => element.matches?.(".modern-battle-cell"))
      && pair.some(element => element.matches?.(".modern-battle-ship"));
  };
  let overlap = null;
  for (let leftIndex = 0; leftIndex < controls.length && !overlap; leftIndex += 1) {
    const left = controls[leftIndex];
    if (cardLikeControl(left)) continue;
    const leftRect = left.getBoundingClientRect();
    for (let rightIndex = leftIndex + 1; rightIndex < controls.length; rightIndex += 1) {
      const right = controls[rightIndex];
      if (cardLikeControl(right) || left.contains(right) || right.contains(left)
          || intentionalControlLayer(left, right)
          || controlScope(left) !== controlScope(right)) continue;
      const rightRect = right.getBoundingClientRect();
      const intersection = intersectionArea(leftRect, rightRect);
      const minimumArea = Math.min(leftRect.width * leftRect.height, rightRect.width * rightRect.height);
      if (minimumArea > 0 && intersection / minimumArea >= 0.35) {
        overlap = [left, right];
        break;
      }
    }
  }
  if (overlap) {
    findings.push({
      code: 'GAME_CONTROLS_OVERLAP',
      title: 'Game controls overlap',
      message: 'Two enabled game controls substantially cover the same space.',
      evidence: { source: 'game-visual-audit', elements: overlap.map(elementDescription) },
    });
  }
  const activeDialog = [...documentObject.querySelectorAll('dialog[open],[role="dialog"],.modal.open')]
    .find(element => elementVisible(element, view));
  const auditControls = activeDialog ? controls.filter(element => activeDialog.contains(element)) : controls;
  const obscuredControl = auditControls.find(element => {
    if (cardLikeControl(element)) return false;
    const rect = element.getBoundingClientRect();
    const x = rect.left + rect.width / 2;
    const y = rect.top + rect.height / 2;
    if (!gameControlCenterVisible(frame, view, x, y)) return false;
    const top = documentObject.elementFromPoint?.(x, y);
    return top && top !== element && !element.contains(top) && !top.contains(element);
  });
  if (obscuredControl) {
    findings.push({
      code: 'GAME_CONTROL_OBSCURED',
      title: 'Game control is covered by another element',
      message: 'An enabled game control cannot receive input at its visible center point.',
      evidence: { source: 'game-input-audit', element: elementDescription(obscuredControl), viewportBand },
    });
  }
  const undersizedControl = auditControls.find(element => {
    if (cardLikeControl(element) || element.matches?.('a:not([role="button"])')) return false;
    const rect = effectiveControlTargetRect(element, view);
    return rect.width < 24 || rect.height < 24;
  });
  if (undersizedControl) {
    findings.push({
      code: 'GAME_INPUT_TARGET_TOO_SMALL',
      title: 'Game input target is too small',
      message: 'An interactive game control is smaller than the minimum reliable pointer target.',
      evidence: { source: 'game-input-audit', element: elementDescription(undersizedControl), minimumPixels: 24, viewportBand },
    });
  }
  const textOverflow = [...documentObject.querySelectorAll('[data-diagnostic-text-fit],.game-status,[class*="score"],[class*="status"]')]
    .slice(0, 80)
    .find(element => {
      if (!elementVisible(element, view)) return false;
      const style = view.getComputedStyle(element);
      const constrained = /hidden|clip/.test(`${style.overflowX} ${style.overflowY} ${style.overflow}`);
      return constrained && (element.scrollWidth > element.clientWidth + 2 || element.scrollHeight > element.clientHeight + 2);
    });
  if (textOverflow) {
    findings.push({
      code: 'GAME_TEXT_OVERFLOW',
      title: 'Game text overflows its container',
      message: 'Visible game text does not fit inside a container that hides overflow.',
      evidence: { source: 'game-visual-audit', element: elementDescription(textOverflow), clientWidth: textOverflow.clientWidth, scrollWidth: textOverflow.scrollWidth, clientHeight: textOverflow.clientHeight, scrollHeight: textOverflow.scrollHeight },
    });
  }
  const stretchedAsset = [...documentObject.images]
    .filter(image => elementVisible(image, view) && image.complete && image.naturalWidth > 0 && image.naturalHeight > 0)
    .find(image => {
      if (!image.matches?.('[data-diagnostic-asset-fit],[class*="card"] img,[class*="avatar"] img,[class*="board"] img')) return false;
      const rect = image.getBoundingClientRect();
      if (rect.width < 32 || rect.height < 32) return false;
      const style = view.getComputedStyle(image);
      if (!['fill', ''].includes(style.objectFit || '')) return false;
      const naturalRatio = image.naturalWidth / image.naturalHeight;
      const renderedRatio = rect.width / rect.height;
      return Math.abs(renderedRatio / naturalRatio - 1) > 0.22;
    });
  if (stretchedAsset) {
    findings.push({
      code: 'GAME_ASSET_STRETCHED',
      title: 'Game artwork is visibly stretched',
      message: 'A card, avatar, or board image is rendered at a substantially different aspect ratio from its source.',
      evidence: { source: 'game-asset-fit-audit', element: elementDescription(stretchedAsset), naturalWidth: stretchedAsset.naturalWidth, naturalHeight: stretchedAsset.naturalHeight },
    });
  }
  const duplicateId = [...documentObject.querySelectorAll('[id]')]
    .map(element => element.id).find((id, index, ids) => id && ids.indexOf(id) !== index);
  const unnamedControl = auditControls.find(element => {
    const name = element.getAttribute?.('aria-label') || element.getAttribute?.('title')
      || element.getAttribute?.('alt') || element.value || element.textContent;
    return !String(name || '').trim();
  });
  const unnamedDialog = [...documentObject.querySelectorAll('dialog[open],[role="dialog"]')]
    .find(element => elementVisible(element, view)
      && !String(element.getAttribute('aria-label') || '').trim()
      && !String(element.getAttribute('aria-labelledby') || '').trim());
  const missingAlt = [...documentObject.images].find(image => elementVisible(image, view)
    && !image.hasAttribute('alt') && image.getAttribute('role') !== 'presentation');
  if (duplicateId || unnamedControl || unnamedDialog || missingAlt) {
    const reason = duplicateId ? 'duplicate-id' : unnamedControl ? 'unnamed-control' : unnamedDialog ? 'unnamed-dialog' : 'missing-image-alt';
    const element = unnamedControl || unnamedDialog || missingAlt;
    findings.push({
      code: 'GAME_ACCESSIBILITY_CONTRACT_FAILED',
      title: 'Game accessibility contract failed',
      message: 'The game contains an objective keyboard or accessible-name defect.',
      evidence: { source: 'game-accessibility-audit', reason, duplicateId: duplicateId || null, element: element ? elementDescription(element) : null, viewportBand },
    });
  }
  return findings.slice(0, MAX_REPORTS_PER_MINUTE);
}

export const runtimeIssueCaptureContract = Object.freeze({
  createsOnlyGeneratedSchematicPixels: true,
  forbiddenKeyPattern: SENSITIVE_KEY,
  duplicateBackoffMs: DUPLICATE_BACKOFF_MS,
  maxReportsPerMinute: MAX_REPORTS_PER_MINUTE,
  finiteCollectionModes: Object.freeze(['off', 'errors-only', 'errors-and-warnings', 'verbose']),
  defaultCollectionMode: 'errors-only',
  engineeringDiagnosticsIndependent: true,
  observesEmbeddedGameFailures: true,
  objectiveGameVisualChecks: Object.freeze([
    'overlap', 'clipping', 'overflow', 'missing-images', 'hidden-required-controls', 'invalid-frame-size',
    'obscured-inputs', 'minimum-input-size', 'responsive-overflow', 'asset-aspect-ratio', 'avatar-play-area-overlap', 'accessibility-contracts',
  ]),
  gameStateChecks: Object.freeze(['state-invariants', 'duplicate-actions', 'stuck-actions', 'request-performance', 'render-performance', 'reload-integrity']),
  selfTestEnabled: true,
  excludedArcadeGames: Object.freeze([...EXCLUDED_ARCADE_GAMES]),
});
