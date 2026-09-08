# Glassmorphism Navbar Leaks Underlying Text When a Full-Screen Drawer Opens

## Symptom

A site has a glassmorphism top navbar (`background: transparent` + `backdrop-filter: blur(30px)`) — it looks great over normal page content. But when a full-screen side drawer (or any overlay) slides in, the navbar region shows the *underlying page text* through itself. With a light background the drawer looks dirty; with busy content behind, the text is clearly readable through the glass, which looks broken.

Worse: when the overlay closes, the navbar snaps back to normal — but during the open/close animation there is a brief flash where the underlying text is visible.

## Principle

Glassmorphism is literally two things:

1. `background: transparent` — the element has **no paint of its own**, so everything behind it shows through.
2. `backdrop-filter: blur(...)` — the browser blurs what is behind it, which *softens* the leak but never removes it. Text is still visible, just fuzzy.

This is a feature over normal content (the page scrolls under a translucent bar) — but it becomes a bug when an **overlay opens**: the area under the navbar is now covered by the drawer, yet the navbar itself still shows whatever is behind it (the page content that happens to sit there). The eye reads "text bleeding through a UI chrome", not "pretty glass".

## The sneaky extra bug: transition makes it flash

The naive fix is to make the navbar solid while the overlay is open:

```css
#nav.overlay-open { background: var(--bg); }
```

But sites usually have a global transition rule:

```css
*, *::before, *::after {
    transition: background-color .25s ease, color .25s ease, border-color .25s ease;
}
```

So the background change **animates over 0.25 s**. The overlay animation (0.2–0.3 s) and the background transition race: in the first frames of opening, the navbar is still semi-transparent and the underlying text flashes through. Then it settles to solid. Result: "it flashes once when opening".

## Solution

### 1. Make the navbar solid while the overlay is open

```css
#nav.overlay-open,
#nav.overlay-open > * {
    background: var(--bg) !important;
    -webkit-backdrop-filter: none !important;
    backdrop-filter: none !important;
}
```

Applied via JS when the drawer opens (and removed when it closes).

### 2. Kill the background transition *during the open state*

Without this, the solid background fades in and the text flashes during the animation:

```css
#nav.overlay-open,
#nav.overlay-open > * {
    transition: background-color 0s !important;
}
```

The transition rule applies only while the overlay is open, so normal glass behavior (and its smooth transition back) is unaffected when the drawer closes.

### 3. Bonus: this also fixes animation frame drops

`backdrop-filter` is expensive. While a full-screen drawer slides under the navbar, the browser recomputes the blur every frame. Disabling `backdrop-filter` during the overlay animation removes that cost entirely — the drawer animation becomes much smoother on mobile WebViews. (See related note on drawer lag.)

## Summary

- Glassmorphism (`transparent` + `backdrop-filter`) always leaks underlying content — that is its nature; it is only acceptable over normal page content.
- When an overlay/drawer opens, switch the navbar to a solid background for the duration, and **disable the background transition** during that state or you get a flash of the leaked text in the first frames.
- Turning off `backdrop-filter` during the overlay also removes per-frame blur recomputation → smoother animation.
- Checklist: overlay open → navbar solid + no transition + no blur; overlay closed → restore glass.
