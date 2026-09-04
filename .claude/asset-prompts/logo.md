# WordPress.org icon prompt

**Asset:** `.wordpress-org/icon-256x256.png` and `icon-128x128.png`
**Generate at:** 512 x 512 (square). Export copies at 256 x 256 and 128 x 128 —
do **not** regenerate.
**Model:** Gemini image generation (Nano Banana / Imagen in AI Studio).

## Layout constraints (non-negotiable)

- Must read at 32 px. One centered mark, no fine detail.
- The mark fills ~68% of the frame with even padding on all sides, so it
  survives a circle crop.
- Background is a solid fill (or soft radial gradient) that bleeds to the whole
  square.
- No text, letters, or wordmark.

## Palette (swap for real Perxel brand colors if they differ)

- Background: deep indigo-violet `#3B2E7E`, or a soft radial gradient to
  `#2A1F5E` at the corners
- Glass mark: frosted cool white with one panel tinted teal `#2DD4BF`, the
  other tinted violet `#7C5CFF`
- Core spark: pure white `#FFFFFF`

## Prompt

```
A square 512 x 512 app icon in a clean, minimal, modern liquid-glass style.
Centered on the frame: a single mark made of two rounded-square panels of
translucent frosted glass, the same size, overlapping at a roughly 15-degree
offset so they fuse into one clean silhouette. The lower panel is tinted cool
teal, the upper panel tinted soft violet; where they overlap the glass reads
brighter. A small pure-white luminous dot sits at the point where they cross —
a subtle "AI" spark.

Liquid-glass treatment: visible soft blur and refraction through the panels,
a thin bright highlight running along the top edge of each panel, a faint
prismatic color fringe at the edges, one gentle specular glint, and a soft
diffuse shadow beneath the mark for depth.

Background: a solid deep indigo-violet, very slightly darker toward the
corners, filling the entire square. Optional barely-there rounded corners.

Even, soft lighting. Generous empty padding around the mark on all four sides.

No text, no letters, no numbers, no wordmark, no UI elements, no drop-shadow
clutter, no busy patterns. Just the one centered glass mark on the solid
background.
```

## Negative prompt (if the model supports one)

```
text, letters, numbers, typography, wordmark, watermark, UI, app store frame,
photorealistic, 3d render, glossy plastic, skeuomorphic bevel, cartoon mascot,
busy pattern, multiple objects, harsh shadow, color banding
```

## After generating

1. Export 512 x 512, then downscale to `.wordpress-org/icon-256x256.png` and
   `.wordpress-org/icon-128x128.png`
2. Check it at 32 px and as a circle crop — the two-panel shape and the spark
   must still be legible.
