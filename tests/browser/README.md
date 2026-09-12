# Browser harnesses — the console's page shell, and the Customer Chat widget

These are the D-C5 checks from `docs/staff-ai-console-pages-runsheet.md` (DEF repo),
kept in the repo so the numbers a PR reports can be re-run by anyone (`harness-v-s7b.js`
is V-S7b from `docs/staff-ai-voice-runsheet.md`, and slices Customer Chat's JS the same
way). Each harness
slices the **shipped** `assets/js/staff-ai.js` by the comment markers the code already
carries (`tests/browser/extract.js`), runs those exact lines inside jsdom against a DOM
that mirrors `templates/staff-ai-shell.php`, and drives them through real events —
clicks with real buttons and modifier keys, `keydown`, `hashchange`, `history.back()`.
Nothing here is a copy of the code under test: rename a function out of a block and the
extraction throws rather than passing against a stale duplicate. `apiRequest`,
`window.confirm` and friends are injected per boot, so a check can hold a request open,
fail it, or answer a confirm either way and watch what the page does. No network, no
WordPress, no browser — `node` and `jsdom` only.

They cover what unit tests cannot reach and a canary can only spot-check: that history
does not grow over repeated open/leave cycles, that a reload on a route opens its page,
that the chat is hidden and never reset, that Escape unwinds one layer at a time, and
that a page's `onLeave` closes what it opened.

## Running them

```sh
npm install          # jsdom, a devDependency; node_modules is gitignored
npm run test:browser # all of them, non-zero exit if any check fails
```

Or one at a time: `node tests/browser/harness-c1.js`.

## What is here

| File | Covers |
| --- | --- |
| `extract.js` | The marker slicer — one exported extractor per block, each asserting the names it expects |
| `harness-c1.js` | The shell itself (v7.8.1): routes, history, focus, Escape, the delegated sidebar click |
| `harness-c2.js` | Projects on the shell (v7.8.2), including the inline Create row |
| `harness-c3.js` | Memories, Usage and Connections on the shell (v7.8.3) |
| `harness-v-s7b.js` | A multi-step reply shown once (v7.8.4): the SSE stream handler of BOTH widgets — `step_superseded` moves a superseded round out of the bubble |
| `harness-cc-uploads.js` | The Customer Chat attach gate (U-1b, v7.8.5): no upload before the conversation exists |
| `harness-so3.js` | The `once` cadence on the Scheduled page (v7.8.6): the creator's date field and the card's two one-off badges |
| `harness-voice.js` | Hands-free voice (v7.8.7): the speech detector against a fake microphone, and the spoken stop on both surfaces |
| `harness-connections-consent.js` | Connections (v7.8.8): the pending Finish-connecting / Connect-another link survives the focus rebuild until the account connects, the row loses its picker, or the user dismisses it |
| `harness-c3b.js` | The installed app keeps up with the release (v7.9.0): the version check, the quiet-moment rule, one reload per target, and the notice only when it landed |
| `harness-c4.js` | The document viewer as a page (v7.9.1): `#document/<id>` reached cold or from an opener, and one page serving many documents |
| `harness-c5.js` | The card kit named (v7.9.3): the seven Ask entries on one shared helper, named and unnamed, and the proof that every kit class shares its rule with the 7.8.0 name it replaced |
| `harness-c6a.js` | Documents on the card kit (v7.9.4): the proof that every kit name the page takes shares its rule with the name it replaced, the ⋯ menu and touch sheet on a document card, Move to project / Download / Delete driven from the menu, and the document viewer's status line held as it is |
| `harness-c6c.js` | Connections on the card kit (C6c): the page takes the ⋯ menu and the status line and NO card name (D-C8 - it is rows); Connect stays on the row, Disconnect moves behind the menu, a row with nothing to manage renders no menu at all, and the 10px the status line gains on re-point |
| `harness-c6d.js` | Memories on the kit's status line (C6d): the page takes the status line and NOTHING else — the runsheet rules it rows with one Delete, so the card and menu families are asserted absent; the proof that the kit carries every declaration the retired `.memories-status` family did, with the 10px it gains as the one declared exception - and the proof that the 10px collapses into the page head above it, so nothing on screen actually moves; and Delete still asking, sending and reloading exactly as it did |
| `harness-contrast.js` | The palette against WCAG 2.1 AA, computed rather than pinned: --accent-green as text and as a border/ring in both themes, and the integrations OK pill. Asserts ratios, so any readable colour passes |
| `harness-c6b.js` | Scheduled on the card kit (v7.9.5): the same proof for the page, Run now joined to Open Project's rules, one ⋯ menu shared by task and triage cards, Edit / Remove driven from it, and the page's status line on the kit with the creator's own lines left alone |

## Bite checks

A green harness proves nothing until you have watched it go red. Every extractor takes
an override env var naming a file to load **instead** of the shipped block — put the old
code back in a scratch file, point the var at it, and confirm the checks meant to catch
that regression actually fail:

```sh
BLOCK=/tmp/old-shell.js       node tests/browser/harness-c1.js   # the page shell
PROJECTS=/tmp/old-projects.js node tests/browser/harness-c2.js   # initProjects
MEMORIES=… USAGE=… INTEGRATIONS=… node tests/browser/harness-c3.js
STAFFSTREAM=/tmp/old-staff.js CCSTREAM=/tmp/old-cc.js node tests/browser/harness-v-s7b.js
ATTACH_GATE=/tmp/old-gate.js  node tests/browser/harness-cc-uploads.js  # the attach gate
SCHEDULED=/tmp/old-scheduled.js node tests/browser/harness-so3.js   # initScheduled
VOICE_MODULE=/tmp/old-voice.js node tests/browser/harness-voice.js   # the shared recorder
INTEGRATIONS=/tmp/old-integrations.js node tests/browser/harness-connections-consent.js   # initIntegrations
RELEASE=/tmp/old-release.js   node tests/browser/harness-c3b.js   # the release check
DOCVIEWER=/tmp/old-viewer.js  node tests/browser/harness-c4.js    # initDocumentViewer
ASK_ENTRY=/tmp/old-ask.js     node tests/browser/harness-c5.js    # the shared Ask entry
ASK_ENTRY_CALLS=/tmp/old-calls.json node tests/browser/harness-c5.js  # the seven calls to it (JSON: [{base, source}])
DOCUMENTS=/tmp/old-documents.js node tests/browser/harness-c6a.js  # initDocuments
SCHEDULED=/tmp/old-scheduled.js node tests/browser/harness-c6b.js  # initScheduled on the kit
```

`harness-c5.js`, `harness-c6a.js` and `harness-c6b.js` read the stylesheet and the
template from the working tree, so their CSS and markup checks bite by editing those
files (a scratch `git worktree` keeps that off your branch).

The env var names match the extractor names in `extract.js`. `ASK_ENTRY` reaches
further than its own harness: `extract.buildAskEntry` hands the shipped helper to
every page that has an Ask entry, so pointing it at an older helper turns
`harness-c2`, `harness-c3`, `harness-so3` and `harness-connections-consent` red too.

## Adding to them

A harness is a plain script: `boot()` builds a fresh jsdom per check so no check inherits
another's state, `check(n, label, condition, detail)` records one line, and the file exits
non-zero if any failed. Keep the count in the file's header comment honest — the PR that
adds checks reports the new number.
