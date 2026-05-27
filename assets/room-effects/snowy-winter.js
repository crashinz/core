// @effect-key snowy_winter
// @effect-label Snowy Winter
// @effect-description Cold stage edges, snowfall, and snow that gathers on avatars.
(function registerSnowyWinter() {
  window.ChatSpaceRoomEffects = window.ChatSpaceRoomEffects || {};

  function ensureStyle() {
    if (document.getElementById('room-effect-style-snowy-winter')) return;
    const style = document.createElement('style');
    style.id = 'room-effect-style-snowy-winter';
    style.textContent = `
      .room-stage.effect-snowy-winter {
        box-shadow: inset 0 0 42px rgba(140, 210, 255, .45), inset 0 0 92px rgba(220, 245, 255, .18), 0 0 18px rgba(92, 185, 255, .18);
      }
      .snowy-winter-layer {
        z-index: 3;
        background:
          radial-gradient(circle at 50% 0%, rgba(210, 242, 255, .16), transparent 46%),
          linear-gradient(180deg, rgba(6, 22, 40, .28), rgba(185, 230, 255, .08));
      }
      .snowflake {
        position: absolute;
        top: -18px;
        width: var(--size);
        height: var(--size);
        border-radius: 50%;
        background: rgba(245, 252, 255, .9);
        box-shadow: 0 0 8px rgba(190, 230, 255, .9);
        opacity: .9;
        animation: snowFall var(--duration) linear infinite;
      }
      @keyframes snowFall {
        from { transform: translate3d(0, -24px, 0); }
        to { transform: translate3d(var(--drift), calc(100vh + 44px), 0); }
      }
      .snow-avatar-layer {
        z-index: 4;
        overflow: visible;
      }
      .avatar-snow-cap {
        position: absolute;
        height: var(--cap-height, 6px);
        border-radius: 999px 999px 8px 8px;
        background: linear-gradient(180deg, rgba(255,255,255,.98), rgba(207,239,255,.86));
        box-shadow: 0 0 10px rgba(210, 244, 255, .7), inset 0 -3px 6px rgba(128,190,220,.24);
        opacity: .96;
      }
      .fallen-snow {
        position: absolute;
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: rgba(245,252,255,.94);
        box-shadow: 0 0 8px rgba(190,230,255,.72);
        animation: snowDrop .82s ease-in forwards;
      }
      @keyframes snowDrop {
        to { transform: translate3d(var(--dx), var(--dy), 0) scale(.65); opacity: 0; }
      }
    `;
    document.head.appendChild(style);
  }

  function stagePoint(stage, rect) {
    const stageRect = stage.getBoundingClientRect();
    return {
      x: rect.left - stageRect.left,
      y: rect.top - stageRect.top,
      width: rect.width,
      height: rect.height,
    };
  }

  window.ChatSpaceRoomEffects.snowy_winter = {
    mount(context) {
      const stage = context.roomStage;
      if (!stage) return null;
      ensureStyle();
      stage.classList.add('effect-snowy-winter');

      const snowLayer = document.createElement('div');
      snowLayer.className = 'room-effect-layer snowy-winter-layer';
      snowLayer.setAttribute('aria-hidden', 'true');
      for (let i = 0; i < 58; i += 1) {
        const flake = document.createElement('i');
        flake.className = 'snowflake';
        flake.style.left = `${Math.random() * 100}%`;
        flake.style.setProperty('--size', `${2 + Math.random() * 5}px`);
        flake.style.setProperty('--drift', `${Math.round(Math.random() * 120 - 60)}px`);
        flake.style.setProperty('--duration', `${7 + Math.random() * 8}s`);
        flake.style.animationDelay = `${Math.random() * -12}s`;
        snowLayer.appendChild(flake);
      }

      const avatarLayer = document.createElement('div');
      avatarLayer.className = 'room-effect-layer snow-avatar-layer';
      avatarLayer.setAttribute('aria-hidden', 'true');
      stage.append(snowLayer, avatarLayer);

      const states = new Map();

      const burst = (rect, amount = 10) => {
        for (let i = 0; i < amount; i += 1) {
          const piece = document.createElement('i');
          piece.className = 'fallen-snow';
          piece.style.left = `${rect.x + Math.random() * rect.width}px`;
          piece.style.top = `${rect.y + Math.min(rect.height, 12)}px`;
          piece.style.setProperty('--dx', `${Math.round(Math.random() * 80 - 40)}px`);
          piece.style.setProperty('--dy', `${Math.round(55 + Math.random() * Math.max(45, stage.clientHeight - rect.y - 20))}px`);
          piece.style.animationDelay = `${Math.random() * .12}s`;
          avatarLayer.appendChild(piece);
          piece.addEventListener('animationend', () => piece.remove(), { once: true });
        }
      };

      const tick = () => {
        const seen = new Set();
        context.getAvatars().forEach(({ participant, element }) => {
          const id = Number(participant.id);
          seen.add(id);
          const rect = stagePoint(stage, element.getBoundingClientRect());
          let state = states.get(id);
          if (!state) {
            const cap = document.createElement('i');
            cap.className = 'avatar-snow-cap';
            avatarLayer.appendChild(cap);
            state = { cap, height: 2, rect };
            states.set(id, state);
          }
          const moved = Math.hypot(rect.x - state.rect.x, rect.y - state.rect.y) > 4;
          if (moved && state.height > 1) {
            burst(state.rect, Math.ceil(state.height));
            state.height = .5;
          } else {
            state.height = Math.min(14, state.height + .22);
          }
          state.rect = rect;
          state.cap.style.left = `${rect.x + 4}px`;
          state.cap.style.top = `${rect.y - Math.min(8, state.height / 2)}px`;
          state.cap.style.width = `${Math.max(24, rect.width - 8)}px`;
          state.cap.style.setProperty('--cap-height', `${state.height}px`);
        });

        states.forEach((state, id) => {
          if (seen.has(id)) return;
          burst(state.rect, Math.max(8, Math.ceil(state.height * 1.4)));
          state.cap.remove();
          states.delete(id);
        });
      };

      tick();
      const timer = setInterval(tick, 260);

      return {
        destroy() {
          clearInterval(timer);
          snowLayer.remove();
          avatarLayer.remove();
          stage.classList.remove('effect-snowy-winter');
        },
      };
    },
  };
}());
