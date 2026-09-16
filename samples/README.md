# Sample output from the live test run, 16 September 2026

Everything here was produced by the real pipeline, not written by hand as an
illustration. The topics come from live web research, the source quotes were
verified verbatim against the pages they cite, and the images came out of
Higgsfield.

## `topics-2026-09-16.json` - 35 topics

What A1 produced in one run, after deduplication and after the batch checker
forced one drop. Every topic carries:

- a source URL that was actually fetched and read
- a **verbatim** quote from that page, which is what any number in the eventual
  post has to trace back to
- a publication date read off the page, all inside 18 months
- an India-relevance score with a one-line justification

Batch shape: 12 themes, 6 post types, none above 30%, 35 of 35 strongly
India-relevant.

One topic was dropped by the checker, not by choice: `data_point` had reached
31% of the batch, over the 30% ceiling. The weakest of them went - vendor content
marketing for a recognition platform, on a theme already covered by a stronger
source.

## `posts/` - 3 posts

Written from three of those topics. Each passes all 19 hard gates:

| | Body words | Hashtags | Numbers traced |
| --- | --- | --- | --- |
| `post1.json` background verification | 58 | 4 | 11.15% and 7.68%, both verbatim in the source quote |
| `post2.json` notice periods | 72 | 4 | 90, verbatim in the source quote |
| `post3.json` labour codes | 64 | 4 | none, so nothing to trace |

Check any of them yourself:

```bash
node lib/validate.mjs post samples/posts/post1.json
```

Note that none of the three carries a link in the body. The link lives in
`first_comment_text`, and A5 posts it as a comment within two minutes of
publishing, because a URL in the body suppresses reach.

## `images/` - 6 images, two per post

Two models per post, so the choice is between genuinely different pictures
rather than two renders of one idea.

**This is the one part of the test that did not go well, and the numbers are
worth seeing.**

| Model | Usable | Verdict |
| --- | --- | --- |
| `recraft_v4_1` (option 1) | 3 of 3 | Clean, literal, well lit, reads at feed size |
| `soul_cinematic` (option 2) | 0 of 3 | Every one came back near-black or cropped so tight the subject was unreadable |

Look at `notice-opt1-recraft.png` against `notice-opt2-soulcinema.png` and the
gap is obvious. The first is an empty chair at a bare desk with the rest of the
floor occupied behind it, which is exactly what the post is about. The second is
a boardroom so dark it would be a black rectangle in the feed.

All six pass the hard gates that matter - no text anywhere, no logos, no
identifiable faces, no anatomy defects - so this is a craft failure rather than a
safety one. `config/pipeline.config.json` now carries a mandatory lighting
directive, and the note on `images.optionB` says to switch models if the next
batch is no better.
