/* ==========================================================================
   Tech4TIME — admin-init.js
   Starts the admin page's modules.

   A separate file rather than an inline <script> because the CSP is
   script-src 'self'. It is the same reason theme-init.js exists.
   ========================================================================== */

(function (global) {
  "use strict";

  function start() {
    var api = global.Tech4Time;
    if (api && api.editor) {
      api.editor.init();
    }
  }

  if (global.document.readyState === "loading") {
    global.document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})(window);
