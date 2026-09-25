const box = (x, y, width, height) => ({ x, y, width, height });

function deepFreeze(value) {
  if (value && typeof value === "object" && !Object.isFrozen(value)) {
    Object.freeze(value);
    for (const child of Object.values(value)) deepFreeze(child);
  }
  return value;
}

// Source-space geometry is measured in the immutable native canvas for each
// original surface. Renderers scale this one map; CSS percentages are not an
// independent geometry owner.
// Checkers, Chess and the point games use U resources for lit/enabled FX
// and D resources for dim/disabled FX; hover stays within the same state.
export const CLASSIC_SOURCE_MAPS = deepFreeze({
  checkers: {
    canvas: { width: 460, height: 320 },
    orientation: "viewer-relative-source",
    gridRows: [
      { x: 49.5, step: 33.85, y: 12, width: 37, height: 36 },
      { x: 45, step: 34.75, y: 38, width: 37, height: 36 },
      { x: 39, step: 36, y: 66, width: 38, height: 38 },
      { x: 35, step: 37, y: 96, width: 39, height: 39 },
      { x: 30.5, step: 38, y: 129, width: 40, height: 40 },
      { x: 26, step: 38.8, y: 163, width: 41, height: 40 },
      { x: 23.25, step: 39.6, y: 200, width: 42, height: 43 },
      { x: 18, step: 41.2, y: 237, width: 42, height: 43 },
    ],
    // The OCX ships four perspective sizes. The far two rows use size 4;
    // each nearer pair advances toward the full-size foreground asset.
    pieceSizeByRow: [4, 4, 3, 3, 2, 2, 1, 1],
    // Natural source-pixel bounds measured from each authenticated OCX piece
    // level.  Moving/captured pieces use these boxes rather than stretching to
    // the full logical perspective cell.
    pieceAssetDimensions: {
      1: { width: 45, height: 50 },
      2: { width: 41, height: 45 },
      3: { width: 40, height: 40 },
      4: { width: 38, height: 38 },
    },
      destinationCueAsset: "../../assets/images/checkers-ocx-crosshair-cue.svg",
    destinationCueMeasuredBounds: box(165, 144, 27, 19),
    // The logical perspective cell includes its front face. The source cue is
    // optically centered on the playable black top face at 64% cell height.
    destinationCueOpticalCenter: { x: 0.5, y: 0.64 },
    avatarFrames: [box(372, 207, 46, 102), box(341, 9, 40, 96)],
    // Measured portrait interiors inside the two perspective lavatar source
    // objects. The earlier 24 x 32 boxes were visibly underfilled/offset.
    avatarWells: [box(381, 222, 26, 35), box(348, 24, 26, 35)],
    controls: {
      // Registered independently against the unchanged pixels in the
      // immutable 460 x 320 board.  The prior observation placed these
      // opaque source sprites over one another and exposed neighboring crop
      // fragments; all recorded rest/hover/off frames resolve to these same
      // non-overlapping source anchors.
      soundFx: box(387, 56, 40, 72),
      visualFx: box(351, 106, 36, 86),
      drawTab: box(448, 34, 12, 70),
      resignTab: box(448, 161, 12, 109),
      // The 12 px rectangles above are the normally visible clipped handles.
      // Owner clarification and the F5F1... private recording at approximately
      // 6.8-8.6 s fix DRAW's direction: the authenticated 23 px source tab
      // projects inward-left from the immutable right edge.  The accessible
      // compact confirmation rail follows that same contained direction; its
      // full open region ends at the 460 px canvas edge instead of extending
      // beyond it.
      drawDrawer: box(382, 34, 78, 70),
      // DFC56... directly authenticates RESIGN's right-edge rest, inward-left
      // reveal at approximately 4.5-5.5 s, and retraction by approximately
      // 6.0 s.  Its compact confirmation rail therefore follows the same
      // fixed-source-edge containment rule as DRAW without claiming that the
      // private source pixels themselves are deployable.
      resignDrawer: box(382, 161, 78, 109),
    },
    controlSprites: {
      soundFx: { nativeSize: [40, 72], on: { rest: "gif-sfx-ur", hover: "gif-sfx-uh" }, off: { rest: "gif-sfx-dr", hover: "gif-sfx-dh" } },
      visualFx: { nativeSize: [36, 86], on: { rest: "gif-gfx-ur", hover: "gif-gfx-uh" }, off: { rest: "gif-gfx-dr", hover: "gif-gfx-dh" } },
    },
    motion: {
      checkerSlide: { durationMs: 760, easing: "cubic-bezier(.22,.61,.36,1)" },
      movingSurfaces: {
        a: { manBase: 504, kingBase: 512 },
        b: { manBase: 500, kingBase: 508 },
      },
      capture: {
        // Native callbacks: lift -> (landing + missile) -> explosion.
        frameDurationMs: 80,
        lift: { frameCount: 6, durationMs: 480, height: 40 },
        landing: { frameCount: 6, durationMs: 480 },
        projectile: {
          slots: { a: "bitmap-524", b: "bitmap-525" },
          naturalSize: { width: 5, height: 11 },
          delayMs: 480,
          durationMs: 320,
          frameCount: 4,
          offsetX: 17,
          startOffsetY: -38,
          endOffsetY: -20,
        },
        explosion: {
          slot: "bitmap-526",
          frameWidth: 45,
          frameHeight: 48,
          frameCount: 16,
          frameDurationMs: 80,
          durationMs: 1280,
          offsetX: -5,
          offsetY: -6,
        },
      },
      promotion: {
        frameDurationMs: 80,
        strips: {
          "bitmap-520": { side: "a", perspective: "near", frameWidth: 45, frameHeight: 50, frameCount: 21 },
          "bitmap-521": { side: "a", perspective: "far", frameWidth: 38, frameHeight: 38, frameCount: 20 },
          "bitmap-522": { side: "b", perspective: "near", frameWidth: 45, frameHeight: 50, frameCount: 20 },
          "bitmap-523": { side: "b", perspective: "far", frameWidth: 38, frameHeight: 38, frameCount: 20 },
        },
      },
      // Decisive terminal results use the exact square-specific GIF_DISCO
      // top face assigned to each source square. These assets are never used
      // as capture projectile or explosion frames.
      decisiveTerminal: {
        sourceFps: 30,
        sourceStartFrame: 265,
        sourceLastFrame: 336,
        sourceSettledFrame: 337,
        durationMs: 2400,
        assetDimensions: {
          "01": { width: 34, height: 26 }, "03": { width: 34, height: 26 },
          "05": { width: 34, height: 25 }, "07": { width: 34, height: 25 },
          "10": { width: 37, height: 27 }, "12": { width: 37, height: 28 },
          "14": { width: 34, height: 27 }, "16": { width: 35, height: 28 },
          "21": { width: 37, height: 28 }, "23": { width: 35, height: 28 },
          "25": { width: 36, height: 28 }, "27": { width: 37, height: 28 },
          "30": { width: 40, height: 30 }, "32": { width: 38, height: 31 },
          "34": { width: 36, height: 31 }, "36": { width: 38, height: 32 },
          "41": { width: 40, height: 33 }, "43": { width: 38, height: 32 },
          "45": { width: 37, height: 32 }, "47": { width: 39, height: 31 },
          "50": { width: 43, height: 34 }, "52": { width: 40, height: 33 },
          "54": { width: 38, height: 34 }, "56": { width: 39, height: 34 },
          "61": { width: 43, height: 37 }, "63": { width: 41, height: 36 },
          "65": { width: 40, height: 34 }, "67": { width: 40, height: 35 },
          "70": { width: 45, height: 38 }, "72": { width: 40, height: 38 },
          "74": { width: 41, height: 40 }, "76": { width: 43, height: 38 },
        },
        phases: [
          { row: 4, column: 1, frames: 6 },
          { row: 0, column: 1, frames: 6 },
          { row: 1, column: 2, frames: 5 },
          { row: 0, column: 3, frames: 6 },
          { row: 3, column: 2, frames: 5 },
          { row: 5, column: 2, frames: 6 },
          { row: 3, column: 6, frames: 6 },
          { row: 5, column: 4, frames: 5 },
          { row: 7, column: 2, frames: 6 },
          { row: 3, column: 4, frames: 5 },
          { row: 6, column: 3, frames: 6 },
          { row: 5, column: 4, frames: 6 },
          { row: 0, column: 1, frames: 4 },
        ],
      },
    },
  },
  chess: {
    canvas: { width: 460, height: 320 },
    orientation: "viewer-relative-source",
    boardSlots: {
      "white-viewer": "classic-board-alternate",
      "black-viewer": "classic-board",
      "spectator-stable-white": "classic-board-alternate",
    },
    grid: {
      rowEdges: [18, 52, 83, 115, 148, 185, 225, 266, 309],
      topLeft: 69,
      topRight: 354,
      bottomLeft: 23,
      bottomRight: 392,
    },
    pieceSizeByRow: [4, 4, 3, 3, 2, 2, 1, 1],
    // Exact selected/highlight GIF dimensions from the static resource audit.
    // Every source family shares the level baseline except the three literal
    // white-piece exceptions below. Motion uses these source pixels before it
    // starts; it must never wait on a destination image load or collapse the
    // tall piece into the board-cell rectangle.
    selectedPieceNaturalSizes: {
      byLevel: {
        1: { width: 36, height: 69 },
        2: { width: 35, height: 65 },
        3: { width: 32, height: 58 },
        4: { width: 28, height: 53 },
      },
      bySlot: {
        "gif-k-w-h-2": { width: 35, height: 64 },
        "gif-kn-w-h-1": { width: 36, height: 67 },
        "gif-r-w-h-2": { width: 35, height: 64 },
      },
    },
    // Exact RT_CURSOR 1 pixels, statically decoded without loading the OCX.
    // The transparent 32 x 32 cursor has a (16,14) hotspot and a 25 x 23
    // visible horseshoe at (3,4); it replaces the rejected constructed arcs.
      destinationCueAsset: "../../assets/images/chess-ocx-horseshoe-cue.png",
    destinationCueNaturalSize: { width: 32, height: 32 },
    destinationCueVisibleBounds: box(3, 4, 25, 23),
    destinationCueHotspot: { x: 16, y: 14 },
    destinationCueMeasuredBounds: box(258, 117, 28, 25),
    // Exact placements of the two original portrait source objects, ordered
    // White/bottom-right then Black/top-left. Static border registration of
    // the exact 35 x 39 Black frame against the immutable 460 x 320 board
    // resolves to (8,31); the former (14,32) mapping was six source pixels
    // right and one pixel low, outside the small painted player slot.
    avatarFrames: [box(412, 224, 36, 39), box(8, 31, 35, 39)],
    // The authenticated avatar fills only the inner portrait opening; the
    // surrounding original frame pixels remain visible.
    avatarWells: [box(416, 228, 28, 31), box(12, 35, 27, 31)],
    controls: {
      // The four DRAW and four RSGN source files are complete 91 x 39 board
      // patches.  Independent native-pixel registration against the intact
      // 460 x 320 board resolves to these two positions.  They must never be
      // stretched over the larger painted shield regions.
      draw: box(367, 5, 91, 39),
      // The Sound FX and Visual FX state resources are independent 35 x 74
      // complete source strips.  Static native-pixel registration places
      // Visual on the left and Sound on the right, both at y=95.  The former
      // 61 x 60/61 mapping stretched and vertically stacked the strips,
      // repainting both labels twice over neighbouring board art.
      soundFx: box(420, 95, 35, 74),
      visualFx: box(384, 95, 35, 74),
      resign: box(367, 46, 91, 39),
    },
    controlSprites: {
      soundFx: { nativeSize: [35, 74], on: { rest: "gif-sfx-ur", hover: "gif-sfx-uh" }, off: { rest: "gif-sfx-dr", hover: "gif-sfx-dh" } },
      visualFx: { nativeSize: [35, 74], on: { rest: "gif-gfx-ur", hover: "gif-gfx-uh" }, off: { rest: "gif-gfx-dr", hover: "gif-gfx-dh" } },
    },
    actionSprites: {
      draw: { nativeSize: [91, 39], rest: "gif-draw-r", hover: "gif-draw-h", pressed: "gif-draw-p", disabled: "gif-draw-d" },
      resign: { nativeSize: [91, 39], rest: "gif-rsgn-r", hover: "gif-rsgn-h", pressed: "gif-rsgn-p", disabled: "gif-rsgn-d" },
    },
    motion: {
      pieceSlide: { durationMs: 1260, easing: "cubic-bezier(.2,.72,.3,1)" },
      // Optional original 500-539 strips contain the complete sinking piece.
      // Older packs retain the fragment fallback and its existing timing.
      nativeCapture: {
        baseByPiece: { B:500, N:508, P:516, Q:524, R:532 },
        frameCount:14, frameDurationMs:80, durationMs:1120,
        frameSizes:[{width:36,height:69},{width:35,height:65},{width:32,height:58},{width:28,height:53}],
        rowYOffset:[2,3,3,2,3,2,6,0],
      },
      nativeKing: {
        frameDurationMs:80,
        bitmapHeights:[1351,1274,1217,1059,1311,1299,1223,1116],
        loopStarts:[7,7,8,8,8,9,9,9],
      },
      captureFall: {
        durationMs: 1000,
        frameDurationMs: 100,
        holeSize: { width: 22, height: 18 },
        fragmentSize: { width: 14, height: 14 },
        frameSlotsByPiece: {
          P: ["1", "2", "3", "4", "5", "6", "7", "8"],
          B: ["1", "2"],
          N: ["1", "2"],
          R: ["1", "2"],
          Q: [""],
        },
      },
      checkmateFlag: {
        // Independently traced against the literal changed-pixel union in all
        // 256 frames of 2026-08-06 18-46-38.mp4. The private recording is
        // source authority only; none of its bytes are shipped by this asset.
        region: box(382, 98, 78, 70),
      stripAsset: "../../assets/images/chess-ocx-checkmate-source-traced-strip.svg",
        frameCount: 19,
        frameDurationMs: 150,
        startDelayMs: 600,
        durationMs: 3450,
        sourceFrames: { start: 100, end: 184, fps: 30 },
        persistFinal: false,
      },
    },
  },
  "acey-deucy": {
    canvas: { width: 500, height: 320 },
    // Acey Deucy and Backgammon embed byte-identical RT_CURSOR 1 resources.
    // This exact 32 x 32, 4bpp cursor is decoded statically without loading
    // either OCX; its visible pixels occupy (5,5)-(25,25), with hotspot (15,15).
      destinationCueAsset: "../../assets/images/point-game-ocx-legal-move-cue.png",
    destinationCueNaturalSize: { width: 32, height: 32 },
    destinationCueVisibleBounds: box(5, 5, 21, 21),
    destinationCueHotspot: { x: 15, y: 15 },
    destinationCueResourceSha256: "B2A97A056DEC993896AFF22AC533FAE75031318DEAA06CBA6526C385B7991B94",
    pointX: [42, 70, 97, 125, 152, 181, 236, 265, 292, 320, 348, 375],
    // Same logical0..23 track as Backgammon: the lower row returns right-to-left.
    pointOrder: [0,1,2,3,4,5,6,7,8,9,10,11,11,10,9,8,7,6,5,4,3,2,1,0],
    pointRows: [box(0, 8, 25, 132), box(0, 180, 25, 132)],
    bar: box(206, 7, 29, 305),
    reserves: [box(469, 20, 27, 129), box(469, 176, 29, 137)],
    borneOff: [box(4, 206, 27, 108), box(4, 46, 27, 113)],
    // The Acey Deucy source uses the same narrow side-view checker resources
    // and alternating rack notches as Backgammon once a checker bears off.
    borneOffCheckers: {
      slots: ["gif-w-s", "gif-b-s"],
      nativeSizes: [[23, 5], [24, 5]],
      // Native lower/upper baselines:305 and148; both advance upward by7.
      edgeInset: 4,
      edgeInsets: [4, 6],
      stackStep: 7,
      notchLeftInsets: [[0, 2], [2, 0]],
    },
    avatarFrames: [box(0, 163, 35, 42), box(0, 4, 35, 41)],
    avatarWells: [box(5, 168, 25, 33), box(5, 9, 25, 32)],
    // The occupied source recordings retain the same 24 x 32 top-left
    // portrait footprint as the homologous Backgammon source objects. The
    // remaining one-pixel right/bottom edge is neutral source-panel backing.
    avatarImageBoxes: [box(5, 168, 24, 32), box(5, 9, 24, 32)],
    diceSlots: [box(238, 142, 20, 20), box(266, 142, 20, 20)],
    controls: {
      // The three permitted OCX sprites tile the original 49 px control
      // column at their native dimensions.  The former 38 x 104/112 boxes
      // were implementation-derived outer regions and visibly stretched the
      // source pixels over neighbouring board art.
      visualFx: box(411, 0, 49, 119),
      roll: box(412, 121, 44, 78),
      soundFx: box(411, 201, 49, 119),
    },
    controlSprites: {
      soundFx: { nativeSize: [49, 119], on: { rest: "gif-sfx-ur", hover: "gif-sfx-uh" }, off: { rest: "gif-sfx-dr", hover: "gif-sfx-dh" } },
      visualFx: { nativeSize: [49, 119], on: { rest: "gif-gfx-ur", hover: "gif-gfx-uh" }, off: { rest: "gif-gfx-dr", hover: "gif-gfx-dh" } },
    },
    actionSprites: {
      roll: { nativeSize: [44, 78], rest: "gif-roll-r", hover: "gif-roll-h", pressed: "gif-roll-p", disabled: "gif-roll-d" },
    },
    motion: {
      checkerSlide: { durationMs: 620, easing: "cubic-bezier(.22,.61,.36,1)" },
      hitToBar: { moverDurationMs: 620, capturedDelayMs: 410, capturedDurationMs: 620 },
      // Both original OCXs select the same bear-off strips and animation parameters.
      bearOff: { durationMs: 640, frameDurationMs: 80, frameCount: 8 },
      // The approved static Acey Deucy audit owns an immediate authoritative
      // resolution for every no-legal path. Do not invent Backgammon's timed
      // blocked-roll panel, a delay, or a generic failure effect here.
      noLegalMove: { ticks: 0, tickMs: 0, durationMs: 0, presentation: "source-immediate" },
      nativeWin: {
        frameDurationMs:80, durationMs:5600,
        baseSlots:[515,519,519,523,527],
        frames:[{width:60,height:36,count:11},{width:80,height:38,count:10},{width:80,height:38,count:10},{width:77,height:57,count:9},{width:51,height:57,count:30}],
        // Acey's source board has a fixed orientation: white travels across
        // the lower row, black across the upper. The two extra variants are
        // imported for the original opposite orientation, not CSS-mirrored.
        positions:{white:[[2,190],[38,190],[93,190],[150,190],[196,190]],black:[[4,32],[40,32],[95,32],[152,32],[198,32]]},
      },
      win: {
        // Acey Deucy and the original Backgammon surface share the authenticated
        // W-S/B-S trail sprites, but retain independent canvas registration and
        // terminal state ownership. The path stays inside Acey's lower playable
        // source region and never crosses either right-edge reserve well.
        region: box(34, 192, 208, 52),
        durationMs: 4300,
        easing: "linear",
        checkerSize: 25,
        trailSize: { width: 16, height: 9 },
        trailOffset: { x: -12, y: 8 },
        checkerPath: [
          { phase: 0, x: 49, y: 209 },
          { phase: .08, x: 64, y: 210 },
          { phase: .24, x: 96, y: 210 },
          { phase: .38, x: 120, y: 210 },
          { phase: .55, x: 147, y: 211 },
          { phase: .69, x: 185, y: 212 },
          { phase: .82, x: 220, y: 212 },
        ],
        phases: [0, .08, .24, .38, .55, .69, .82, .88, .94, 1],
      },
    },
  },
  "backgammon-first-party": {
    canvas: { width: 460, height: 320 },
    // Byte-identical to the Acey Deucy RT_CURSOR 1 resource and decoded by
    // the same inert static extraction path.
      destinationCueAsset: "../../assets/images/point-game-ocx-legal-move-cue.png",
    destinationCueNaturalSize: { width: 32, height: 32 },
    destinationCueVisibleBounds: box(5, 5, 21, 21),
    destinationCueHotspot: { x: 15, y: 15 },
    destinationCueResourceSha256: "B2A97A056DEC993896AFF22AC533FAE75031318DEAA06CBA6526C385B7991B94",
    sourceRole: "role-1",
    boardSlot: "classic-board-alternate",
    pointX: [40, 68, 96, 124, 152, 180, 235, 263, 291, 319, 347, 375],
    // The authoritative state uses one absolute 0..23 track. Role 1 traverses
    // the upper row left-to-right and returns across the lower row right-to-left,
    // placing point 23 beside the lower-left bear-off channel.
    pointOrder: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
    pointRows: [box(0, 11, 25, 133), box(0, 180, 25, 133)],
    bar: box(207, 7, 26, 306),
    // Role 1 orders source identities bottom then top, so its borne-off boxes
    // follow the same player order rather than raw top-to-bottom screen order.
    borneOff: [box(4, 206, 27, 108), box(4, 46, 27, 113)],
    // The OCX provides dedicated side-view assets for settled borne-off
    // checkers. Board and moving checkers remain the full 25 x 25 resources.
    borneOffCheckers: {
      slots: ["gif-w-s", "gif-b-s"],
      nativeSizes: [[23, 5], [24, 5]],
      // The OCX seats the side profiles in the rack's alternating source
      // notches. The first lower-rack profile starts at source y=305, then
      // advances by seven pixels toward the avatar.
      // Native lower/upper baselines:305 and148; both advance upward by7.
      edgeInset: 4,
      edgeInsets: [4, 6],
      stackStep: 7,
      notchLeftInsets: [[0, 2], [2, 0]],
    },
    avatarFrames: [box(0, 163, 35, 42), box(0, 4, 35, 41)],
    // Interior apertures measured from the original 35 x 42 and 35 x 41
    // portrait objects, ordered first-side/bottom then second-side/top.
    avatarWells: [box(5, 168, 25, 33), box(5, 9, 25, 32)],
    // The original occupied portrait is a 24 x 32 opaque rectangle anchored
    // at the aperture's top-left. The remaining one-pixel right/bottom edge
    // belongs to the neutral source-panel backing, not to the portrait.
    avatarImageBoxes: [box(5, 168, 24, 32), box(5, 9, 24, 32)],
    diceSlots: [box(303, 157, 20, 20), box(363, 143, 20, 20)],
    controls: {
      visualFx: box(411, 0, 49, 119),
      roll: box(412, 121, 44, 78),
      soundFx: box(411, 201, 49, 119),
    },
    controlSprites: {
      soundFx: { nativeSize: [49, 119], on: { rest: "gif-sfx-ur", hover: "gif-sfx-uh" }, off: { rest: "gif-sfx-dr", hover: "gif-sfx-dh" } },
      visualFx: { nativeSize: [49, 119], on: { rest: "gif-gfx-ur", hover: "gif-gfx-uh" }, off: { rest: "gif-gfx-dr", hover: "gif-gfx-dh" } },
    },
    actionSprites: {
      roll: { nativeSize: [44, 78], rest: "gif-roll-r", hover: "gif-roll-h", pressed: "gif-roll-p", disabled: "gif-roll-d" },
    },
    motion: {
      // Ordinary moves, re-entry and bearing off occupy about 1.9 s in the
      // owner recordings. The authoritative blot-hit recording at 30 fps is
      // a distinct sequence: the captured checker wipes away from right to
      // left in vertical source-sprite sections over about 1.0 s, settles at
      // the center seam, and then the attacking checker
      // occupies the vacated point over about 2.0 s.
      checkerSlide: { durationMs: 1900, easing: "cubic-bezier(.18,.58,.32,1)" },
      hitToBar: {
        sequence: "captured-then-mover",
        capturedDurationMs: 1000,
        capturedEraseSections: 8,
        moverDelayMs: 1000,
        moverDurationMs: 2000,
      },
      bearOff: { durationMs: 640, frameDurationMs: 80, frameCount: 8 },
      blockedReentry: {
        wholeTurn: { ticks: 30, tickMs: 80, durationMs: 2400 },
        partialTurn: { ticks: 7, tickMs: 80, durationMs: 560 },
        boardMustRemainUnchanged: true,
      },
      win: {
        // Static RT_BITMAP audit of the authoritative Backgammon OCX proves
        // four consecutive, step-framed native strips for each checker color
        // and source direction. The source timer owns one 80 ms tick per
        // frame; no CSS-interpolated checker/trail approximation is allowed.
        frameDurationMs: 80,
        durationMs: 4800,
        direction: "left-to-right",
        strips: {
          white: ["bitmap-515", "bitmap-519", "bitmap-523", "bitmap-527"],
          black: ["bitmap-517", "bitmap-521", "bitmap-525", "bitmap-529"],
        },
        phases: [
          { frameWidth: 60, frameHeight: 36, frameCount: 11, box: box(34, 192, 60, 36) },
          { frameWidth: 80, frameHeight: 38, frameCount: 10, box: box(78, 192, 80, 38) },
          { frameWidth: 77, frameHeight: 57, frameCount: 9, box: box(140, 190, 77, 57) },
          { frameWidth: 51, frameHeight: 57, frameCount: 30, box: box(207, 190, 51, 57) },
        ],
        supplementalResultAfterVictory: true,
      },
    },
  },
  battleship: {
    canvas: { width: 420, height: 320 },
    grids: {
      own: box(11, 15, 182, 182),
      target: box(227, 123, 182, 182),
    },
    gridEdges: {
      own: {
        x: [11, 29, 47, 65, 84, 101, 119, 137, 155, 173, 192],
        y: [15, 33, 51, 69, 87, 105, 123, 141, 159, 177, 196],
      },
      target: {
        x: [227, 246, 264, 282, 299, 317, 336, 353, 372, 390, 408],
        y: [123, 141, 159, 177, 195, 213, 231, 249, 267, 286, 304],
      },
    },
    // The two asymmetric 40 x 43 OCX frame families register independently
    // against the owner source. Authenticated avatars fill only their measured
    // apertures; the normal and illuminated frame pixels stay literal.
    avatarFrames: [box(255, 45, 40, 43), box(368, 20, 40, 43)],
    avatarWells: [box(263, 49, 24, 33), box(375, 26, 27, 34)],
    avatarBorders: [
      { nativeSize: [40, 43], normal: "gif-icona1", illuminated: "gif-icona2", activePhase: "battle" },
      { nativeSize: [40, 43], normal: "gif-iconb1", illuminated: "gif-iconb2", activePhase: "battle" },
    ],
    status: box(202, 12, 165, 32),
    scoreCells: {
      // The source paints these records on two different baselines. Their
      // measured centers are Wins (340.5, 90.5) and Losses (372, 99).
      wins: box(329, 79, 23, 23),
      losses: box(360, 88, 24, 22),
    },
    controls: {
      // The full state images include their source-painted background. These
      // registrations are therefore the literal rectangles in
      // startgamewait.PNG, not enlarged label/button interaction estimates.
      autoPlacement: box(79, 210, 126, 28),
      start: box(62, 247, 78, 23),
      // Exact source sprite registrations measured independently by matching
      // each private control-state image against the intact owner reference.
      // The larger surrounding regions contain painted labels/background and
      // are not sprite rectangles or interaction geometry.
      soundFx: box(25, 265, 27, 27),
      music: box(67, 277, 27, 22),
      visualFx: box(109, 274, 27, 27),
    },
    controlSprites: {
      soundFx: { nativeSize: [27, 27], on: { rest: "gif-sfx-dr", hover: "gif-sfx-dh" }, off: { rest: "gif-sfx-ur", hover: "gif-sfx-uh" } },
      music: { nativeSize: [27, 22], on: { rest: "gif-music-dr", hover: "gif-music-dh" }, off: { rest: "gif-music-ur", hover: "gif-music-uh" } },
      visualFx: { nativeSize: [27, 27], on: { rest: "gif-gfx-dr", hover: "gif-gfx-dh" }, off: { rest: "gif-gfx-ur", hover: "gif-gfx-uh" } },
    },
    actionSprites: {
      autoPlacement: { nativeSize: [126, 28], rest: "gif-auto-r", hover: "gif-auto-h", pressed: "gif-auto-p", disabled: "gif-auto-d" },
      startGame: { geometryKey: "start", nativeSize: [78, 23], rest: "gif-start-r", hover: "gif-start-h", pressed: "gif-start-p", disabled: "gif-start-d" },
    },
    motion: {
      // Native 80 ms dispatcher: launch/projectile together, then result callbacks.
      // DIB8 skips alternate ticks; DIB9 loops frames52..101 after its intro.
      autoPlacement: { durationMs: 66.667 },
      shot: {
        frameDurationMs: 80,
        launchLeadMs: 0,
        launchByPerspective: {
          viewer: { slot: "dib-3", region: box(20, 212, 40, 40), frameCount: 45, muzzle: box(23, 224, 24, 25) },
          opponent: { slot: "dib-2", region: box(165, 267, 40, 40), frameCount: 35, muzzle: box(174, 280, 24, 25) },
        },
        projectile: { slot: "dib-4", nativeSize: { width: 24, height: 25 }, frameCount: 12 },
        durationMs: 960,
        impactFraction: 1,
        easing: "linear",
      },
      impacts: {
        miss: { slot: "dib-7", nativeSize: { width: 20, height: 20 }, frameCount: 9 },
        hit: { slot: "dib-5", nativeSize: { width: 18, height: 20 }, frameCount: 14 },
        explosion: { slot: "dib-6", nativeSize: { width: 18, height: 20 }, frameCount: 16 },
        marker: { slot: "dib-8", nativeSize: { width: 13, height: 13 }, frameCount: 56, frameDurationMs: 160, initialDelayMs: 80 },
      },
      wrecks: {
        "1": { slot: "dib-10", nativeSize: { width: 21, height: 21 }, frameCount: 35 },
        "vertical-2": { slot: "dib-11", nativeSize: { width: 20, height: 39 }, frameCount: 31 },
        "vertical-3": { slot: "dib-12", nativeSize: { width: 24, height: 57 }, frameCount: 31 },
        "vertical-4": { slot: "dib-13", nativeSize: { width: 26, height: 75 }, frameCount: 31 },
        "vertical-5": { slot: "dib-14", nativeSize: { width: 27, height: 93 }, frameCount: 31 },
        "horizontal-2": { slot: "dib-15", nativeSize: { width: 39, height: 22 }, frameCount: 31 },
        "horizontal-3": { slot: "dib-16", nativeSize: { width: 57, height: 23 }, frameCount: 31 },
        "horizontal-4": { slot: "dib-17", nativeSize: { width: 74, height: 26 }, frameCount: 31 },
        "horizontal-5": { slot: "dib-18", nativeSize: { width: 93, height: 25 }, frameCount: 31 },
      },
      miss: { durationMs: 10720 },
      hit: { durationMs: 3600 },
      sunk: { durationMs: 5040 },
      audio: { impactStartMs: 960, sinkStartMs: 2240 },
      victoryFlag: {
        slot: "dib-9",
        nativeSize: { width: 60, height: 40 },
        frameCount: 102,
        frameDurationMs: 80,
        durationMs: 8160,
        loopStartFrame: 52,
        persistFinal: true,
      },
      // In the decoded source recordings M_WAIT is a static placement state:
      // only the native status title changes to "Wait for opponent". DIB3,
      // DIB4, and DIB8 begin under the later combat-selection/shot sequence
      // and must never be replayed as decorative waiting motion.
      waitingState: { title: "gif-m-wait", decorativeMotion: false, authoritativeMutation: false, stateDisclosure: false },
    },
  },
  spades: {
    canvas: { width: 420, height: 308 },
    seats: {
      bottom: box(273, 243, 44, 56),
      left: box(57, 118, 44, 56),
      top: box(190, 4, 44, 56),
      right: box(321, 118, 44, 56),
    },
    avatarFrames: {
      bottom: box(273, 243, 44, 56),
      left: box(57, 118, 44, 56),
      top: box(190, 4, 44, 56),
      right: box(321, 118, 44, 56),
    },
    avatarWells: {
      bottom: box(277, 247, 36, 48),
      left: box(61, 122, 36, 48),
      top: box(194, 8, 36, 48),
      right: box(325, 122, 36, 48),
    },
    avatarBorders: {
      team1: { nativeSize: [44, 56], rest: "gif-team1", highlighted: "gif-team1-hilite" },
      team2: { nativeSize: [44, 56], rest: "gif-team2", highlighted: "gif-team2-hilite" },
      currentTurnPulse: {
        sourceTickMs: 80,
        sourceTickCount: 24,
        highlightedTicks: [22, 23],
        durationMs: 1920,
        highlightedDurationMs: 160,
      },
    },
    // Backward-compatible alias for the same immutable inner apertures. New
    // verification and rendering own avatarFrames/avatarWells explicitly.
    avatarInsets: {
      bottom: box(277, 247, 36, 48),
      left: box(61, 122, 36, 48),
      top: box(194, 8, 36, 48),
      right: box(325, 122, 36, 48),
    },
    scoreTable: box(8, 7, 117, 50),
    scoreCells: {
      team1Last: box(64, 29, 25, 13),
      team2Last: box(100, 29, 25, 13),
      team1Total: box(64, 42, 25, 13),
      team2Total: box(100, 42, 25, 13),
    },
    scoreLabels: {
      team1: box(39, 16, 50, 13),
      team2: box(89, 16, 36, 13),
      last: box(8, 29, 31, 13),
      total: box(8, 42, 31, 13),
    },
    trick: box(122, 67, 178, 156),
    trickCards: {
      top: box(185, 71, 54, 72),
      right: box(241, 110, 54, 72),
      bottom: box(185, 149, 54, 72),
      left: box(129, 110, 54, 72),
    },
    turnArrows: {
      bottom: { box: box(197, 197, 30, 24), active: "gif-arrow-d", off: "gif-arrow-d-off" },
      left: { box: box(129, 131, 24, 30), active: "gif-arrow-l", off: "gif-arrow-l-off" },
      top: { box: box(197, 71, 30, 24), active: "gif-arrow-u", off: "gif-arrow-u-off" },
      right: { box: box(272, 131, 24, 30), active: "gif-arrow-r", off: "gif-arrow-r-off" },
    },
    teamRoster: {
      // Measured from the original 420x308 OCX board in spades1.png. The
      // roster owns the lower-left source region; the compacted hand begins
      // to its right so cards can never cover the team/name lines.
      region: box(8, 228, 84, 77),
      team1: {
        label: box(9, 230, 80, 11),
        names: [box(9, 242, 80, 11), box(9, 253, 80, 11)],
      },
      team2: {
        label: box(9, 269, 80, 11),
        names: [box(9, 281, 80, 11), box(9, 292, 80, 11)],
      },
    },
    // This region contains both the exact 32-pixel legacy hover lift and the
    // compacted resting hand. Card top-lefts are x=89+(index*10), y=229.
    hand: box(89, 197, 184, 104),
    handCard: { width: 54, height: 72, restY: 229, legacyHoverY: 197, stepX: 10 },
    cardBack: { slot: "gif-card-back", nativeSize: [54, 72] },
    passCards: [box(185, 149, 54, 72), box(195, 149, 54, 72)],
    controls: { options: box(343, 5, 72, 20) },
    motion: {
      // Owner-authorized bounded motion only: a confirmed viewer card travels
      // from its current measured hand slot to the bottom trick slot. No deal
      // or trick-collection sequence is inferred without source evidence.
      cardPlay: { durationMs: 520, easing: "cubic-bezier(.22,.61,.36,1)" },
    },
  },
  "five-dice": {
    canvas: { width: 840, height: 640 },
    // Empty score columns retain the intact board's printed 54 x 64 X areas.
    avatarFrames: [
      box(178, 141, 54, 64),
      box(240, 141, 54, 64),
      box(302, 141, 54, 64),
      box(364, 141, 54, 64),
    ],
    // Every occupied column is covered by one exact 62 x 506 lane strip. Its
    // portrait frame is the strip's top 62 x 70 pixels, not the printed empty
    // X area underneath it.
    occupiedAvatarFrames: [
      box(170, 134, 62, 70),
      box(232, 134, 62, 70),
      box(294, 134, 62, 70),
      box(356, 134, 62, 70),
    ],
    avatarWells: [
      // All exact normal, active HSTRIP, and ON HOLD lane variants share a
      // measured 48 x 64 black portrait opening at (6,6) inside their 62-pixel
      // strip. The former box began two pixels inside the opening, was six
      // pixels too wide, shifted its center 5.5 source pixels right, and
      // covered the strip's eight-pixel right frame.
      box(176, 140, 48, 64),
      box(238, 140, 48, 64),
      box(300, 140, 48, 64),
      box(362, 140, 48, 64),
    ],
    // The painted recesses were measured independently from the intact board
    // after the private owner captures were registered at their exact source
    // transform. Runtime/CSS rectangles are not measurement inputs. The die
    // boxes align the source-derived alpha foreground centroid to each painted
    // center, so a matching image/button rectangle is never treated as proof.
    dieSize: 56,
    dieForegroundCentroid: box(29.324175824175825, 23.93076923076923, 0, 0),
    paintedRecesses: [
      box(481.5, 66.5, 0, 0), box(528.5, 142.5, 0, 0), box(586.5, 223.5, 0, 0),
      box(658.5, 285.5, 0, 0), box(743.5, 335.5, 0, 0),
    ],
    dice: [
      box(452, 43, 56, 56), box(499, 119, 56, 56), box(557, 200, 56, 56),
      box(629, 262, 56, 56), box(714, 312, 56, 56),
    ],
    rollingDiceSize: 70,
    rollingDice: [
      box(447, 32, 70, 70), box(494, 108, 70, 70), box(552, 189, 70, 70),
      box(624, 251, 70, 70), box(709, 301, 70, 70),
    ],
    motion: {
      // GIF_SPEACH is a 23-frame vertical strip registered over the original
      // singer/microphone ROI. The two owner recordings repeat this exact
      // strip every 0.75 seconds while SFX is enabled.
      microphone: box(768, 42, 24, 26),
      microphoneFrames: 23,
      microphoneDurationMs: 750,
      drum: box(578, 352, 92, 90),
      drumFrames: 5,
      drumRestFrame: 2,
      drumSequence: [0, 2, 4, 0, 1, 2, 1, 4],
      drumFrameDurationsMs: [167, 200, 100, 100, 100, 100, 100, 33],
      diceFrames: 12,
      diceDurationMs: 1167,
    },
    scoreColumns: [178, 240, 302, 364],
    scoreWidth: 54,
    scoreAction: { x: 6, width: 412, labelWidth: 160, height: 24 },
    scoreRows: [
      box(0, 204, 54, 26), box(0, 229, 54, 26), box(0, 254, 54, 26),
      box(0, 277, 54, 26), box(0, 302, 54, 26), box(0, 324, 54, 26),
      box(0, 348, 54, 26), box(0, 379, 54, 26), box(0, 404, 54, 26),
      box(0, 428, 54, 26), box(0, 453, 54, 26), box(0, 478, 54, 26),
      box(0, 501, 54, 26), box(0, 526, 54, 26), box(0, 550, 54, 26),
      box(0, 576, 54, 26), box(0, 602, 54, 26),
    ],
    playerLanes: [
      box(170, 134, 62, 506), box(232, 134, 62, 506),
      box(294, 134, 62, 506), box(356, 134, 62, 506),
    ],
    controls: {
      // Exact best-fit registration of the installation-private state sprites
      // against the intact 840x640 composite board.  These regions are
      // independent; sharing the N'Roll rectangle with SFX or Music is a
      // source-map failure rather than a responsive-layout choice.
      sound: box(744, 408, 70, 36),
      nRoll: box(590, 456, 104, 78),
      music: box(460, 510, 130, 66),
      gfx: box(694, 530, 146, 110),
      startNewGame: box(528, 0, 168, 42),
    },
  },
});

const BACKGAMMON_ROLE_2_SOURCE_MAP = deepFreeze({
  ...CLASSIC_SOURCE_MAPS["backgammon-first-party"],
  sourceRole: "role-2",
  boardSlot: "classic-board",
  pointX: [8, 36, 64, 92, 120, 148, 203, 231, 259, 287, 315, 343],
  // Role 2 is the horizontal source mirror: point 0 is beside the upper-right
  // bear-off channel and point 23 is beside the lower-right channel.
  pointOrder: [11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1, 0, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11],
  pointRows: [box(0, 7, 25, 133), box(0, 180, 25, 133)],
  bar: box(174, 7, 26, 306),
  // Player zero occupies the lower source identity and player one the upper;
  // each settled channel is directly below its corresponding avatar.
  borneOff: [box(378, 206, 27, 108), box(378, 46, 27, 113)],
  borneOffCheckers: {
    ...CLASSIC_SOURCE_MAPS["backgammon-first-party"].borneOffCheckers,
    // Horizontal mirror of the left-rack notch seats. The two checker assets
    // have different native widths, so each source identity owns its insets.
    notchLeftInsets: [[2, 4], [4, 2]],
  },
  avatarFrames: [box(378, 163, 35, 42), box(378, 4, 35, 41)],
  // Role 2 keeps the immutable frame pixels but centers the authenticated
  // portrait apertures on the measured right-side checker recess column.
  avatarWells: [box(379, 168, 25, 33), box(379, 9, 25, 32)],
  avatarImageBoxes: [box(379, 168, 24, 32), box(379, 9, 24, 32)],
  diceSlots: [box(270, 157, 20, 20), box(330, 143, 20, 20)],
  motion: {
    ...CLASSIC_SOURCE_MAPS["backgammon-first-party"].motion,
    win: {
      ...CLASSIC_SOURCE_MAPS["backgammon-first-party"].motion.win,
      direction: "right-to-left",
      strips: {
        white: ["bitmap-516", "bitmap-520", "bitmap-524", "bitmap-528"],
        black: ["bitmap-518", "bitmap-522", "bitmap-526", "bitmap-530"],
      },
      phases: [
        { frameWidth: 60, frameHeight: 36, frameCount: 11, box: box(318, 192, 60, 36) },
        { frameWidth: 80, frameHeight: 38, frameCount: 10, box: box(252, 192, 80, 38) },
        { frameWidth: 77, frameHeight: 57, frameCount: 9, box: box(190, 190, 77, 57) },
        { frameWidth: 51, frameHeight: 57, frameCount: 30, box: box(152, 190, 51, 57) },
      ],
    },
  },
});

export function classicSourceMap(gameId, sourceRole = "role-1") {
  if (gameId === "backgammon-first-party" && sourceRole === "role-2") return BACKGAMMON_ROLE_2_SOURCE_MAP;
  return CLASSIC_SOURCE_MAPS[gameId] || null;
}
