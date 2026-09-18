import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

// Execute selected real shared-iframe functions with isolated transport/DOM
// adapters. This is a contract test, not a replacement for physical UI tests.
const parserModule = { exports: {} };
vm.runInNewContext(process.binding('natives')['internal/deps/acorn/acorn/dist/acorn'], { module: parserModule, exports: parserModule.exports });
const source = fs.readFileSync(new URL('../games/ocx-extension-game.js', import.meta.url), 'utf8');
const ast = parserModule.exports.parse(source, { ecmaVersion: 'latest', sourceType: 'module' });
const names = new Set(['withLoopbackRequestLock', 'gameFetch', 'gameSessionEnvelopeProblem', 'assertGameSessionEnvelope', 'readGameResponseJson', 'apiGet', 'apiPost', 'refreshSession', 'render', 'isTerminalSessionFailure', 'resetGameRecords', 'endUnavailableSession', 'stopSurfaceReachability', 'poll', 'startSessionConnection']);
const functions = ast.body.filter(node => node.type === 'FunctionDeclaration' && names.has(node.id.name));
assert.equal(functions.length, names.size, 'Every tested function must come from the production module.');
const selectedSource = functions.map(node => source.slice(node.start, node.end)).join('\n');
const failure = status => ({ ok: false, status, json: async () => ({ error: 'Fixture session is unavailable.', code: status === 503 ? 'MULTIPLAYER_GAME_DATABASE_BUSY' : 'MULTIPLAYER_GAME_ACCESS_DENIED' }) });

function fixture(response = () => failure(403)) {
    const state = { requests: [], notices: [], timers: new Map(), nextTimer: 0, audioPauses: 0, automaticStops: 0, removedPrivateContent: false, status: { textContent: '' } };
    const sandbox = vm.createContext({
        URLSearchParams, AbortController, DOMException, console,
        context: { gameSessionId: 'old-fixture-game', sessionId: 'fixture-room', participantId: 12, joinToken: 'fixture-token', gameKey: 'fixture-game', csrf: 'fixture-csrf' },
        location: { origin: 'https://corechat.example', hostname: 'corechat.example' },
        document: { hidden: false, body: { dataset: {} } }, navigator: {},
        optionCategory: (_key, fallback) => fallback,
        window: { parent: { postMessage: (data, origin) => state.notices.push({ data, origin }) } },
        root: { replaceChildren: () => { state.removedPrivateContent = true; } },
        el: id => id === 'status' ? state.status : null,
        fetch: async (url, options) => { state.requests.push({ url, options }); return response(url, options); },
        clearTimeout: id => state.timers.delete(id),
        setTimeout: (callback, delay) => { const id = ++state.nextTimer; state.timers.set(id, { callback, delay }); return id; },
        clearSpadesAutomaticAction: () => { state.automaticStops++; },
        clearBlackjackAutomaticAction: () => { state.automaticStops++; },
        clearUnoAutomaticAction: () => { state.automaticStops++; },
        pauseAudio: () => { state.audioPauses++; },
    });
    vm.runInContext(`
        let terminalSessionError = null, session = { stateVersion: 1 }, records = null, options = null;
        let recordsRequestSerial = 0, recordsScope = '', recordsRequest = null;
        let recordsTerminalKey = '', recordsError = '';
        let optionsMutationRevision = 0, gameSurfaceVisible = true, pollTimer = 0;
        let battleshipSelectionTimer = 0, chessClockRenderTimer = 0, chessClockDeadlineTimer = 0;
        let sharedLifecycleDeadlineTimer = 0, sharedLifecycleRenderTimer = 0, builtInBattleshipDragState = null;
        let surfaceReachabilityObserver = null;
        const surfaceReachabilityFrames = new Set();
        const connectionEpoch = 'fixture-epoch';
        const LOOPBACK_REQUEST_LOCK = 'fixture-lock', LOOPBACK_HOST = /^(?:127(?:\\.\\d{1,3}){3}|localhost)$/i;
        const LOOPBACK_REQUEST_LOCK_WAIT_MS = 2000, GAME_REQUEST_TIMEOUT_MS = 8000;
        ${selectedSource}
        globalThis.snapshot = () => ({ terminal: !!terminalSessionError, session, gameSurfaceVisible });
    `, sandbox);
    return { state, sandbox };
}
const results = [];
async function check(name, run) {
    try { await run(); results.push({ name, status: 'PASS' }); }
    catch (error) { results.push({ name, status: 'FAIL', message: error.message }); }
}
for (const status of [401, 403, 404, 410]) {
    await check(`session GET ${status} preserves metadata and stops polling/private content`, async () => {
        const { state, sandbox } = fixture(() => failure(status));
        await assert.rejects(sandbox.apiGet('session'), error => error.httpStatus === status && error.code === 'MULTIPLAYER_GAME_ACCESS_DENIED');
        await sandbox.poll();
        assert.equal(sandbox.snapshot().terminal, true);
        assert.equal(sandbox.snapshot().session, null);
        assert.equal(state.timers.size, 0);
        assert.equal(state.removedPrivateContent, true);
        assert.equal(state.audioPauses, 1);
        assert.equal(state.automaticStops, 3);
        assert.equal(state.notices.length, 1);
        assert.equal(state.notices[0].origin, 'https://corechat.example');
        assert.equal(JSON.stringify(state.notices).includes('fixture-token'), false);
        const count = state.requests.length;
        await sandbox.poll();
        await assert.rejects(sandbox.apiPost('disconnect', {}, { keepalive: true }));
        await assert.rejects(sandbox.apiPost('extension-action', { type: 'move' }));
        assert.equal(state.requests.length, count, 'Terminal session must not send more transport requests.');
        sandbox.render();
    });
}
await check('503 session failures remain retryable and preserve the active session', async () => {
    const { state, sandbox } = fixture(() => failure(503));
    await sandbox.poll();
    assert.equal(sandbox.snapshot().terminal, false);
    assert.notEqual(sandbox.snapshot().session, null);
    assert.equal(state.timers.size, 1);
    assert.equal(state.notices.length, 0);
});
await check('terminal reconnect does not fall through to session polling', async () => {
    const { state, sandbox } = fixture();
    await sandbox.startSessionConnection();
    assert.equal(state.requests.length, 1);
    assert.equal(JSON.parse(state.requests[0].options.body).action, 'reconnect');
    assert.equal(sandbox.snapshot().terminal, true);
    assert.equal(state.timers.size, 0);
});
await check('transient reconnect still attempts session recovery', async () => {
    const { state, sandbox } = fixture(() => failure(503));
    await sandbox.startSessionConnection();
    assert.ok(state.requests.length > 1);
    assert.equal(sandbox.snapshot().terminal, false);
    assert.equal(state.timers.size, 1);
});
await check('late successful refresh cannot restore a revoked session', async () => {
    const pending = [];
    const { state, sandbox } = fixture(() => new Promise(resolve => pending.push(resolve)));
    const refreshing = sandbox.refreshSession();
    sandbox.endUnavailableSession({ httpStatus: 403, code: 'MULTIPLAYER_GAME_ACCESS_DENIED' });
    for (const resolve of pending) resolve({
        ok: true,
        status: 200,
        json: async () => ({
            publicId: 'old-fixture-game',
            status: 'active',
            settingsSha256: 'fixture-settings',
            stateVersion: 99,
            members: [],
            state: {},
        }),
    });
    await refreshing;
    assert.equal(sandbox.snapshot().session, null);
    assert.equal(state.notices.length, 1);
});
await check('request waiting for loopback lock cannot start after revocation', async () => {
    const { state, sandbox } = fixture();
    let unlock;
    sandbox.location.hostname = '127.0.0.27';
    sandbox.navigator.locks = { request: (_name, _options, callback) => new Promise((resolve, reject) => { unlock = () => Promise.resolve().then(callback).then(resolve, reject); }) };
    const waiting = sandbox.apiGet('session');
    sandbox.endUnavailableSession({ httpStatus: 403, code: 'MULTIPLAYER_GAME_ACCESS_DENIED' });
    const rejected = assert.rejects(waiting, error => error.httpStatus === 403);
    await unlock();
    await rejected;
    assert.equal(state.requests.length, 0);
});
await check('abandoned loopback lock falls back without stranding the request', async () => {
    const { state, sandbox } = fixture();
    sandbox.location.hostname = '127.0.0.27';
    sandbox.navigator.locks = {
        request: (_name, options) => new Promise((_resolve, reject) => {
            options.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted lock wait'), { name: 'AbortError' })), { once: true });
        }),
    };
    const waiting = sandbox.apiGet('session');
    const lockTimer = [...state.timers.values()].find(timer => timer.delay === 2000);
    assert.ok(lockTimer, 'Loopback lock acquisition must have a bounded timer.');
    lockTimer.callback();
    await assert.rejects(waiting, error => error.httpStatus === 403);
    assert.equal(state.requests.length, 1, 'The bounded fallback must execute the request exactly once.');
});
await check('framework fetch timeout releases a hung request with a retryable error', async () => {
    const { state, sandbox } = fixture((_url, options) => new Promise((_resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted fetch'), { name: 'AbortError' })), { once: true });
    }));
    const waiting = sandbox.apiGet('session');
    const fetchTimer = [...state.timers.values()].find(timer => timer.delay === 8000);
    assert.ok(fetchTimer, 'Framework fetch must have a bounded timer.');
    fetchTimer.callback();
    await assert.rejects(waiting, error => error.code === 'GAME_REQUEST_TIMEOUT' && error.retryable === true);
    assert.equal(state.requests.length, 1);
});
console.log(JSON.stringify({ finding: 'F002', scope: 'production shared iframe functions with isolated adapters', cases: results.length, passed: results.filter(row => row.status === 'PASS').length, results }, null, 2));
if (results.some(row => row.status !== 'PASS')) process.exitCode = 1;
