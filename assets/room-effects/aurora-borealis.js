// @effect-key aurora_borealis
// @effect-label Aurora Borealis
// @effect-description Darkened stage with animated northern lights along the top.
(function registerAuroraBorealis() {
  window.ChatSpaceRoomEffects = window.ChatSpaceRoomEffects || {};

  function ensureStyle() {
    if (document.getElementById('room-effect-style-aurora-borealis')) return;
    const style = document.createElement('style');
    style.id = 'room-effect-style-aurora-borealis';
    style.textContent = `
      .room-stage.effect-aurora-borealis {
        box-shadow: inset 0 0 64px rgba(18, 10, 42, .55), inset 0 0 96px rgba(66, 255, 185, .08);
      }
      .aurora-borealis-layer {
        z-index: 1;
        background:
          linear-gradient(180deg, rgba(1,4,16,.48), rgba(3,6,18,.18) 45%, rgba(0,0,0,.2)),
          radial-gradient(circle at 50% 0%, rgba(65, 255, 199, .12), transparent 52%);
      }
      .aurora-band {
        position: absolute;
        left: -12%;
        top: -7%;
        width: 124%;
        height: 40%;
        opacity: .82;
        filter: blur(18px) saturate(1.35);
        transform-origin: 50% 0%;
        animation: auroraWave 8s ease-in-out infinite alternate;
        mix-blend-mode: screen;
      }
      .aurora-band.one {
        background:
          linear-gradient(100deg, transparent 0 10%, rgba(75,255,183,.58) 24%, rgba(90,150,255,.24) 43%, rgba(189,92,255,.42) 58%, rgba(75,255,183,.3) 76%, transparent 94%),
          repeating-linear-gradient(92deg, transparent 0 4%, rgba(255,255,255,.14) 5%, transparent 8%);
        clip-path: polygon(0 24%, 10% 12%, 19% 28%, 31% 8%, 43% 26%, 55% 10%, 66% 30%, 79% 13%, 91% 31%, 100% 22%, 100% 76%, 0 68%);
      }
      .aurora-band.two {
        top: 4%;
        height: 34%;
        opacity: .52;
        animation-duration: 11s;
        animation-delay: -4s;
        background: linear-gradient(90deg, transparent 4%, rgba(28,210,255,.34), rgba(86,255,170,.4), rgba(230,82,255,.24), transparent 94%);
        clip-path: polygon(0 35%, 12% 24%, 26% 42%, 39% 18%, 51% 38%, 64% 20%, 78% 41%, 90% 25%, 100% 36%, 100% 76%, 0 72%);
      }
      @keyframes auroraWave {
        from { transform: translate3d(-2%, 0, 0) skewX(-5deg) scaleY(.9); }
        to { transform: translate3d(2%, 3%, 0) skewX(5deg) scaleY(1.12); }
      }
    `;
    document.head.appendChild(style);
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
      layer.innerHTML = '<i class="aurora-band one"></i><i class="aurora-band two"></i>';
      stage.appendChild(layer);

      return {
        destroy() {
          layer.remove();
          stage.classList.remove('effect-aurora-borealis');
        },
      };
    },
  };
}());
