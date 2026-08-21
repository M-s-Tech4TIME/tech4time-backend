/* ==========================================================================
   Tech4TIME — theme-init.js
   Everything that has to be decided BEFORE first paint: the visitor's saved
   colour-mode choice, and whether the scroll reveal is armed.

   This is the one script loaded synchronously in <head> (no defer/async).
   It has to run before the browser paints, or the page renders in the default
   mode for a frame and then flips — the "flash of wrong theme".

   The project forbids inline <script> so a strict Content-Security-Policy can
   be applied. An external, render-blocking file achieves the same result: it is
   a few hundred bytes from the same origin, already in the HTTP cache after the
   first page, and needs no 'unsafe-inline' in the CSP.

   Deliberately minimal: it only applies an EXPLICIT stored choice. With nothing
   stored, no data-theme attribute is set and the prefers-color-scheme block in
   theme.css decides — which keeps OS-preference support working with
   JavaScript disabled.
   ========================================================================== */

(function () {
  "use strict";

  var STORAGE_KEY = "tech4time-theme";

  try {
    var stored = window.localStorage.getItem(STORAGE_KEY);
    if (stored === "light" || stored === "dark") {
      document.documentElement.setAttribute("data-theme", stored);
    }
  } catch (error) {
    /* localStorage can throw in private mode or when storage is blocked.
       The OS preference remains the fallback, so there is nothing to do. */
  }

  /* ------------------------------------------------------------------------
     Arm the scroll reveal.

     animations.js is deferred, so it runs only once the document has been
     parsed — and on a long page over a slow connection the browser may well
     have painted the top of it by then. If the hidden state were applied from
     there, content would appear, disappear, and fade back in. Arming it here,
     before the first frame, means an element is either hidden from the start
     or never hidden at all.

     Only armed when the reveal can actually happen. The visitor who asked for
     less motion, and the browser with no IntersectionObserver, both get the
     page with nothing hidden — the reveal is decoration, and decoration must
     never be the reason something cannot be read.
     ------------------------------------------------------------------------ */
  var root = document.documentElement;
  var wantsMotion = !(
    window.matchMedia &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches
  );

  if (wantsMotion && "IntersectionObserver" in window) {
    root.classList.add("js-reveal");

    /* The safety net for the case this file cannot see: animations.js failing
       to arrive at all — a dropped request, a proxy mangling it, a parse error.
       Without this, hiding content here would hide it permanently. It marks the
       root when it starts; if the load event arrives and no such mark exists,
       the hidden state is lifted and the page is simply static. */
    window.addEventListener("load", function () {
      if (!root.hasAttribute("data-reveal-ready")) {
        root.classList.remove("js-reveal");
      }
    });
  }
})();
