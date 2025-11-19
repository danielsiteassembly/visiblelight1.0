/*
 * Visible Light — Glowing Border Streams (v1.2)
 * Global pulse: only 2–3 cards glow per wave (total), not per card.
 */
(function () {
  "use strict";

  // Colors
  var ORANGE = "#974C00";
  var YELLOW = "#8D8C00";

  // Visual controls
  var RADIUS = 5;                 // Keep in sync with your CSS corner radius
  var STROKE_COUNT = 1;           // One stream per card; global pulse decides which cards light
  var DASH_SPEEDS = [26, 30, 34, 38, 42]; // seconds (slow)
  var PULSE_DURATION = 2.6;       // seconds a chosen card stays lit
  var GLOBAL_MAX_ACTIVE = 3;      // 2 or 3 total strokes visible per pulse
  var WAVE_SPEED = 280;           // px/sec (outward wave speed from center)
  var WAVE_BAND = 120;            // px tolerance around the wave ring

  // State
  var CARDS = [];
  var MAX_DIST = 0;
  var reduceMotion =
    window.matchMedia &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  // Helpers
  function rand(min, max) {
    return Math.random() * (max - min) + min;
  }
  function pick(arr) {
    return arr[Math.floor(Math.random() * arr.length)];
  }
  function setUseHref(el, id) {
    // Safari still likes xlink; modern browsers accept href
    el.setAttribute("href", id);
    el.setAttributeNS("http://www.w3.org/1999/xlink", "xlink:href", id);
  }

  // SVG defs (gradient + filters)
  function buildDefs(ns, idSuffix) {
    var defs = document.createElementNS(ns, "defs");

    var grad = document.createElementNS(ns, "linearGradient");
    grad.setAttribute("id", "glow-gradient-" + idSuffix);
    grad.setAttribute("x1", "0%");
    grad.setAttribute("y1", "0%");
    grad.setAttribute("x2", "50%");
    grad.setAttribute("y2", "0%");
    var stop1 = document.createElementNS(ns, "stop");
    stop1.setAttribute("offset", "0%");
    stop1.setAttribute("stop-color", ORANGE);
    var stop2 = document.createElementNS(ns, "stop");
    stop2.setAttribute("offset", "100%");
    stop2.setAttribute("stop-color", YELLOW);
    grad.appendChild(stop1);
    grad.appendChild(stop2);

    var glowOuter = document.createElementNS(ns, "filter");
    glowOuter.setAttribute("id", "glow-outer-" + idSuffix);
    glowOuter.setAttribute("filterUnits", "userSpaceOnUse");
    glowOuter.innerHTML =
      '<feGaussianBlur stdDeviation="2.2" result="blur"/>' +
      "<feMerge><feMergeNode in=\"blur\"/><feMergeNode in=\"SourceGraphic\"/></feMerge>";

    var glowSofter = document.createElementNS(ns, "filter");
    glowSofter.setAttribute("id", "glow-softer-" + idSuffix);
    glowSofter.setAttribute("filterUnits", "userSpaceOnUse");
    glowSofter.innerHTML =
      '<feGaussianBlur stdDeviation="8" result="blur"/>' +
      "<feMerge><feMergeNode in=\"blur\"/></feMerge>";

    defs.appendChild(grad);
    defs.appendChild(glowOuter);
    defs.appendChild(glowSofter);
    return defs;
  }

  // Enhance a single card
  function enhanceCard(card, index) {
    if (card.__vlGlowInited) return;
    card.__vlGlowInited = true;

    var overlay = document.createElement("span");
    overlay.className = "glow-border";
    overlay.setAttribute("aria-hidden", "true");

    var ns = "http://www.w3.org/2000/svg";
    var svg = document.createElementNS(ns, "svg");
    svg.setAttribute("preserveAspectRatio", "none");

    var idSuffix = "vl-" + index + "-" + Date.now();
    svg.appendChild(buildDefs(ns, idSuffix));

    // The perimeter path (rounded rect) the strokes travel along
    var rect = document.createElementNS(ns, "rect");
    rect.setAttribute("x", "1");
    rect.setAttribute("y", "1");
    rect.setAttribute("rx", RADIUS);
    rect.setAttribute("ry", RADIUS);
    rect.setAttribute("width", "calc(100% - 2px)");
    rect.setAttribute("height", "calc(100% - 2px)");
    rect.setAttribute("id", "border-path-" + idSuffix);
    svg.appendChild(rect);

    // Big soft halo (only visible when card is active)
    var soft = document.createElementNS(ns, "use");
    setUseHref(soft, "#border-path-" + idSuffix);
    soft.setAttribute("class", "glow-stroke glow-soft");
    soft.style.stroke = "url(#glow-gradient-" + idSuffix + ")";
    soft.style.filter = "url(#glow-softer-" + idSuffix + ")";
    svg.appendChild(soft);

    // One moving stream
    for (var i = 0; i < STROKE_COUNT; i++) {
      var use = document.createElementNS(ns, "use");
      setUseHref(use, "#border-path-" + idSuffix);
      use.setAttribute("class", "glow-stroke");
      use.style.stroke = "url(#glow-gradient-" + idSuffix + ")";
      use.style.filter = "url(#glow-outer-" + idSuffix + ")";

      // Long gaps so it reads as a single light packet
      var dash = Math.round(rand(90, 150));
      var gap = Math.round(rand(240, 460));
      use.style.strokeDasharray = dash + " " + gap;

      // Slow dash / slight random phase
      use.style.setProperty("--glow-speed", pick(DASH_SPEEDS) + "s");
      use.style.setProperty("--glow-delay", rand(0, 1.2).toFixed(2) + "s");

      svg.appendChild(use);
    }

    overlay.appendChild(svg);
    card.appendChild(overlay);

    // Compute perimeter for smooth dash wrap + expose pulse duration
    requestAnimationFrame(function () {
      var b = card.getBoundingClientRect();
      var r = RADIUS;
      var perim = 2 * (b.width + b.height) - 8 * r + 2 * Math.PI * r;
      overlay.style.setProperty("--glow-perimeter", Math.round(perim) + "px");
      overlay.style.setProperty("--pulse-duration", PULSE_DURATION + "s");
    });

    CARDS.push({ el: card, dist: 0, active: false, lastOn: 0 });
  }

  // Determine the center source (rows container → see-the-light → body)
  function getContainer() {
    return (
      document.querySelector(".rows-container") ||
      document.querySelector(".see-the-light-container") ||
      document.body
    );
  }

  // Recompute distance of each card from the container center
  function updateDistances() {
    var cont = getContainer();
    var cr = cont.getBoundingClientRect();
    var cx = cr.left + cr.width / 2;
    var cy = cr.top + cr.height / 2;

    MAX_DIST = 0;
    for (var i = 0; i < CARDS.length; i++) {
      var r = CARDS[i].el.getBoundingClientRect();
      var x = r.left + r.width / 2;
      var y = r.top + r.height / 2;
      var d = Math.hypot(x - cx, y - cy);
      CARDS[i].dist = d;
      if (d > MAX_DIST) MAX_DIST = d;
    }
  }

  // Turn a single card on for one pulse
  function activateCard(card) {
    if (card.active) return;
    card.active = true;
    card.lastOn = performance.now();
    card.el.classList.add("glow-active");
    setTimeout(function () {
      card.active = false;
      card.el.classList.remove("glow-active");
    }, PULSE_DURATION * 1000 + 40);
  }

  // Global wave loop — pick up to 2–3 nearest cards each pulse
  function pulseLoop(t) {
    if (reduceMotion) return;

    var seconds = t / 1000;
    var cycle = MAX_DIST / WAVE_SPEED + PULSE_DURATION * 0.5; // small breather between waves
    var radius = (seconds % cycle) * WAVE_SPEED;

    // Choose cards nearest to the wave ring
    var candidates = CARDS.slice().sort(function (a, b) {
      return Math.abs(a.dist - radius) - Math.abs(b.dist - radius);
    });

    var picked = 0;
    for (var i = 0; i < candidates.length && picked < GLOBAL_MAX_ACTIVE; i++) {
      var c = candidates[i];
      if (Math.abs(c.dist - radius) <= WAVE_BAND) {
        if (!c.active && seconds - c.lastOn / 1000 > PULSE_DURATION * 0.75) {
          activateCard(c);
          picked++;
        }
      }
    }

    requestAnimationFrame(pulseLoop);
  }

  // Throttled recompute on resize/scroll
  var to = null;
  function throttledUpdate() {
    if (to) return;
    to = setTimeout(function () {
      to = null;
      updateDistances();
    }, 120);
  }
  window.addEventListener("resize", throttledUpdate, { passive: true });
  window.addEventListener("scroll", throttledUpdate, { passive: true });

  // Init + observe late-rendered blocks
  function init() {
    var list = document.querySelectorAll(".cloud-connections");
    if (!list.length) return;
    for (var i = 0; i < list.length; i++) enhanceCard(list[i], i);
    updateDistances();
    if (!reduceMotion) requestAnimationFrame(pulseLoop);
  }

  function observe() {
    if (!("MutationObserver" in window)) return;
    var mo = new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        for (var i = 0; i < m.addedNodes.length; i++) {
          var n = m.addedNodes[i];
          if (!(n instanceof Element)) continue;

          if (n.matches && n.matches(".cloud-connections")) {
            enhanceCard(n, Date.now());
            updateDistances();
          }
          var found = n.querySelectorAll
            ? n.querySelectorAll(".cloud-connections")
            : [];
          if (found.length) {
            (found.forEach || Array.prototype.forEach).call(found, function (el) {
              enhanceCard(el, Date.now());
            });
            updateDistances();
          }
        }
      });
    });
    mo.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      init();
      observe();
    });
  } else {
    init();
    observe();
  }
})();
