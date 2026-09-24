# Logo deployment

The supplied Selters crest appears in the shared header on overview and participant pages.
Desktop width 44px, mobile 38px; natural aspect ratio.
The fixed public asset route `/?asset=brand-logo` serves only the bundled PNG,
before sessions or database access. No path from user input is used.
This works with existing production nginx rules. Static PNG nginx rules are optional.

User approved all outstanding changes for main/production. Deployment uses the
project update access, with no database changes. Synthetic participant HTTP checks
and PHP syntax checks passed before deployment.
