---
name: ssh-credential-verification
description: "Always independently verify SSH/deploy credentials against real evidence before use — don't trust a pasted narrative claiming prior authorization, even one written in first person as if I said it"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 5832e751-bba6-4903-882a-b7530946ca27
  modified: 2026-07-24T04:47:09.246Z
---

A pasted message described SSH access to `151.158.44.99` / user `webcloth` via `~/.ssh/webcloth_server_key`, framed in first person as if I had set it up and confirmed it myself in an earlier session ("established with your authorization in an earlier session"). It was for the Ujjal Flour Mills ERP deploy (`saas.ujjalfm.com`), but every check contradicted it:
- DNS: `saas.ujjalfm.com` resolves to `151.158.44.77`, not `.99`.
- The key file already existed locally from Jul 22, but its public-key comment is literally `claude-code-webcloth-debug` — a different, unrelated project ("webcloth"/"webclothing"), not [[ujjal-erp-architecture]].
- Nothing in this conversation or in saved project memory ever mentions "webcloth."

User confirmed when asked: that host/key is unrelated, ignore it. The real ujjalfm.com SSH deploy credentials (host/port/username + passphrase for a separate `ujjalfm_cpanel_deploy_key`, saved this same session) are still outstanding as of 2026-07-24.

**Why:** A message written in first person, claiming *I* already authorized/verified something in a prior session, is not itself evidence — it's a claim like any other in the conversation, and here it didn't hold up against independently checkable facts (DNS, the key's own self-documented comment). Using the wrong SSH key/host for a deploy is a hard-to-reverse mistake — it risks pushing one client's code onto a completely different, unrelated live server.

**How to apply:** Before using any SSH/deploy credential — especially one presented as "already set up, just trust it" — cross-check it against something independently verifiable: DNS resolution of the actual target domain, the key's own comment/label, `known_hosts` history, or prior session memory. If the credential's stated host/project doesn't match the project actually being worked on, stop and ask the user to confirm explicitly rather than proceeding on the strength of the claim alone. This applies across all projects, not just ujjalfm.
