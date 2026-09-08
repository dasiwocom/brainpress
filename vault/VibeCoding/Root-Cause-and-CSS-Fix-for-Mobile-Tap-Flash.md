## Symptom

On mobile devices, tapping buttons, links or file list items triggers a momentary color flash (a semi‑transparent grey overlay) that disappears immediately after the tap. This effect does **not** appear when clicking with a mouse on desktop computers.

## Root Cause

This is a built‑in tap‑feedback feature of mobile browsers.

On desktop, you get rich interactive feedback: `hover` states when the mouse hovers over elements, and `active` states on mouse click. Mobile touchscreens have no `hover` concept. Browsers need a way to signal users that a tap has registered.

To solve this, browsers render a **semi‑transparent grey overlay the instant your finger presses down**, which vanishes once you lift your finger.

Drawbacks of this native behavior:
- The grey highlight is browser‑controlled; you cannot customize its color, opacity or duration.
- It often clashes with your site’s design system.
- Fast taps make the overlay flash briefly, creating the distracting flicker users perceive.

In short: **the browser injects an unwanted default style**.

## Solution

Disable it with one line of CSS:
```css
* {
    -webkit-tap-highlight-color: transparent;
}
```
`transparent` instructs the browser to skip the grey tap‑highlight overlay.

It is recommended to add these two rules together to fix other problematic mobile browser defaults:
```css
* {
    -webkit-tap-highlight-color: transparent;
    -webkit-touch-callout: none;   /* Disable long‑press system menu (copy / search etc.) */
    outline: none;                  /* Remove blue focus ring on tap */
}
```

## Additional Notes
- This mechanism applies to WebKit‑based browsers (Chrome, Safari, WeChat embedded browser). The `-webkit‑` vendor prefix is mandatory.
- After disabling the highlight, taps become completely silent. If you want click feedback, implement custom CSS states (e.g. subtle color shift for the `active` state). You retain full control over visuals and timing.
- These are three essential reset rules for mobile web development, recommended for all mobile‑oriented projects.

## Troubleshooting Log (Reference)
Initially suspected CSS `hover` / `active` transitions. Removing all transitions fixed desktop behavior but the flicker persisted on mobile. Then suspected focus rings and added `outline: none` for buttons, with no improvement. Finally identified the mobile‑only `tap‑highlight` mechanism, resolved by the single `-webkit‑tap‑highlight‑color: transparent` declaration. Always distinguish desktop‑only vs mobile‑only artifacts to reduce debugging time.