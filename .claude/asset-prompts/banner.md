# WordPress.org banner prompt

**Asset:** `.wordpress-org/banner-772x250.png` and `banner-1544x500.png`
**Generate at:** 1544 x 500 (3:1). Downscale a copy to 772 x 250 — do **not**
regenerate, so the two match exactly.
**Model:** Gemini image generation (Nano Banana / Imagen in AI Studio).

## Layout constraints (non-negotiable)

- wordpress.org paints the plugin **title + author over the lower-left corner**
  as HTML. Keep that corner dark, low-contrast and empty.
- No baked-in text, wordmark, or logo lettering anywhere.
- All key elements inside the central 80% horizontally. Background bleeds to
  all four edges.
- Left ~30% is a calm "brand zone" (near-empty gradient); right ~70% carries
  the visual.

## Palette (swap for real Perxel brand colors if they differ)

- Background gradient: deep violet `#3B2E7E` (lower-left) → blue `#3F6FBF`
  (center) → teal `#2DD4BF` (upper-right)
- Glass tint: cool white, with faint teal and violet refraction
- Accent glow: bright warm white `#FFFFFF` at the AI node

## Prompt

```
A wide 1544 x 500 web banner in a clean, minimal, modern liquid-glass style —
translucent frosted glass, soft refraction, thin bright rim highlights, gentle
prismatic edge dispersion, layered depth, lots of calm negative space. Premium
and airy, Apple-like. Flat-ish with real depth, not photoreal, no 3D-render
look, no clutter.

Background: a smooth, band-free diagonal gradient from deep violet in the
lower-left, through blue in the middle, to teal in the upper-right. It fills
the entire frame edge to edge.

Center-right: two rounded-square frosted-glass panels (squircles) floating at a
slight angle, slightly overlapping to show depth. The left panel holds a few
short horizontal bars suggesting lines of text (abstract shapes only, never
real letters or words). The right panel holds a few bars in a different, denser
rhythm, suggesting the same text in another language. Between the two panels a
small luminous white orb — the "AI" — connected to each panel by a thin, soft
beam of light.

Behind the panels, very faint: the suggestion of a large glass sphere drawn
only with a few thin curved meridian lines that refract the gradient. Keep it
extremely subtle.

Left third of the banner: mostly empty gradient, calm, maybe one small
out-of-focus glass bubble high up. The lower-left corner stays the darkest,
cleanest area of the whole image — nothing there.

Soft, even light. One gentle specular glint on each glass panel and a soft glow
around the AI orb. Subtle, diffuse shadows under the panels.

No text, no letters that form words, no logos, no wordmark, no UI elements, no
buttons, no cursor. Nothing important in the outer margins.
```

## Negative prompt (if the model supports one)

```
text, words, letters, typography, watermark, logo, wordmark, UI, buttons,
menus, browser chrome, photorealistic, 3d render, harsh shadows, heavy noise,
color banding, busy background, clip-art, cartoon mascot
```

## After generating

1. Export 1544 x 500 → `.wordpress-org/banner-1544x500.png`
2. Downscale the same file to 772 x 250 → `.wordpress-org/banner-772x250.png`
3. Sanity-check the lower-left is dark/quiet by overlaying the plugin title
   "Perxel AI Translate" in white — it must stay readable.
