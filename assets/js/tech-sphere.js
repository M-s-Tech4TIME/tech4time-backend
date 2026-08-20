/* ==========================================================================
   Tech4TIME — tech-sphere.js
   Turns the technology list on the company profile into a slowly rotating 3D
   sphere of logos.

   PROGRESSIVE ENHANCEMENT
   The list ships as an ordinary responsive grid of logos with real alt text.
   This file only adds a class and a set of coordinates; if it never runs, the
   grid is what visitors and crawlers get, and nothing is lost but the effect.

   HOW IT WORKS
   Points are spread over the sphere with a Fibonacci (golden-spiral)
   distribution, which spaces N points evenly without the clumping at the poles
   you get from naive latitude/longitude stepping. Each logo is placed with
   translate3d inside a preserve-3d parent, and the parent is rotated per frame.

   Rotation follows the pointer: the further from the centre of the sphere the
   pointer sits, the faster it turns that way. It drifts on its own when nobody
   is pointing at it.

   TWO DETAILS WORTH KNOWING
   1. Per-frame work is two custom properties on ONE element, not fifty
      transform writes. The items read --rot-x/--rot-y through inheritance, so
      the browser recalculates them in a single pass.
   2. Those same two properties are what lets each logo counter-rotate to stay
      facing the viewer (billboarding), rather than turning edge-on as it orbits.

   The CSP here is style-src 'self', which forbids style attributes written into
   the HTML. It does not restrict the CSSOM, so setting properties from script
   like this is fine — and it is why the coordinates cannot simply be baked into
   the markup.
   ========================================================================== */

(function (global) {
  "use strict";

  var doc = global.document;

  /* Below this the sphere is too small to read fifty logos on, so the grid is
     left alone. Matches the breakpoint in company-profile.css. */
  var MIN_WIDTH = 48 * 16;

  var DRIFT = 0.06;        /* degrees per frame with no pointer            */
  var MAX_SPEED = 0.55;    /* degrees per frame at the very edge           */
  var EASING = 0.06;       /* how quickly it takes up a new target speed   */
  var TILT = 14;           /* fixed rotateX, for a little depth            */

  function Sphere(root) {
    this.root = root;
    this.list = root.querySelector(".tech-sphere__list");
    this.items = this.list
      ? Array.prototype.slice.call(this.list.children)
      : [];

    this.rotX = TILT;
    this.rotY = 0;
    this.speedX = 0;
    this.speedY = DRIFT;
    this.targetX = 0;
    this.targetY = DRIFT;
    this.frame = null;
  }

  /**
   * Fibonacci sphere: evenly spaced points on a sphere's surface.
   * Stepping latitude and longitude instead would bunch the logos at the poles.
   */
  Sphere.prototype.place = function (radius) {
    var golden = (1 + Math.sqrt(5)) / 2;
    var total = this.items.length;

    this.items.forEach(function (item, i) {
      var theta = (2 * Math.PI * i) / golden;
      var phi = Math.acos(1 - (2 * (i + 0.5)) / total);
      var sinPhi = Math.sin(phi);

      item.style.setProperty("--x", (radius * sinPhi * Math.cos(theta)).toFixed(2) + "px");
      item.style.setProperty("--y", (radius * sinPhi * Math.sin(theta)).toFixed(2) + "px");
      item.style.setProperty("--z", (radius * Math.cos(phi)).toFixed(2) + "px");
    });
  };

  Sphere.prototype.measure = function () {
    var size = Math.min(this.root.clientWidth, 560);
    this.root.style.setProperty("--sphere-size", size + "px");
    this.place(size * 0.42);
  };

  Sphere.prototype.render = function () {
    /* Ease towards the target so the sphere never changes direction abruptly. */
    this.speedY += (this.targetY - this.speedY) * EASING;
    this.speedX += (this.targetX - this.speedX) * EASING;

    this.rotY += this.speedY;
    this.rotX += this.speedX;

    /* Keep the tilt within a range that never flips the sphere over. */
    this.rotX = Math.max(-TILT - 24, Math.min(TILT + 24, this.rotX));

    this.list.style.setProperty("--rot-y", this.rotY.toFixed(2) + "deg");
    this.list.style.setProperty("--rot-x", this.rotX.toFixed(2) + "deg");

    this.frame = global.requestAnimationFrame(this.render.bind(this));
  };

  Sphere.prototype.onPointerMove = function (event) {
    var box = this.root.getBoundingClientRect();
    /* -1 at the left/top edge, +1 at the right/bottom. */
    var nx = ((event.clientX - box.left) / box.width) * 2 - 1;
    var ny = ((event.clientY - box.top) / box.height) * 2 - 1;

    this.targetY = Math.max(-1, Math.min(1, nx)) * MAX_SPEED;
    this.targetX = Math.max(-1, Math.min(1, ny)) * -MAX_SPEED;
  };

  Sphere.prototype.onPointerLeave = function () {
    this.targetY = DRIFT;
    this.targetX = 0;
  };

  Sphere.prototype.start = function () {
    var self = this;

    this.measure();
    this.root.classList.add("tech-sphere--on");

    /* Pointer events rather than mouse events, so a pen works too. Touch is
       excluded on purpose: on a touch screen a drag over the sphere is a scroll,
       and hijacking it to spin logos would be hostile. */
    this.root.addEventListener("pointermove", function (event) {
      if (event.pointerType !== "touch") {
        self.onPointerMove(event);
      }
    });
    this.root.addEventListener("pointerleave", function () {
      self.onPointerLeave();
    });

    var resizeTimer;
    global.addEventListener("resize", function () {
      global.clearTimeout(resizeTimer);
      resizeTimer = global.setTimeout(function () {
        if (global.innerWidth < MIN_WIDTH) {
          self.stop();
        } else {
          self.measure();
        }
      }, 150);
    });

    this.render();
  };

  Sphere.prototype.stop = function () {
    if (this.frame) {
      global.cancelAnimationFrame(this.frame);
      this.frame = null;
    }
    this.root.classList.remove("tech-sphere--on");
  };

  var api = (global.Tech4Time = global.Tech4Time || {});

  api.techSphere = {
    init: function () {
      var roots = doc.querySelectorAll("[data-tech-sphere]");
      if (!roots.length) {
        return;
      }

      /* A sphere of logos orbiting the screen is exactly the kind of continuous
         motion prefers-reduced-motion exists to stop, and the grid underneath
         says the same thing. So it simply is not built. */
      var calm = global.matchMedia("(prefers-reduced-motion: reduce)");
      if (calm.matches || global.innerWidth < MIN_WIDTH) {
        return;
      }

      Array.prototype.forEach.call(roots, function (root) {
        var sphere = new Sphere(root);
        if (sphere.items.length > 2) {
          sphere.start();
        }
      });
    }
  };
})(window);
