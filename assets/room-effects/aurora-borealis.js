// @effect-key aurora_borealis
// @effect-label Aurora Borealis
// @effect-description Darkened stage with living northern light curtains and shimmer.
(function registerAuroraBorealis() {
  window.ChatSpaceRoomEffects = window.ChatSpaceRoomEffects || {};

  function ensureStyle() {
    if (document.getElementById('room-effect-style-aurora-borealis')) return;
    const style = document.createElement('style');
    style.id = 'room-effect-style-aurora-borealis';
    style.textContent = `
      .room-stage.effect-aurora-borealis {
        box-shadow:
          inset 0 0 70px rgba(0, 4, 16, .82),
          inset 0 0 132px rgba(31, 255, 185, .16),
          inset 0 0 180px rgba(146, 74, 255, .16),
          0 0 22px rgba(31, 255, 185, .12);
      }
      .aurora-borealis-layer {
        z-index: 1;
        background:
          radial-gradient(ellipse at 26% 4%, rgba(42, 255, 188, .2), transparent 42%),
          radial-gradient(ellipse at 72% 2%, rgba(202, 88, 255, .22), transparent 46%),
          linear-gradient(180deg, rgba(0, 5, 22, .42), rgba(1, 8, 18, .12) 42%, rgba(0,0,0,.14));
        mix-blend-mode: screen;
      }
      .aurora-atmosphere,
      .aurora-curtain,
      .aurora-mote,
      .aurora-shimmer,
      .aurora-wave {
        position: absolute;
        pointer-events: none;
        mix-blend-mode: screen;
        will-change: transform, opacity;
      }
      .aurora-atmosphere {
        top: -18%;
        height: 128%;
        filter: blur(var(--blur, 28px));
      }
      .aurora-curtain {
        top: -24%;
        height: 136%;
        filter: blur(var(--blur, 14px));
        transform-origin: 50% 0;
      }
      .aurora-mote {
        border-radius: 999px;
        background: rgba(255,255,255,.92);
      }
      .aurora-shimmer {
        top: -18%;
        height: 128%;
        filter: blur(9px);
      }
      .aurora-wave {
        left: -10%;
        width: 120%;
        height: 18%;
        filter: blur(16px);
        background: linear-gradient(to bottom, transparent, rgba(198, 142, 255, .16), transparent);
      }
    `;
    document.head.appendChild(style);
  }

  function animate(el, frames, options, animations) {
    const animation = el.animate(frames, options);
    animations.push(animation);
    return animation;
  }

  window.ChatSpaceRoomEffects.aurora_borealis = {
    mount(context) {
      const stage = context.roomStage;
      if (!stage) return null;
      ensureStyle();
      stage.classList.add('effect-aurora-borealis');

      const layer = document.createElement('div');
      layer.className = 'room-effect-layer aurora-borealis-layer';
      layer.setAttribute('aria-hidden', 'true');
      stage.appendChild(layer);

      const animations = [];
      const timers = [];
      const motes = [];

      const atmosphereLayers = [
        {
          left: '-18%',
          width: '78%',
          blur: 30,
          gradient: 'linear-gradient(to bottom, transparent 0%, rgba(116,48,224,.26) 24%, rgba(35,218,174,.18) 62%, transparent 100%)',
          duration: 9500,
          delay: 0,
          sway: 28,
        },
        {
          left: '35%',
          width: '84%',
          blur: 32,
          gradient: 'linear-gradient(to bottom, transparent 0%, rgba(226,70,208,.22) 20%, rgba(93,62,232,.26) 55%, rgba(36,210,172,.1) 78%, transparent 100%)',
          duration: 12000,
          delay: -4200,
          sway: 22,
        },
        {
          left: '5%',
          width: '105%',
          blur: 42,
          gradient: 'linear-gradient(to bottom, transparent 5%, rgba(50,210,255,.11) 26%, rgba(42,255,174,.15) 46%, rgba(170,72,255,.1) 68%, transparent 100%)',
          duration: 15000,
          delay: -7600,
          sway: 18,
        },
      ];

      atmosphereLayers.forEach(item => {
        const el = document.createElement('div');
        el.className = 'aurora-atmosphere';
        el.style.left = item.left;
        el.style.width = item.width;
        el.style.background = item.gradient;
        el.style.setProperty('--blur', `${item.blur}px`);
        layer.appendChild(el);
        animate(el, [
          { transform: 'translateX(0)', opacity: .5 },
          { transform: `translateX(${item.sway}px)`, opacity: .72, offset: .3 },
          { transform: `translateX(-${item.sway}px)`, opacity: .58, offset: .72 },
          { transform: 'translateX(0)', opacity: .5 },
        ], { duration: item.duration, delay: item.delay, iterations: Infinity, easing: 'ease-in-out' }, animations);
      });

      const curtains = [
        { left: '0%', width: '21%', colors: ['transparent', 'rgba(127,55,238,.46)', 'rgba(27,211,170,.32)', 'rgba(100,48,218,.18)', 'transparent'], blur: 15, duration: 6800, delay: 0, sway: 18, skew: 3 },
        { left: '15%', width: '30%', colors: ['transparent', 'rgba(220,72,205,.38)', 'rgba(106,48,232,.52)', 'rgba(37,205,166,.22)', 'transparent'], blur: 17, duration: 8600, delay: -2100, sway: 24, skew: 2.6 },
        { left: '35%', width: '25%', colors: ['transparent', 'rgba(158,69,255,.54)', 'rgba(35,235,178,.34)', 'rgba(143,55,236,.2)', 'transparent'], blur: 14, duration: 6200, delay: -900, sway: 20, skew: 4.2 },
        { left: '53%', width: '29%', colors: ['transparent', 'rgba(38,220,180,.4)', 'rgba(111,54,232,.48)', 'rgba(210,75,205,.24)', 'transparent'], blur: 16, duration: 7900, delay: -3900, sway: 21, skew: 2.2 },
        { left: '69%', width: '24%', colors: ['transparent', 'rgba(232,75,205,.42)', 'rgba(124,54,242,.5)', 'rgba(40,211,174,.2)', 'transparent'], blur: 15, duration: 7200, delay: -1600, sway: 18, skew: 3.4 },
        { left: '81%', width: '24%', colors: ['transparent', 'rgba(66,104,242,.36)', 'rgba(142,58,230,.44)', 'rgba(34,198,174,.2)', 'transparent'], blur: 16, duration: 9400, delay: -5200, sway: 15, skew: 2.1 },
      ];

      curtains.forEach(item => {
        const el = document.createElement('div');
        el.className = 'aurora-curtain';
        el.style.left = item.left;
        el.style.width = item.width;
        el.style.background = `linear-gradient(to bottom, ${item.colors.join(', ')})`;
        el.style.setProperty('--blur', `${item.blur}px`);
        layer.appendChild(el);
        animate(el, [
          { transform: 'translateX(0) skewX(0deg)', opacity: .48 },
          { transform: `translateX(${item.sway}px) skewX(${item.skew}deg)`, opacity: .74, offset: .25 },
          { transform: `translateX(${item.sway * .2}px) skewX(-${item.skew * .35}deg)`, opacity: .58, offset: .52 },
          { transform: `translateX(-${item.sway * .75}px) skewX(-${item.skew}deg)`, opacity: .72, offset: .76 },
          { transform: 'translateX(0) skewX(0deg)', opacity: .48 },
        ], { duration: item.duration, delay: item.delay, iterations: Infinity, easing: 'ease-in-out' }, animations);
      });

      const colors = [
        '180,100,255',
        '40,230,185',
        '235,86,210',
        '102,96,245',
        '215,178,255',
        '120,255,220',
      ];

      let tick = 0;
      const interval = setInterval(() => {
        tick += 1;

        if (tick % 2 === 0) {
          const mote = document.createElement('div');
          const rgb = colors[Math.floor(Math.random() * colors.length)];
          const size = 1.5 + Math.random() * 4;
          mote.className = 'aurora-mote';
          mote.style.width = `${size}px`;
          mote.style.height = `${size}px`;
          mote.style.left = `${Math.random() * 100}%`;
          mote.style.top = `${10 + Math.random() * 80}%`;
          mote.style.boxShadow = `0 0 7px rgba(${rgb},.92), 0 0 16px rgba(${rgb},.58)`;
          layer.appendChild(mote);
          motes.push(mote);

          const dx = `${Math.random() * 28 - 14}px`;
          const dy = `${-(Math.random() * 60 + 26)}px`;
          animate(mote, [
            { transform: 'translate(0,0) scale(0)', opacity: 0 },
            { transform: `translate(${dx}, ${dy}) scale(1)`, opacity: .78, offset: .42 },
            { transform: `translate(${dx}, ${dy}) scale(0)`, opacity: 0 },
          ], { duration: 2600 + Math.random() * 2200, easing: 'ease-in-out' }, animations).onfinish = () => {
            mote.remove();
          };
        }

        if (tick % 58 === 0) {
          const shimmer = document.createElement('div');
          const rgb = colors[Math.floor(Math.random() * colors.length)];
          shimmer.className = 'aurora-shimmer';
          shimmer.style.left = `${5 + Math.random() * 78}%`;
          shimmer.style.width = `${7 + Math.random() * 13}%`;
          shimmer.style.background = `linear-gradient(to bottom, transparent, rgba(${rgb}, .4) 30%, rgba(${rgb}, .22) 66%, transparent)`;
          layer.appendChild(shimmer);
          animate(shimmer, [
            { opacity: 0, transform: 'skewX(0deg)' },
            { opacity: .7, transform: `skewX(${Math.random() * 7 - 3.5}deg)`, offset: .2 },
            { opacity: .48, transform: `skewX(${Math.random() * 5 - 2.5}deg)`, offset: .62 },
            { opacity: 0, transform: 'skewX(0deg)' },
          ], { duration: 1400 + Math.random() * 900, easing: 'ease-in-out' }, animations).onfinish = () => {
            shimmer.remove();
          };
        }

        if (tick % 96 === 0) {
          const wave = document.createElement('div');
          wave.className = 'aurora-wave';
          wave.style.top = `${-8 + Math.random() * 24}%`;
          layer.appendChild(wave);
          animate(wave, [
            { transform: 'translateY(0)', opacity: 0 },
            { opacity: .58, offset: .3 },
            { opacity: .42, offset: .72 },
            { transform: 'translateY(90px)', opacity: 0 },
          ], { duration: 3400 + Math.random() * 1600, easing: 'ease-in-out' }, animations).onfinish = () => {
            wave.remove();
          };
        }
      }, 40);
      timers.push(interval);

      return {
        destroy() {
          timers.forEach(timer => clearInterval(timer));
          animations.forEach(animation => animation.cancel());
          motes.forEach(mote => mote.remove());
          layer.remove();
          stage.classList.remove('effect-aurora-borealis');
        },
      };
    },
  };
}());
