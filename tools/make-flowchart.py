"""Detailed backend flowchart: the whole pipeline, stage-coloured, with shapes."""
from PIL import Image, ImageDraw, ImageFont

W = 1560
BG = (255, 255, 255)
INK = (26, 26, 26)
GREY = (92, 102, 112)
WHITE = (255, 255, 255)

S1 = ((31, 78, 140), (233, 240, 249))    # indexing        blue
S2 = ((32, 114, 74), (233, 246, 238))    # source page     green
S3 = ((176, 106, 18), (253, 244, 230))   # shortlist       orange
S4 = ((104, 60, 150), (243, 236, 250))   # destination     purple
S5 = ((176, 42, 55), (252, 235, 237))    # anchor          red
S6 = ((22, 112, 118), (231, 245, 246))   # score + place   teal
NEUT = ((62, 72, 82), (244, 246, 248))
SKIP = ((150, 90, 20), (253, 247, 232))

F = "C:/Windows/Fonts/segoeui.ttf"
FB = "C:/Windows/Fonts/segoeuib.ttf"
f_h1 = ImageFont.truetype(FB, 30)
f_sub = ImageFont.truetype(F, 15)
f_stage = ImageFont.truetype(FB, 17)
f_t = ImageFont.truetype(FB, 16)
f_d = ImageFont.truetype(F, 13)
f_lbl = ImageFont.truetype(FB, 12)
f_note = ImageFont.truetype(F, 13)

img = Image.new("RGB", (W, 4400), BG)
d = ImageDraw.Draw(img)

LEFT, RIGHT = 265, 885           # rectangle spine
CX = (LEFT + RIGHT) // 2         # 575
DIA_HALF = 400                   # diamonds reach CX +/- this
SKX0, SKX1 = 1010, 1530          # discard column
BAND = 62


def tw(text, font):
    a, _, c, _ = d.textbbox((0, 0), text, font=font)
    return c - a


def centre(text, font, cx, cy, fill):
    a, b, c, e = d.textbbox((0, 0), text, font=font)
    d.text((cx - (c - a) / 2, cy - (e - b) / 2 - b), text, font=font, fill=fill)


def wrap(text, font, maxw):
    """Greedy wrap into as many lines as needed."""
    words, lines, cur = text.split(), [], ""
    for w in words:
        trial = (cur + " " + w).strip()
        if tw(trial, font) <= maxw or not cur:
            cur = trial
        else:
            lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return lines


def draw_text_block(title, desc, cx, cy, maxw, ink=INK, sub=GREY):
    """Title (1 line) above wrapped description, vertically centred on cy."""
    dl = wrap(desc, f_d, maxw) if desc else []
    total = 20 + len(dl) * 17
    y = cy - total / 2
    centre(title, f_t, cx, y + 10, ink)
    for i, ln in enumerate(dl):
        centre(ln, f_d, cx, y + 28 + i * 17, sub)


AI_PUR = (124, 58, 167)


def badge(x_right, y_edge, text, kind):
    """Ribbon straddling a node's top border. kind: 'ai' or 'no'."""
    h, w = 21, tw(text, f_lbl) + 20
    x0 = x_right - w
    if kind == "ai":
        d.rounded_rectangle([x0, y_edge - h / 2, x_right, y_edge + h / 2],
                            radius=h / 2, fill=AI_PUR, outline=AI_PUR, width=1)
        centre(text, f_lbl, (x0 + x_right) / 2, y_edge, WHITE)
    else:
        d.rounded_rectangle([x0, y_edge - h / 2, x_right, y_edge + h / 2],
                            radius=h / 2, fill=WHITE, outline=(158, 166, 174), width=1)
        centre(text, f_lbl, (x0 + x_right) / 2, y_edge, (118, 126, 134))


def node(y, title, desc, pal, shape="rect", bdg=None):
    """Draw a spine node. Returns (top, bottom, right_edge)."""
    dark, light = pal
    if shape == "diamond":
        # Text sits in a band around the middle; work out the height needed so
        # the widest line still fits inside the sloping sides.
        lines = wrap(desc, f_d, 520) if desc else []
        band = 20 + len(lines) * 17
        h = int(band / 0.52) + 20
        mid = y + h / 2
        x0, x1 = CX - DIA_HALF, CX + DIA_HALF
        d.polygon([(CX, y), (x1, mid), (CX, y + h), (x0, mid)], fill=light)
        d.line([(CX, y), (x1, mid), (CX, y + h), (x0, mid), (CX, y)], fill=dark, width=2)
        draw_text_block(title, desc, CX, mid, 520)
        if bdg:
            badge(CX + 355, y - 9, bdg[0], bdg[1])
        return y, y + h, x1
    if shape == "oval":
        h = 74
        d.rounded_rectangle([LEFT, y, RIGHT, y + h], radius=h // 2, fill=dark, outline=dark, width=2)
        draw_text_block(title, desc, CX, y + h / 2, RIGHT - LEFT - 90, WHITE, (222, 231, 240))
        return y, y + h, RIGHT
    lines = wrap(desc, f_d, RIGHT - LEFT - 40) if desc else []
    body = 20 + len(lines) * 17
    if shape == "db":
        ry = 13
        h = body + 62
        # Barrel, then the lower rim as an arc only, so nothing crosses the text.
        d.rectangle([LEFT, y + ry, RIGHT, y + h - ry], fill=light, outline=light)
        d.ellipse([LEFT, y + h - ry * 2, RIGHT, y + h], fill=light)
        d.arc([LEFT, y + h - ry * 2, RIGHT, y + h], 0, 180, fill=dark, width=2)
        d.line([(LEFT, y + ry), (LEFT, y + h - ry)], fill=dark, width=2)
        d.line([(RIGHT, y + ry), (RIGHT, y + h - ry)], fill=dark, width=2)
        d.ellipse([LEFT, y, RIGHT, y + ry * 2], fill=light, outline=dark, width=2)
        draw_text_block(title, desc, CX, y + h / 2 + 2, RIGHT - LEFT - 40)
        return y, y + h, RIGHT
    h = body + 26
    d.rounded_rectangle([LEFT, y, RIGHT, y + h], radius=6, fill=light, outline=dark, width=2)
    draw_text_block(title, desc, CX, y + h / 2, RIGHT - LEFT - 40)
    if bdg:
        badge(RIGHT - 14, y, bdg[0], bdg[1])
    return y, y + h, RIGHT


def arrow(y0, y1, pal=NEUT):
    col = pal[0]
    d.line([CX, y0, CX, y1 - 9], fill=col, width=2)
    d.polygon([(CX - 6, y1 - 10), (CX + 6, y1 - 10), (CX, y1)], fill=col)


def skip(ymid, x_from, title, desc, label="No"):
    d.line([x_from, ymid, SKX0 - 10, ymid], fill=SKIP[0], width=2)
    d.polygon([(SKX0 - 11, ymid - 6), (SKX0 - 11, ymid + 6), (SKX0, ymid)], fill=SKIP[0])
    d.text((x_from + 12, ymid - 21), label, font=f_lbl, fill=SKIP[0])
    lines = wrap(desc, f_d, SKX1 - SKX0 - 34) if desc else []
    h = 20 + len(lines) * 17 + 24
    d.rounded_rectangle([SKX0, ymid - h / 2, SKX1, ymid + h / 2], radius=5,
                        fill=SKIP[1], outline=SKIP[0], width=1)
    draw_text_block(title, desc, (SKX0 + SKX1) // 2, ymid, SKX1 - SKX0 - 34)


def stage(y0, y1, num, name, pal):
    d.rounded_rectangle([BAND, y0, BAND + 36, y1], radius=7, fill=pal[0])
    txt = "%d   %s" % (num, name)
    tmp = Image.new("RGBA", (int(y1 - y0), 36), (0, 0, 0, 0))
    td = ImageDraw.Draw(tmp)
    a, b, c, e = td.textbbox((0, 0), txt, font=f_stage)
    td.text(((y1 - y0 - (c - a)) / 2, (36 - (e - b)) / 2 - b), txt, font=f_stage, fill=WHITE)
    rot = tmp.rotate(90, expand=True)
    img.paste(rot, (BAND, int(y0)), rot)


d.text((BAND, 24), "Internal Linking Plugin \u2014 what the backend actually does",
       font=f_h1, fill=(20, 40, 70))
d.text((BAND, 62), "Every number below is the shipped default. Nothing is written to your site without your approval.",
       font=f_sub, fill=GREY)

# Callout: where AI is, and is not, used.
cy0 = 96
d.rounded_rectangle([BAND, cy0, SKX1, cy0 + 96], radius=8, fill=(247, 243, 252), outline=AI_PUR, width=2)
d.rectangle([BAND, cy0, BAND + 6, cy0 + 96], fill=AI_PUR)
d.text((BAND + 22, cy0 + 14), "AI IS CALLED IN EXACTLY ONE PLACE", font=f_lbl, fill=AI_PUR)
for i, ln in enumerate([
    "One request to your chosen model per source page, and only if you switched the AI engine on. That single reply supplies three things, marked",
    "AI below: the destination it picks, the anchor phrase it proposes, and the relevance figure you see on that row. Every other step is ordinary",
    "code with no model involved, including the shortlist, the destination summaries the model is shown, and both of the other two engines.",
]):
    d.text((BAND + 22, cy0 + 36 + i * 19), ln, font=f_note, fill=(70, 60, 90))
badge(SKX1 - 18, cy0 + 14, "AI", "ai")
d.text((SKX0, cy0 + 118), "THROWN AWAY HERE, AND WHY", font=f_lbl, fill=SKIP[0])

y = cy0 + 118
_, prev, _ = node(y, "INDEX / RE-INDEX SITE", "you press the button; nothing runs on a timer", NEUT, "oval")

# ---------------- 1  INDEXING ----------------
s_top = prev + 30
steps = [
    ("Read every page from the database", "get_post directly, plus each builder's own stored fields. It never requests your site over HTTP", "rect", None),
    ("Reduce to plain words", "tags, scripts, styles and EVERY heading are stripped, so a heading can never become an anchor", "rect", None),
    ("Can this page be a source?", "it must be published, of a content type you ticked, and not a WooCommerce cart or checkout page", "diamond",
     ("Not indexed", "drafts, unticked types, Woo system pages")),
    ("Count each page's words", "how often every surviving word is used, keeping the 300 most frequent", "rect", None),
    ("Content index", "title, address, language, plain text, word counts, content hash", "db", None),
]
db_y = None
for title, desc, shape, sk in steps:
    arrow(prev, prev + 34, S1)
    t, b, xr = node(prev + 34, title, desc, S1, shape)
    if sk:
        skip((t + b) / 2, xr, sk[0], sk[1])
    if shape == "db":
        db_y = (t + b) / 2
    prev = b
stage(s_top, prev, 1, "INDEXING", S1)

# ---------------- 2  SOURCE PAGE ----------------
s_top = prev + 30
arrow(prev, prev + 34, S2)
t, prev, _ = node(prev + 34, "Take the next page in scope", "the scan walks your pages one at a time", S2)
arrow(prev, prev + 34, S2)
t, b, xr = node(prev + 34, "Room for more links?",
                "5 per 1,000 words by default, never fewer than 2. Links already in your content count", S2, "diamond")
skip((t + b) / 2, xr, "Page skipped", "already at its link limit")
prev = b
stage(s_top, prev, 2, "SOURCE PAGE", S2)

# ---------------- 3  SHORTLIST ----------------
s_top = prev + 30
steps = [
    ("Could this page be a destination?", "not itself, published, right content type, not excluded, not a Woo system page, same language, and at least 2 words in common", "diamond",
     ("Never considered", "one of seven absolute rules"), None),
    ("Compare distinctive vocabulary", "rare shared words count most, and a word appearing on over 40% of your pages counts for nothing", "rect", None,
     ("NO AI  —  arithmetic only", "no")),
    ("Term index", "up to 300 words per page, with how often each appears", "db", None, None),
    ("Keep the closest N", "15 by default, adjustable from 5 to 200. This shortlist is built BEFORE any engine chooses, so the model never sees your whole site", "rect", None,
     ("NO AI", "no")),
    ("Describe each destination", "whole sentences copied out of that page, scored by its own distinctive words. Nothing here is written by a model", "rect", None,
     ("NO AI  —  extracted, not generated", "no")),
]
for title, desc, shape, sk, bd in steps:
    arrow(prev, prev + 34, S3)
    t, b, xr = node(prev + 34, title, desc, S3, shape, bd)
    if sk:
        skip((t + b) / 2, xr, sk[0], sk[1])
    prev = b
stage(s_top, prev, 3, "SHORTLIST", S3)

# ---------------- 4  DESTINATION ----------------
s_top = prev + 30
arrow(prev, prev + 34, S4)
t, prev, _ = node(prev + 34, "Three engines, always in this order",
                  "each one proposes links until the page's budget is used up, so a later engine only sees what is left", S4)
for title, desc, bd in [
    ("1.  GSC keyword", "Google decides: your page mentions a phrase another page already ranks for. That phrase becomes the anchor. Stops at 3 identical anchors aimed at one page",
     ("NO AI  —  Google data + exact matching", "no")),
    ("2.  AI Suggestion", "THE ONLY MODEL CALL IN THE PLUGIN. One request per page. It receives your page text plus the numbered shortlist, and answers with a NUMBER, an anchor phrase, and its own confidence",
     ("AI  —  one call per page", "ai")),
    ("3.  Related Content", "no key needed: the highest shared vocabulary wins, and the anchor is drawn from that page's title",
     ("NO AI  —  arithmetic only", "no")),
]:
    arrow(prev, prev + 26, S4)
    t, prev, _ = node(prev + 26, title, desc, S4, "rect", bd)
arrow(prev, prev + 34, S4)
t, b, xr = node(prev + 34, "Is the AI's number one it was shown?",
                "it may only pick from the shortlist, and the plugin looks the address up itself", S4, "diamond",
                ("AI OUTPUT 1 of 3: the destination", "ai"))
skip((t + b) / 2, xr, "Thrown away", "a number outside the list means nothing, so it cannot invent a page")
prev = b
stage(s_top, prev, 4, "DESTINATION", S4)

# ---------------- 5  ANCHOR ----------------
s_top = prev + 30
steps = [
    ("Does the phrase appear in the page?", "whole words, ignoring capitals. Exact match only, with no partial words and no synonyms", "diamond",
     ("Discarded", "this single rule is what stops invented anchors"),
     ("AI OUTPUT 2 of 3: the anchor, checked here", "ai")),
    ("Can it legally be placed?", "not in a heading, not inside an existing link, not in code, and not inside a tag's attributes", "diamond",
     ("Never even suggested", "checked before storing, so the queue holds nothing unusable"), None),
    ("Already linked, or already judged?", "one link per destination per page, and a pair you approved or rejected is never offered again", "diamond",
     ("Skipped", "the link already exists, or your earlier decision stands"), None),
    ("Take the FIRST allowed occurrence", "top to bottom, one link per suggestion however often the phrase appears, saved in YOUR capitalisation", "rect", None,
     ("NO AI  —  the model never chooses the position", "no")),
]
for title, desc, shape, sk, bd in steps:
    arrow(prev, prev + 34, S5)
    t, b, xr = node(prev + 34, title, desc, S5, shape, bd)
    if sk:
        skip((t + b) / 2, xr, sk[0], sk[1])
    prev = b
stage(s_top, prev, 5, "ANCHOR", S5)

# ---------------- 6  SCORE, REVIEW, PLACE ----------------
s_top = prev + 30
for title, desc, bd in [
    ("Score it", "0.60 x relevance + 0.40 x naturalness. On an AI row the relevance IS the model's own confidence figure; the other two engines compute it. Naturalness is never AI: 2 to 4 words and 8 to 60 characters score best",
     ("AI OUTPUT 3 of 3: the relevance figure", "ai")),
    ("Apply the per page cap", "at most 8 new suggestions from one page in a single scan, however many the engines found", ("NO AI", "no")),
    ("Show it to you", "source, destination, anchor, the sentence around it, the score, and which engine proposed it", None),
]:
    arrow(prev, prev + 30, S6)
    t, prev, _ = node(prev + 30, title, desc, S6, "rect", bd)
arrow(prev, prev + 34, S6)
t, b, xr = node(prev + 34, "You approve it?", "nothing is ever inserted without this", S6, "diamond")
skip((t + b) / 2, xr, "Rejected and remembered", "the pair is never offered again on a later scan")
prev = b
for title, desc, bd in [
    ("Save a revision and an undo record", "both are written BEFORE the post is touched, so every insertion has two ways back", None),
    ("Splice the link in", "a byte-exact insertion. If anything but the new link would change, the write is refused",
     ("NO AI  —  no model ever writes to your site", "no")),
    ("Re-index the page", "the new link is counted at once, so the next scan sees it and the link budget updates", None),
]:
    arrow(prev, prev + 30, S6)
    t, prev, _ = node(prev + 30, title, desc, S6, "rect", bd)
reindex_y = prev - 40
arrow(prev, prev + 34, S6)
t, b, _ = node(prev + 34, "INTERNAL LINK LIVE", "undo restores your original content at any time", S6, "oval")
stage(s_top, b, 6, "SCORE, REVIEW, PLACE", S6)
prev = b

# feedback loop: re-index -> content index
fb = 170
d.line([LEFT, reindex_y, fb, reindex_y], fill=S6[0], width=2)
d.line([fb, reindex_y, fb, db_y], fill=S6[0], width=2)
d.line([fb, db_y, LEFT - 11, db_y], fill=S6[0], width=2)
d.polygon([(LEFT - 12, db_y - 6), (LEFT - 12, db_y + 6), (LEFT, db_y)], fill=S6[0])
lab = "an applied link feeds straight back into the index"
tmp = Image.new("RGBA", (tw(lab, f_lbl) + 8, 20), (0, 0, 0, 0))
ImageDraw.Draw(tmp).text((0, 0), lab, font=f_lbl, fill=S6[0])
rot = tmp.rotate(90, expand=True)
img.paste(rot, (fb - 24, int((db_y + reindex_y) / 2 - rot.height / 2)), rot)

# ---------------- footer: the limits, and what is deliberately absent ----------------
fy = prev + 46
d.line([BAND, fy, SKX1, fy], fill=(206, 211, 217), width=1)

d.text((BAND, fy + 20), "EVERY LIMIT, AND WHERE YOU CHANGE IT", font=f_lbl, fill=(20, 40, 70))
limits = [
    ("Links per 1,000 words", "5, and never fewer than 2 per page", "Settings"),
    ("New suggestions per page", "8, from any one scan", "Settings"),
    ("Lowest relevance accepted", "0.08, and only for the Related Content engine", "Settings"),
    ("Anchor length", "2 to 4 words", "Settings"),
    ("Words of the page read by the AI", "1,000, adjustable from 500 to 20,000", "Setup & Dashboard"),
    ("Pages on the shortlist", "15, anywhere from 5 to 200", "Setup & Dashboard"),
    ("How each destination is described", "a 40-word summary, key words, or title only", "Setup & Dashboard"),
]
C1, C2, C3 = BAND + 14, BAND + 300, BAND + 660
for i, (a, b_, c) in enumerate(limits):
    ly = fy + 46 + i * 24
    d.text((C1, ly), a, font=f_note, fill=INK)
    d.text((C2, ly), b_, font=f_note, fill=GREY)
    d.text((C3, ly), c + " screen", font=f_note, fill=(31, 78, 140))
tbl_bottom = fy + 46 + len(limits) * 24

ny = tbl_bottom + 26
d.text((BAND, ny), "NOT IN THIS PLUGIN, AND DELIBERATELY SO", font=f_lbl, fill=(150, 55, 55))
notes = [
    "The AI engine is optional. Switch it off, or supply no key, and the plugin still works: the GSC keyword and Related Content engines need no model and cost nothing.",
    "If the model fails, times out or answers with something unusable, the scan does not stop. That page simply falls through to the Related Content engine.",
    "No model ever writes to your site, and no model is ever shown your whole site. It sees one page and a shortlist of at most 200 titles and summaries.",
    "No sitemap and no HTTP crawl. It reads your database, so caching, staging and password protection cannot affect it, and drafts can be indexed.",
    "No synonym, stem or partial matching. An anchor must appear in your text word for word, or no suggestion is made at all.",
    "No page-type priority and no orphan boost when ranking. Internal PageRank IS measured, but it is only reported in Link Health.",
    "No keyword cannibalisation check. If two of your pages target one phrase, both may be offered and you decide between them.",
    "No semantic check of the surrounding sentence. The sentence is shown to you, and you are the judge of whether it fits.",
]
ly = ny + 26
for n in notes:
    for j, ln in enumerate(wrap(n, f_note, SKX1 - BAND - 40)):
        d.text((BAND + 16 if j == 0 else BAND + 30, ly), ("\u2022   " + ln) if j == 0 else ln,
               font=f_note, fill=GREY)
        ly += 21
    ly += 3

img = img.crop((0, 0, W, int(ly + 26)))
img.save(r"C:\plugin\.claude\worktrees\github-publishing\docs\backend-process-flowchart.png", "PNG")
print("written:", img.size)
