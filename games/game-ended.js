'use strict';

try {
    window.parent.postMessage({ type: 'game_close' }, '*');
} catch (e) {}

try {
    window.close();
} catch (e) {}
