(function () {
  'use strict';

  var SAFE_GAP = 9;
  var MIN_BOARD_FIT = 0.62;
  var FIT_STEP = 0.01;
  var MOVE_STEP = 2;
  var scheduledFrame = 0;
  var observedElements = new WeakSet();

  var seats = [
    { selector: '.cc-player-slot-bottom', direction: [0, 1] },
    { selector: '.cc-player-slot-lower-left', direction: [-0.707, 0.707] },
    { selector: '.cc-player-slot-upper-left', direction: [-1, 0] },
    { selector: '.cc-player-slot-top', direction: [0, -1] },
    { selector: '.cc-player-slot-upper-right', direction: [0.707, -0.707] },
    { selector: '.cc-player-slot-lower-right', direction: [1, 0] }
  ];

  function schedulePlacement() {
    if (scheduledFrame) {
      cancelAnimationFrame(scheduledFrame);
    }
    scheduledFrame = requestAnimationFrame(function () {
      scheduledFrame = 0;
      placePlayers();
    });
  }

  function ensureBoardLayer(stage) {
    var layer = stage.querySelector(':scope > .cc-board-geometry');
    if (!layer) {
      layer = document.createElement('div');
      layer.className = 'cc-board-geometry';
      stage.insertBefore(layer, stage.firstChild);
    }

    Array.prototype.slice.call(stage.children).forEach(function (child) {
      if (child !== layer && child.matches('.cc-board-field, .cc-route-layer, .cc-goal-guide, .cc-hole')) {
        layer.appendChild(child);
      }
    });

    return layer;
  }

  function movedRect(rect, dx, dy) {
    return {
      left: rect.left + dx,
      right: rect.right + dx,
      top: rect.top + dy,
      bottom: rect.bottom + dy,
      width: rect.width,
      height: rect.height
    };
  }

  function rectanglesTouch(a, b, gap) {
    return a.left < b.right + gap &&
      a.right > b.left - gap &&
      a.top < b.bottom + gap &&
      a.bottom > b.top - gap;
  }

  function touchesBoard(rect, holeRects) {
    return holeRects.some(function (holeRect) {
      return rectanglesTouch(rect, holeRect, SAFE_GAP);
    });
  }

  function findSafeShift(slot, direction, bounds, holeRects, outerScale) {
    var rect = slot.getBoundingClientRect();
    var maxTravel = Math.max(bounds.width, bounds.height);
    var previousDx = null;
    var previousDy = null;

    for (var travel = 0; travel <= maxTravel; travel += MOVE_STEP) {
      var dx = direction[0] * travel;
      var dy = direction[1] * travel;

      dx = Math.max(bounds.left - rect.left, Math.min(bounds.right - rect.right, dx));
      dy = Math.max(bounds.top - rect.top, Math.min(bounds.bottom - rect.bottom, dy));

      if (dx === previousDx && dy === previousDy) {
        break;
      }

      var candidate = movedRect(rect, dx, dy);
      if (!touchesBoard(candidate, holeRects)) {
        return {
          cssX: dx / outerScale,
          cssY: dy / outerScale,
          rect: candidate
        };
      }

      previousDx = dx;
      previousDy = dy;
    }

    return null;
  }

  function solveAtCurrentFit(stage, slots, directions) {
    var stageRect = stage.getBoundingClientRect();
    var cssWidth = parseFloat(getComputedStyle(stage).width) || stageRect.width;
    var outerScale = stageRect.width / cssWidth || 1;
    var bounds = {
      left: stageRect.left,
      right: stageRect.right,
      top: stageRect.top,
      bottom: stageRect.bottom,
      width: stageRect.width,
      height: stageRect.height
    };
    var holeRects = Array.prototype.map.call(stage.querySelectorAll('.cc-board-geometry .cc-hole:not(.cc-moving-marble)'), function (hole) {
      return hole.getBoundingClientRect();
    });
    var placements = [];

    for (var index = 0; index < slots.length; index += 1) {
      var placement = findSafeShift(slots[index], directions[index], bounds, holeRects, outerScale);
      if (!placement) {
        return null;
      }
      placements.push(placement);
    }

    for (var leftIndex = 0; leftIndex < placements.length; leftIndex += 1) {
      for (var rightIndex = leftIndex + 1; rightIndex < placements.length; rightIndex += 1) {
        if (rectanglesTouch(placements[leftIndex].rect, placements[rightIndex].rect, 4)) {
          return null;
        }
      }
    }

    return placements;
  }

  function observeElement(element) {
    if (!element || observedElements.has(element)) {
      return;
    }
    observedElements.add(element);
    resizeObserver.observe(element);
  }

  function placePlayers() {
    var stage = document.querySelector('.cc-stage');
    if (!stage) {
      return;
    }

    var layer = ensureBoardLayer(stage);
    var slots = [];
    var directions = [];

    seats.forEach(function (seat) {
      var slot = stage.querySelector(':scope > ' + seat.selector);
      if (slot) {
        slots.push(slot);
        directions.push(seat.direction);
      }
    });

    if (!slots.length || !layer.querySelector('.cc-hole')) {
      return;
    }

    slots.forEach(function (slot) {
      slot.style.removeProperty('--cc-player-shift-x');
      slot.style.removeProperty('--cc-player-shift-y');
    });
    layer.style.setProperty('--cc-auto-board-fit', '1');
    stage.offsetWidth;

    var placements = null;
    var acceptedFit = 1;

    for (var fit = 1; fit >= MIN_BOARD_FIT; fit -= FIT_STEP) {
      acceptedFit = Math.max(MIN_BOARD_FIT, fit);
      layer.style.setProperty('--cc-auto-board-fit', acceptedFit.toFixed(2));
      stage.offsetWidth;
      placements = solveAtCurrentFit(stage, slots, directions);
      if (placements) {
        break;
      }
    }

    if (!placements) {
      acceptedFit = MIN_BOARD_FIT;
      layer.style.setProperty('--cc-auto-board-fit', acceptedFit.toFixed(2));
      placements = solveAtCurrentFit(stage, slots, directions);
    }

    if (placements) {
      placements.forEach(function (placement, index) {
        slots[index].style.setProperty('--cc-player-shift-x', placement.cssX.toFixed(2) + 'px');
        slots[index].style.setProperty('--cc-player-shift-y', placement.cssY.toFixed(2) + 'px');
      });
      stage.dataset.playerClearance = 'verified';
      stage.dataset.autoBoardFit = acceptedFit.toFixed(2);
    } else {
      stage.dataset.playerClearance = 'limited';
    }

    observeElement(stage);
    observeElement(stage.closest('.cc-board-scroll'));
    slots.forEach(observeElement);
  }

  var resizeObserver = new ResizeObserver(schedulePlacement);
  var mutationObserver = new MutationObserver(function (mutations) {
    var needsPlacement = mutations.some(function (mutation) {
      if (mutation.type === 'childList') {
        return true;
      }
      var target = mutation.target;
      return target instanceof Element &&
        !target.closest('.cc-player-slot') &&
        target.matches('.cc-stage-scaler, .cc-stage-frame, .cc-board-scroll');
    });
    if (needsPlacement) {
      schedulePlacement();
    }
  });

  mutationObserver.observe(document.documentElement, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['class', 'style']
  });

  window.addEventListener('resize', schedulePlacement, { passive: true });
  document.addEventListener('click', function () {
    setTimeout(schedulePlacement, 0);
  }, true);
  document.addEventListener('change', schedulePlacement, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', schedulePlacement, { once: true });
  } else {
    schedulePlacement();
  }
}());
