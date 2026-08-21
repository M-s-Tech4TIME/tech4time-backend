/* ==========================================================================
   Tech4TIME — editor.js
   A small rich text editor for the job post fields in /admin/.

   PROGRESSIVE ENHANCEMENT
   Every field ships as an ordinary <textarea> holding HTML. This replaces it
   with a formatting surface and keeps the two in sync; if the file never
   loads, the textarea is still there and still saves. Nothing depends on this
   running.

   WHY NOT A LIBRARY
   The CSP is script-src 'self', so nothing loads from a CDN, and there is no
   build step to bundle a package with. Writing the few commands actually
   needed is smaller than the machinery required to vendor an editor.

   ALIGNMENT IS A CLASS, NOT A STYLE
   document.execCommand("justifyCenter") writes style="text-align:center".
   The CSP is style-src 'self', so that attribute is blocked on the public
   page: it would look right here and do nothing there. Alignment is applied
   by toggling a class on the block element instead, which is also what the
   server keeps — see careers_sanitise_html() in lib/careers.php.

   ON execCommand
   Deprecated, and still the only thing every browser implements for inline
   formatting. The alternative is a selection-and-range engine of our own,
   which is a large amount of subtle code to write and get wrong. Where its
   output is unacceptable — alignment — it is not used.

   WHATEVER THIS PRODUCES IS RE-SANITISED ON THE SERVER. This file is a
   convenience for whoever is typing, not a security boundary; the allow-list
   that matters runs in PHP.
   ========================================================================== */

(function (global) {
  "use strict";

  var doc = global.document;

  var ALIGNMENTS = ["ta-left", "ta-center", "ta-right", "ta-justify"];

  /* Toolbar icons.

     Drawn here rather than pulled from the sprite because this toolbar is
     built by script: the sprite is inlined into pages by tools/inject_icons.py,
     which only walks pages/, and the admin is not one of those. Bold, italic
     and underline stay as letterforms — a B in bold says more than any glyph.

     The bars are plain rectangles so they stay crisp at 1rem, where a detailed
     path turns to mush. */
  function svg(viewBox, body) {
    return '<svg class="rte__icon" viewBox="' + viewBox + '" aria-hidden="true" ' +
           'focusable="false">' + body + '</svg>';
  }

  function bars(rows) {
    return rows.map(function (row) {
      return '<rect x="' + row[0] + '" y="' + row[1] + '" width="' + row[2] +
             '" height="2" rx="1"/>';
    }).join("");
  }

  var ROWS = [4, 8.5, 13, 17.5];

  var ICONS = {
    "align-left": svg("0 0 24 24", bars([
      [3, ROWS[0], 18], [3, ROWS[1], 11], [3, ROWS[2], 18], [3, ROWS[3], 11]
    ])),
    "align-center": svg("0 0 24 24", bars([
      [3, ROWS[0], 18], [6.5, ROWS[1], 11], [3, ROWS[2], 18], [6.5, ROWS[3], 11]
    ])),
    "align-right": svg("0 0 24 24", bars([
      [3, ROWS[0], 18], [10, ROWS[1], 11], [3, ROWS[2], 18], [10, ROWS[3], 11]
    ])),
    "align-justify": svg("0 0 24 24", bars([
      [3, ROWS[0], 18], [3, ROWS[1], 18], [3, ROWS[2], 18], [3, ROWS[3], 18]
    ])),

    "list-ul": svg("0 0 24 24",
      '<circle cx="4.5" cy="6" r="1.75"/><circle cx="4.5" cy="12" r="1.75"/>' +
      '<circle cx="4.5" cy="18" r="1.75"/>' +
      bars([[9, 5, 12], [9, 11, 12], [9, 17, 12]])),

    /* Numerals as text: three glyphs at this size are more legible drawn by
       the font than approximated with paths. */
    "list-ol": svg("0 0 24 24",
      '<text x="1" y="8.5" font-size="8" font-weight="700">1</text>' +
      '<text x="1" y="14.5" font-size="8" font-weight="700">2</text>' +
      '<text x="1" y="20.5" font-size="8" font-weight="700">3</text>' +
      bars([[10, 5, 11], [10, 11, 11], [10, 17, 11]])),

    /* Font Awesome Free, the same source as the site's sprite. */
    "link": svg("0 0 640 512", '<path d="M579.8 267.7c56.5-56.5 56.5-148 0-204.5c-50-50-128.8-56.5-186.3-15.4l-1.6 1.1c-14.4 10.3-17.7 30.3-7.4 44.6s30.3 17.7 44.6 7.4l1.6-1.1c32.1-22.9 76-19.3 103.8 8.6c31.5 31.5 31.5 82.5 0 114L422.3 334.8c-31.5 31.5-82.5 31.5-114 0c-27.9-27.9-31.5-71.8-8.6-103.8l1.1-1.6c10.3-14.4 6.9-34.4-7.4-44.6s-34.4-6.9-44.6 7.4l-1.1 1.6C206.5 251.2 213 330 263 380c56.5 56.5 148 56.5 204.5 0L579.8 267.7zM60.2 244.3c-56.5 56.5-56.5 148 0 204.5c50 50 128.8 56.5 186.3 15.4l1.6-1.1c14.4-10.3 17.7-30.3 7.4-44.6s-30.3-17.7-44.6-7.4l-1.6 1.1c-32.1 22.9-76 19.3-103.8-8.6C74 372 74 321 105.5 289.5L217.7 177.2c31.5-31.5 82.5-31.5 114 0c27.9 27.9 31.5 71.8 8.6 103.9l-1.1 1.6c-10.3 14.4-6.9 34.4 7.4 44.6s34.4 6.9 44.6-7.4l1.1-1.6C433.5 260.8 427 182 377 132c-56.5-56.5-148-56.5-204.5 0L60.2 244.3z"/>')
  };


  /* `tags` is how the pressed state is decided — see refresh(). */
  var TOOLS = [
    { label: "B", title: "Bold (Ctrl+B)", command: "bold",
      className: "rte__btn--bold", tags: ["strong", "b"] },
    { label: "I", title: "Italic (Ctrl+I)", command: "italic",
      className: "rte__btn--italic", tags: ["em", "i"] },
    { label: "U", title: "Underline (Ctrl+U)", command: "underline",
      className: "rte__btn--underline", tags: ["u"] },
    { separator: true },
    { icon: "list-ul", title: "Bulleted list", command: "insertUnorderedList", tags: ["ul"] },
    { icon: "list-ol", title: "Numbered list", command: "insertOrderedList", tags: ["ol"] },
    { icon: "link", title: "Insert link", command: "createLink", tags: ["a"] },
    { separator: true },
    { icon: "align-left", title: "Align left", align: "ta-left" },
    { icon: "align-center", title: "Align centre", align: "ta-center" },
    { icon: "align-right", title: "Align right", align: "ta-right" },
    { icon: "align-justify", title: "Justify", align: "ta-justify" }
  ];

  function Editor(textarea) {
    this.textarea = textarea;
    this.buttons = [];

    this.root = doc.createElement("div");
    this.root.className = "rte";

    this.toolbar = doc.createElement("div");
    this.toolbar.className = "rte__toolbar";
    this.toolbar.setAttribute("role", "toolbar");
    this.toolbar.setAttribute("aria-label", "Text formatting");

    this.surface = doc.createElement("div");
    this.surface.className = "rte__surface";
    this.surface.setAttribute("contenteditable", "true");
    this.surface.setAttribute("role", "textbox");
    this.surface.setAttribute("aria-multiline", "true");

    /* The textarea's own <span class="admin__label"> already names this field;
       pointing at it means the editor is announced with the same name rather
       than as an anonymous text box. */
    var label = textarea.closest(".admin__field");
    var labelText = label ? label.querySelector(".admin__label") : null;
    if (labelText) {
      if (!labelText.id) {
        labelText.id = "rte-label-" + textarea.name;
      }
      this.surface.setAttribute("aria-labelledby", labelText.id);
    }
  }

  Editor.prototype.build = function () {
    var self = this;

    TOOLS.forEach(function (tool) {
      if (tool.separator) {
        var hr = doc.createElement("span");
        hr.className = "rte__separator";
        hr.setAttribute("aria-hidden", "true");
        self.toolbar.appendChild(hr);
        return;
      }

      var button = doc.createElement("button");
      button.type = "button";
      button.className = "rte__btn" + (tool.className ? " " + tool.className : "");
      if (tool.icon) {
        button.innerHTML = ICONS[tool.icon];
      } else {
        button.textContent = tool.label;
      }
      button.title = tool.title;
      button.setAttribute("aria-label", tool.title);
      button.setAttribute("aria-pressed", "false");
      /* Buttons in a toolbar are one tab stop, arrow keys move within it. */
      button.tabIndex = -1;

      button.addEventListener("mousedown", function (event) {
        /* Keep the caret where it is: focusing the button would collapse the
           selection before the command could act on it. */
        event.preventDefault();
      });

      button.addEventListener("click", function () {
        self.run(tool);
      });

      self.buttons.push({ tool: tool, el: button });
      self.toolbar.appendChild(button);
    });

    if (this.buttons.length) {
      this.buttons[0].el.tabIndex = 0;
    }

    this.toolbar.addEventListener("keydown", this.onToolbarKey.bind(this));

    this.surface.innerHTML = this.textarea.value;
    this.root.appendChild(this.toolbar);
    this.root.appendChild(this.surface);

    this.textarea.parentNode.insertBefore(this.root, this.textarea);
    this.textarea.classList.add("rte__source");
    this.textarea.setAttribute("hidden", "hidden");
    this.textarea.setAttribute("aria-hidden", "true");
    this.textarea.tabIndex = -1;

    /* If this editor is ever placed inside a <label> again, that label will
       forward a click from anywhere inside it to its first labelable
       descendant — which, since the toolbar is inserted before the textarea,
       is the Bold button. Every click in the text would then silently press
       it. The markup keeps these fields in a <div> for exactly this reason;
       this cancels the activation if that ever changes, because the symptom
       points nowhere near the cause. */
    if (this.textarea.closest("label")) {
      this.root.addEventListener("click", function (event) {
        event.preventDefault();
      });
    }

    this.surface.addEventListener("input", this.sync.bind(this));
    this.surface.addEventListener("blur", this.sync.bind(this));
    this.surface.addEventListener("keydown", this.onKey.bind(this));

    ["keyup", "mouseup", "focus"].forEach(function (type) {
      self.surface.addEventListener(type, self.refresh.bind(self));
    });

    var form = this.textarea.form;
    if (form) {
      /* Belt and braces: the input handler has already written it, but a
         submit triggered before a blur would otherwise miss the last edit. */
      form.addEventListener("submit", this.sync.bind(this));
    }

    /* Produce tags, not inline styles, for the commands that offer a choice.
       Without this Chrome writes <span style="font-weight:bold">, which the
       CSP blocks and the sanitiser would strip — losing the formatting. */
    try {
      doc.execCommand("styleWithCSS", false, false);
    } catch (error) {
      /* Firefox throws if this is called with no editable focus; harmless. */
    }

    this.refresh();
  };

  Editor.prototype.sync = function () {
    this.textarea.value = this.surface.innerHTML;
  };

  /** The element the caret sits in, or null if the caret is not in here. */
  Editor.prototype.selectionNode = function () {
    var selection = global.getSelection();
    if (!selection || !selection.rangeCount) {
      return null;
    }

    var node = selection.getRangeAt(0).startContainer;
    if (node.nodeType === 3) {
      node = node.parentNode;
    }

    /* With several editors on the page, the selection may belong to another
       one — or to nothing at all. Acting on it then would format the wrong
       field. */
    return this.surface.contains(node) ? node : null;
  };

  /**
   * Is the caret inside one of these elements?
   *
   * This replaces document.queryCommandState, which cannot be trusted for
   * this. In Blink it answers by looking at COMPUTED STYLE, so any text that
   * merely renders bold reports as bold — and the button lights up over a
   * word that carries no <strong> at all. Clicking it to "turn it off" then
   * runs execCommand("bold") against a document whose real state is not bold,
   * which turns bold ON. That is the whole of the double-click bug: a wrong
   * reading making the toggle work backwards.
   *
   * Walking the tree answers the question actually being asked, and matches
   * exactly what careers_sanitise_html() will store.
   */
  Editor.prototype.within = function (tags) {
    var node = this.selectionNode();

    while (node && node !== this.surface) {
      if (node.nodeType === 1 &&
          tags.indexOf(node.nodeName.toLowerCase()) !== -1) {
        return true;
      }
      node = node.parentNode;
    }
    return false;
  };

  /** The block element the caret sits in, within this editor. */
  Editor.prototype.currentBlock = function () {
    var node = this.selectionNode();

    while (node && node !== this.surface) {
      var tag = node.nodeName.toLowerCase();
      if (tag === "p" || tag === "li" || tag === "ul" || tag === "ol") {
        return node;
      }
      node = node.parentNode;
    }
    return null;
  };

  Editor.prototype.run = function (tool) {
    this.surface.focus();

    /* focus() alone does not guarantee a selection inside the surface — an
       empty field, or a click that landed between editors, leaves it
       elsewhere. Put the caret in this surface before any command runs. */
    if (!this.selectionNode()) {
      var range = doc.createRange();
      range.selectNodeContents(this.surface);
      range.collapse(false);
      var selection = global.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    }

    if (tool.align) {
      this.align(tool.align);
    } else if (tool.command === "createLink") {
      this.link();
    } else {
      doc.execCommand(tool.command, false, null);
    }

    this.sync();
    this.refresh();
  };

  Editor.prototype.align = function (className) {
    var block = this.currentBlock();

    /* Typing into an empty editor leaves bare text nodes with no block to
       align, so give them one first. */
    if (!block) {
      doc.execCommand("formatBlock", false, "p");
      block = this.currentBlock();
    }
    if (!block) {
      return;
    }

    var already = block.classList.contains(className);
    ALIGNMENTS.forEach(function (name) {
      block.classList.remove(name);
    });
    if (!already) {
      block.classList.add(className);
    }
    if (!block.className) {
      block.removeAttribute("class");
    }
  };

  Editor.prototype.link = function () {
    var selection = global.getSelection();
    var selected = selection ? selection.toString() : "";

    if (!selected) {
      global.alert("Select the words you want to link first.");
      return;
    }

    var url = global.prompt("Link address", "https://");
    if (!url) {
      return;
    }

    url = url.trim();
    if (!/^(https?:\/\/|mailto:|\/)/i.test(url)) {
      global.alert(
        "Links must start with https://, mailto: or / — anything else is " +
        "removed when the post is saved."
      );
      return;
    }

    doc.execCommand("createLink", false, url);
  };

  /** Reflect the state of the caret in the toolbar. */
  Editor.prototype.refresh = function () {
    var self = this;
    var block = this.currentBlock();

    this.buttons.forEach(function (entry) {
      var on = false;

      if (entry.tool.align) {
        on = !!block && block.classList.contains(entry.tool.align);
      } else if (entry.tool.tags) {
        on = self.within(entry.tool.tags);
      }

      entry.el.setAttribute("aria-pressed", on ? "true" : "false");
      entry.el.classList.toggle("rte__btn--on", on);
    });
  };

  Editor.prototype.onKey = function (event) {
    if (!event.ctrlKey && !event.metaKey) {
      return;
    }

    var map = { b: "bold", i: "italic", u: "underline" };
    var command = map[event.key.toLowerCase()];

    if (command) {
      event.preventDefault();
      doc.execCommand(command, false, null);
      this.sync();
      this.refresh();
    }
  };

  /* A toolbar is one tab stop; the arrow keys move between its buttons. That
     is the expected behaviour for role="toolbar", and it keeps the editor
     itself only one Tab away from the field before it. */
  Editor.prototype.onToolbarKey = function (event) {
    var keys = { ArrowRight: 1, ArrowLeft: -1, Home: "first", End: "last" };
    if (!(event.key in keys)) {
      return;
    }

    event.preventDefault();

    var items = this.buttons.map(function (entry) { return entry.el; });
    var current = items.indexOf(doc.activeElement);
    if (current < 0) {
      current = 0;
    }

    var next;
    if (keys[event.key] === "first") {
      next = 0;
    } else if (keys[event.key] === "last") {
      next = items.length - 1;
    } else {
      next = (current + keys[event.key] + items.length) % items.length;
    }

    items.forEach(function (el) { el.tabIndex = -1; });
    items[next].tabIndex = 0;
    items[next].focus();
  };

  var api = (global.Tech4Time = global.Tech4Time || {});

  api.editor = {
    init: function () {
      var fields = doc.querySelectorAll("textarea[data-editor]");

      Array.prototype.forEach.call(fields, function (textarea) {
        var editor = new Editor(textarea);
        editor.build();
      });
    }
  };
})(window);
