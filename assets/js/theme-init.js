/* ==========================================================================
   Tech4TIME — theme-init.js
   Applies the visitor's saved colour-mode choice BEFORE first paint.

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
})();
