/**
 * Khazan - falling autumn leaves effect (canvas, rAF, pauses when tab is hidden).
 * Author: امیــــــــررضا پـروانه
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'khazan_leaves_enabled';
  var LEAF_COUNT = 14;
  var COLORS = ['#b91c1c', '#ea580c', '#f59e0b'];
  var SHAPES = [
    'M50 4l9 20 14-6-4 22 22-6-11 18 14 8-25 8 4 14-21-6v20h-8V78l-21 6 4-14-25-8 14-8-11-18 22 6-4-22 14 6z',
    'M50 6C26 30 22 60 50 94c28-34 24-64 0-88z',
    'M50 90C16 68 10 38 30 20c10-8 18-2 20 6 2-8 10-14 20-6 20 18 14 48-20 70z'
  ];

  var canvas = null;
  var ctx = null;
  var paths = null;
  var leaves = [];
  var raf = 0;
  var lastTime = 0;
  var enabled = false;
  var width = 0;
  var height = 0;

  function readStored() {
    try {
      var v = window.localStorage.getItem(STORAGE_KEY);
      if (v === '1') return true;
      if (v === '0') return false;
    } catch (e) {}
    return !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  function writeStored(value) {
    try { window.localStorage.setItem(STORAGE_KEY, value ? '1' : '0'); } catch (e) {}
  }

  function rand(min, max) { return min + Math.random() * (max - min); }

  function createLeaf(initial) {
    return {
      shape: Math.floor(Math.random() * SHAPES.length),
      color: COLORS[Math.floor(Math.random() * COLORS.length)],
      size: rand(16, 30),
      baseX: rand(0, width),
      y: initial ? rand(-0.05, 0.55) * height : rand(-60, -20),
      speed: rand(32, 62),
      swayAmp: rand(24, 70),
      swayFreq: rand(0.5, 1.2),
      phase: rand(0, Math.PI * 2),
      rot: rand(0, Math.PI * 2),
      rotSpeed: rand(-0.9, 0.9),
      flip: rand(0, Math.PI * 2),
      flipSpeed: rand(0.8, 2),
      age: 0
    };
  }

  function resize() {
    if (!canvas) return;
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    width = window.innerWidth;
    height = window.innerHeight;
    canvas.width = Math.floor(width * dpr);
    canvas.height = Math.floor(height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function frame(time) {
    if (!enabled) return;
    var dt = Math.min((time - lastTime) / 1000 || 0, 0.05);
    lastTime = time;
    ctx.clearRect(0, 0, width, height);

    var fadeStart = height * 0.72;
    var fadeEnd = height * 0.95;

    for (var i = 0; i < leaves.length; i++) {
      var l = leaves[i];
      l.age += dt;
      l.y += l.speed * dt;
      l.rot += l.rotSpeed * dt;
      l.flip += l.flipSpeed * dt;

      var alpha = l.y < fadeStart ? 1 : Math.max(0, 1 - (l.y - fadeStart) / (fadeEnd - fadeStart));
      if (l.y > fadeEnd) { leaves[i] = createLeaf(false); continue; }
      if (l.y < 0) alpha = Math.max(0, 1 + l.y / 40);

      var x = l.baseX + Math.sin(l.age * l.swayFreq + l.phase) * l.swayAmp;
      var scaleX = Math.max(0.18, Math.abs(Math.cos(l.flip)));

      ctx.save();
      ctx.globalAlpha = alpha * 0.85;
      ctx.translate(x, l.y);
      ctx.rotate(l.rot);
      ctx.scale(scaleX, 1);
      var s = l.size / 100;
      ctx.scale(s, s);
      ctx.translate(-50, -50);
      ctx.fillStyle = l.color;
      ctx.fill(paths[l.shape]);
      ctx.restore();
    }
    raf = window.requestAnimationFrame(frame);
  }

  function start() {
    if (raf || !enabled || document.hidden) return;
    lastTime = performance.now();
    raf = window.requestAnimationFrame(frame);
  }

  function stop() {
    if (raf) window.cancelAnimationFrame(raf);
    raf = 0;
  }

  function mount() {
    if (canvas) return;
    canvas = document.createElement('canvas');
    canvas.id = 'khazan-leaves-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    canvas.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:99990;';
    document.body.appendChild(canvas);
    ctx = canvas.getContext('2d');
    paths = SHAPES.map(function (d) { return new Path2D(d); });
    resize();
    leaves = [];
    for (var i = 0; i < LEAF_COUNT; i++) leaves.push(createLeaf(true));
  }

  function unmount() {
    stop();
    if (canvas && canvas.parentNode) canvas.parentNode.removeChild(canvas);
    canvas = null;
    ctx = null;
  }

  function setEnabled(value, persist) {
    enabled = !!value;
    if (persist) writeStored(enabled);
    if (enabled) { mount(); start(); } else { unmount(); }
  }

  window.addEventListener('resize', function () {
    if (!canvas) return;
    resize();
    leaves.forEach(function (l) { if (l.baseX > width) l.baseX = rand(0, width); });
  });

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) stop(); else start();
  });

  window.KhazanLeaves = {
    isEnabled: function () { return enabled; },
    set: function (value) { setEnabled(value, true); },
    toggle: function () { setEnabled(!enabled, true); return enabled; },
    init: function () { setEnabled(readStored(), false); }
  };

  window.KhazanLeaves.init();
})();
