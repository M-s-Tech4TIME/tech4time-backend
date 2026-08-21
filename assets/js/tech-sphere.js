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

   It can also be taken hold of and turned. Press and drag and the sphere
   follows the hand exactly, in any direction and to any angle, with nothing
   clamping how far it goes; it keeps whatever momentum the drag ended with and
   stays where it was put. That is an enhancement on top of an enhancement: the
   logos are a grid with real alt text underneath all of this, every one of
   them is in the page whether the sphere turns or not, and the sphere turns by
   itself regardless — so nothing here is reachable only by dragging.

   On a touch screen the sphere claims horizontal drags and leaves vertical
   ones to the page (touch-action: pan-y in company-profile.css). Taking both
   would mean a visitor who starts a scroll on top of the logos cannot scroll.

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

  var DRAG_DEGREES = 0.32; /* degrees of rotation per pixel dragged        */
  /* What is left of the drag's speed when the hand lets go. */
  var THROW = 0.55;

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
    this.running = false;
    this.dragging = false;
    this.pointerId = null;
    this.last = null;
    /* What was last written, so an unchanged value is not written again. */
    this.paintedX = null;
    this.paintedY = null;
    this.tick = this.render.bind(this);
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
    if (this.dragging) {
      /* The hand is setting the rotation directly. Easing towards a target
         speed here would put a lag between the pointer and the sphere, which
         is the one thing a drag must not have.

         The drawing still happens here, once per frame, rather than in the
         pointermove handler. A mouse can report far faster than the screen
         refreshes — a 1000Hz one fires roughly sixteen times per frame — and
         each write invalidates the transform of all fifty logos. Painting on
         the frame does that work once however many events arrived. */
      this.paint();
      this.frame = global.requestAnimationFrame(this.tick);
      return;
    }

    /* Ease towards the target so the sphere never changes direction abruptly. */
    this.speedY += (this.targetY - this.speedY) * EASING;
    this.speedX += (this.targetX - this.speedX) * EASING;

    this.rotY += this.speedY;
    this.rotX += this.speedX;

    /* Nothing clamps the tilt. It used to be held inside a narrow band so that
       steering by hover could not tip the sphere over, but that band also
       undid a drag: turn it right round by hand, let go, and it would crawl
       back to where it was allowed to be. The sphere stays where it is put.

       Upside down is not a broken state here — every logo counter-rotates to
       face the viewer, so the arrangement turns and the logos stay readable at
       any angle. */

    this.paint();
    this.frame = global.requestAnimationFrame(this.tick);
  };

  /* Two custom properties on one element, not fifty transform writes: the
     items read them through inheritance, so the browser recalculates the lot in
     a single pass.

     One decimal place, and nothing written when the value has not changed.
     Fifty logos have to have their transforms recalculated every time these
     move, and a tenth of a degree is far below anything the eye can see — so
     during the idle drift, which turns at six hundredths of a degree a frame,
     this skips roughly every other frame's work for no visible difference. */
  Sphere.prototype.paint = function () {
    var y = this.rotY.toFixed(1) + "deg";
    var x = this.rotX.toFixed(1) + "deg";

    if (y !== this.paintedY) {
      this.list.style.setProperty("--rot-y", y);
      this.paintedY = y;
    }
    if (x !== this.paintedX) {
      this.list.style.setProperty("--rot-x", x);
      this.paintedX = x;
    }
  };

  /* --- taking hold of it ---------------------------------------------------
     The rotation is set straight from the movement, frame by frame, so the
     sphere stays under the pointer instead of chasing it. The speeds are kept
     up to date as it goes, and what is left of them when the hand lets go is
     what it carries on with.
     ---------------------------------------------------------------------- */

  Sphere.prototype.onDragStart = function (event) {
    if (!this.running || this.dragging || !event.isPrimary) return;

    this.dragging = true;
    this.pointerId = event.pointerId;
    this.last = {x: event.clientX, y: event.clientY};
    this.speedX = 0;
    this.speedY = 0;
    this.root.classList.add("tech-sphere--held");

    /* Capture, so a drag that leaves the sphere — or the window — still ends
       up back here rather than being lost mid-turn. */
    if (this.root.setPointerCapture) {
      try {
        this.root.setPointerCapture(event.pointerId);
      } catch (error) {
        /* Some pointer types refuse capture; the drag still works. */
      }
    }
  };

  Sphere.prototype.onDragMove = function (event) {
    if (!this.dragging || event.pointerId !== this.pointerId) return;

    var dx = event.clientX - this.last.x;
    var dy = event.clientY - this.last.y;
    this.last = {x: event.clientX, y: event.clientY};

    this.rotY += dx * DRAG_DEGREES;
    /* Dragging down should tip the top of the sphere towards the viewer, which
       is a decrease in rotateX — hence the sign.

       No limit on either axis. Any direction, any angle, as far round as the
       hand cares to take it. */
    this.rotX -= dy * DRAG_DEGREES;

    /* Remembered as a per-frame speed, which is what the throw below uses. */
    this.speedY = dx * DRAG_DEGREES;
    this.speedX = -dy * DRAG_DEGREES;

    /* Deliberately no paint here. This handler can run many times between two
       frames, and each paint invalidates fifty transforms; the frame loop draws
       the result once, which is as often as a screen can show it anyway. */
  };

  Sphere.prototype.onDragEnd = function (event) {
    if (!this.dragging || (event && event.pointerId !== this.pointerId)) return;

    this.dragging = false;
    this.pointerId = null;
    this.root.classList.remove("tech-sphere--held");

    /* Keep part of the speed so it carries on turning and slows down, rather
       than stopping dead under the finger. */
    this.speedY *= THROW;
    this.speedX *= THROW;
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

  /* Listeners are bound once, for the life of the page. Enabling and disabling
     the sphere only starts and stops the animation — otherwise every trip back
     above the breakpoint would stack another set of handlers. */
  Sphere.prototype.attach = function () {
    var self = this;
    var resizeTimer;

    /* Pointer events rather than mouse events, so a pen and a finger work the
       same way a mouse does.

       Steering by hover is for pointers that hover. A touch has no hover state,
       so on a touch screen the position of a finger that is not down means
       nothing — only the drag applies. */
    this.root.addEventListener("pointermove", function (event) {
      if (!self.running) return;
      if (self.dragging) {
        self.onDragMove(event);
      } else if (event.pointerType !== "touch") {
        self.onPointerMove(event);
      }
    });
    this.root.addEventListener("pointerleave", function () {
      if (!self.dragging) {
        self.onPointerLeave();
      }
    });

    this.root.addEventListener("pointerdown", function (event) {
      self.onDragStart(event);
    });
    ["pointerup", "pointercancel"].forEach(function (name) {
      self.root.addEventListener(name, function (event) {
        self.onDragEnd(event);
      });
    });

    /* A drag across logos would otherwise start a native image drag or select
       the alt text, and the sphere would be left mid-turn. */
    this.root.addEventListener("dragstart", function (event) {
      if (self.running) {
        event.preventDefault();
      }
    });

    global.addEventListener("resize", function () {
      global.clearTimeout(resizeTimer);
      resizeTimer = global.setTimeout(function () {
        self.sync();
      }, 150);
    });
  };

  /**
   * Match the sphere to the viewport as it is now.
   *
   * Browser zoom reports as a resize — zooming in shrinks the viewport in CSS
   * pixels — so this is also what hands the sphere back when you zoom out
   * again. Enabling has to be able to follow disabling, not just precede it.
   */
  Sphere.prototype.sync = function () {
    if (global.innerWidth < MIN_WIDTH) {
      this.disable();
    } else {
      this.enable();
    }
  };

  Sphere.prototype.enable = function () {
    this.measure();

    if (this.running) {
      return;
    }

    this.root.classList.add("tech-sphere--on");
    this.running = true;
    this.render();
  };

  Sphere.prototype.disable = function () {
    if (!this.running) {
      return;
    }

    /* A drag in progress when the sphere is taken away — a resize, or a zoom
       past the breakpoint — would otherwise leave it stuck holding on. */
    this.onDragEnd(null);

    if (this.frame) {
      global.cancelAnimationFrame(this.frame);
      this.frame = null;
    }

    this.root.classList.remove("tech-sphere--on");
    this.running = false;
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
         says the same thing. So under that setting it is never built at all.

         Being narrow is different: that is a condition which can change, and
         browser zoom changes it constantly. So the sphere is always wired up
         and sync() decides, now and on every resize, whether it should be
         running. */
      var calm = global.matchMedia("(prefers-reduced-motion: reduce)");
      if (calm.matches) {
        return;
      }

      Array.prototype.forEach.call(roots, function (root) {
        var sphere = new Sphere(root);
        if (sphere.items.length > 2) {
          sphere.attach();
          sphere.sync();
        }
      });
    }
  };
})(window);
