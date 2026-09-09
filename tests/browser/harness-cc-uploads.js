/*
 * Customer Chat attach gate - behavioural harness (U-1b / v7.9.0), 16 checks.
 *
 * Runs the SHIPPED attach-gate and staged-upload blocks (extracted by marker
 * from assets/js/def-core-customer-chat.js) inside jsdom over the same closure
 * the widget gives them, so the checks below run the lines that ship.
 *
 * What it holds: an upload belongs to the conversation it is sent in. There is
 * no attach control until the thread exists, a drop or paste before then is
 * refused with one line, and every upload names the real thread - never the
 * '_anonymous' placeholder.
 */
const { JSDOM } = require('jsdom');
const fs = require('fs');
const path = require('path');
const extract = require('./extract');

const GATE = extract.attachGate();
const UPLOAD = extract.uploadStaged();
const SRC = extract.customerChatSource();
const PHP = fs.readFileSync(path.join(extract.REPO, 'includes/class-def-core.php'), 'utf8');

const NOTICE = 'Send your first message, then attach the image.';

function boot() {
  const dom = new JSDOM('<!doctype html><html><body>'
    + '<button class="def-cc-composer-attach" style="display:none"></button>'
    + '</body></html>');
  const window = dom.window, document = window.document;
  const els = { attachBtn: document.querySelector('.def-cc-composer-attach') };
  const notices = [], stagedFiles = [], uploads = [];
  const appendMessage = (role, text) => notices.push(role + ': ' + text);
  const stageFile = file => stagedFiles.push({ file: file, status: 'staged' });
  const uploadSingleFile = (staged, conversationId) => {
    uploads.push({ name: staged.file.name, conversationId: conversationId });
    return Promise.resolve({ success: true, fileId: 'file_' + staged.file.name });
  };
  const factory = new window.Function(
    'els', 'stagedFiles', 'appendMessage', 'stageFile', 'renderStagedAttachments',
    'uploadSingleFile', 't',
    'var uploadEligible = false, threadId = null;\n' + GATE + '\n' + UPLOAD + '\n'
    + 'return { setUploadEligible: setUploadEligible, setThreadId: setThreadId,'
    + ' stageAttachedFiles: stageAttachedFiles, uploadStagedFiles: uploadStagedFiles };');
  const api = factory(els, stagedFiles, appendMessage, stageFile, () => {},
    uploadSingleFile, () => NOTICE);
  return {
    api, notices, stagedFiles, uploads,
    attachShown: () => els.attachBtn.style.display !== 'none',
    file: name => ({ name: name, size: 10, type: 'image/png' })
  };
}

let pass = 0, fail = 0;
const results = [];
function check(n, label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(n).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(n).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

(async () => {
  // 1-4. The control follows the thread, and still follows eligibility.
  {
    const t = boot();
    t.api.setUploadEligible(true);
    check(1, 'a fresh widget shows NO attach control - turn one has no conversation to attach to',
      !t.attachShown());
    t.api.setThreadId('thr_abc');
    check(2, 'the first reply carries a thread id and the attach control appears', t.attachShown());
    t.api.setThreadId(null);
    check(3, 'Clear conversation takes the attach control with the thread', !t.attachShown());
    const u = boot();
    u.api.setThreadId('thr_abc');
    check(4, 'a thread without upload eligibility still shows no control (that gate is unchanged)',
      !u.attachShown());
  }
  // 5-8. Drop and paste, which have no button to hide.
  {
    const t = boot();
    t.api.setUploadEligible(true);
    t.api.stageAttachedFiles([t.file('a.png'), t.file('b.png')]);
    check(5, 'a drop before the thread exists stages nothing',
      t.stagedFiles.length === 0, 'staged=' + t.stagedFiles.length);
    check(6, 'and says the one line ONCE, however many files the attempt carried',
      t.notices.length === 1 && t.notices[0] === 'assistant: ' + NOTICE,
      JSON.stringify(t.notices));
    t.api.setThreadId('thr_abc');
    t.api.stageAttachedFiles([t.file('a.png'), t.file('b.png')]);
    check(7, 'the same drop from the second message stages every file and says nothing',
      t.stagedFiles.length === 2 && t.notices.length === 1,
      'staged=' + t.stagedFiles.length + ' notices=' + t.notices.length);
    const u = boot();
    u.api.setUploadEligible(true);
    u.api.stageAttachedFiles([]);
    check(8, 'a drop carrying no files is silent - the notice answers an attempt, not a stray drag',
      u.notices.length === 0, JSON.stringify(u.notices));
  }
  // 9. The file input, the third way in: a picked file is a FileList, and the
  //    hidden button is not what refuses it.
  {
    const t = boot();
    t.api.setUploadEligible(true);
    t.api.stageAttachedFiles({ 0: t.file('a.png'), length: 1 });
    check(9, 'a file picked from the file input on turn one stages nothing and says the one line',
      /function handleFileSelect[\s\S]{0,60}?stageAttachedFiles\(e\.target\.files\)/.test(SRC)
      && t.stagedFiles.length === 0 && t.notices.length === 1,
      'staged=' + t.stagedFiles.length + ' notices=' + JSON.stringify(t.notices));
  }
  // 10-11. Every upload names the real conversation.
  {
    const t = boot();
    t.api.setUploadEligible(true);
    t.api.setThreadId('thr_abc');
    t.api.stageAttachedFiles([t.file('a.png')]);
    await t.api.uploadStagedFiles();
    check(10, 'an upload carries the real thread id as its conversation',
      t.uploads.length === 1 && t.uploads[0].conversationId === 'thr_abc',
      JSON.stringify(t.uploads));
    // 5.8.3: a green-ticked chip from a failed send rides the next one.
    t.stagedFiles.push({ file: t.file('c.png'), status: 'uploaded', fileId: 'file_c.png' });
    t.api.stageAttachedFiles([t.file('d.png')]);
    const again = await t.api.uploadStagedFiles();
    check(11, 'a retry still carries the green-ticked ids alongside the fresh upload (5.8.3), on the same thread',
      again.indexOf('file_c.png') !== -1 && again.indexOf('file_d.png') !== -1
      && t.uploads.every(u => u.conversationId === 'thr_abc'),
      JSON.stringify(again) + ' ' + JSON.stringify(t.uploads));
  }
  // 12-15. The wiring, asserted against the shipped file.
  {
    check(12, "the placeholder conversation is gone from the widget - no upload can be '_anonymous'",
      SRC.indexOf('_anonymous') === -1);
    check(13, 'the composer PASTE path routes files through the gate',
      /addEventListener\('paste'[\s\S]{0,450}?stageAttachedFiles\(/.test(SRC));
    check(14, 'the composer DROP path routes files through the gate',
      /addEventListener\('drop'[\s\S]{0,260}?stageAttachedFiles\(/.test(SRC));
    const bare = SRC.split(/\r?\n/).filter(l => /^\s+threadId = /.test(l));
    check(15, 'no assignment to threadId bypasses setThreadId (the gate cannot be forgotten)',
      bare.length === 1 && bare[0].includes('id || null'), JSON.stringify(bare));
    check(16, 'the notice is in both string maps, so it can be translated',
      SRC.includes("attachAfterFirstMessage: '" + NOTICE + "'")
      && PHP.includes("'attachAfterFirstMessage' => __( '" + NOTICE + "'"));
  }

  console.log(results.join('\n'));
  console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
  process.exit(fail ? 1 : 0);
})();
