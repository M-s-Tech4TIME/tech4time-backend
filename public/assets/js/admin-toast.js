/* ==========================================================================
   Tech4TIME — admin-toast.js
   Says what just happened, beside the thing it happened to.

   WHAT IT IS FOR
   "Saved the contact page." was a paragraph at the top of the document. On a
   page that is several screens long — and every one of these is — that is a
   message printed somewhere the person is not looking, about work they did
   somewhere else. Worse, it was the reason the page appeared to reload: the
   only way to see it was to be taken to it.

   Now the server still renders that paragraph, exactly as before, and this
   lifts it out of the page and shows it in the corner instead. So with no
   JavaScript the message is still there, in the flow, where it always was —
   and the enhancement is that you do not have to go and find it.

   WHAT DOES NOT COME HERE
   The error list from a failed save, and the standing advisories. Those are
   not notifications: one is a list of fields to go and fix, the other is a
   condition of the page. Both belong in it, and both would be lost by
   something that fades out after four seconds.
   ========================================================================== */

(function (global) {
  "use strict";

  var doc = global.document;

  /* Long enough to read a sentence and glance back at what you were doing.
     A failure is not given one at all — it stays until it is dismissed,
     because the whole content of it is "this did not happen". */
  var LINGER = 5000;

  function region() {
    return doc.querySelector("[data-toasts]");
  }

  function dismiss(toast) {
    if (!toast || !toast.parentNode) {
      return;
    }

    toast.classList.remove("toast--in");

    /* Let it slide back out, then take it away — but never wait for a
       transitionend that a reduced-motion setting means will not arrive. */
    global.setTimeout(function () {
      if (toast.parentNode) {
        toast.parentNode.removeChild(toast);
      }
    }, 200);
  }

  /**
   * Put a message in the corner. Returns it, so a caller holding a "Working…"
   * can take it away when the answer arrives.
   *
   * kind: "ok" | "bad" | "busy". Only "ok" leaves on its own.
   */
  function show(text, kind) {
    var slot = region();
    if (!slot || !text) {
      return null;
    }

    var toast = doc.createElement("div");
    toast.className = "toast toast--" + (kind || "ok");

    var body = doc.createElement("p");
    body.className = "toast__text";
    body.textContent = text;
    toast.appendChild(body);

    if (kind === "bad") {
      var close = doc.createElement("button");
      close.className = "toast__close";
      close.type = "button";
      close.setAttribute("aria-label", "Dismiss");
      close.textContent = "×";
      close.addEventListener("click", function () { dismiss(toast); });
      toast.appendChild(close);
    }

    slot.appendChild(toast);

    /* One frame with the off-screen class still on, so there is something to
       transition FROM. Adding the class in the same frame as the element is
       how a slide-in becomes a pop-in. */
    global.requestAnimationFrame(function () {
      global.requestAnimationFrame(function () {
        toast.classList.add("toast--in");
      });
    });

    if (kind !== "bad" && kind !== "busy") {
      global.setTimeout(function () { dismiss(toast); }, LINGER);
    }

    return toast;
  }

  /**
   * Move any confirmation the server rendered into the corner.
   *
   * Called on load and after every swap. The paragraph is removed from the
   * page once it has been read out of it, so the message exists in exactly one
   * place and the top of the form is not left holding a gap.
   */
  function lift() {
    var notices = doc.querySelectorAll("#admin-main .admin__notice--ok");

    Array.prototype.forEach.call(notices, function (notice) {
      var text = notice.textContent.replace(/\s+/g, " ").trim();
      if (text !== "") {
        show(text, "ok");
      }
      if (notice.parentNode) {
        notice.parentNode.removeChild(notice);
      }
    });
  }

  var api = (global.Tech4Time = global.Tech4Time || {});

  api.adminToast = {
    show: show,
    dismiss: dismiss,
    lift: lift,
    init: function () {
      lift();
    }
  };
})(window);
