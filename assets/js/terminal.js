/* ==========================================================================
   Tech4TIME — terminal.js
   The hero terminal, typed rather than faded in.

   A command is typed a character at a time, the way someone at a keyboard
   types it; then it runs, and its output arrives in a block the way a shell
   prints. That distinction is the whole effect — output does not get typed,
   because a machine does not type.

   The caret moves with the typing. There is one caret element in the markup,
   and it is moved into whichever line is being typed, then handed back to the
   final prompt at the end, where it blinks waiting for the next command.

   PROGRESSIVE ENHANCEMENT
   Every line is real text in the markup. Without this script, or with reduced
   motion requested, the CSS in pages/home.css shows the whole session at once
   and this file does nothing. The panel is aria-hidden either way: it is a
   picture of a terminal, not information anyone needs read aloud.

   Exposes window.Tech4Time.terminal for main.js to initialise.
   ========================================================================== */

(function (global) {
  "use strict";

  /* Per character. Not a constant rate — see jitter() — because an even one is
     the thing that reads as a machine rather than a person. */
  var TYPE_MS = 42;
  /* After a command is typed, before its output starts: the pause where the
     command is actually running. */
  var RUN_MS = 340;
  /* Between the lines of one command's output. */
  var OUTPUT_MS = 130;
  /* Before the first character, so the panel is read as a terminal before
     anything starts happening in it. */
  var START_MS = 500;

  var doc = document;

  function jitter(ms) {
    return ms * (0.6 + Math.random() * 0.8);
  }

  function Session(root) {
    this.root = root;
    this.lines = Array.prototype.slice.call(
      root.querySelectorAll(".terminal__line")
    );
    this.caret = root.querySelector(".terminal__cursor");
    this.timers = [];
  }

  Session.prototype.wait = function (ms, then) {
    this.timers.push(global.setTimeout(then, ms));
  };

  /* Put the session back exactly as the markup had it, then hide it. Saving the
     text first because typing replaces it character by character. */
  Session.prototype.prepare = function () {
    var self = this;

    this.script = this.lines.map(function (line) {
      var command = line.querySelector(".terminal__command");
      return {
        line: line,
        command: command,
        /* The text to type, for a command line; output lines are revealed
           whole, so only the command needs saving. */
        text: command ? command.textContent : "",
      };
    });

    this.script.forEach(function (step) {
      step.line.setAttribute("data-typed", "false");
      if (step.command) {
        step.command.textContent = "";
      }
    });

    if (this.caret) {
      this.caret.setAttribute("data-active", "true");
    }

    self.root.setAttribute("data-typing", "true");
  };

  Session.prototype.run = function () {
    this.prepare();
    var self = this;
    this.wait(START_MS, function () { self.step(0); });
  };

  Session.prototype.step = function (index) {
    var self = this;

    if (index >= this.script.length) {
      return this.finish();
    }

    var step = this.script[index];
    step.line.setAttribute("data-typed", "true");

    if (!step.command) {
      /* Output. It arrives whole, then the next line follows. */
      return this.wait(OUTPUT_MS, function () { self.step(index + 1); });
    }

    /* A command line: move the caret to the end of it and type. */
    if (this.caret && step.line !== this.caret.parentNode) {
      step.line.appendChild(this.caret);
    }

    this.type(step, 0, function () {
      self.wait(RUN_MS, function () { self.step(index + 1); });
    });
  };

  Session.prototype.type = function (step, position, done) {
    var self = this;

    if (position > step.text.length) {
      return done();
    }

    step.command.textContent = step.text.slice(0, position);
    this.wait(jitter(TYPE_MS), function () {
      self.type(step, position + 1, done);
    });
  };

  Session.prototype.finish = function () {
    /* The caret returns to the last line, which is the bare prompt, and is left
       blinking there. */
    var last = this.lines[this.lines.length - 1];
    if (this.caret && last && this.caret.parentNode !== last) {
      last.appendChild(this.caret);
    }
    this.root.setAttribute("data-typing", "done");
  };

  function init() {
    var root = doc.querySelector("[data-terminal]");
    if (!root) return;

    var reduced =
      global.matchMedia &&
      global.matchMedia("(prefers-reduced-motion: reduce)").matches;

    /* Reduced motion, or no timers to speak of: the CSS has already shown the
       whole session and there is nothing for this to do. */
    if (reduced) return;

    var session = new Session(root);
    if (!session.lines.length) return;

    /* Do not type into a panel nobody is looking at. The hero is at the top of
       the page, so this is almost always true immediately; it matters for
       someone who arrives on a deep link and scrolls back up. */
    if (!("IntersectionObserver" in global)) {
      session.run();
      return;
    }

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          observer.disconnect();
          session.run();
        });
      },
      { threshold: 0.2 }
    );
    observer.observe(root);
  }

  global.Tech4Time = global.Tech4Time || {};
  global.Tech4Time.terminal = { init: init };
})(window);
