---
name: explain-blocked-actions-habit
description: "Standing UX rule for the Ujjal ERP - never silently hide/disable an action; always say why the user can't do it and the exact Access Management steps to enable it"
metadata:
  type: feedback
---

User's explicit instruction (2 Sep 2026): "make this habit in everywhere, if any action gets stuck for an user, let him know why this is not his eligibility and how to solve this, also, for now, let the message say how to enable that function for the user, with step by step guide, like — in access management pages, under which module, in which action this action toggle is at."

**Why:** Users (and the admins they escalate to) were hitting bare-disabled buttons, hidden UI, or generic "You do not have permission" messages with no path forward — forcing guesswork or a support round-trip to figure out which of the ~15 modules/~140 pages/many per-page action toggles in `admin/privileges.php` actually gates the thing they wanted to do.

**How to apply:** Whenever a page/button in this codebase (`/Users/apple/Desktop/saas/saasurfm`) hides, disables, or blocks something because of role/permission/eligibility, don't just omit it or show a generic error — use `permissionDenialReason($module, $page_key, $action_key = null, $what = null)` (added 2 Sep 2026 in `core/functions/helpers.php`, right after `userCanPageAction()`). It reads real labels from `getModuleRegistry()` and returns a message shaped like: *"You don't have permission to <X>. To enable this: Admin → Access Management (admin/privileges.php) → select this user → module "<Module>" → page "<Page>" → check the action "<Action>" → Save."* Use it as a `title=""` tooltip on a small lock-icon badge in place of a hidden button (established pattern, see `credit_production.php`'s Start/Complete/Ready and icon-action columns), or as the `$_SESSION['error_flash']` on a page-level redirect.

`restrict_access()` itself was updated the same day to use this helper for all its module/page-level denial paths, so **every page in the app already gets a real "why + how" message for free on access-gate rejection** — no per-page change needed for that layer. What still needs deliberate per-page work is action-level gates (`userCanPageAction()` checks guarding individual buttons) — apply the tooltip pattern to those as you touch each page, rather than retrofitting the whole app in one pass. Also applies to order-level eligibility checks that aren't privilege-registry-based (e.g. credit_dispatch.php's quantity-shortfall/payment-hold reasons) — same spirit (name the reason, name the fix), even though those don't go through `permissionDenialReason()` since they're not module/page/action grants.

Related: [[implementation-backlog]] (2 Sep 2026 entries — CR-20260823-0014 fix + credit_dispatch.php shortfall gate were the first concrete application of this pattern), [[module-page-map]] (the module/page/action registry this helper reads from).
