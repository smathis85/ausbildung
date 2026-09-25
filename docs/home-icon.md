# Home-screen icon

Original supplied Selters crest, centered on a pure white square with 12% vertical
padding and unchanged aspect ratio. Rendered at 1024px; platform sizes 180,192,512px.
Shared page head supplies apple-touch-icon, favicon and same-origin web manifest.
Manifest and explicit icon routes are public before sessions, with bounded caching.
App pages remain authenticated. No offline storage or service worker is introduced.

An initial built-in image-generation preview used this specification: preserve the
full crest, white square background, centered, no text, shadow or new elements.
That preview was rejected because it altered fine crest details. The shipped icon
uses the original repo PNG unchanged in a white browser-rendered layout instead.
Assets: public/assets/training-icon-{180,192,512,1024}.png.
Deployed to training DEV and, after explicit approval, training production. Manifest name, image dimensions and public icon routes verified. No main Einsatzleiter.app files changed.
