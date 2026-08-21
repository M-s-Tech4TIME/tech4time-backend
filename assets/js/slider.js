/* ==========================================================================
   Tech4TIME — slider.js
   The carousel behind the specialities on About and the journey photographs on
   Company Profile. One mechanism, two uses.

   PROGRESSIVE ENHANCEMENT
   Without this script every slide is on screen at once, in the grid the section
   had before — see .slider__track in components.css. The controls are in the
   markup but hidden until this file marks the slider ready, so a visitor
   without JavaScript is never shown a button that does nothing.

   WHY THE CONTROLS ARE IN THE MARKUP AND NOT BUILT HERE
   They carry icons, and icons on this site are <use href="#name"> resolved
   against symbols that tools/inject_icons.py inlines per page by reading the
   markup. A button assembled in JavaScript references a symbol no tool ever
   saw, so it would render as nothing at all.

   NOTHING IS HIDDEN FROM ANYONE
   Slides that are off screen are moved, not concealed: still rendered, still
   in the accessibility tree, still in the page for a crawler. The usual
   objection is keyboard focus wandering into a slide nobody can see, and it
   does not apply here — no slide contains anything focusable, they are a
   heading with a paragraph, and a photograph.

   AUTOMATIC ADVANCE
   WCAG 2.2.2 is the reason for the pause control: content that moves on its
   own for more than five seconds needs a way to stop it. It also stops on
   hover, on focus, and while the tab is in the background, and it never starts
   for a visitor who has asked for reduced motion.

   Exposes window.Tech4Time.slider for main.js to initialise.
   ========================================================================== */

(function (global) {
  "use strict";

  var DEFAULT_INTERVAL = 8000;
  var doc = document;

  function Slider(root) {
    this.root = root;
    this.track = root.querySelector("[data-slider-track]");
    this.slides = this.track
      ? Array.prototype.slice.call(this.track.children)
      : [];
    this.dots = Array.prototype.slice.call(
      root.querySelectorAll("[data-slider-to]")
    );
    this.prevButton = root.querySelector("[data-slider-prev]");
    this.nextButton = root.querySelector("[data-slider-next]");
    this.pauseButton = root.querySelector("[data-slider-pause]");

    this.index = 0;
    this.timer = null;
    this.paused = false;

    var declared = parseInt(root.getAttribute("data-slider-interval"), 10);
    this.interval = declared > 0 ? declared : DEFAULT_INTERVAL;

    this.reduced =
      global.matchMedia &&
      global.matchMedia("(prefers-reduced-motion: reduce)").matches;
  }

  Slider.prototype.setup = function () {
    var self = this;

    this.root.setAttribute("role", "region");
    this.root.setAttribute("aria-roledescription", "carousel");

    this.slides.forEach(function (slide, i) {
      slide.setAttribute("role", "group");
      slide.setAttribute("aria-roledescription", "slide");
      slide.setAttribute("aria-label", i + 1 + " of " + self.slides.length);
    });

    if (this.prevButton) {
      this.prevButton.addEventListener("click", function () {
        self.step(-1, true);
      });
    }
    if (this.nextButton) {
      this.nextButton.addEventListener("click", function () {
        self.step(1, true);
      });
    }

    this.dots.forEach(function (dot, i) {
      dot.addEventListener("click", function () {
        self.show(i, i > self.index ? 1 : -1);
        self.pauseByVisitor();
      });
    });

    if (this.pauseButton) {
      if (this.reduced) {
        /* Nothing is going to move on its own, so a control to stop it would
           be a lie. */
        this.pauseButton.hidden = true;
      } else {
        this.pauseButton.addEventListener("click", function () {
          if (self.paused) {
            self.paused = false;
            self.setPauseState(false);
            self.start();
          } else {
            self.pauseByVisitor();
          }
        });
      }
    }

    this.root.addEventListener("keydown", function (event) {
      if (event.key === "ArrowLeft") {
        event.preventDefault();
        self.step(-1, true);
      } else if (event.key === "ArrowRight") {
        event.preventDefault();
        self.step(1, true);
      }
    });

    /* Stop while it is being looked at or used. Neither is the visitor asking
       for it to stop, so the pause button's own state is left alone. */
    ["mouseenter", "focusin"].forEach(function (name) {
      self.root.addEventListener(name, function () { self.stop(); });
    });
    ["mouseleave", "focusout"].forEach(function (name) {
      self.root.addEventListener(name, function () { self.start(); });
    });

    doc.addEventListener("visibilitychange", function () {
      if (doc.hidden) {
        self.stop();
      } else {
        self.start();
      }
    });

    this.root.setAttribute("data-ready", "true");
    this.show(0);
    this.start();
  };

  Slider.prototype.setPauseState = function (paused) {
    if (!this.pauseButton) return;
    this.pauseButton.setAttribute(
      "aria-label",
      paused ? "Resume the slideshow" : "Pause the slideshow"
    );
    /* Both icons are in the markup and CSS shows one of them, the same way the
       dock's menu button carries its grid and its close mark. */
    this.pauseButton.setAttribute("data-paused", paused ? "true" : "false");
  };

  Slider.prototype.pauseByVisitor = function () {
    this.paused = true;
    this.setPauseState(true);
    this.stop();
    /* Once it has stopped moving on its own, a change of slide is worth
       announcing. While it rotates, announcing every slide would talk over
       whatever the visitor was actually reading. */
    this.track.setAttribute("aria-live", "polite");
  };

  Slider.prototype.show = function (index, direction) {
    var total = this.slides.length;
    this.index = ((index % total) + total) % total;

    /* Through the CSSOM, not a style attribute: style-src 'self' would refuse
       the attribute, and does not apply to this. */
    this.track.style.setProperty("--slider-index", String(this.index));

    var self = this;
    this.dots.forEach(function (dot, i) {
      if (i === self.index) {
        dot.setAttribute("aria-current", "true");
      } else {
        dot.removeAttribute("aria-current");
      }
    });

    if (direction) {
      this.root.setAttribute("data-slider-dir", direction > 0 ? "next" : "prev");
    }
  };

  Slider.prototype.step = function (delta, byVisitor) {
    this.show(this.index + delta, delta);
    if (byVisitor) {
      this.pauseByVisitor();
    }
  };

  Slider.prototype.start = function () {
    if (this.timer || this.paused || this.reduced || doc.hidden) return;
    var self = this;
    this.timer = global.setInterval(function () {
      self.show(self.index + 1, 1);
    }, this.interval);
  };

  Slider.prototype.stop = function () {
    if (!this.timer) return;
    global.clearInterval(this.timer);
    this.timer = null;
  };

  function init() {
    var roots = doc.querySelectorAll("[data-slider]");
    Array.prototype.forEach.call(roots, function (root) {
      var slider = new Slider(root);
      /* One slide is not a slideshow, and activating it would show controls
         with nothing to control. */
      if (slider.slides.length > 1) {
        slider.setup();
      }
    });
  }

  global.Tech4Time = global.Tech4Time || {};
  global.Tech4Time.slider = { init: init };
})(window);
