// @effect-key rain_rain_rain
// @effect-label Rain Rain Rain
// @effect-description Dark rainstorm with drops that strike avatars, roll down, and ripple away.
(function registerRainRainRain() {
  window.ChatSpaceRoomEffects = window.ChatSpaceRoomEffects || {};

  function ensureStyle() {
    if (document.getElementById('room-effect-style-rain-rain-rain')) return;
    const style = document.createElement('style');
    style.id = 'room-effect-style-rain-rain-rain';
    style.textContent = `
      .room-stage.effect-rain-rain-rain {
        box-shadow: inset 0 0 72px rgba(0,0,0,.58), inset 0 0 58px rgba(77, 149, 255, .12);
      }
      .rain-rain-rain-layer {
        z-index: 3;
        background:
          linear-gradient(180deg, rgba(0,0,0,.46), rgba(4,9,18,.3)),
          radial-gradient(circle at 60% 0%, rgba(92,140,255,.14), transparent 48%);
      }
      .rain-drop {
        position: absolute;
        top: -28px;
        width: 1px;
        height: var(--len, 24px);
        border-radius: 999px;
        background: linear-gradient(180deg, rgba(210,232,255,0), rgba(210,232,255,.9));
        opacity: .78;
        transform: rotate(10deg);
        animation: rainDrop var(--duration, .8s) linear forwards;
      }
      @keyframes rainDrop {
        to { transform: translate3d(var(--wind, 34px), calc(100vh + 70px), 0) rotate(10deg); opacity: .15; }
      }
      .avatar-rain-roll {
        position: absolute;
        width: 4px;
        height: 9px;
        border-radius: 999px;
        background: rgba(187, 223, 255, .88);
        box-shadow: 0 0 8px rgba(92,170,255,.7);
        animation: avatarRainRoll .72s ease-in forwards;
      }
      @keyframes avatarRainRoll {
        to { transform: translate3d(var(--dx), var(--dy), 0) scale(.72); opacity: 0; }
      }
      .rain-ripple {
        position: absolute;
        width: 8px;
        height: 3px;
        border: 1px solid rgba(168,216,255,.7);
        border-radius: 50%;
        animation: rainRipple .5s ease-out forwards;
      }
      @keyframes rainRipple {
        to { transform: translate(-50%, -50%) scale(4.2, 2.2); opacity: 0; }
      }
    `;
    document.head.appendChild(style);
  }

  function relativeRect(stage, element) {
    const stageRect = stage.getBoundingClientRect();
    const rect = element.getBoundingClientRect();
    return {
      x: rect.left - stageRect.left,
      y: rect.top - stageRect.top,
      width: rect.width,
      height: rect.height,
    };
  }

  window.ChatSpaceRoomEffects.rain_rain_rain = {
    mount(context) {
      const stage = context.roomStage;
      if (!stage) return null;
      ensureStyle();
      stage.classList.add('effect-rain-rain-rain');

      const layer = document.createElement('div');
      layer.className = 'room-effect-layer rain-rain-rain-layer';
      layer.setAttribute('aria-hidden', 'true');
      stage.appendChild(layer);

      const addDrop = () => {
        const drop = document.createElement('i');
        drop.className = 'rain-drop';
        drop.style.left = `${Math.random() * 100}%`;
        drop.style.setProperty('--len', `${18 + Math.random() * 34}px`);
        drop.style.setProperty('--wind', `${Math.round(18 + Math.random() * 72)}px`);
        drop.style.setProperty('--duration', `${.5 + Math.random() * .65}s`);
        layer.appendChild(drop);
        drop.addEventListener('animationend', () => drop.remove(), { once: true });
      };

      const ripple = (x, y) => {
        const el = document.createElement('i');
        el.className = 'rain-ripple';
        el.style.left = `${x}px`;
        el.style.top = `${Math.min(stage.clientHeight - 5, y)}px`;
        layer.appendChild(el);
        el.addEventListener('animationend', () => el.remove(), { once: true });
      };

      const avatarHit = () => {
        const avatars = context.getAvatars();
        if (!avatars.length) return;
        const { element } = avatars[Math.floor(Math.random() * avatars.length)];
        const rect = relativeRect(stage, element);
        const x = rect.x + Math.random() * rect.width;
        const y = rect.y + Math.random() * Math.max(12, rect.height * .22);
        const roll = document.createElement('i');
        roll.className = 'avatar-rain-roll';
        roll.style.left = `${x}px`;
        roll.style.top = `${y}px`;
        roll.style.setProperty('--dx', `${Math.round(Math.random() * 24 - 12)}px`);
        roll.style.setProperty('--dy', `${Math.round(rect.height - (y - rect.y) + 14)}px`);
        layer.appendChild(roll);
        roll.addEventListener('animationend', () => {
          ripple(x, rect.y + rect.height + 10);
          roll.remove();
        }, { once: true });
      };

      const rainTimer = setInterval(() => {
        for (let i = 0; i < 7; i += 1) addDrop();
      }, 90);
      const avatarTimer = setInterval(avatarHit, 240);
      const floorTimer = setInterval(() => {
        ripple(Math.random() * stage.clientWidth, stage.clientHeight - (Math.random() * 34));
      }, 180);

      return {
        destroy() {
          clearInterval(rainTimer);
          clearInterval(avatarTimer);
          clearInterval(floorTimer);
          layer.remove();
          stage.classList.remove('effect-rain-rain-rain');
        },
      };
    },
  };
}());
