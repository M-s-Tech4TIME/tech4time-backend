#!/usr/bin/env python3
"""
A minimal HTML tree with source offsets, for tools that need to edit markup
structurally rather than by regex.

Build tool. NOT deployed to the web server (see tools/README.md).

WHY THIS EXISTS
Neither BeautifulSoup nor lxml is installed on this machine, and this project
will not grow a dependency it does not need. html.parser is in the standard
library but is a stream parser: it reports tags as it passes them and keeps no
tree, so it cannot answer "what are this element's children".

This wraps it in the smallest thing that can: a Node tree where every element
remembers where its start tag begins in the source. That offset is what makes
safe edits possible — an attribute can be inserted immediately after the tag
name without re-serialising the document, so nothing else on the page moves and
the diff stays readable.

Re-serialising was the alternative and it is the wrong one here: it would
reformat all fifteen pages, bury the real change in whitespace noise, and put
the shared header/footer blocks at risk of drifting out of byte-identity with
tools/templates/ (see tools/check_shared_markup.py).

NOT A GENERAL PARSER. It handles the markup this project writes: well-formed,
hand-authored, no unclosed <p>, no <table> soup. Malformed input will produce a
malformed tree rather than an error.
"""

from html.parser import HTMLParser

# Elements with no closing tag. <svg> children are XML-ish, but the void set is
# the same in practice for the markup here.
VOID = {
    "area", "base", "br", "col", "embed", "hr", "img", "input",
    "link", "meta", "param", "source", "track", "wbr",
}


class Node:
    __slots__ = ("tag", "attrs", "start", "parent", "children")

    def __init__(self, tag, attrs, start, parent):
        self.tag = tag
        self.attrs = attrs          # dict; later duplicates win, as in browsers
        self.start = start          # offset of the "<" of the start tag
        self.parent = parent
        self.children = []          # element children only; text is not kept

    @property
    def classes(self) -> set[str]:
        return set(self.attrs.get("class", "").split())

    def has(self, *names: str) -> bool:
        return bool(self.classes.intersection(names))

    def ancestors(self):
        node = self.parent
        while node is not None:
            yield node
            node = node.parent

    def descendants(self):
        for child in self.children:
            yield child
            yield from child.descendants()

    def find(self, tag=None, cls=None):
        """Every descendant matching a tag name and/or a single class."""
        for node in self.descendants():
            if tag and node.tag != tag:
                continue
            if cls and cls not in node.classes:
                continue
            yield node

    def __repr__(self):
        cls = self.attrs.get("class", "")
        return f"<{self.tag}{' class=' + cls if cls else ''} @{self.start}>"


class _Builder(HTMLParser):
    def __init__(self, source: str):
        super().__init__(convert_charrefs=True)
        self.source = source
        # Line offsets, so getpos() can be turned into an absolute offset.
        self.line_offsets = [0]
        for line in source.splitlines(keepends=True):
            self.line_offsets.append(self.line_offsets[-1] + len(line))
        self.root = Node("#document", {}, 0, None)
        self.stack = [self.root]

    def _offset(self) -> int:
        line, col = self.getpos()
        return self.line_offsets[line - 1] + col

    def handle_starttag(self, tag, attrs):
        node = Node(tag, dict(attrs), self._offset(), self.stack[-1])
        self.stack[-1].children.append(node)
        if tag not in VOID:
            self.stack.append(node)

    def handle_startendtag(self, tag, attrs):
        node = Node(tag, dict(attrs), self._offset(), self.stack[-1])
        self.stack[-1].children.append(node)

    def handle_endtag(self, tag):
        # Walk back to the matching open tag. Anything skipped was unclosed;
        # closing it here keeps one stray tag from re-parenting the rest of the
        # document.
        for i in range(len(self.stack) - 1, 0, -1):
            if self.stack[i].tag == tag:
                del self.stack[i:]
                return


def parse(source: str) -> Node:
    """Parse a document and return its root node."""
    builder = _Builder(source)
    builder.feed(source)
    builder.close()
    return builder.root


def insert_attribute(source: str, edits: list[tuple[int, str]]) -> str:
    """
    Insert attribute text into start tags.

    `edits` is a list of (node.start, ' data-foo="bar"'). Applied back to front
    so that each offset still refers to the text it was measured against.
    """
    out = source
    for start, text in sorted(edits, reverse=True):
        # Step over "<" and the tag name; the insertion point is the first
        # place a new attribute is legal, and is stable regardless of how the
        # existing attributes are wrapped across lines.
        i = start + 1
        while i < len(out) and (out[i].isalnum() or out[i] in "-:"):
            i += 1
        out = out[:i] + text + out[i:]
    return out
