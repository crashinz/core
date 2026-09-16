import { gameViewStorage } from "./game-view-storage.js?v=1dd11e938aa8";
const heightFitOverrides = new Map();

export function viewerHeightFitEnabled(gameId, fallback = false) {
  if (heightFitOverrides.has(gameId)) return heightFitOverrides.get(gameId);
  try {
    const saved = gameViewStorage.getItem("corechat:" + gameId + ":height-fit");
    if (saved === "true" || saved === "false") return saved === "true";
  } catch {}
  return Boolean(fallback);
}

export function setViewerHeightFit(gameId, enabled) {
  const value = Boolean(enabled);
  heightFitOverrides.set(gameId, value);
  try { gameViewStorage.setItem("corechat:" + gameId + ":height-fit", String(value)); } catch {}
}

export function setStyleIfChanged(style, name, value, priority = "") {
  if (!style || style.getPropertyValue(name) === value
    && style.getPropertyPriority(name) === priority) return false;
  style.setProperty(name, value, priority);
  return true;
}

export function availableGameViewportHeight(view = window) {
  let height = view === view.top ? Number(view.visualViewport?.height || view.innerHeight || 0) : 0;
  try {
    const parentHeight = Number(view.top.visualViewport?.height || view.top.innerHeight || 0);
    const frame = view.frameElement;
    const stage = frame?.closest(".room-stage");
    if (stage?.clientHeight > 0) {
      const inset = frame.getBoundingClientRect().top - stage.getBoundingClientRect().top
        - Number(stage.clientTop || 0) + Number(stage.scrollTop || 0);
      if (Number.isFinite(inset)) height = Math.max(0,
        Math.min(stage.clientHeight, parentHeight || stage.clientHeight) - Math.max(0, inset));
    } else if (!frame) height = parentHeight;
  } catch {}
  return Number.isFinite(height) ? height : 0;
}

export function viewportHeightFitScale(height, budget, enabled, requestedScale = 1, minimumScale = 0.75) {
  const requested = Number.isFinite(requestedScale) && requestedScale > 0 ? requestedScale : 1;
  const fitted = enabled && height > 0 && Number.isFinite(budget)
    ? Math.max(minimumScale, Math.min(1, Math.max(0, budget) / height)) : 1;
  return fitted * requested;
}

// Size the existing composition as one unit. The outer room stage supplies the
// height budget; the auto-growing game iframe must never supply its own budget.
export function installViewportHeightFit(board, host, settings = {}) {
  if (!board || !host) return () => {};
  const documentOwner = board.ownerDocument;
  const view = documentOwner.defaultView;
  const originalStyles = new Map(["width", "max-width", "zoom"].map(name =>
    [name, { value: board.style.getPropertyValue(name), priority: board.style.getPropertyPriority(name) }]));
  const originalData = new Map(["heightFit", "heightFitEnabled", "viewerScale", "effectiveViewerScale", "heightFitReadability"]
    .map(name => [name, board.dataset[name]]));
  let disposed = false;
  let pending = 0;
  let parentView = null;
  const read = (value, fallback) => typeof value === "function" ? value() : value ?? fallback;
  const restoreStyles = () => {
    for (const [name, previous] of originalStyles) {
      if (previous.value) board.style.setProperty(name, previous.value, previous.priority);
      else board.style.removeProperty(name);
    }
  };
  const sync = () => {
    pending = 0;
    if (disposed || !board.isConnected || !host.isConnected || documentOwner.hidden) return;
    const enabled = Boolean(read(settings.enabled, false));
    const requested = Number(read(settings.scale, 1));
    const maximumWidth = Number(read(settings.maximumWidth, 0));
    board.dataset.heightFitEnabled = String(enabled);
    board.dataset.viewerScale = String(requested);
    const hasReadabilityFloor = settings.minimumScale !== undefined;
    if (hasReadabilityFloor) board.dataset.heightFitReadability = enabled ? "pending" : "off";
    if (!enabled && requested === 1 && maximumWidth <= 0) {
      if (board.dataset.heightFit !== "off") restoreStyles();
      board.dataset.effectiveViewerScale = "1";
      board.dataset.heightFit = "off";
      return;
    }
    const hostWidth = Number(host.clientWidth || 0);
    const width = Math.min(hostWidth, maximumWidth > 0 ? maximumWidth : hostWidth);
    if (width <= 0) return;
    setStyleIfChanged(board.style, "width", width + "px");
    setStyleIfChanged(board.style, "max-width", "none");
    const naturalHeight = Number(board.offsetHeight || 0);
    if (naturalHeight <= 0) return;
    const viewport = availableGameViewportHeight(view);
    const inset = Math.max(0, host.getBoundingClientRect().top + Number(view.scrollY || 0));
    const reserve = Math.max(24, Number(read(settings.reserveBottom, 24)) || 24);
    const budget = Math.max(0, viewport - inset - reserve);
    const requestedMinimum = Number(read(settings.minimumScale, 0.75));
    const minimumScale = Number.isFinite(requestedMinimum) && requestedMinimum > 0
      ? Math.max(0.75, requestedMinimum) : 0.75;
    const widthScaleLimit = Number(host.clientWidth || 0) / width;
    const scale = Math.min(widthScaleLimit,
      viewportHeightFitScale(naturalHeight, budget, enabled && viewport > 0, requested, minimumScale));
    const roundedScale = Math.round(scale * 10000) / 10000;
    let effectiveScale = roundedScale * width > Number(host.clientWidth || 0)
      ? Math.floor(widthScaleLimit * 10000) / 10000 : roundedScale;
    if (hasReadabilityFloor && enabled && viewport > 0) {
      const normalizedRequested = Number.isFinite(requested) && requested > 0 ? requested : 1;
      const requiredScale = minimumScale * normalizedRequested;
      const roundedMinimum = Math.ceil(requiredScale * 10000) / 10000;
      if (roundedMinimum <= widthScaleLimit) effectiveScale = Math.max(effectiveScale, roundedMinimum);
      board.dataset.heightFitReadability = effectiveScale >= requiredScale ? "readable" : "width-limited";
    } else if (hasReadabilityFloor && enabled) {
      board.dataset.heightFitReadability = "viewport-unavailable";
    }
    const value = String(effectiveScale);
    setStyleIfChanged(board.style, "zoom", value);
    board.dataset.effectiveViewerScale = value;
    const measuredScale = hasReadabilityFloor ? effectiveScale : scale;
    board.dataset.heightFit = !enabled ? "off" : viewport > 0 && naturalHeight * measuredScale <= budget + 1 ? "fit" : "scroll";
  };
  const schedule = () => { if (!disposed && !pending) pending = view.requestAnimationFrame(sync); };
  const observer = typeof view.ResizeObserver === "function" ? new view.ResizeObserver(schedule) : null;
  observer?.observe(board);
  observer?.observe(host);
  if (documentOwner.body) observer?.observe(documentOwner.body);
  view.addEventListener("resize", schedule, { passive: true });
  view.visualViewport?.addEventListener("resize", schedule, { passive: true });
  documentOwner.addEventListener("visibilitychange", schedule);
  try {
    if (view.top !== view) {
      parentView = view.top;
      parentView.addEventListener("resize", schedule, { passive: true });
      parentView.visualViewport?.addEventListener("resize", schedule, { passive: true });
      const stage = view.frameElement?.closest(".room-stage");
      if (stage) observer?.observe(stage);
    }
  } catch {}
  const cleanup = () => {
    if (disposed) return;
    disposed = true;
    if (pending) view.cancelAnimationFrame(pending);
    observer?.disconnect();
    view.removeEventListener("resize", schedule);
    view.visualViewport?.removeEventListener("resize", schedule);
    documentOwner.removeEventListener("visibilitychange", schedule);
    view.removeEventListener("pagehide", cleanup);
    try {
      parentView?.removeEventListener("resize", schedule);
      parentView?.visualViewport?.removeEventListener("resize", schedule);
    } catch {}
    restoreStyles();
    for (const [name, value] of originalData) {
      if (value === undefined) delete board.dataset[name];
      else board.dataset[name] = value;
    }
  };
  view.addEventListener("pagehide", cleanup, { once: true });
  sync();
  documentOwner.fonts?.ready?.then(schedule).catch(() => {});
  return cleanup;
}
